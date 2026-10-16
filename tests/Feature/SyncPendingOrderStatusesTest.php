<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Jobs\SendOrderNotificationJob;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderNotificationAttempt;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PendingOrderStatusSyncService;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class SyncPendingOrderStatusesTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const QUERY_URL = 'https://shop.example.com/wp-json/payment-plugin/v1/order-query';

    public function test_queried_payment_credits_the_settlement_and_notifies_only_once(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '25.00']);
        $method = $this->makePaymentMethod('stripe');
        $order = $this->pendingOrder('PAID', $method, [
            'settlement_amount' => '96.50',
            'notify_url' => 'https://merchant.example/callback',
        ]);
        Event::fake([OrderStatusChanged::class]);
        Bus::fake([SendOrderNotificationJob::class]);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response([
            'code' => 0,
            'data' => [
                'status' => 'paid',
                'paid_at' => '2026-10-17T03:45:00Z',
                'transaction_id' => 'txn-recovered',
                'wp_order_id' => 123,
            ],
        ])]);

        $this->artisan('orders:sync-pending-status')
            ->expectsOutput('待支付订单状态查询结果：{"orders_checked":1,"orders_changed":1,"orders_failed":0}')
            ->assertSuccessful();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('2026-10-17T03:45:00+00:00', $order->paid_at->utc()->toIso8601String());
        $this->assertSame('txn-recovered', $order->transaction_id);
        $this->assertSame(123, $order->wp_order_id);
        $this->assertSame('121.50', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'order_id' => $order->id,
            'type' => MerchantBalanceTransaction::TYPE_ORDER_PAID,
            'amount' => '96.50',
            'idempotency_key' => 'order_paid:'.$order->id,
        ]);
        $attempt = OrderNotificationAttempt::query()->sole();
        $this->assertSame($order->id, $attempt->order_id);
        $this->assertSame('paid', $attempt->request_payload['status']);
        Bus::assertDispatched(SendOrderNotificationJob::class, fn ($job): bool => $job->attemptId === $attempt->id);
        Event::assertDispatched(OrderStatusChanged::class, fn ($event): bool => $event->order->id === $order->id
            && $event->oldStatus === 'pending' && $event->newStatus === 'paid');

        $this->travel(30)->minutes();
        $this->artisan('orders:sync-pending-status')
            ->expectsOutput('待支付订单状态查询结果：{"orders_checked":0,"orders_changed":0,"orders_failed":0}')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame('121.50', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 1);
        $this->assertDatabaseCount('order_notification_attempts', 1);
        Bus::assertDispatchedTimes(SendOrderNotificationJob::class, 1);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_only_unpaid_pending_orders_created_in_the_last_48_hours_are_queried_across_merchants(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $viewer = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $method = $this->makePaymentMethod('stripe');
        $boundary = $this->pendingOrder('48H', $method, ['created_at' => now()->subHours(48)]);
        $olderThan24Hours = $this->pendingOrder('36H', $method, ['created_at' => now()->subHours(36)]);
        $justCreated = $this->pendingOrder('NOW', $method, ['created_at' => now()]);
        $this->pendingOrder('TOO-OLD', $method, ['created_at' => now()->subHours(48)->subSecond()]);
        $this->pendingOrder('FUTURE', $method, ['created_at' => now()->addSecond()]);
        $this->pendingOrder('ALREADY-PAID', $method, ['status' => 'paid', 'paid_at' => now()]);
        $this->pendingOrder('CANCELLED', $method, ['status' => 'cancelled']);
        $this->pendingOrder('PAID-AT-SET', $method, ['paid_at' => now()]);
        $this->pendingOrder('DELETED', $method)->delete();
        $this->createMerchantAndApplication();
        $otherMethod = $this->makePaymentMethod('other-stripe');
        $otherMerchantOrder = $this->pendingOrder('OTHER-MERCHANT', $otherMethod);
        $this->actingAs($viewer);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response(['code' => 0, 'data' => ['status' => 'pending']])]);

        $result = app(PendingOrderStatusSyncService::class)->sync();

        $this->assertSame(['orders_checked' => 4, 'orders_changed' => 0, 'orders_failed' => 0], $result);
        $this->assertEqualsCanonicalizing(
            [$boundary->order_no, $olderThan24Hours->order_no, $justCreated->order_no, $otherMerchantOrder->order_no],
            Http::recorded()->map(fn (array $pair): string => $pair[0]['s_order_id'])->all(),
        );
        Http::assertSentCount(4);
    }

    public function test_failed_queries_and_missing_credentials_do_not_stop_later_orders_and_return_a_failure_exit(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        $gatewayFailure = $this->pendingOrder('NOT-FOUND', $method);
        $incompleteMethod = $this->makePaymentMethod('missing-credentials');
        $incompleteMethod->update(['domain_client_sk' => null]);
        $missingCredentials = $this->pendingOrder('MISSING-CREDENTIALS', $incompleteMethod);
        $unknown = $this->pendingOrder('UNKNOWN', $method);
        $laterOrder = $this->pendingOrder('LATER', $method);
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => function (Request $request) use ($gatewayFailure, $unknown) {
            return match ($request['s_order_id']) {
                $gatewayFailure->order_no => Http::response(['code' => 10001, 'message' => 'Order not found'], 404),
                $unknown->order_no => Http::response(['code' => 0, 'data' => ['status' => 'unsupported']]),
                default => Http::response(['code' => 0, 'data' => ['status' => 'expired']]),
            };
        }]);

        $this->artisan('orders:sync-pending-status')
            ->expectsOutput('待支付订单状态查询结果：{"orders_checked":4,"orders_changed":1,"orders_failed":3}')
            ->assertFailed();

        $this->assertSame('pending', $gatewayFailure->refresh()->status);
        $this->assertSame('pending', $missingCredentials->refresh()->status);
        $this->assertSame('pending', $unknown->refresh()->status);
        $this->assertSame('expired', $laterOrder->refresh()->status);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Http::assertSentCount(3);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_a_soft_deleted_shared_channel_keeps_its_locked_query_credentials(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('shared-stripe');
        $method->update([
            'merchant_id' => null,
            'domain_client_id' => 'ck_shared',
            'domain_client_sk' => 'cs_shared',
        ]);
        $method->assignedMerchants()->attach($this->merchant);
        $order = $this->pendingOrder('SHARED', $method);
        $method->delete();
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response(['code' => 0, 'data' => ['status' => 'pending']])]);

        $result = app(PendingOrderStatusSyncService::class)->sync();

        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $result);
        Http::assertSent(function (Request $request) use ($order): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'POST'
                && $request['s_order_id'] === $order->order_no
                && ($query['consumer_key'] ?? null) === 'ck_shared'
                && ($query['consumer_secret'] ?? null) === 'cs_shared';
        });
        Http::assertSentCount(1);
    }

    public function test_status_changes_do_not_skip_orders_after_the_first_chunk(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        for ($number = 1; $number <= 201; $number++) {
            $this->pendingOrder('BATCH-'.$number, $method);
        }
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response(['code' => 0, 'data' => ['status' => 'expired']])]);

        $result = app(PendingOrderStatusSyncService::class)->sync();

        $this->assertSame(['orders_checked' => 201, 'orders_changed' => 201, 'orders_failed' => 0], $result);
        $this->assertSame(201, Order::query()->where('status', 'expired')->count());
        Http::assertSentCount(201);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 201);
    }

    public function test_the_query_command_is_scheduled_every_half_hour_without_overlapping(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $event = collect(app(Schedule::class)->events())
            ->sole(fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'orders:sync-pending-status'));

        $this->assertTrue($event->isDue($this->app));
        $this->travel(29)->minutes();
        $this->assertFalse($event->isDue($this->app));
        $this->travel(1)->minute();
        $this->assertTrue($event->isDue($this->app));
        $this->travel(1)->minute();
        $this->assertFalse($event->isDue($this->app));
        $this->travel(29)->minutes();
        $this->assertTrue($event->isDue($this->app));
        $this->assertTrue($event->withoutOverlapping);
    }

    /** @param array<string, mixed> $overrides */
    private function pendingOrder(string $suffix, PaymentMethod $method, array $overrides = []): Order
    {
        $createdAt = $overrides['created_at'] ?? now()->subHour();
        unset($overrides['created_at']);

        $order = $this->makeOrder('SSC-'.$suffix, 'M-'.$suffix, array_merge([
            'status' => 'pending',
            'paid_at' => null,
            'payment_method' => $method->method_code,
            'payment_method_id' => $method->id,
        ], $overrides));
        $order->forceFill(['created_at' => $createdAt])->save();

        return $order;
    }
}
