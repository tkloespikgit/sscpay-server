<?php

namespace Tests\Feature;

use App\Services\TatumSubscriptionClient;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class TatumSubscriptionClientTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    #[DataProvider('uncertainCreationResponses')]
    public function test_post_is_not_blindly_retried_after_server_error_or_rate_limit(int $status): void
    {
        $monitor = $this->createCryptoMonitor();
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['https://api.tatum.io/v4/subscription*' => Http::response(['sensitive' => 'do-not-forward'], $status, ['Retry-After' => '1'])]);

        try {
            app(TatumSubscriptionClient::class)->create($monitor);
            $this->fail('Uncertain creation was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('Tatum subscription creation was not confirmed.', $exception->getMessage());
            $this->assertSame($status, $exception->getCode());
            $this->assertNull($exception->getPrevious());
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    public static function uncertainCreationResponses(): array
    {
        return ['server error' => [503], 'rate limit' => [429]];
    }

    public function test_idempotent_hmac_setup_waits_for_rate_limit_and_repeats_the_same_configuration(): void
    {
        $this->createCryptoMonitor();
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['https://api.tatum.io/v4/subscription' => Http::sequence()
            ->push([], 429, ['Retry-After' => '1'])->push('', 204)]);

        app(TatumSubscriptionClient::class)->enableHmac('testnet');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['hmacSecret'] === 'test-testnet-webhook-secret-123456');
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    public function test_subscription_lookup_waits_for_provider_rate_limit(): void
    {
        $monitor = $this->createCryptoMonitor();
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['https://api.tatum.io/v4/subscription/alert-1' => Http::sequence()
            ->push([], 429, ['Retry-After' => '1'])->push($this->alertData($monitor))]);

        $this->assertSame('alert-1', app(TatumSubscriptionClient::class)->get('testnet', 'alert-1')['id']);

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    #[DataProvider('subscriptionNetworks')]
    public function test_legacy_currency_alias_is_matched_only_with_the_exact_canonical_network(string $network, string $asset, int $decimals, string $address, string $currency): void
    {
        $monitor = $this->createCryptoMonitor(['network' => $network, 'asset' => $asset, 'decimals' => $decimals],
            ['network' => $network, 'address' => $address]);
        $alert = $this->alertData($monitor);
        $alert['chain'] = $network;
        $alert['attr']['chain'] = $currency;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.tatum.io/v4/subscription/alert-1' => Http::response($alert),
            'https://api.tatum.io/v4/subscription?*' => Http::response([$alert]),
        ]);
        $client = app(TatumSubscriptionClient::class);

        $remote = $client->get($monitor->environment, 'alert-1');

        $this->assertTrue($client->matches($monitor, $remote));
        $this->assertSame(['alert-1'], $client->matchingIds($monitor));
        Http::assertSentCount(2);
    }

    public static function subscriptionNetworks(): array
    {
        return [
            'BTC mainnet' => ['bitcoin-mainnet', 'BTC', 8, '1BoatSLRHtKNngkdXEeobR76b53LETtpyT', 'BTC'],
            'BTC testnet' => ['bitcoin-testnet', 'BTC', 8, 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn', 'BTC'],
            'LTC mainnet' => ['litecoin-mainnet', 'LTC', 8, 'LZMFvW5GSFwwz6bAsAT37h6ej8H8QwMSHk', 'LTC'],
            'LTC testnet' => ['litecoin-testnet', 'LTC', 8, 'mzBc4XEFSdzCDcTxAgf6EZXgsZWpztRhef', 'LTC'],
            'ETH mainnet' => ['ethereum-mainnet', 'ETH', 18, '0x'.str_repeat('a', 40), 'ETH'],
            'ETH Sepolia' => ['ethereum-sepolia', 'ETH', 18, '0x'.str_repeat('a', 40), 'ETH'],
            'TRON mainnet' => ['tron-mainnet', 'USDT', 6, 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL', 'TRON'],
            'TRON Shasta' => ['tron-shasta', 'USDT', 6, 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL', 'TRON'],
        ];
    }

    #[DataProvider('invalidLegacyAlerts')]
    public function test_legacy_alias_never_relaxes_network_binding_address_callback_or_health_checks(array $changes): void
    {
        $monitor = $this->createCryptoMonitor(['network' => 'bitcoin-testnet', 'asset' => 'BTC', 'decimals' => 8],
            ['network' => 'bitcoin-testnet', 'address' => 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn']);
        $alert = $this->alertData($monitor);
        $alert['chain'] = 'bitcoin-testnet';
        $alert['attr']['chain'] = 'BTC';
        $alert = array_replace_recursive($alert, $changes);
        Http::preventStrayRequests();

        $this->assertFalse(app(TatumSubscriptionClient::class)->matches($monitor, $alert));

        Http::assertNothingSent();
    }

    public static function invalidLegacyAlerts(): array
    {
        return [
            'mainnet mismatch' => [['chain' => 'bitcoin-mainnet']],
            'ambiguous network' => [['chain' => null]],
            'other family' => [['attr' => ['chain' => 'LTC']]],
            'unknown alias' => [['attr' => ['chain' => 'btc']]],
            'conflicting attribute network' => [['attr' => ['chain' => 'bitcoin-mainnet']]],
            'conflicting top-level network' => [['chain' => 'bitcoin-mainnet', 'attr' => ['chain' => 'bitcoin-testnet']]],
            'wrong address' => [['attr' => ['address' => 'different-address']]],
            'wrong callback' => [['attr' => ['url' => 'https://other.example.test/callback']]],
            'wrong event type' => [['type' => 'INCOMING_NATIVE_TX']],
            'paused' => [['paused' => true]],
            'inactive' => [['status' => 'paused']],
            'unsupported template' => [['templateId' => 'custom-template']],
        ];
    }

    public function test_bare_legacy_currency_without_canonical_network_is_not_accepted(): void
    {
        $monitor = $this->createCryptoMonitor(['network' => 'bitcoin-testnet', 'asset' => 'BTC', 'decimals' => 8],
            ['network' => 'bitcoin-testnet', 'address' => 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn']);
        $alert = $this->alertData($monitor);
        $alert['attr']['chain'] = 'BTC';
        Http::preventStrayRequests();

        $this->assertFalse(app(TatumSubscriptionClient::class)->identifies($monitor, $alert));

        Http::assertNothingSent();
    }

    public function test_matching_alert_on_second_page_is_found_using_exact_url_chain_and_address(): void
    {
        $monitor = $this->createCryptoMonitor();
        $target = $this->alertData($monitor);
        $other = $target;
        $other['attr']['url'] = 'https://other.example.test/callback';
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/subscription*' => fn (Request $r) => Http::response((int) $r['offset'] === 0 ? array_fill(0, 50, $other) : [$target])]);

        $ids = app(TatumSubscriptionClient::class)->matchingIds($monitor);

        $this->assertSame(['alert-1'], $ids);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => $r['chain'] === 'ethereum-sepolia' && $r['address'] === $monitor->address->address && (int) $r['offset'] === 50);
    }

    public function test_page_limit_is_a_failure_instead_of_proof_that_subscription_does_not_exist(): void
    {
        $monitor = $this->createCryptoMonitor();
        $other = $this->alertData($monitor);
        $other['attr']['url'] = 'https://other.example.test/callback';
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/subscription*' => Http::response(array_fill(0, 50, $other))]);
        $this->expectException(DomainException::class);
        try {
            app(TatumSubscriptionClient::class)->matchingIds($monitor);
        } finally {
            Http::assertSentCount(3);
            Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
        }
    }

    public function test_paused_matching_alert_is_found_but_cannot_be_claimed_healthy(): void
    {
        $monitor = $this->createCryptoMonitor();
        $paused = [...$this->alertData($monitor), 'paused' => true];
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/subscription*' => Http::response([$paused])]);
        $client = app(TatumSubscriptionClient::class);

        $this->assertSame(['alert-1'], $client->matchingIds($monitor));
        $this->assertFalse($client->matches($monitor, $paused));
        $this->assertTrue($client->identifies($monitor, $paused));
    }

    public function test_absent_remote_alert_can_be_cancelled_idempotently(): void
    {
        $this->createCryptoMonitor();
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/subscription/absent' => Http::response([], 404)]);
        $client = app(TatumSubscriptionClient::class);

        $this->assertNull($client->get('testnet', 'absent'));
        $client->cancel('testnet', 'absent');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_unsafe_identifier_never_reaches_any_provider_endpoint(): void
    {
        Http::preventStrayRequests();
        $this->expectException(DomainException::class);
        try {
            app(TatumSubscriptionClient::class)->cancel('mainnet', '../wallet/priv');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_redirect_cannot_send_credentials_to_another_host(): void
    {
        $monitor = $this->createCryptoMonitor();
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/subscription*' => Http::response('', 302, ['Location' => 'https://other.example.test/'])]);
        $this->expectException(DomainException::class);
        try {
            app(TatumSubscriptionClient::class)->create($monitor);
        } finally {
            Http::assertSentCount(1);
        }
    }
}
