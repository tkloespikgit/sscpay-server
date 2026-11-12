<?php

namespace Tests\Feature;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoWebhookReceipt;
use App\Models\SystemConfig;
use App\Services\CryptoAddressMonitorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class CryptoAddressMonitorTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    public function test_create_activates_one_subscription_and_ttl_covers_existing_quote_without_publishing_payment(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->fakeProvider($monitor);
        $service = app(CryptoAddressMonitorService::class);

        $this->assertTrue($service->synchronize($this->merchant->id, $monitor->id));
        $monitor->refresh();
        $expiry = $monitor->expires_at;
        $this->travel(1)->minutes();
        $same = $service->prepare($this->merchant->id, $monitor->crypto_payment_request_id);
        $this->assertTrue($service->synchronize($this->merchant->id, $same->id));

        $this->assertSame($monitor->id, $same->id);
        $this->assertSame('active', $monitor->refresh()->status);
        $this->assertSame('alert-1', $monitor->subscription_id);
        $this->assertSame('2026-11-11 12:15:00', $expiry->toDateTimeString());
        $this->assertSame($expiry->toDateTimeString(), $monitor->expires_at->toDateTimeString());
        $this->assertSame('preparing', $monitor->paymentRequest->status);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r['type'] === 'ADDRESS_EVENT'
            && $r['attr']['chain'] === 'ethereum-sepolia' && $r->hasHeader('x-api-key', 'test-testnet-credential'));
    }

    public function test_creation_uncertainty_is_reconciled_without_a_second_post(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->fakeProvider($monitor, true);

        $this->assertTrue(app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('active', $monitor->refresh()->status);
        Http::assertSentCount(5);
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r->method() === 'POST'));
    }

    public function test_existing_remote_alert_is_adopted_before_creating_a_new_one(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->fakeProvider($monitor, false, true);

        $this->assertTrue(app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('alert-1', $monitor->refresh()->subscription_id);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    }

    public function test_expiry_without_history_takeover_never_deletes_the_remote_subscription(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->activateFixture($monitor);
        $this->travel(16)->minutes();
        Http::preventStrayRequests();

        $this->assertFalse(app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('active', $monitor->refresh()->status);
        $this->assertSame('history_coverage_required', $monitor->last_error_code);
        $this->assertNull($monitor->cancelled_at);
        Http::assertNothingSent();
    }

    public function test_disabling_history_keeps_subscription_even_with_recent_takeover_proof(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->activateFixture($monitor);
        $this->travel(16)->minutes();
        $this->coverHistory($monitor);
        config(['services.tatum.history_enabled' => false]);
        Http::preventStrayRequests();

        $this->assertFalse(app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('active', $monitor->fresh()->status);
        $this->assertSame('history_scanner_disabled', $monitor->fresh()->last_error_code);
        Http::assertNothingSent();
    }

    public function test_expiry_cancels_only_after_fresh_history_takeover_and_preserves_subscription_id(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->activateFixture($monitor);
        $this->travel(16)->minutes();
        $this->coverHistory($monitor);
        $this->fakeProvider($monitor, false, true);
        $service = app(CryptoAddressMonitorService::class);

        $this->assertTrue($service->synchronize($this->merchant->id, $monitor->id));
        $this->assertTrue($service->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('cold', $monitor->refresh()->status);
        $this->assertSame('alert-1', $monitor->subscription_id);
        $this->assertNotNull($monitor->cancelled_at);
        $this->assertDatabaseCount('crypto_addresses', 1);
        $this->assertDatabaseCount('crypto_address_monitors', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/alert-1'));
    }

    #[DataProvider('blockingReceiptStates')]
    public function test_pending_receipt_blocks_cancellation_even_with_history_takeover(string $status): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->activateFixture($monitor);
        $this->travel(16)->minutes();
        $this->coverHistory($monitor);
        CryptoWebhookReceipt::factory()->create([
            'merchant_id' => $this->merchant->id, 'crypto_address_monitor_id' => $monitor->id,
            'crypto_payment_request_id' => $monitor->crypto_payment_request_id,
            'status' => $status,
        ]);
        Http::preventStrayRequests();

        $this->assertFalse(app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('verification_pending', $monitor->refresh()->last_error_code);
        Http::assertNothingSent();
    }

    public static function blockingReceiptStates(): array
    {
        return ['pending' => ['pending'], 'confirming' => ['confirming'], 'manual review' => ['review']];
    }

    public function test_delete_failure_never_marks_local_subscription_as_cancelled(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->activateFixture($monitor);
        $this->travel(16)->minutes();
        $this->coverHistory($monitor);
        $this->fakeProvider($monitor, false, true, true);

        $this->assertFalse(app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('cancelling', $monitor->refresh()->status);
        $this->assertNull($monitor->cancelled_at);
        $this->assertSame('alert-1', $monitor->subscription_id);
        $this->assertSame('provider_failure', $monitor->last_error_code);
        $this->assertNotNull($monitor->next_attempt_at);
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r->method() === 'DELETE'));
    }

    public function test_changed_api_credential_requires_reconciliation_instead_of_using_a_different_account(): void
    {
        $monitor = $this->createCryptoMonitor();
        config(['services.tatum.testnet_key' => 'replacement-key']);
        Http::preventStrayRequests();

        $this->assertFalse(app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame('credential_changed', $monitor->refresh()->last_error_code);
        Http::assertNothingSent();
    }

    public function test_renewal_requires_gap_backfill_and_preserves_old_generation_from_stale_cancel_work(): void
    {
        $monitor = $this->createCryptoMonitor();
        $this->activateFixture($monitor, 30);
        $this->travel(31)->seconds();
        $this->coverHistory($monitor);
        $this->fakeProvider($monitor, false, true);
        $service = app(CryptoAddressMonitorService::class);
        $this->assertTrue($service->synchronize($this->merchant->id, $monitor->id));
        $this->travel(1)->seconds();
        $monitor->update(['backfill_completed_at' => now()]);
        SystemConfig::set('crypto.subscription_reopen_max_renewals', '1');
        $next = $service->prepare($this->merchant->id, $monitor->crypto_payment_request_id);

        $this->assertTrue($service->synchronize($this->merchant->id, $monitor->id));

        $this->assertSame(2, $next->generation);
        $this->assertNotSame($monitor->callback_uuid, $next->callback_uuid);
        $this->assertSame($next->id, $monitor->paymentRequest->refresh()->current_monitor_id);
        $this->assertSame('cold', $monitor->refresh()->status);
        Http::assertSentCount(2);
    }

    public function test_cross_merchant_monitor_is_not_found_before_remote_actions(): void
    {
        $monitor = $this->createCryptoMonitor();
        Http::preventStrayRequests();
        $this->expectException(ModelNotFoundException::class);
        try {
            app(CryptoAddressMonitorService::class)->synchronize($this->merchant->id + 1, $monitor->id);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_command_is_inert_by_default_and_reports_blocked_expiry_when_enabled(): void
    {
        config(['services.tatum.subscriptions_enabled' => false]);
        $this->artisan('crypto:maintain-subscriptions')->assertSuccessful();
        $monitor = $this->createCryptoMonitor();
        $this->activateFixture($monitor);
        $this->travel(16)->minutes();
        Http::preventStrayRequests();

        $this->artisan('crypto:maintain-subscriptions --limit=1')->assertFailed();

        $this->assertSame('history_coverage_required', $monitor->refresh()->last_error_code);
        Http::assertNothingSent();
    }

    private function activateFixture(CryptoAddressMonitor $monitor, int $seconds = 900): void
    {
        $monitor->update(['status' => 'active', 'subscription_id' => 'alert-1', 'activated_at' => now(), 'expires_at' => now()->addSeconds($seconds), 'next_attempt_at' => null]);
    }

    private function coverHistory(CryptoAddressMonitor $monitor): void
    {
        $monitor->update(['backfill_to_block' => 1000, 'backfill_to_hash' => str_repeat('a', 64), 'backfill_completed_at' => now(),
            'cold_scan_to_block' => 1001, 'cold_scan_to_hash' => str_repeat('b', 64), 'cold_scan_checked_at' => now()]);
    }

    private function fakeProvider(CryptoAddressMonitor $monitor, bool $uncertainPost = false, bool $alreadyExists = false, bool $failedDelete = false): void
    {
        Http::preventStrayRequests();
        $exists = $alreadyExists;
        $alert = $this->alertData($monitor);
        Http::fake(['https://api.tatum.io/v4/subscription*' => function (Request $r) use (&$exists, $alert, $uncertainPost, $failedDelete) {
            if ($r->method() === 'PUT') {
                return Http::response('', 204);
            }
            if ($r->method() === 'POST') {
                $exists = true;

                return $uncertainPost ? Http::response([], 503) : Http::response(['id' => 'alert-1']);
            }
            if ($r->method() === 'DELETE') {
                if ($failedDelete) {
                    return Http::response([], 503);
                }
                $exists = false;

                return Http::response('', 204);
            }
            if (str_contains($r->url(), '/alert-1')) {
                return $exists ? Http::response($alert) : Http::response([], 404);
            }

            return Http::response($exists ? [$alert] : []);
        }]);
    }
}
