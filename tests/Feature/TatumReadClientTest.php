<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Services\TatumReadClient;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\CryptoPublicKeyTest;

class TatumReadClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_price_explicitly_requests_usd_and_preserves_decimal_string_and_provider_time(): void
    {
        $this->freezeTime();
        $this->setCryptoSettings(['crypto.api_key' => 'test-mainnet-key']);
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/data/rate/symbol*' => Http::response([
            'value' => '0.9987654321', 'basePair' => 'USD', 'symbol' => 'USDT', 'timestamp' => now()->getTimestampMs(),
        ])]);

        $price = app(TatumReadClient::class)->usdPrice('tron-mainnet:USDT');

        $this->assertSame('0.9987654321', $price['value']);
        $this->assertSame(now()->getTimestampMs(), $price['at']->getTimestampMs());
        Http::assertSent(fn (Request $r): bool => $r['symbol'] === 'USDT' && $r['basePair'] === 'USD' && $r->hasHeader('x-api-key', 'test-mainnet-key'));
    }

    #[DataProvider('badPrices')]
    public function test_rejects_wrong_pair_missing_timestamp_float_or_stale_prices(array $changes): void
    {
        $this->freezeTime();
        $this->setCryptoSettings(['crypto.api_key' => 'key']);
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/data/rate/symbol*' => Http::response(array_replace([
            'value' => '2500', 'basePair' => 'USD', 'symbol' => 'ETH', 'timestamp' => now()->getTimestampMs(),
        ], $changes))]);
        $this->expectException(DomainException::class);

        app(TatumReadClient::class)->usdPrice('ethereum-mainnet:ETH');
    }

    public static function badPrices(): array
    {
        return [
            'wrong pair' => [['basePair' => 'EUR']], 'wrong symbol' => [['symbol' => 'BTC']],
            'unknown time' => [['timestamp' => null]], 'stale' => [['timestamp' => 1]],
            'future time' => [['timestamp' => PHP_INT_MAX]],
            'float value' => [['value' => 2500.1]], 'zero price' => [['value' => '0']],
            'exponential value' => [['value' => '2.5e3']],
        ];
    }

    public function test_testnet_derivation_uses_testnet_key_and_no_secret_or_signing_endpoint(): void
    {
        $this->setCryptoSettings(['crypto.api_key' => 'mainnet', 'crypto.testnet_key' => 'testnet']);
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v3/ethereum/address/*/2*' => Http::response(['address' => '0x'.str_repeat('c', 40)])]);

        $address = app(TatumReadClient::class)->deriveAddress('ethereum-sepolia', CryptoConfigurationTest::XPUB, 2);

        $this->assertSame('0x'.str_repeat('c', 40), $address);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->hasHeader('x-api-key', 'testnet') && $r['testnetType'] === 'ethereum-sepolia');
        Http::assertSentCount(1);
    }

    public function test_litecoin_testnet_derivation_sends_ttub_with_the_testnet_key(): void
    {
        $this->setCryptoSettings(['crypto.api_key' => 'mainnet', 'crypto.testnet_key' => 'testnet']);
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v3/litecoin/address/*' => Http::response(['address' => 'mzBc4XEFSdzCDcTxAgf6EZXgsZWpztRhef'])]);

        $address = app(TatumReadClient::class)->deriveAddress('litecoin-testnet', CryptoPublicKeyTest::TPUB, 0);

        $this->assertSame('mzBc4XEFSdzCDcTxAgf6EZXgsZWpztRhef', $address);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v3/litecoin/address/'.CryptoPublicKeyTest::TTUB.'/0')
            && ! str_contains($request->url(), CryptoPublicKeyTest::TPUB)
            && $request->hasHeader('x-api-key', 'testnet'));
    }

    public function test_bitcoin_testnet_derivation_keeps_the_stored_tpub(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => 'testnet']);
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v3/bitcoin/address/*' => Http::response(['address' => 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn'])]);

        app(TatumReadClient::class)->deriveAddress('bitcoin-testnet', CryptoPublicKeyTest::TPUB, 1);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v3/bitcoin/address/'.CryptoPublicKeyTest::TPUB.'/1')
            && ! str_contains($request->url(), CryptoPublicKeyTest::TTUB));
    }

    public function test_read_failure_is_sanitized_and_does_not_leak_key_or_public_wallet_url(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => 'sensitive-api-key']);
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v3/ethereum/address/*' => Http::response(['error' => 'sensitive-api-key'], 401)]);

        try {
            app(TatumReadClient::class)->deriveAddress('ethereum-sepolia', CryptoConfigurationTest::XPUB, 2);
            $this->fail('Failed read was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('Tatum read request failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            Http::assertSentCount(1);
        }
    }

    public function test_retries_safe_reads_on_rate_limit(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => 'key']);
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['https://api.tatum.io/v3/ethereum/address/*' => Http::sequence()->push([], 429, ['Retry-After' => '1'])->push(['address' => '0x'.str_repeat('c', 40)])]);

        $this->assertSame('0x'.str_repeat('c', 40), app(TatumReadClient::class)->deriveAddress('ethereum-sepolia', CryptoConfigurationTest::XPUB, 2));
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    public function test_read_redirect_is_rejected_without_following_or_throwing_a_type_error(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => 'key']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://untrusted.example/'])]);

        try {
            app(TatumReadClient::class)->deriveAddress('ethereum-sepolia', CryptoConfigurationTest::XPUB, 2);
            $this->fail('Redirect was accepted.');
        } catch (DomainException) {
            Http::assertSentCount(1);
        }
    }
}
