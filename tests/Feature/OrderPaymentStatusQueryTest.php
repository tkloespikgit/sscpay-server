<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Jobs\NotifyAdConversionJob;
use App\Jobs\SendOrderNotificationJob;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderPaymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderPaymentStatusQueryTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const QUERY_URL = 'https://shop.example.com/wp-json/payment-plugin/v1/order-query';

    #[DataProvider('unpaidStatuses')]
    public function test_queried_paid_overrides_unpaid_status_and_credits_and_notifies_only_once(string $status): void
    {
        $this->freezeTime();
        $order = $this->queryableOrder($status);
        Event::fake([OrderStatusChanged::class]);
        Bus::fake([SendOrderNotificationJob::class, NotifyAdConversionJob::class]);
        $this->fakeQuery('paid');

        $service = app(OrderPaymentStatusService::class);
        $result = $service->queryAndApply($order);

        $this->assertSame([
            'queried_status' => 'paid',
            'mapped_status' => 'paid',
            'old_status' => $status,
            'new_status' => 'paid',
            'changed' => true,
        ], $result);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertSame('2026-10-17T03:45:00+00:00', $order->refresh()->paid_at->utc()->toIso8601String());
        $this->assertSame('txn-queried-paid', $order->transaction_id);
        $this->assertSame(123, $order->wp_order_id);
        $this->assertSame('121.50', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'order_id' => $order->id,
            'type' => MerchantBalanceTransaction::TYPE_ORDER_PAID,
            'amount' => '96.50',
            'idempotency_key' => 'order_paid:'.$order->id,
        ]);
        $this->assertDatabaseHas('order_notification_attempts', [
            'order_id' => $order->id,
            'request_payload->status' => 'paid',
        ]);
        $this->assertDatabaseHas('ad_conversion_attempts', ['order_id' => $order->id, 'platform' => 'meta']);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->order->id === $order->id
            && $event->oldStatus === $status && $event->newStatus === 'paid');

        $repeated = $service->queryAndApply($order);

        $this->assertSame([
            'queried_status' => 'paid',
            'mapped_status' => 'paid',
            'old_status' => 'paid',
            'new_status' => 'paid',
            'changed' => false,
        ], $repeated);
        $this->assertSame('121.50', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 1);
        $this->assertDatabaseCount('order_notification_attempts', 1);
        $this->assertDatabaseCount('ad_conversion_attempts', 1);
        Bus::assertDispatchedTimes(SendOrderNotificationJob::class, 1);
        Bus::assertDispatchedTimes(NotifyAdConversionJob::class, 1);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        Http::assertSentCount(2);
    }

    #[DataProvider('previouslyPaidStatuses')]
    public function test_queried_paid_overrides_previously_paid_status_without_repeating_payment_side_effects(string $status): void
    {
        $this->freezeTime();
        $order = $this->queryableOrder($status, [
            'paid_at' => '2026-10-16 08:00:00',
            'refunded_amount' => '20.00',
        ]);
        app(BalanceService::class)->creditForPaidOrder($order);
        Event::fake([OrderStatusChanged::class]);
        Bus::fake([SendOrderNotificationJob::class, NotifyAdConversionJob::class]);
        $this->fakeQuery('paid');

        $result = app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertSame([
            'queried_status' => 'paid',
            'mapped_status' => 'paid',
            'old_status' => $status,
            'new_status' => 'paid',
            'changed' => true,
        ], $result);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'paid_at' => '2026-10-16 08:00:00',
            'refunded_amount' => '20.00',
        ]);
        $this->assertSame('121.50', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 1);
        $this->assertDatabaseCount('order_notification_attempts', 0);
        $this->assertDatabaseCount('ad_conversion_attempts', 0);
        Bus::assertNotDispatched(SendOrderNotificationJob::class);
        Bus::assertNotDispatched(NotifyAdConversionJob::class);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->oldStatus === $status
            && $event->newStatus === 'paid');
        Http::assertSentCount(1);
    }

    public function test_queried_paid_preserves_the_processing_dispute_and_frozen_funds(): void
    {
        $this->freezeTime();
        $order = $this->queryableOrder('paid', ['paid_at' => now()->subDay()]);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $dispute = app(BalanceService::class)->freezeForDisputeEvent($order, $operator, [
            'event_no' => 'CASE-QUERY-PAID',
            'reason' => 'Review remains open',
            'final_action' => OrderDisputeEvent::FINAL_ACTION_REFUND,
            'deadline_value' => 24,
            'deadline_unit' => OrderDisputeEvent::DEADLINE_UNIT_HOURS,
            'deadline_hours' => 24,
        ]);
        $order->refresh();
        Event::fake([OrderStatusChanged::class]);
        Bus::fake([SendOrderNotificationJob::class, NotifyAdConversionJob::class]);
        $this->fakeQuery('paid');

        $result = app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertSame('paid', $result['new_status']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('order_dispute_events', [
            'id' => $dispute->id,
            'status' => OrderDisputeEvent::STATUS_PROCESSING,
            'frozen_amount' => '100.00',
            'closed_at' => null,
        ]);
        $this->assertSame('100.00', (string) $this->merchant->refresh()->frozen_balance);
        $this->assertSame('25.00', (string) $this->merchant->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Bus::assertNotDispatched(SendOrderNotificationJob::class);
        Bus::assertNotDispatched(NotifyAdConversionJob::class);
        Event::assertDispatched(OrderStatusChanged::class);
        Http::assertSentCount(1);
    }

    public function test_queried_paid_returns_the_current_database_status_for_a_stale_order(): void
    {
        $order = $this->queryableOrder('expired');
        Order::query()->whereKey($order->id)->update(['status' => 'paid']);
        Event::fake([OrderStatusChanged::class]);
        Bus::fake([SendOrderNotificationJob::class, NotifyAdConversionJob::class]);
        $this->fakeQuery('paid');

        $result = app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertSame('paid', $result['new_status']);
        $this->assertFalse($result['changed']);
        $this->assertSame('paid', $order->status);
        $this->assertSame('25.00', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Bus::assertNotDispatched(SendOrderNotificationJob::class);
        Bus::assertNotDispatched(NotifyAdConversionJob::class);
        Http::assertSentCount(1);
    }

    #[DataProvider('protectedQueryStatuses')]
    public function test_queried_non_paid_status_keeps_existing_guards(string $localStatus, string $queriedStatus): void
    {
        $order = $this->queryableOrder($localStatus);
        Event::fake([OrderStatusChanged::class]);
        Bus::fake([SendOrderNotificationJob::class, NotifyAdConversionJob::class]);
        $this->fakeQuery($queriedStatus);

        $result = app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertSame($localStatus, $result['new_status']);
        $this->assertFalse($result['changed']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $localStatus]);
        $this->assertSame('25.00', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        $this->assertDatabaseCount('order_notification_attempts', 0);
        $this->assertDatabaseCount('ad_conversion_attempts', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Bus::assertNotDispatched(SendOrderNotificationJob::class);
        Bus::assertNotDispatched(NotifyAdConversionJob::class);
        Http::assertSentCount(1);
    }

    #[DataProvider('webhookProtectedStatuses')]
    public function test_paid_webhook_keeps_terminal_and_manual_review_guards(string $status): void
    {
        $order = $this->queryableOrder($status);
        Event::fake([OrderStatusChanged::class]);
        Bus::fake([SendOrderNotificationJob::class, NotifyAdConversionJob::class]);
        Http::preventStrayRequests();

        app(OrderPaymentStatusService::class)->handle(['s_order_id' => $order->order_no, 'status' => 'paid']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status]);
        $this->assertSame('25.00', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        $this->assertDatabaseCount('order_notification_attempts', 0);
        $this->assertDatabaseCount('ad_conversion_attempts', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Bus::assertNotDispatched(SendOrderNotificationJob::class);
        Bus::assertNotDispatched(NotifyAdConversionJob::class);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function unpaidStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'failed' => ['failed'],
            'cancelled' => ['cancelled'],
            'expired' => ['expired'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function previouslyPaidStatuses(): array
    {
        return [
            'shipped' => ['shipped'],
            'completed' => ['completed'],
            'partially_refunded' => ['partially_refunded'],
            'disputing' => ['disputing'],
            'dispute_review' => ['dispute_review'],
            'refunded' => ['refunded'],
            'chargeback' => ['chargeback'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function protectedQueryStatuses(): array
    {
        return [
            'expired_to_pending' => ['expired', 'pending'],
            'paid_to_expired' => ['paid', 'expired'],
            'review_to_failed' => ['dispute_review', 'failed'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function webhookProtectedStatuses(): array
    {
        return [
            'failed' => ['failed'],
            'cancelled' => ['cancelled'],
            'expired' => ['expired'],
            'refunded' => ['refunded'],
            'chargeback' => ['chargeback'],
            'completed' => ['completed'],
            'dispute_review' => ['dispute_review'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function queryableOrder(string $status, array $overrides = []): Order
    {
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '25.00']);
        $this->application->update(['ad_platform_credentials' => [
            'meta' => ['pixel_id' => 'test-pixel', 'access_token' => 'test-token'],
        ]]);
        $method = $this->makePaymentMethod('stripe');

        return $this->makeOrder('SSC-QUERY', 'M-QUERY', array_merge([
            'status' => $status,
            'payment_method' => $method->method_code,
            'payment_method_id' => $method->id,
            'settlement_amount' => '96.50',
            'notify_url' => 'https://merchant.example/callback',
            'ad_params' => ['meta' => ['fbc' => 'test-click']],
        ], $overrides));
    }

    private function fakeQuery(string $status): void
    {
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response([
            'code' => 0,
            'data' => [
                'status' => $status,
                'paid_at' => '2026-10-17T03:45:00Z',
                'transaction_id' => 'txn-queried-paid',
                'wp_order_id' => 123,
            ],
        ])]);
    }
}
