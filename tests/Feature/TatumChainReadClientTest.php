<?php

namespace Tests\Feature;

use App\Services\TatumChainReadClient;
use App\Support\CryptoVerificationPolicy;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TatumChainReadClientTest extends TestCase
{
    #[DataProvider('networks')]
    public function test_read_uses_fixed_host_and_network_credential(string $network, bool $tron): void
    {
        config(['services.tatum.api_key' => 'mainnet-key', 'services.tatum.testnet_key' => 'testnet-key']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($tron ? '{}' : ['id' => 1, 'result' => null, 'error' => null])]);
        $client = app(TatumChainReadClient::class);

        if ($tron) {
            $this->assertSame([], $client->tron($network, 'wallet', 'getnowblock'));
        } else {
            $this->assertNull($client->utxo($network, 'getrawtransaction', [str_repeat('a', 64), true]));
        }
        $key = str_ends_with($network, '-mainnet') ? 'mainnet-key' : 'testnet-key';
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://'.$network.'.gateway.tatum.io/')
            && $request->method() === 'POST' && $request->hasHeader('x-api-key', $key));
    }

    public static function networks(): array
    {
        return ['BTC' => ['bitcoin-mainnet', false], 'BTC test' => ['bitcoin-testnet', false],
            'LTC' => ['litecoin-mainnet', false], 'LTC test' => ['litecoin-testnet', false],
            'TRON' => ['tron-mainnet', true], 'Shasta' => ['tron-shasta', true]];
    }

    #[DataProvider('unsafeRequests')]
    public function test_unknown_hosts_views_and_mutating_methods_are_rejected_before_http(string $network, string $view, string $method, bool $tron): void
    {
        Http::preventStrayRequests();
        try {
            $client = app(TatumChainReadClient::class);
            if ($tron) {
                $client->tron($network, $view, $method);
            } else {
                $client->utxo($network, $method);
            }
            $this->fail('Unsafe request was allowed.');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }

    public static function unsafeRequests(): array
    {
        return ['UTXO broadcast' => ['bitcoin-mainnet', '', 'sendrawtransaction', false],
            'UTXO host' => ['untrusted.example', '', 'getblockhash', false],
            'TRON broadcast' => ['tron-mainnet', 'wallet', 'broadcasttransaction', true],
            'TRON view' => ['tron-mainnet', '../wallet', 'getnowblock', true],
            'TRON host' => ['untrusted.example', 'wallet', 'getnowblock', true]];
    }

    #[DataProvider('badEnvelopes')]
    public function test_invalid_rpc_response_cannot_be_used_as_utxo_facts(mixed $body, int $status): void
    {
        config(['services.tatum.api_key' => 'key']);
        Http::fake(['*' => Http::response($body, $status, ['Location' => 'https://untrusted.example/'])]);
        try {
            app(TatumChainReadClient::class)->utxo('bitcoin-mainnet', 'getblockchaininfo');
            $this->fail('Invalid RPC response was accepted.');
        } catch (DomainException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('provider-secret', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public static function badEnvelopes(): array
    {
        return ['wrong id' => [['id' => 2, 'result' => null], 200], 'wrong version' => [['id' => 1, 'jsonrpc' => '3.0', 'result' => null], 200],
            'missing result' => [['id' => 1], 200], 'rpc error' => [['id' => 1, 'error' => ['code' => -8, 'message' => 'provider-secret']], 200],
            'redirect' => ['', 302], '401' => [['Error' => 'provider-secret'], 401], 'invalid JSON' => ['{provider-secret', 200], 'array root' => ['[]', 200]];
    }

    public function test_missing_transaction_rpc_error_is_pending_but_other_rpc_errors_are_not_swallowed(): void
    {
        config(['services.tatum.api_key' => 'key']);
        Http::fake(['*' => Http::response(['id' => 1, 'result' => null, 'error' => ['code' => -5, 'message' => 'not found']])]);

        $this->assertNull(app(TatumChainReadClient::class)->utxo('bitcoin-mainnet', 'getrawtransaction', [str_repeat('a', 64), true]));
        $this->expectException(DomainException::class);
        app(TatumChainReadClient::class)->utxo('bitcoin-mainnet', 'getblockhash', [100]);
    }

    public function test_http_200_with_tron_error_is_still_a_failure(): void
    {
        config(['services.tatum.testnet_key' => 'key']);
        Http::fake(['*' => Http::response(['Error' => 'provider-secret'])]);
        $this->expectException(DomainException::class);

        app(TatumChainReadClient::class)->tron('tron-shasta', 'wallet', 'getnowblock');
    }

    public function test_safe_read_retries_only_transient_failures(): void
    {
        config(['services.tatum.testnet_key' => 'key']);
        Sleep::fake();
        Http::fake(['*' => Http::sequence()->push([], 429)->push('{}')]);

        $this->assertSame([], app(TatumChainReadClient::class)->tron('tron-shasta', 'wallet', 'getnowblock'));
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_missing_credentials_fail_without_http(): void
    {
        config(['services.tatum.testnet_key' => null]);
        Http::preventStrayRequests();
        try {
            app(TatumChainReadClient::class)->utxo('litecoin-testnet', 'getblockhash', [0]);
            $this->fail('Missing key was accepted.');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('networks')]
    public function test_policy_snapshot_pins_each_network_before_later_configuration_changes(string $network, bool $tron): void
    {
        $prefix = $tron ? 'tron' : 'utxo';
        config(['services.tatum.'.$prefix.'_min_confirmations.'.$network => '6', 'services.tatum.genesis_hashes.'.$network => str_repeat('a', 64)]);
        $snapshot = CryptoVerificationPolicy::snapshot($network);
        config(['services.tatum.'.$prefix.'_min_confirmations.'.$network => '12', 'services.tatum.genesis_hashes.'.$network => str_repeat('b', 64)]);

        $this->assertSame(['version' => $tron ? 'tron-solidified-v1' : 'utxo-canonical-v1', 'minimum_confirmations' => '6', 'genesis_hash' => str_repeat('a', 64)], $snapshot);
    }
}
