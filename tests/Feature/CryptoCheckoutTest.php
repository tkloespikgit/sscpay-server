<?php

namespace Tests\Feature;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoCheckout;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Order;
use App\Models\OrderNotificationAttempt;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Services\CryptoCheckoutService;
use App\Services\CryptoHistoryScanService;
use App\Services\OrderCreationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCryptoCheckout;
use Tests\TestCase;

class CryptoCheckoutTest extends TestCase
{
    use CreatesCryptoCheckout;
    use RefreshDatabase;

    public function test_preparation_requires_pre_subscription_and_post_subscription_history_before_showing_address(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        $this->fakeCheckoutProvider($rpc);
        $order = $monitor->paymentRequest->order;
        $this->get('/payment/'.$order->payment_link_token)->assertOk()->assertSee(__('crypto.checkout.title'))
            ->assertDontSee($monitor->address->address)->assertHeader('Referrer-Policy', 'no-referrer');
        Http::assertNothingSent();
        $service = app(CryptoCheckoutService::class);
        $service->process($order->merchant_id, $order->id);
        $this->assertSame('handoff', $checkout->fresh()->phase);
        $this->getJson($this->endpoint($order))->assertOk()->assertJsonPath('state', 'checking')->assertJsonPath('address', null);
        $service->process($order->merchant_id, $order->id);
        $this->getJson($this->endpoint($order))->assertOk()->assertJsonPath('state', 'ready')
            ->assertJsonPath('due_amount', '0.040800000000000000')->assertJsonPath('address', $monitor->address->address)
            ->assertJsonPath('received_amount', '0.000000000000000000')->assertJsonMissingPath('quote_snapshot');
        $this->assertDatabaseCount('crypto_address_monitors', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertTrue(Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/subscription'))->count() === 1);
    }

    public function test_reopen_restores_cold_address_without_changing_valid_quote_and_refresh_does_not_extend_ttl(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        $monitor->update(['status' => 'cold', 'subscription_id' => 'old-alert', 'cancelled_at' => now()->subMinute()]);
        $checkout->update(['status' => 'ready']);
        $oldQuote = $monitor->paymentRequest->quote_snapshot;
        $order = $monitor->paymentRequest->order;
        $this->fakeCheckoutProvider($rpc);
        $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(202);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $active = CryptoAddressMonitor::query()->where('generation', 2)->sole();
        $expires = $active->expires_at->toIso8601String();
        $this->assertSame($monitor->crypto_address_id, $active->crypto_address_id);
        $this->assertSame($oldQuote, $monitor->paymentRequest->fresh()->quote_snapshot);
        $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(202);
        $this->assertDatabaseCount('crypto_address_monitors', 2);
        $this->assertSame($expires, $active->fresh()->expires_at->toIso8601String());
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'ready');
    }

    public function test_reopen_backfills_payment_before_restoring_subscription_and_credits_only_once(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture(withPayment: true);
        $monitor->update(['status' => 'cold', 'cancelled_at' => now()->subMinute()]);
        $order = $monitor->paymentRequest->order;
        $this->fakeCheckoutProvider($rpc);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'confirming')->assertJsonPath('address', null);
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->artisan('crypto:settle-payments')->assertSuccessful();
        $this->artisan('crypto:settle-payments')->assertSuccessful();
        $this->get('/payment/'.$order->payment_link_token)->assertOk();
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'paid')->assertJsonPath('address', null);
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
        $this->assertDatabaseCount('crypto_address_monitors', 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v4/subscription'));
    }

    public function test_combined_enabled_application_keeps_crypto_backfill_credit_and_v2_notification_independent(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture(withPayment: true);
        $this->application->update(['is_combine_payment_enabled' => true]);
        $order = $monitor->paymentRequest->order;
        $order->update(['notify_url' => 'https://shop.example.com/notify', 'risk_reservation_status' => 'held']);
        $monitor->update(['status' => 'cold', 'cancelled_at' => now()->subMinute()]);
        $fiatBalances = $this->merchant->fresh()->only(['balance', 'frozen_balance', 'pending_settlement_balance']);
        Queue::fake();
        $this->fakeCheckoutProvider($rpc);

        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->artisan('crypto:settle-payments')->assertSuccessful();
        $this->artisan('crypto:settle-payments')->assertSuccessful();

        $this->getJson($this->endpoint($order))->assertOk()->assertJsonPath('state', 'paid');
        $this->assertSame('consumed', $order->fresh()->risk_reservation_status);
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
        $this->assertDatabaseCount('combined_orders', 0);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        $this->assertSame($fiatBalances, $this->merchant->fresh()->only(array_keys($fiatBalances)));
        $notification = OrderNotificationAttempt::sole();
        $this->assertSame('single', $notification->request_payload['order_type']);
        $this->assertSame('paid', $notification->request_payload['status']);
        $this->assertSame([], $notification->request_payload['children']);
        $this->assertNotEmpty($notification->event_id);
        $this->assertNull($notification->combined_order_id);
    }

