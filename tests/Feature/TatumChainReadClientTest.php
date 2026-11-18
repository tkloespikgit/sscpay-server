<?php

namespace Tests\Feature;

use App\Services\TatumChainReadClient;
use App\Support\CryptoVerificationPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TatumChainReadClientTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('networks')]
    public function test_read_uses_fixed_host_and_network_credential(string $network, bool $tron): void
    {
        $this->setCryptoSettings(['crypto.api_key' => 'mainnet-key', 'crypto.testnet_key' => 'testnet-key']);
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
        $this->setCryptoSettings(['crypto.api_key' => 'key']);
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
        $this->setCryptoSettings(['crypto.api_key' => 'key']);
        Http::fake(['*' => Http::response(['id' => 1, 'result' => null, 'error' => ['code' => -5, 'message' => 'not found']])]);

        $this->assertNull(app(TatumChainReadClient::class)->utxo('bitcoin-mainnet', 'getrawtransaction', [str_repeat('a', 64), true]));
        $this->expectException(DomainException::class);
        app(TatumChainReadClient::class)->utxo('bitcoin-mainnet', 'getblockhash', [100]);
    }

    public function test_http_200_with_tron_error_is_still_a_failure(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => 'key']);
        Http::fake(['*' => Http::response(['Error' => 'provider-secret'])]);
        $this->expectException(DomainException::class);

        app(TatumChainReadClient::class)->tron('tron-shasta', 'wallet', 'getnowblock');
    }

    public function test_safe_read_retries_only_transient_failures(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => 'key']);
        Sleep::fake();
        Http::fake(['*' => Http::sequence()->push([], 429)->push('{}')]);

        $this->assertSame([], app(TatumChainReadClient::class)->tron('tron-shasta', 'wallet', 'getnowblock'));
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    #[DataProvider('rateLimitDelays')]
    public function test_rate_limited_read_waits_for_provider_cooldown(?string $retryAfter, int $expectedMilliseconds): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18T04:00:00Z'));
        $this->setCryptoSettings(['crypto.testnet_key' => 'private-test-key']);
        Http::preventStrayRequests();
        Sleep::fake();
        $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];
        Http::fake(['https://bitcoin-testnet.gateway.tatum.io/' => Http::sequence()
            ->push(['message' => 'private-provider-detail'], 429, $headers)
            ->push(['id' => 1, 'result' => str_repeat('a', 64), 'error' => null])]);

        $this->assertSame(str_repeat('a', 64), app(TatumChainReadClient::class)->utxo('bitcoin-testnet', 'getblockhash', [0]));

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for($expectedMilliseconds)->milliseconds()]);
    }

    public static function rateLimitDelays(): array
    {
        return ['one second' => ['1', 1000], 'two seconds' => ['2', 2000], 'bounded five seconds' => ['5', 5000],
            'absent header' => [null, 1000], 'invalid header' => ['invalid', 1000], 'zero' => ['0', 1000],
            'HTTP date' => ['Wed, 18 Nov 2026 04:00:02 GMT', 2000],
            'past HTTP date' => ['Wed, 18 Nov 2026 03:59:59 GMT', 1000]];
    }

    #[DataProvider('longRateLimitDelays')]
    public function test_long_provider_cooldown_is_not_retried_early_or_waited_inline(string $retryAfter): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18T04:00:00Z'));
        $this->setCryptoSettings(['crypto.testnet_key' => 'private-test-key']);
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['https://bitcoin-testnet.gateway.tatum.io/' => Http::response(['message' => 'private-provider-detail'], 429, ['Retry-After' => $retryAfter])]);

        try {
            app(TatumChainReadClient::class)->utxo('bitcoin-testnet', 'getblockhash', [0]);
            $this->fail('Rate-limited read was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame(429, $exception->getCode());
            $this->assertStringNotContainsString('private-provider-detail', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    public static function longRateLimitDelays(): array
    {
        return ['six seconds' => ['6'], 'large delay' => ['999999999999999999999999999999999999'],
            'HTTP date' => ['Wed, 18 Nov 2026 04:00:06 GMT']];
    }

    public function test_exhausted_rate_limit_preserves_safe_http_status_without_provider_details(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => 'private-test-key']);
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['https://bitcoin-testnet.gateway.tatum.io/' => Http::response(['message' => 'private-provider-detail'], 429, ['Retry-After' => '1'])]);

        try {
            app(TatumChainReadClient::class)->utxo('bitcoin-testnet', 'getblockhash', [0]);
            $this->fail('Rate-limited read was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame(429, $exception->getCode());
            $this->assertSame('Tatum chain read failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            Http::assertSentCount(2);
            Sleep::assertSequence([Sleep::for(1)->second()]);
        }
    }

    public function test_missing_credentials_fail_without_http(): void
    {
        $this->setCryptoSettings(['crypto.testnet_key' => null]);
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
        $this->setCryptoSettings(['crypto.'.$prefix.'_min_confirmations.'.$network => '6', 'crypto.genesis_hashes.'.$network => str_repeat('a', 64)]);
        $snapshot = CryptoVerificationPolicy::snapshot($network);
        $this->setCryptoSettings(['crypto.'.$prefix.'_min_confirmations.'.$network => '12', 'crypto.genesis_hashes.'.$network => str_repeat('b', 64)]);

        $this->assertSame(['version' => $tron ? 'tron-solidified-v1' : 'utxo-canonical-v1', 'minimum_confirmations' => '6', 'genesis_hash' => str_repeat('a', 64)], $snapshot);
    }
}
