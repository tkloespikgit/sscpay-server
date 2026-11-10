<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderGatewayStatusEvent;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderPaymentStatusService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        Event::fake([OrderStatusChanged::class]);
        Http::preventStrayRequests();
        Http::fake(['*order-logs*' => Http::response(['code' => 0, 'data' => []])]);
        $this->createMerchantAndApplication();
    }

    public function test_completed_order_accepts_dispute_and_new_paid_restores_completion_without_recredit(): void
    {
        $order = $this->completedOrder();
        $this->push($order, 'disputing', '2026-11-06 10:01:00');
        $this->assertSame('disputing', $order->refresh()->status);
        $this->assertSame('settled', $order->settlement->status);

        $this->fakeQuery(['status' => 'disputing', 'updated_at' => '2026-11-06 10:01:00']);
        $this->push($order, 'paid', '2026-11-06 10:00:00');
        $this->assertSame('disputing', $order->refresh()->status);
        $this->assertDatabaseHas('order_gateway_status_events', ['order_id' => $order->id, 'outcome' => 'stale']);

        $this->push($order, 'paid', '2026-11-06 10:02:00');
        $this->assertSame('completed', $order->refresh()->status);
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertSame(1, MerchantBalanceTransaction::where('type', 'order_paid')->count());
        $this->assertSame(1, MerchantBalanceTransaction::where('type', 'order_settlement')->count());
    }

    #[DataProvider('reversals')]
    public function test_completed_order_accepts_final_reversal_only_once(string $gatewayStatus, string $expected): void
    {
        $order = $this->completedOrder();
        $this->push($order, $gatewayStatus, '2026-11-06 10:01:00');
        $this->push($order, $gatewayStatus, '2026-11-06 10:01:00');
        $this->assertSame($expected, $order->refresh()->status);
        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertSame('settled', $order->settlement->status);
        $this->assertSame(1, MerchantBalanceTransaction::where('type', $expected === 'refunded' ? 'refund' : 'chargeback')->count());
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'status' => 'completed', 'type' => $expected === 'refunded' ? 'refund' : 'chargeback']);
    }

    public function test_equal_timestamp_conflict_is_audited_and_automatically_resolved_by_query(): void
    {
        $order = $this->completedOrder();
        $this->fakeQuery(['status' => 'disputing', 'updated_at' => '2026-11-06 10:00:00']);

        $this->push($order, 'disputing', '2026-11-06 10:00:00');

        $this->assertSame('disputing', $order->refresh()->status);
        $this->assertDatabaseHas('order_gateway_status_events', ['outcome' => 'conflict']);

        $this->assertDatabaseHas('order_gateway_status_events', ['source' => 'query', 'outcome' => 'accepted']);
        Http::assertSentCount(2);
    }

    public function test_fallback_query_cannot_overwrite_a_callback_received_during_the_query(): void
    {
        $order = $this->completedOrder();
        Http::fake(['*order-query*' => function () use ($order) {
            $this->push($order, 'refunded', '2026-11-06 10:01:00');

            return Http::response(['code' => 0, 'data' => ['status' => 'paid', 'updated_at' => '2026-11-06 10:00:00']]);
        }, '*' => Http::response(['code' => 0, 'data' => []])]);
        $this->push($order, 'paid', '2026-11-06 09:59:00');
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('order_gateway_status_events', ['outcome' => 'query_race']);
    }

    public function test_gateway_refund_closes_review_and_deducts_in_the_same_accounting_transaction(): void
    {
        $order = $this->completedOrder();
        $event = app(BalanceService::class)->freezeForDisputeEvent($order, User::factory()->create(), [
            'event_no' => 'CASE-REFUND', 'reason' => 'Review', 'images' => [], 'final_action' => 'refund',
            'deadline_value' => 24, 'deadline_unit' => 'hours', 'deadline_hours' => 24,
        ]);
        $this->push($order, 'refunded', '2026-11-06 10:01:00');
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('closed', $event->refresh()->status);
        $this->assertSame('0.00', $this->merchant->refresh()->frozen_balance);
        $this->assertSame('-5.00', $this->merchant->balance);
    }

    public function test_legacy_completed_refund_is_retryable_without_creating_income_or_settlement(): void
    {
        $order = $this->makeOrder('LEGACY', 'M-LEGACY', ['status' => 'completed', 'settlement_mode' => null]);
        $this->merchant->update(['balance' => '100.00']);
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'merchant_balance_transactions')) {
                $fail = false;
                throw new RuntimeException('simulated accounting failure');
            }
        });
        try {
            $this->push($order, 'refunded', '2026-11-06 10:01:00');
            $this->fail('Expected accounting failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated accounting failure', $exception->getMessage());
        }
        $this->assertSame('100.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'refund', 'status' => 'pending']);
        $this->travel(2)->minutes();
        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->assertSame('0.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertSame(0, MerchantBalanceTransaction::where('type', 'order_paid')->count());
    }

    public function test_newly_confirmed_legacy_payment_recovers_income_without_a_settlement_cycle(): void
    {
        $order = $this->makeOrder('LEGACY-PENDING', 'M-LEGACY-PENDING', [
            'status' => 'pending', 'settlement_mode' => null, 'settlement_amount' => '95.00',
        ]);
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'merchant_balance_transactions')) {
                $fail = false;
                throw new RuntimeException('simulated accounting failure');
            }
        });
        try {
            $this->push($order, 'paid', '2026-11-06 10:00:00');
            $this->fail('Expected accounting failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated accounting failure', $exception->getMessage());
        }
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'paid', 'status' => 'pending']);
        $this->push($order, 'paid', '2026-11-06 10:00:00');
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertSame(1, MerchantBalanceTransaction::where('type', 'order_paid')->count());
    }

    public function test_initial_notification_creation_failure_rolls_back_the_payment_intent_and_can_retry(): void
    {
        $order = $this->makeOrder('NOTIFICATION-RETRY', 'M-NOTIFICATION-RETRY', [
            'status' => 'pending', 'settlement_mode' => 'immediate', 'settlement_days' => 0,
            'settlement_amount' => '95.00', 'notify_url' => 'https://merchant.example/notify',
        ]);
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'order_notification_attempts')) {
                $fail = false;
                throw new RuntimeException('simulated notification persistence failure');
            }
        });
        try {
            $this->push($order, 'paid', '2026-11-06 10:00:00');
            $this->fail('Expected notification failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated notification persistence failure', $exception->getMessage());
        }
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertNull($order->paid_at);
        $this->assertDatabaseCount('order_gateway_status_events', 0);
        $this->assertDatabaseCount('order_accounting_tasks', 0);
        $this->assertDatabaseCount('order_notification_attempts', 0);
        $this->assertSame('0.00', $this->merchant->refresh()->balance);

        $this->push($order, 'paid', '2026-11-06 10:00:00');
        $this->assertSame('completed', $order->refresh()->status);
        $this->assertDatabaseCount('order_notification_attempts', 1);
        $this->assertDatabaseHas('order_notification_attempts', ['request_payload->status' => 'paid']);
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
    }

    public function test_received_audit_only_stores_allowed_payment_fields(): void
    {
        $order = $this->completedOrder();
        app(OrderPaymentStatusService::class)->handle([
            's_order_id' => $order->order_no, 'status' => 'paid', 'updated_at' => '2026-11-06 10:00:00',
            'api_key' => 'must-not-be-stored', 'customer_email' => 'private@example.com',
        ]);
        $payload = OrderGatewayStatusEvent::latest('id')->first()->payload;
        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertArrayNotHasKey('customer_email', $payload);
    }

    public static function reversals(): array
    {
        return ['refund' => ['refunded', 'refunded'], 'chargeback' => ['confused', 'chargeback']];
    }

    private function completedOrder(): Order
    {
        $method = $this->makePaymentMethod('stripe');
        $order = $this->makeOrder('LIFECYCLE', 'M-LIFECYCLE', [
            'status' => 'pending', 'settlement_mode' => 'immediate', 'settlement_days' => 0,
            'settlement_amount' => '95.00', 'reserve_percent' => '0', 'reserve_days' => 0,
            'refund_fee_snapshot' => '0.00', 'chargeback_fee_snapshot' => '0.00',
            'payment_method_id' => $method->id, 'payment_method' => 'stripe',
        ]);
        $this->push($order, 'paid', '2026-11-06 10:00:00');
        $this->assertSame('completed', $order->refresh()->status);

        return $order;
    }

    private function push(Order $order, string $status, string $timestamp): void
    {
        app(OrderPaymentStatusService::class)->handle([
            's_order_id' => $order->order_no, 'status' => $status, 'updated_at' => $timestamp,
            'amount' => '100.00', 'currency' => 'USD',
        ]);
    }

    private function fakeQuery(array $data): void
    {
        Http::fake(['*order-query*' => Http::response(['code' => 0, 'data' => $data]), '*' => Http::response(['code' => 0, 'data' => []])]);
    }
}