    public function test_expired_quote_requires_explicit_action_and_uses_new_address_with_frozen_service_fee(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture(requestOverrides: ['expires_at' => CarbonImmutable::parse('2026-11-11 11:59:00')]);
        $order = $monitor->paymentRequest->order;
        $this->fakeCheckoutProvider($rpc);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'expired')->assertJsonPath('can_requote', true);
        $this->assertDatabaseCount('crypto_payment_requests', 1);
        PaymentMethod::query()->findOrFail($checkout->payment_method_id)->update(['fee_percent' => '10']);
        $rpc['price'] = '2000';
        $this->postJson($this->endpoint($order), ['action' => 'requote', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(202);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $next = CryptoPaymentRequest::query()->latest('id')->first();
        $this->assertSame($monitor->crypto_payment_request_id, $next->replaces_request_id);
        $this->assertSame('1', $next->quote_snapshot['fee_percent']);
        $this->assertSame('51000000000000000', $next->due_units);
        $this->assertNotSame($monitor->crypto_address_id, $next->crypto_address_id);
        $this->assertDatabaseCount('crypto_addresses', 2);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'ready')->assertJsonPath('request_id', $next->id);
        $this->postJson($this->endpoint($order), ['action' => 'requote', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(409);
        $this->assertDatabaseCount('crypto_payment_requests', 2);
    }

    public function test_payment_during_subscription_handoff_hides_address_and_blocks_requote(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        $order = $monitor->paymentRequest->order;
        $rpc['after_subscribe'] = function () use (&$rpc, $monitor): void {
            $rpc['head'] = 103;
            $this->addHistoryTransfer($rpc, $monitor, 103, str_repeat('e', 64), $monitor->paymentRequest->due_units);
        };
        $this->fakeCheckoutProvider($rpc);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'confirming')->assertJsonPath('address', null);
        $this->assertDatabaseCount('crypto_payment_requests', 1);
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);
    }

    public function test_failed_history_never_shows_address_and_worker_retries_without_duplicate_requests(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        $rpc['fail_method'] = 'eth_getLogs';
        $this->fakeCheckoutProvider($rpc);
        $this->artisan('crypto:prepare-checkouts')->assertFailed();
        $this->assertSame('preparation_retry', $checkout->fresh()->error_code);
        $this->getJson($this->endpoint($monitor->paymentRequest->order))->assertJsonPath('address', null)->assertDontSee('credential');
        unset($rpc['fail_method']);
        $this->travel(61)->seconds();
        $this->artisan('crypto:prepare-checkouts')->assertSuccessful();
        $this->artisan('crypto:prepare-checkouts')->assertSuccessful();
        $this->assertSame('ready', $checkout->fresh()->status);
        $this->assertDatabaseCount('crypto_payment_requests', 1);
    }

