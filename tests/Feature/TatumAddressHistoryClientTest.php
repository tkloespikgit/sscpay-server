<?php

namespace Tests\Feature;

use App\Models\CryptoAddress;
use App\Models\CryptoPaymentRequest;
use App\Services\TatumAddressHistoryClient;
use App\Support\CryptoAssetRegistry;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TatumAddressHistoryClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setCryptoSettings(['crypto.api_key' => 'public-test-main', 'crypto.testnet_key' => 'public-test-network']);
        Http::preventStrayRequests();
    }

    #[DataProvider('utxoNetworks')]
    public function test_utxo_pages_are_bound_to_network_address_and_fixed_height_range(string $network, string $asset, string $address): void
    {
        $request = $this->payment($network, $asset, $address);
        $row = ['hash' => str_repeat('a', 64), 'blockNumber' => 101, 'outputs' => [['address' => $address, 'value' => '0.001']]];
        Http::fakeSequence()->push(array_fill(0, 50, $row))->push([]);
        $page = app(TatumAddressHistoryClient::class)->page($request, $this->range());
        $this->assertSame(['hashes' => [str_repeat('a', 64)], 'next' => 50], $page);
        $this->assertSame(['hashes' => [], 'next' => null], app(TatumAddressHistoryClient::class)->page($request, $this->range(cursor: 50)));
        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/history/utxos?') && $r['chain'] === $network
            && $r['address'] === $address && (int) $r['blockFrom'] === 100 && (int) $r['blockTo'] === 105
            && (int) $r['offset'] === 50 && $r['txType'] === 'incoming'
            && $r->hasHeader('x-api-key', str_ends_with($network, '-mainnet') ? 'public-test-main' : 'public-test-network'));
    }

    public static function utxoNetworks(): array
    {
        return [
            ['bitcoin-mainnet', 'BTC', '1BoatSLRHtKNngkdXEeobR76b53LETtpyT'],
            ['bitcoin-testnet', 'BTC', 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn'],
            ['litecoin-mainnet', 'LTC', 'LZMFvW5GSFwwz6bAsAT37h6ej8H8QwMSHk'],
            ['litecoin-testnet', 'LTC', 'mzBc4XEFSdzCDcTxAgf6EZXgsZWpztRhef'],
        ];
    }

    public function test_ethereum_queries_native_and_internal_transfers_separately(): void
    {
        $address = '0x'.str_repeat('a', 40);
        $request = $this->payment('ethereum-sepolia', 'ETH', $address);
        $hash = str_repeat('c', 64);
        Http::fakeSequence()->push(['result' => [['hash' => '0x'.$hash, 'blockNumber' => 101, 'chain' => 'ethereum-sepolia',
            'address' => $address, 'transactionType' => 'native', 'transactionSubtype' => 'incoming']]])
            ->push([
                ['hash' => '0x'.$hash, 'blockNumber' => '101', 'to' => $address, 'isError' => '0'],
                ['hash' => '0x'.str_repeat('d', 64), 'blockNumber' => '101', 'to' => $address, 'isError' => '1'],
                ['hash' => '0x'.str_repeat('e', 64), 'blockNumber' => '101', 'to' => '0x'.str_repeat('b', 40), 'isError' => '0'],
            ]);
        $client = app(TatumAddressHistoryClient::class);
        $this->assertSame([$hash], $client->page($request, $this->range())['hashes']);
        $this->assertSame([$hash], $client->page($request, $this->range(stage: 1))['hashes']);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/transaction/history?') && $r['transactionTypes'] === 'native' && $r['addresses'] === $address);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/transaction/internal?') && $r['address'] === $address);
    }

    public function test_erc20_queries_only_the_frozen_contract(): void
    {
        $address = '0x'.str_repeat('a', 40);
        $contract = '0x'.str_repeat('1', 40);
        $request = $this->payment('ethereum-mainnet', 'USDT', $address, $contract);
        Http::fakeSequence()->push(['result' => [['hash' => '0x'.str_repeat('c', 64), 'blockNumber' => 101,
            'chain' => 'ethereum-mainnet', 'address' => $address, 'tokenAddress' => $contract,
            'transactionType' => 'fungible', 'transactionSubtype' => 'incoming']]]);
        $this->assertSame([str_repeat('c', 64)], app(TatumAddressHistoryClient::class)->page($request, $this->range())['hashes']);
        Http::assertSent(fn ($r): bool => $r['transactionTypes'] === 'fungible' && $r['tokenAddress'] === $contract);
    }

    public function test_trc20_uses_documented_camel_case_rows_and_resumes_fingerprint(): void
    {
        $address = 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL';
        $contract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        $request = $this->payment('tron-shasta', 'USDT', $address, $contract);
        Http::fakeSequence()->push(['transactions' => [['txID' => str_repeat('c', 64), 'type' => 'Transfer',
            'to' => $address, 'tokenInfo' => ['address' => $contract]]], 'next' => 'cursor-page-2'])->push(['transactions' => []]);
        $client = app(TatumAddressHistoryClient::class);
        $this->assertSame(['hashes' => [str_repeat('c', 64)], 'next' => 'cursor-page-2'], $client->page($request, $this->range()));
        $this->assertNull($client->page($request, $this->range(cursor: 'cursor-page-2'))['next']);
        Http::assertSent(fn ($r): bool => ($r->data()['next'] ?? null) === 'cursor-page-2' && $r['contractAddress'] === $contract
            && (int) $r['minTimestamp'] === 1000000 && (int) $r['maxTimestamp'] === 2000000
            && $r->hasHeader('x-api-key', 'public-test-network'));
    }

    #[DataProvider('invalidPages')]
    public function test_malformed_unbound_and_incomplete_pages_fail_closed(array $body): void
    {
        $request = $this->payment('ethereum-sepolia', 'ETH', '0x'.str_repeat('a', 40));
        Http::fakeSequence()->push($body);
        $this->expectException(DomainException::class);
        app(TatumAddressHistoryClient::class)->page($request, $this->range());
    }

    public static function invalidPages(): array
    {
        $valid = ['hash' => '0x'.str_repeat('c', 64), 'blockNumber' => 101, 'chain' => 'ethereum-sepolia',
            'address' => '0x'.str_repeat('a', 40), 'transactionType' => 'native', 'transactionSubtype' => 'incoming'];

        return [
            'missing list' => [['error' => 'no data']],
            'wrong shape' => [['result' => ['unexpected' => $valid]]],
            'wrong chain' => [['result' => [[...$valid, 'chain' => 'ethereum-mainnet']]]],
            'wrong address' => [['result' => [[...$valid, 'address' => '0x'.str_repeat('b', 40)]]]],
            'outside range' => [['result' => [[...$valid, 'blockNumber' => 99]]]],
            'invalid hash' => [['result' => [[...$valid, 'hash' => 'not-a-hash']]]],
        ];
    }

    public function test_provider_error_does_not_expose_credentials_or_response_body(): void
    {
        $request = $this->payment('ethereum-sepolia', 'ETH', '0x'.str_repeat('a', 40));
        Http::fakeSequence()->push(['message' => 'private-provider-text'], 403);
        try {
            app(TatumAddressHistoryClient::class)->page($request, $this->range());
            $this->fail('Provider error was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('private-provider-text', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_address_history_waits_for_provider_rate_limit_before_retrying_the_same_page(): void
    {
        $request = $this->payment('bitcoin-testnet', 'BTC', 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn');
        Sleep::fake();
        Http::fake(['https://api.tatum.io/v4/data/blockchains/transaction/history/utxos*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '1'])->push([])]);

        $this->assertSame(['hashes' => [], 'next' => null], app(TatumAddressHistoryClient::class)->page($request, $this->range()));

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    private function payment(string $network, string $asset, string $address, ?string $contract = null): CryptoPaymentRequest
    {
        $request = CryptoPaymentRequest::factory()->make(['network' => $network, 'asset' => $asset,
            'quote_snapshot' => ['payment_config_snapshot' => ['contract_address' => $contract]]]);

        return $request->setRelation('cryptoAddress', CryptoAddress::factory()->make(['network' => $network, 'address' => $address,
            'address_key' => app(CryptoAssetRegistry::class)->addressKey($network, $address)]));
    }

    private function range(int $stage = 0, int|string $cursor = 0): array
    {
        return ['from' => 100, 'to' => 105, 'from_time' => 1000, 'to_time' => 2000, 'stage' => $stage, 'cursor' => $cursor];
    }
}
