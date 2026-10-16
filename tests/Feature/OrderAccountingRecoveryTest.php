<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Exceptions\BalanceOperationException;
use App\Jobs\SendOrderNotificationJob;
use App\Models\MerchantBalanceTransaction;
use App\Models\MerchantFundFreeze;
use App\Models\Order;
use App\Models\OrderAccountingTask;
use App\Models\OrderSettlement;
use App\Models\OrderShipping;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderAccountingService;
use App\Services\OrderPaymentStatusService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderAccountingRecoveryTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
        Http::preventStrayRequests();
        Event::fake([OrderStatusChanged::class]);
        $this->createMerchantAndApplication();
    }

    public function test_repeated_paid_callback_recovers_committed_status_after_accounting_failure(): void
    {
        $order = $this->pendingOrder();
        $this->failNextLedgerInsert();

        $this->pushAndAssertAccountingFailure($order, 'paid');

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame('0.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'paid', 'status' => 'pending']);

        $this->push($order, 'paid');
        $this->push($order, 'paid');

        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'paid', 'status' => 'completed']);
        $this->assertSame(1, MerchantBalanceTransaction::query()->where('type', MerchantBalanceTransaction::TYPE_ORDER_PAID)->count());
    }

    public function test_scheduled_sweep_recovers_a_failed_credit_without_another_callback(): void
    {
        $order = $this->pendingOrder();
        $this->failNextLedgerInsert();
        $this->pushAndAssertAccountingFailure($order, 'paid');
        $this->travel(2)->minutes();

        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'status' => 'completed']);
    }

    public function test_refund_arriving_before_paid_callback_records_income_then_refund_once(): void
    {
        $order = $this->pendingOrder();

        $this->push($order, 'refunded');
        $this->push($order, 'refunded');

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->pending_settlement_balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'paid', 'status' => 'completed']);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'refund', 'status' => 'completed']);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_chargeback_arriving_before_paid_callback_records_both_sides_once(): void
    {
        $order = $this->pendingOrder(['chargeback_fee_snapshot' => '3.00']);

        $this->push($order, 'confused');
        $this->push($order, 'confused');

        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('-8.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->pending_settlement_balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'chargeback', 'status' => 'completed']);
    }

    public function test_refund_accounting_failure_remains_recoverable_after_terminal_state_commits(): void
    {
        $order = $this->pendingOrder();
        $this->push($order, 'paid');
        $this->failNextLedgerInsert();

        $this->pushAndAssertAccountingFailure($order, 'refunded');

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'refund', 'status' => 'pending']);

        $this->push($order, 'refunded');

        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->pending_settlement_balance);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_replayed_callback_does_not_duplicate_merchant_notifications(): void
    {
        Queue::fake([SendOrderNotificationJob::class]);
        $order = $this->pendingOrder(['notify_url' => 'https://merchant.example.com/notify']);

        $this->push($order, 'paid');
        $this->push($order, 'paid');
        $this->artisan('orders:settle-due')->assertSuccessful();

        Queue::assertPushed(SendOrderNotificationJob::class, 1);
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
    }

    public function test_paid_notification_survives_accounting_failure_and_is_not_repeated_during_recovery(): void
    {
        Queue::fake([SendOrderNotificationJob::class]);
        $order = $this->pendingOrder(['notify_url' => 'https://merchant.example.com/notify']);
        $this->failNextLedgerInsert();

        $this->pushAndAssertAccountingFailure($order, 'paid');

        Queue::assertPushed(SendOrderNotificationJob::class, 1);
        $this->assertDatabaseCount('order_notification_attempts', 1);

        $this->push($order, 'paid');
        $this->artisan('orders:settle-due')->assertSuccessful();

        Queue::assertPushed(SendOrderNotificationJob::class, 1);
        $this->assertDatabaseCount('order_notification_attempts', 1);
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
    }

    public function test_direct_refund_income_and_reversal_roll_back_together_if_reversal_ledger_fails(): void
    {
        $order = $this->pendingOrder();
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'merchant_balance_transactions') && ++$inserts === 2) {
                throw new RuntimeException('simulated accounting failure');
            }
        });

        $this->pushAndAssertAccountingFailure($order, 'refunded');

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('0.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        $this->assertDatabaseCount('order_settlements', 0);

        $this->push($order, 'refunded');

        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_worker_rechecks_reversal_created_after_candidate_selection_before_credit(): void
    {
        $order = $this->pendingOrder(['status' => 'paid', 'paid_at' => now()]);
        $accounting = app(OrderAccountingService::class);
        $accounting->recordForStatus($order, 'paid');
        $candidate = OrderAccountingTask::query()->where('order_id', $order->id)->sole();
        $inserted = false;
        DB::listen(function (QueryExecuted $query) use ($order, $accounting, &$inserted): void {
            if (! $inserted && str_starts_with(strtolower($query->sql), 'select')
                && (str_contains($query->sql, 'from "orders"') || str_contains($query->sql, 'from `orders`'))) {
                $inserted = true;
                $order->update(['status' => 'refunded']);
                $accounting->recordForStatus($order, 'refunded');
            }
        });

        $accounting->process($candidate);

        $this->assertTrue($inserted);
        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->pending_settlement_balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'refund', 'status' => 'completed']);
    }

    public function test_pre_recorded_shipping_starts_cycle_only_after_payment(): void
    {
        $order = $this->pendingOrder(['settlement_mode' => 'shipped']);
        OrderShipping::recordShipment($order->id, [
            'merchant_id' => $order->merchant_id, 'operator_id' => 0,
            'logistics_company' => 'ups', 'tracking_number' => 'prepaid-tracking',
            'shipped_at' => now()->subDays(5),
        ]);
        $this->assertSame('pending', $order->refresh()->status);
        $this->travelTo(Carbon::parse('2026-10-17 13:00:00'));

        $this->push($order, 'paid');

        $this->assertSame('shipped', $order->refresh()->status);
        $settlement = OrderSettlement::query()->where('order_id', $order->id)->sole();
        $this->assertSame('pending', $settlement->status);
        $this->assertSame('2026-10-17 13:00:00', $settlement->started_at->toDateTimeString());
        $this->assertSame('2026-10-19 13:00:00', $settlement->due_at->toDateTimeString());
    }

    public function test_sweep_does_not_reconstruct_accounting_for_legacy_paid_orders(): void
    {
        $order = $this->pendingOrder(['status' => 'paid', 'paid_at' => now(), 'settlement_mode' => null, 'settlement_days' => null]);

        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->push($order, 'paid');

        $this->assertSame('0.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_accounting_tasks', 0);
        $this->assertDatabaseCount('order_settlements', 0);
    }

    /** @return array<string, array{string}> */
    public static function reversalCallbacks(): array
    {
        return ['refund' => ['refunded'], 'chargeback' => ['confused']];
    }

    #[DataProvider('reversalCallbacks')]
    public function test_failed_reversal_blocks_new_withdrawals_until_compensation_completes(string $status): void
    {
        $this->merchant->update(['balance' => '200.00']);
        $order = $this->pendingOrder(['settlement_days' => 0]);
        $this->push($order, 'paid');
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $this->failNextLedgerInsert();
        $this->pushAndAssertAccountingFailure($order, $status);

        try {
            app(BalanceService::class)->requestWithdrawal($this->merchant, '50.00', $operator);
            $this->fail('存在未完成退款或拒付时不应允许申请提现。');
        } catch (BalanceOperationException) {
            $this->assertDatabaseCount('merchant_withdrawals', 0);
            $this->assertSame('295.00', $this->merchant->refresh()->balance);
            $this->assertSame('0.00', $this->merchant->pending_withdrawal_balance);
        }

        $this->push($order, $status);
        app(BalanceService::class)->requestWithdrawal($this->merchant, '50.00', $operator);

        $this->assertSame('195.00', $this->merchant->refresh()->balance);
        $this->assertSame('50.00', $this->merchant->pending_withdrawal_balance);
        $this->assertSame('145.00', $this->merchant->availableBalance());
    }

    public function test_failed_reversal_blocks_approval_of_an_already_pending_withdrawal(): void
    {
        $this->merchant->update(['balance' => '200.00']);
        $order = $this->pendingOrder(['settlement_days' => 0]);
        $this->push($order, 'paid');
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $service = app(BalanceService::class);
        $withdrawal = $service->requestWithdrawal($this->merchant, '50.00', $operator);
        $this->failNextLedgerInsert();
        $this->pushAndAssertAccountingFailure($order, 'refunded');

        try {
            $service->approveWithdrawal($withdrawal, $operator);
            $this->fail('存在未完成逆向记账时不应确认放款。');
        } catch (BalanceOperationException) {
            $this->assertSame('pending', $withdrawal->refresh()->status);
            $this->assertSame('295.00', $this->merchant->refresh()->balance);
            $this->assertSame('50.00', $this->merchant->pending_withdrawal_balance);
        }

        $this->push($order, 'refunded');
        $service->approveWithdrawal($withdrawal, $operator);

        $this->assertSame('approved', $withdrawal->refresh()->status);
        $this->assertSame('145.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->pending_withdrawal_balance);
    }

    public function test_failed_reversal_keeps_mature_reserve_held_until_manual_principal_is_recorded(): void
    {
        $this->merchant->update(['balance' => '200.00']);
        $order = $this->pendingOrder(['settlement_days' => 0]);
        $this->push($order, 'paid');
        $freeze = MerchantFundFreeze::query()->where('order_id', $order->id)->sole();
        $this->failNextLedgerInsert();
        $this->pushAndAssertAccountingFailure($order, 'refunded');
        $task = OrderAccountingTask::query()->where('order_id', $order->id)->where('type', 'refund')->sole();
        $task->update(['status' => OrderAccountingTask::STATUS_MANUAL_REVIEW]);
        $this->travel(8)->days();

        $this->artisan('fund-freezes:release-due')->assertSuccessful();

        $this->assertSame('frozen', $freeze->refresh()->status);
        $this->assertSame('9.50', $this->merchant->refresh()->frozen_balance);
        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->assertSame('manual_review', $task->refresh()->status);

        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        app(BalanceService::class)->refund($order->refresh(), '100.00', $operator);
        app(BalanceService::class)->requestWithdrawal($this->merchant, '50.00', $operator);
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertSame('completed', $task->refresh()->status);
        $this->assertNotNull($task->completed_at);
        $this->assertSame('195.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->frozen_balance);
        $this->assertSame('50.00', $this->merchant->pending_withdrawal_balance);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_manual_chargeback_reconciles_failed_paid_dependency_and_reversal_without_reposting_money(): void
    {
        $this->merchant->update(['balance' => '200.00']);
        $order = $this->pendingOrder();
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'merchant_balance_transactions') && ++$inserts === 2) {
                throw new BalanceOperationException('模拟逆向记账需要人工审核。');
            }
        });

        $this->push($order, 'confused');

        $this->assertSame(2, OrderAccountingTask::query()->where('status', 'manual_review')->count());
        $this->assertSame('200.00', $this->merchant->refresh()->balance);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        app(BalanceService::class)->chargeback($order->refresh(), $operator);
        $this->assertSame('195.00', $this->merchant->refresh()->balance);

        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertSame(2, OrderAccountingTask::query()->where('status', 'completed')->count());
        $this->assertSame('195.00', $this->merchant->refresh()->balance);
        $this->assertSame(1, MerchantBalanceTransaction::query()->where('type', 'order_paid')->count());
        $this->assertSame(1, MerchantBalanceTransaction::query()->where('type', 'chargeback')->count());
    }

    public function test_blocked_reversal_reserve_does_not_starve_other_due_reserves_under_the_scan_limit(): void
    {
        $blocked = $this->pendingOrder(['settlement_days' => 0]);
        $healthy = $this->pendingOrder([
            'order_no' => 'SSC-HEALTHY',
            'merchant_order_no' => 'M-HEALTHY',
            'payment_link_token' => 'tok-healthy',
            'settlement_days' => 0,
        ]);
        $this->push($blocked, 'paid');
        $this->push($healthy, 'paid');
        $this->failNextLedgerInsert();
        $this->pushAndAssertAccountingFailure($blocked, 'refunded');
        $this->travel(8)->days();

        $this->artisan('fund-freezes:release-due', ['--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('merchant_fund_freezes', ['order_id' => $blocked->id, 'status' => 'frozen', 'remaining_amount' => '9.50']);
        $this->assertDatabaseHas('merchant_fund_freezes', ['order_id' => $healthy->id, 'status' => 'released', 'remaining_amount' => '0.00']);
        $this->assertSame('9.50', $this->merchant->refresh()->frozen_balance);
    }

    private function pendingOrder(array $overrides = []): Order
    {
        return $this->makeOrder('SSC-ACCOUNTING', 'M-ACCOUNTING', array_merge([
            'status' => 'pending', 'settlement_amount' => '95.00',
            'settlement_mode' => 'immediate', 'settlement_days' => 2,
            'reserve_percent' => '10', 'reserve_days' => 7,
            'refund_fee_snapshot' => '0.00', 'chargeback_fee_snapshot' => '0.00',
        ], $overrides));
    }

    private function push(Order $order, string $status): void
    {
        app(OrderPaymentStatusService::class)->handle([
            's_order_id' => $order->order_no, 'status' => $status,
            'amount' => '100.00', 'currency' => 'USD',
        ]);
    }

    private function failNextLedgerInsert(): void
    {
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'merchant_balance_transactions')) {
                $fail = false;

                throw new RuntimeException('simulated accounting failure');
            }
        });
    }

    private function pushAndAssertAccountingFailure(Order $order, string $status): void
    {
        try {
            $this->push($order, $status);
            $this->fail('模拟记账失败应向调用方抛出。');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated accounting failure', $exception->getMessage());
        }
    }
}
