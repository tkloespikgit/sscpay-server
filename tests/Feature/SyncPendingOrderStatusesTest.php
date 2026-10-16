<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Jobs\SendOrderNotificationJob;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderNotificationAttempt;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\OrderPaymentStatusService;
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
        $this->assertSame(1, $order->pending_status_query_count);
        $this->assertSame('2026-10-17 12:00:00', $order->pending_status_last_queried_at->toDateTimeString());
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

    public function test_only_due_unpaid_pending_orders_in_the_last_48_hours_are_queried_across_merchants(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $viewer = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $method = $this->makePaymentMethod('stripe');
        $boundary = $this->pendingOrder('48H', $method, ['created_at' => now()->subHours(48)]);
        $olderThan24Hours = $this->pendingOrder('36H', $method, ['created_at' => now()->subHours(36)]);
        $firstQueryBoundary = $this->pendingOrder('30M', $method, ['created_at' => now()->subMinutes(30)]);
        $this->pendingOrder('TOO-YOUNG', $method, ['created_at' => now()->subMinutes(30)->addSecond()]);
        $this->pendingOrder('NOW', $method, ['created_at' => now()]);
        $this->pendingOrder('TOO-OLD', $method, ['created_at' => now()->subHours(48)->subSecond()]);
        $this->pendingOrder('FUTURE', $method, ['created_at' => now()->addSecond()]);
        $this->pendingOrder('ALREADY-PAID', $method, ['status' => 'paid', 'paid_at' => now()]);
        $this->pendingOrder('CANCELLED', $method, ['status' => 'cancelled']);
        $this->pendingOrder('PAID-AT-SET', $method, ['paid_at' => now()]);
        $this->pendingOrder('LIMIT-REACHED', $method, ['pending_status_query_count' => 2]);
        $this->pendingOrder('SECOND-NOT-DUE', $method, [
            'created_at' => now()->subMinutes(90)->addSecond(),
            'pending_status_query_count' => 1,
            'pending_status_last_queried_at' => now()->subMinutes(60),
        ]);
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
            [$boundary->order_no, $olderThan24Hours->order_no, $firstQueryBoundary->order_no, $otherMerchantOrder->order_no],
            Http::recorded()->map(fn (array $pair): string => $pair[0]['s_order_id'])->all(),
        );
        Http::assertSentCount(4);
    }

    public function test_pending_orders_are_queried_at_30_and_90_minutes_after_creation_only(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        $order = $this->pendingOrder('TIMING', $method, ['created_at' => now()]);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response(['code' => 0, 'data' => ['status' => 'pending']])]);
        $sync = app(PendingOrderStatusSyncService::class);

        $this->travel(29)->minutes();
        $this->travel(59)->seconds();
        $this->assertSame(['orders_checked' => 0, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(0, $order->refresh()->pending_status_query_count);
        $this->assertNull($order->pending_status_last_queried_at);
        Http::assertNothingSent();

        $this->travel(1)->second();
        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(1, $order->refresh()->pending_status_query_count);
        $this->assertSame('2026-10-17 12:30:00', $order->pending_status_last_queried_at->toDateTimeString());
        $this->assertSame(['orders_checked' => 0, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());

        $this->travel(59)->minutes();
        $this->travel(59)->seconds();
        $this->assertSame(['orders_checked' => 0, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(1, $order->refresh()->pending_status_query_count);

        $this->travel(1)->second();
        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(2, $order->refresh()->pending_status_query_count);
        $this->assertSame('2026-10-17 13:30:00', $order->pending_status_last_queried_at->toDateTimeString());

        $this->travel(24)->hours();
        $this->assertSame(['orders_checked' => 0, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(2, $order->refresh()->pending_status_query_count);
        Http::assertSentCount(2);
    }

    public function test_a_delayed_first_query_keeps_the_second_due_at_90_minutes_after_creation(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        $order = $this->pendingOrder('DELAYED', $method, ['created_at' => now()->subMinutes(80)]);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response(['code' => 0, 'data' => ['status' => 'pending']])]);
        $sync = app(PendingOrderStatusSyncService::class);

        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(1, $order->refresh()->pending_status_query_count);

        $this->travel(9)->minutes();
        $this->travel(59)->seconds();
        $this->assertSame(['orders_checked' => 0, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());

        $this->travel(1)->second();
        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(2, $order->refresh()->pending_status_query_count);
        $this->assertSame('2026-10-17 12:10:00', $order->pending_status_last_queried_at->toDateTimeString());
        Http::assertSentCount(2);
    }

    public function test_a_backlogged_order_uses_only_one_attempt_per_scan_and_two_in_total(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        $order = $this->pendingOrder('BACKLOG', $method, ['created_at' => now()->subHours(36)]);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response(['code' => 0, 'data' => ['status' => 'pending']])]);
        $sync = app(PendingOrderStatusSyncService::class);

        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(1, $order->refresh()->pending_status_query_count);
        Http::assertSentCount(1);

        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        $this->assertSame(2, $order->refresh()->pending_status_query_count);
        $this->assertSame(['orders_checked' => 0, 'orders_changed' => 0, 'orders_failed' => 0], $sync->sync());
        Http::assertSentCount(2);
    }

    public function test_a_reentrant_scan_does_not_query_an_order_again_from_an_outdated_attempt_count(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        $first = $this->pendingOrder('FIRST', $method);
        $later = $this->pendingOrder('LATER', $method, ['created_at' => now()->subHours(2)]);
        $nestedResult = null;
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => function (Request $request) use ($first, &$nestedResult) {
            if ($request['s_order_id'] === $first->order_no) {
                $nestedResult = app(PendingOrderStatusSyncService::class)->sync();
            }

            return Http::response(['code' => 0, 'data' => ['status' => 'pending']]);
        }]);

        $result = app(PendingOrderStatusSyncService::class)->sync();

        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $result);
        $this->assertSame(['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0], $nestedResult);
        $this->assertSame(1, $first->refresh()->pending_status_query_count);
        $this->assertSame(1, $later->refresh()->pending_status_query_count);
        Http::assertSentCount(2);
    }

    public function test_manual_status_queries_do_not_consume_automatic_query_attempts(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        $order = $this->pendingOrder('MANUAL', $method, ['created_at' => now()->subMinutes(20)]);
        Http::preventStrayRequests();
        Http::fake([self::QUERY_URL.'*' => Http::response(['code' => 0, 'data' => ['status' => 'pending']])]);

        $result = app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertFalse($result['changed']);
        $this->assertSame('pending', $result['mapped_status']);
        $this->assertSame(0, $order->refresh()->pending_status_query_count);
        $this->assertNull($order->pending_status_last_queried_at);

        $this->travel(10)->minutes();
        $this->assertSame(
            ['orders_checked' => 1, 'orders_changed' => 0, 'orders_failed' => 0],
            app(PendingOrderStatusSyncService::class)->sync(),
        );
        $this->assertSame(1, $order->refresh()->pending_status_query_count);
        Http::assertSentCount(2);
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

        foreach ([$gatewayFailure, $missingCredentials, $unknown, $laterOrder] as $order) {
            $this->assertSame(1, $order->refresh()->pending_status_query_count);
        }

        $this->artisan('orders:sync-pending-status')
            ->expectsOutput('待支付订单状态查询结果：{"orders_checked":0,"orders_changed":0,"orders_failed":0}')
            ->assertSuccessful();
        $this->travel(30)->minutes();
        $this->artisan('orders:sync-pending-status')
            ->expectsOutput('待支付订单状态查询结果：{"orders_checked":3,"orders_changed":0,"orders_failed":3}')
            ->assertFailed();

        foreach ([$gatewayFailure, $missingCredentials, $unknown] as $order) {
            $this->assertSame(2, $order->refresh()->pending_status_query_count);
            $this->assertSame('2026-10-17 12:30:00', $order->pending_status_last_queried_at->toDateTimeString());
        }
        $this->assertSame(1, $laterOrder->refresh()->pending_status_query_count);

        $this->travel(30)->minutes();
        $this->artisan('orders:sync-pending-status')
            ->expectsOutput('待支付订单状态查询结果：{"orders_checked":0,"orders_changed":0,"orders_failed":0}')
            ->assertSuccessful();
        Http::assertSentCount(5);
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

    public function test_the_query_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $event = collect(app(Schedule::class)->events())
            ->sole(fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'orders:sync-pending-status'));

        $this->assertTrue($event->isDue($this->app));
        $this->travel(1)->minute();
        $this->assertTrue($event->isDue($this->app));
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
