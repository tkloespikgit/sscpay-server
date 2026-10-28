<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Events\PaymentLinkCreationFailed;
use App\Jobs\SyncOrderTrackingJob;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderShipping;
use App\Models\User;
use App\Services\OrderEventSyncService;
use App\Services\OrderPaymentStatusService;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class GatewayOrderReferenceTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const GATEWAY_URL = 'https://shop.example.com/wp-json/payment-plugin/v1';

    private const GATEWAY_REFERENCE = 'GWCurrentAttempt';

    public function test_old_webhook_reference_does_not_change_state_or_disable_the_replacement_channel(): void
    {
        $order = $this->makeRoutedOrder();
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();

        $this->sendWebhook([
            's_order_id' => $order->order_no,
            'status' => 'paid',
            'account_forbidden' => true,
        ]);

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertNull($order->paid_at);
        $this->assertTrue($order->paymentMethod->is_active);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_current_webhook_reference_applies_payment_and_account_restrictions_to_the_current_channel(): void
    {
        $order = $this->makeRoutedOrder();
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();

        $this->sendWebhook([
            's_order_id' => self::GATEWAY_REFERENCE,
            'status' => 'paid',
            'account_forbidden' => true,
            'transaction_id' => 'txn-current',
        ]);

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame('txn-current', $order->transaction_id);
        $this->assertFalse($order->paymentMethod->is_active);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'order_id' => $order->id,
            'type' => 'order_paid',
            'amount' => '97.00',
        ]);
        Event::assertDispatched(OrderStatusChanged::class, fn ($event): bool => $event->order->id === $order->id
            && $event->newStatus === 'paid');
    }

    public function test_query_result_without_a_reference_is_ignored_when_the_channel_changes_during_the_request(): void
    {
        $order = $this->makeRoutedOrder();
        $replacement = $this->makePaymentMethod('replacement');
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY_URL.'/order-query*' => function () use ($order, $replacement) {
            DB::table('orders')->where('id', $order->id)->update([
                'gateway_order_no' => 'GWNextAttempt',
                'payment_method_id' => $replacement->id,
                'payment_method' => $replacement->method_code,
            ]);

            return Http::response(['code' => 0, 'data' => ['status' => 'paid', 'transaction_id' => 'txn-stale']]);
        }]);

        $result = app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertFalse($result['changed']);
        $this->assertSame('pending', $result['new_status']);
        $this->assertSame('GWNextAttempt', $order->refresh()->gateway_order_no);
        $this->assertSame($replacement->id, $order->payment_method_id);
        $this->assertNull($order->transaction_id);
        $this->assertNull($order->paid_at);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Http::assertSent(fn (Request $request): bool => $request['s_order_id'] === self::GATEWAY_REFERENCE);
        Http::assertSentCount(1);
    }

    public function test_webhook_rechecks_the_reference_under_the_order_lock_before_any_channel_or_balance_change(): void
    {
        $order = $this->makeRoutedOrder();
        $originalMethod = $order->paymentMethod;
        $replacement = $this->makePaymentMethod('replacement');
        $switched = false;
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();
        Event::listen('eloquent.retrieved: '.Order::class, function (Order $retrieved) use ($order, $replacement, &$switched): void {
            if ($switched || $retrieved->id !== $order->id) {
                return;
            }

            $switched = true;
            DB::table('orders')->where('id', $order->id)->update([
                'gateway_order_no' => 'GWNextAttempt',
                'payment_method_id' => $replacement->id,
                'payment_method' => $replacement->method_code,
            ]);
        });

        $this->sendWebhook([
            's_order_id' => self::GATEWAY_REFERENCE,
            'status' => 'paid',
            'account_forbidden' => true,
        ]);

        $this->assertTrue($switched);
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame($replacement->id, $order->payment_method_id);
        $this->assertTrue($replacement->refresh()->is_active);
        $this->assertTrue($originalMethod->refresh()->is_active);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_query_response_for_another_reference_cannot_credit_the_order(): void
    {
        $order = $this->makeRoutedOrder();
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY_URL.'/order-query*' => Http::response([
            'code' => 0,
            'data' => ['s_order_id' => 'GWAnotherOrder', 'status' => 'paid'],
        ])]);

        $result = app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertFalse($result['changed']);
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Http::assertSentCount(1);
    }

    public function test_log_queries_use_the_gateway_reference_and_archive_under_the_local_order_number(): void
    {
        $order = $this->makeRoutedOrder();
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY_URL.'/order-logs*' => Http::response([
            'code' => 0,
            'data' => ['logs' => [[
                'id' => 42,
                'created_at' => '2026-10-28 03:00:00',
                'message' => 'Current gateway payment created',
            ]]],
        ])]);

        $result = app(OrderEventSyncService::class)->syncOrderNow($order);

        $this->assertSame(1, $result['logs_written']);
        $this->assertDatabaseHas('order_events', [
            'order_no' => $order->order_no,
            'external_log_id' => 42,
            'message' => 'Current gateway payment created',
        ]);
        Http::assertSent(fn (Request $request): bool => $request['s_order_id'] === self::GATEWAY_REFERENCE);
        Http::assertSentCount(1);
    }

    public function test_tracking_sync_uses_the_current_gateway_reference(): void
    {
        $order = $this->makeRoutedOrder();
        $order->paymentMethod->update(['sync_logistics' => true]);
        $shipping = OrderShipping::recordShipment($order->id, [
            'merchant_id' => $order->merchant_id,
            'logistics_company' => 'Test Carrier',
            'tracking_number' => 'TRACK123',
            'shipped_at' => '2026-10-28 12:00:00',
            'operator_id' => User::factory()->create(['merchant_id' => $order->merchant_id])->id,
        ]);
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY_URL.'/sync-tracking*' => Http::response([
            'code' => 0,
            'data' => ['synced_to_remote' => true, 'remote_sync_message' => 'Synced'],
        ])]);

        (new SyncOrderTrackingJob($shipping->id))->handle(app(PaymentGatewayService::class));

        $this->assertSame(OrderShipping::SYNC_STATUS_SYNCED, $shipping->refresh()->sync_status);
        Http::assertSent(fn (Request $request): bool => $request['s_order_id'] === self::GATEWAY_REFERENCE
            && $request['tracking_number'] === 'TRACK123');
        Http::assertSentCount(1);
    }

    public function test_logs_with_the_same_external_id_are_preserved_for_each_gateway_attempt(): void
    {
        $order = $this->makeRoutedOrder();
        $order->update(['gateway_order_no' => null]);
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY_URL.'/order-logs*' => fn (Request $request) => Http::response([
            'code' => 0,
            'data' => ['logs' => [[
                'id' => 42,
                'created_at' => '2026-10-28 03:00:00',
                'message' => $request['s_order_id'] === self::GATEWAY_REFERENCE ? 'Replacement gateway log' : 'Original gateway log',
            ]]],
        ])]);
        $service = app(OrderEventSyncService::class);

        $service->syncOrderNow($order);
        $order->update(['gateway_order_no' => self::GATEWAY_REFERENCE]);
        $service->syncOrderNow($order);
        $service->syncOrderNow($order);

        $this->assertDatabaseCount('order_events', 2);
        $this->assertDatabaseHas('order_events', [
            'order_no' => $order->order_no,
            'gateway_order_no' => '',
            'external_log_id' => 42,
            'message' => 'Original gateway log',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_no' => $order->order_no,
            'gateway_order_no' => self::GATEWAY_REFERENCE,
            'external_log_id' => 42,
            'message' => 'Replacement gateway log',
        ]);
        Http::assertSentCount(3);
    }

    public function test_log_response_is_ignored_if_the_channel_changes_while_the_request_is_in_flight(): void
    {
        $order = $this->makeRoutedOrder();
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY_URL.'/order-logs*' => function () use ($order) {
            DB::table('orders')->where('id', $order->id)->update(['gateway_order_no' => 'GWNextAttempt']);

            return Http::response(['code' => 0, 'data' => ['logs' => [[
                'id' => 42,
                'created_at' => '2026-10-28 03:00:00',
                'message' => 'Stale gateway log',
            ]]]]);
        }]);

        $result = app(OrderEventSyncService::class)->syncOrderNow($order);

        $this->assertSame(0, $result['logs_written']);
        $this->assertDatabaseCount('order_events', 0);
        Http::assertSent(fn (Request $request): bool => $request['s_order_id'] === self::GATEWAY_REFERENCE);
        Http::assertSentCount(1);
    }

    public function test_log_migration_refuses_to_remove_recorded_gateway_attempts(): void
    {
        $order = $this->makeRoutedOrder();
        $event = OrderEvent::create([
            'merchant_id' => $order->merchant_id,
            'order_no' => $order->order_no,
            'gateway_order_no' => self::GATEWAY_REFERENCE,
            'external_log_id' => 42,
            'level' => 'INFO',
            'message' => 'Retained gateway log',
            'occurred_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_28_142309_add_gateway_order_no_to_order_events_table.php');

        try {
            $migration->down();
            $this->fail('Recorded gateway attempts must prevent a destructive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('禁止回滚', $exception->getMessage());
        }

        $this->assertSame(self::GATEWAY_REFERENCE, $event->refresh()->gateway_order_no);
        $this->assertSame('Retained gateway log', $event->message);
    }

    public function test_log_migration_round_trip_preserves_legacy_logs_before_any_channel_switch(): void
    {
        $order = $this->makeRoutedOrder();
        $event = OrderEvent::create([
            'merchant_id' => $order->merchant_id,
            'order_no' => $order->order_no,
            'external_log_id' => 42,
            'level' => 'INFO',
            'message' => 'Legacy gateway log',
            'occurred_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_28_142309_add_gateway_order_no_to_order_events_table.php');

        $migration->down();
        $migration->up();

        $this->assertSame('', $event->refresh()->gateway_order_no);
        $this->assertSame('Legacy gateway log', $event->message);
        $this->assertDatabaseCount('order_events', 1);
    }

    public function test_order_reference_migration_refuses_to_remove_an_active_gateway_reference(): void
    {
        $order = $this->makeRoutedOrder();
        $migration = require database_path('migrations/2026_10_28_142012_add_gateway_order_no_to_orders_table.php');

        try {
            $migration->down();
            $this->fail('An active gateway reference must prevent a destructive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('不能回滚', $exception->getMessage());
        }

        $this->assertSame(self::GATEWAY_REFERENCE, $order->refresh()->gatewayOrderReference());
    }

    public function test_order_reference_migration_can_be_reversed_before_any_orders_exist(): void
    {
        $migration = require database_path('migrations/2026_10_28_142012_add_gateway_order_no_to_orders_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('orders', 'gateway_order_no'));
        $migration->up();

        $this->assertTrue(Schema::hasColumn('orders', 'gateway_order_no'));
    }

    public function test_payment_failure_notification_accepts_the_current_gateway_reference_and_keeps_the_local_order_number(): void
    {
        $order = $this->makeRoutedOrder();
        Event::fake([PaymentLinkCreationFailed::class]);
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY_URL.'/pay*' => Http::response([
            'code' => 10011,
            'message' => 'Gateway unavailable',
        ], 422)]);

        try {
            app(PaymentGatewayService::class)
                ->withConnection(self::GATEWAY_URL, 'ck_test', 'cs_test')
                ->createPayment(['s_order_id' => self::GATEWAY_REFERENCE], $order);
            $this->fail('The gateway failure must be propagated.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(10011, $exception->pgaCode);
        }

        Event::assertDispatched(PaymentLinkCreationFailed::class, fn ($event): bool => $event->merchantId === $order->merchant_id
            && $event->details['order_no'] === $order->order_no
            && $event->details['payment_method_id'] === (string) $order->payment_method_id);
        Http::assertSentCount(1);
    }

    public function test_webhook_simulator_resolves_the_current_gateway_reference_from_the_local_order_number(): void
    {
        $order = $this->makeRoutedOrder();
        Http::preventStrayRequests();
        Http::fake(['https://callback.example.com/api/webhooks/payment-gateway/status' => Http::response(['code' => 0])]);

        $this->artisan('webhook:simulate-stripe', [
            'order_no' => $order->order_no,
            '--url' => 'https://callback.example.com',
        ])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['s_order_id'] === self::GATEWAY_REFERENCE);
        Http::assertSentCount(1);
    }

    private function makeRoutedOrder(): Order
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('current');

        return $this->makeOrder('LOCAL-ORDER', 'MERCHANT-ORDER', [
            'gateway_order_no' => self::GATEWAY_REFERENCE,
            'payment_method_id' => $method->id,
            'payment_method' => $method->method_code,
            'settlement_amount' => '97.00',
            'settlement_mode' => 'immediate',
            'settlement_days' => 0,
            'status' => 'pending',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function sendWebhook(array $payload): void
    {
        $secret = 'test-gateway-reference-secret';
        config()->set('payment_gateway.webhook_secret', $secret);
        $raw = json_encode(['event' => 'payment_status', ...$payload], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/webhooks/payment-gateway/status', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PGA_SIGNATURE' => hash_hmac('sha256', $raw, $secret),
        ], $raw)->assertOk()->assertExactJson(['code' => 0, 'message' => 'ok']);
    }
}