    public function test_public_boundary_rejects_wrong_host_invalid_action_stale_tab_and_disabled_channel(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        Http::preventStrayRequests();
        $order = $monitor->paymentRequest->order;
        $this->getJson(str_replace('localhost', 'wrong.example', $this->endpoint($order)))->assertNotFound();
        $this->postJson($this->endpoint($order), ['action' => 'sign', 'request_id' => -1])->assertUnprocessable();
        $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id + 1])->assertStatus(409);
        PaymentMethod::query()->findOrFail($checkout->payment_method_id)->update(['is_active' => false]);
        $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(409);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'unavailable')->assertJsonPath('address', null);
        Http::assertNothingSent();
    }

    public function test_same_order_lock_suppresses_concurrent_reopen_and_worker(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        Http::preventStrayRequests();
        $lock = Cache::lock('crypto-checkout:'.$checkout->order_id, 600);
        $this->assertTrue($lock->get());
        try {
            app(CryptoCheckoutService::class)->process($checkout->merchant_id, $checkout->order_id);
            $this->postJson($this->endpoint($monitor->paymentRequest->order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(202);
            Http::assertNothingSent();
            $this->assertDatabaseCount('crypto_address_monitors', 1);
        } finally {
            $lock->release();
        }
    }

    private function endpoint(Order $order): string
    {
        return 'http://localhost/payment/'.$order->payment_link_token.'/crypto';
    }

    public function test_new_unified_order_derives_once_and_recovers_after_subscription_failure(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        $group = PaymentGroup::create(['merchant_id' => $this->merchant->id, 'group_key' => 'new-crypto', 'group_name' => 'Crypto', 'is_active' => true]);
        $group->paymentMethods()->attach($checkout->payment_method_id, ['priority' => 100]);
        $original = $monitor->paymentRequest->order;
        $data = $original->only(['currency', 'subtotal', 'shipping_fee', 'discount', 'tax', 'amount',
            'customer_first_name', 'customer_last_name', 'customer_email', 'customer_phone', 'shipping_address_line1', 'shipping_city', 'shipping_country', 'shipping_zip']);
        $data += ['merchant_order_no' => 'new-quote', 'group_key' => $group->group_key,
            'items' => [['product_id' => '1', 'product_url' => 'https://shop.example/product', 'product_name' => 'Test', 'unit_price' => '100', 'quantity' => 1]]];
        Http::preventStrayRequests();
        $order = app(OrderCreationService::class)->createOrder($data, $this->merchant, $this->application, 'manual');
        Http::assertNothingSent();
        $this->fakeCheckoutProvider($rpc);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $new = CryptoCheckout::query()->where('order_id', $order->id)->sole();
        $request = CryptoPaymentRequest::query()->findOrFail($new->current_request_id);
        $this->assertSame('0x'.str_repeat('c', 40), $request->cryptoAddress->address);
        $this->assertSame('handoff', $new->phase);
        $active = CryptoAddressMonitor::query()->findOrFail($request->current_monitor_id);
        $rpc['alerts'][$active->subscription_id]['paused'] = true;
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'checking')->assertJsonPath('address', null);
        $rpc['alerts'][$active->subscription_id]['paused'] = false;
        $this->travel(31)->seconds();
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'ready');
        $this->assertSame(1, CryptoPaymentRequest::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseCount('crypto_address_allocations', 1);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/subscription'))->count());
        $this->travel(5)->seconds();
        $rpc['head'] = $rpc['finalized'] = 105;
        $this->addHistoryTransfer($rpc, $active, 103, str_repeat('f', 64), $request->due_units);
        $request->update(['history_next_attempt_at' => now()]);
        for ($i = 0; $i < 3; $i++) {
            app(CryptoHistoryScanService::class)->scan($request->network, $request->merchant_id, $request->id);
        }
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->artisan('crypto:settle-payments')->assertSuccessful();
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('40392000000000000', CryptoLedgerEntry::query()->where('order_id', $order->id)->sole()->amount_units);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    public function test_old_address_payment_during_requote_prevents_publication_of_new_address(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture(requestOverrides: ['expires_at' => CarbonImmutable::parse('2026-11-11 11:59:00')]);
        $order = $monitor->paymentRequest->order;
        $this->fakeCheckoutProvider($rpc);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->postJson($this->endpoint($order), ['action' => 'requote', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(202);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $rpc['after_subscribe'] = function (CryptoAddressMonitor $newMonitor) use (&$rpc, $monitor): void {
            $rpc['head'] = $rpc['finalized'] = 105;
            $this->addHistoryTransfer($rpc, $monitor, 103, str_repeat('e', 64), $monitor->paymentRequest->due_units);
            $this->addHistoryTransfer($rpc, $newMonitor, 103, str_repeat('f', 64), $newMonitor->paymentRequest->due_units);
        };
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'confirming')->assertJsonPath('address', null);
        $this->assertSame($monitor->crypto_payment_request_id, CryptoWebhookReceipt::query()->where('transaction_hash', str_repeat('e', 64))->sole()->crypto_payment_request_id);
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->artisan('crypto:settle-payments')->assertSuccessful();
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'review')->assertJsonPath('address', null);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertDatabaseCount('crypto_payment_requests', 2);
        $this->assertSame('multiple_requests_received', CryptoPaymentRequest::query()->latest('id')->first()->review_reason);
    }

    public function test_public_reopen_requires_csrf_and_read_polling_has_separate_rate_budget(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        Http::preventStrayRequests();
        $order = $monitor->paymentRequest->order;
        for ($i = 0; $i < 15; $i++) {
            $this->getJson($this->endpoint($order))->assertOk();
        }
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(202);
        }
        $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(429);
        $this->getJson($this->endpoint($order))->assertOk();
        $this->app->instance('env', 'production');
        $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_remote_subscription_disappearing_during_handoff_is_rebuilt_after_another_backfill(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        $order = $monitor->paymentRequest->order;
        $this->fakeCheckoutProvider($rpc);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $rpc['alerts'] = [];
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->assertSame('cold', $monitor->fresh()->status);
        $this->getJson($this->endpoint($order))->assertJsonPath('address', null);
        $this->travel(31)->seconds();
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        app(CryptoCheckoutService::class)->process($order->merchant_id, $order->id);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'ready');
        $this->assertDatabaseCount('crypto_address_monitors', 2);
        $this->assertDatabaseCount('crypto_payment_requests', 1);
    }

    public function test_worker_cannot_process_another_merchants_order_and_paid_or_cancelled_links_never_reopen(): void
    {
        [$checkout, $monitor, $rpc] = $this->checkoutFixture();
        Http::preventStrayRequests();
        $order = $monitor->paymentRequest->order;
        try {
            app(CryptoCheckoutService::class)->process($order->merchant_id + 1, $order->id);
            $this->fail('Foreign merchant reached checkout preparation.');
        } catch (DomainException) {
            $this->assertNull($checkout->fresh()->error_code);
        }
        $order->update(['status' => 'cancelled']);
        $this->getJson($this->endpoint($order))->assertJsonPath('state', 'unavailable');
        $this->postJson($this->endpoint($order), ['action' => 'open', 'request_id' => $monitor->crypto_payment_request_id])->assertStatus(409);
        $this->assertDatabaseCount('crypto_payment_requests', 1);
        Http::assertNothingSent();
    }
}
