<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Exceptions\BalanceOperationException;
use App\Models\MerchantBalanceTransaction;
use App\Models\MerchantFundFreeze;
use App\Models\MerchantWithdrawal;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\OrderShipping;
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderSettlementBalanceTest extends TestCase
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

    public function test_paid_order_credits_only_net_amount_and_waits_until_due_without_duplicate_credit(): void
    {
        $order = $this->paidOrder();
        $service = app(BalanceService::class);

        $this->assertNotNull($service->creditForPaidOrder($order));
        $this->assertNull($service->creditForPaidOrder($order));

        $this->assertBalances('95.00', '95.00', '0.00', '0.00', '0.00');
        $settlement = $this->settlement($order);
        $this->assertSame('pending', $settlement->status);
        $this->assertSame('2026-10-18 12:00:00', $settlement->due_at->toDateTimeString());
        $this->assertSame(1, MerchantBalanceTransaction::query()->where('type', MerchantBalanceTransaction::TYPE_ORDER_PAID)->count());
        $this->assertFalse($service->settleOrder($settlement));
    }

    public function test_due_settlement_splits_reserve_once_and_automatically_releases_it_on_expiry(): void
    {
        $order = $this->paidOrder();
        app(BalanceService::class)->creditForPaidOrder($order);
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));

        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertBalances('95.00', '0.00', '9.50', '0.00', '85.50');
        $settlement = $this->settlement($order);
        $this->assertSame('settled', $settlement->status);
        $this->assertSame('9.50', $settlement->reserve_amount);
        $this->assertSame('85.50', $settlement->available_amount);
        $this->assertSame('2026-10-18 12:00:00', $settlement->settled_at->toDateTimeString());
        $freeze = MerchantFundFreeze::query()->where('order_id', $order->id)->sole();
        $this->assertSame('2026-10-25 12:00:00', $freeze->release_at->toDateTimeString());

        $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
        $this->artisan('fund-freezes:release-due')->assertSuccessful();
        $this->artisan('fund-freezes:release-due')->assertSuccessful();

        $this->assertBalances('95.00', '0.00', '0.00', '0.00', '95.00');
        $this->assertSame('released', $freeze->refresh()->status);
        $this->assertSame('2026-10-25 12:00:00', $freeze->released_at->toDateTimeString());
    }

    public function test_zero_day_settlement_makes_net_proceeds_available_immediately(): void
    {
        $order = $this->paidOrder(['settlement_days' => 0, 'reserve_percent' => '0']);

        app(BalanceService::class)->creditForPaidOrder($order);

        $this->assertBalances('95.00', '0.00', '0.00', '0.00', '95.00');
        $this->assertSame('settled', $this->settlement($order)->status);
    }

    public function test_shipping_starts_the_cycle_at_recording_time_and_tracking_edits_do_not_restart_it(): void
    {
        $order = $this->paidOrder(['settlement_mode' => 'shipped']);
        app(BalanceService::class)->creditForPaidOrder($order);
        $this->assertSame('waiting_shipment', $this->settlement($order)->status);
        $this->assertNull($this->settlement($order)->due_at);
        $this->travelTo(Carbon::parse('2026-10-17 13:00:00'));

        $this->ship($order, 'first-tracking');

        $this->assertSame('shipped', $order->refresh()->status);
        $this->assertSame('2026-10-17 13:00:00', $this->settlement($order)->started_at->toDateTimeString());
        $this->assertSame('2026-10-19 13:00:00', $this->settlement($order)->due_at->toDateTimeString());
        $this->travelTo(Carbon::parse('2026-10-18 14:00:00'));

        $this->ship($order, 'corrected-tracking');

        $this->assertSame('2026-10-17 13:00:00', $this->settlement($order)->started_at->toDateTimeString());
        $this->assertSame('2026-10-19 13:00:00', $this->settlement($order)->due_at->toDateTimeString());
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_shipping_zero_day_cycle_settles_after_shipment(): void
    {
        $order = $this->paidOrder(['settlement_mode' => 'shipped', 'settlement_days' => 0]);
        app(BalanceService::class)->creditForPaidOrder($order);

        $this->ship($order);

        $this->assertSame('settled', $this->settlement($order)->status);
        $this->assertBalances('95.00', '0.00', '9.50', '0.00', '85.50');
    }

    public function test_terms_are_snapshotted_and_merchant_configuration_changes_do_not_reprice_an_order(): void
    {
        $order = $this->paidOrder(['reserve_percent' => '12.3456']);
        $this->merchant->update(['settlement_mode' => 'shipped', 'settlement_days' => 30]);

        app(BalanceService::class)->creditForPaidOrder($order);
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertBalances('95.00', '0.00', '11.72', '0.00', '83.28');
        $this->assertSame('immediate', $this->settlement($order)->mode);
        $this->assertSame(2, $this->settlement($order)->cycle_days);
    }

    public function test_manual_freeze_cannot_consume_pending_settlement_or_pending_withdrawal(): void
    {
        $this->merchant->update(['balance' => '200.00', 'pending_settlement_balance' => '95.00', 'pending_withdrawal_balance' => '50.00']);
        $operator = $this->operator();

        try {
            app(BalanceService::class)->freezeFunds($this->merchant, '55.01', $operator, '固定保证金');
            $this->fail('冻结不得超过可提现余额。');
        } catch (BalanceOperationException) {
            $this->assertDatabaseCount('merchant_fund_freezes', 0);
            $this->assertBalances('200.00', '95.00', '0.00', '50.00', '55.00');
        }

        app(BalanceService::class)->freezeFunds($this->merchant, '55.00', $operator, '固定保证金');

        $this->assertBalances('200.00', '95.00', '55.00', '50.00', '0.00');
    }

    public function test_withdrawal_rejection_returns_pending_payout_and_approval_deducts_total_only_once(): void
    {
        $this->merchant->update(['balance' => '200.00', 'frozen_balance' => '30.00']);
        $operator = $this->operator();
        $service = app(BalanceService::class);
        $rejected = $service->requestWithdrawal($this->merchant, '50.00', $operator);
        $this->assertBalances('200.00', '0.00', '30.00', '50.00', '120.00');

        $service->rejectWithdrawal($rejected, $operator);

        $this->assertBalances('200.00', '0.00', '30.00', '0.00', '170.00');
        $this->assertSame(MerchantWithdrawal::STATUS_REJECTED, $rejected->refresh()->status);
        $approved = $service->requestWithdrawal($this->merchant, '70.00', $operator);
        $service->approveWithdrawal($approved, $operator);

        $this->assertBalances('130.00', '0.00', '30.00', '0.00', '100.00');
        $this->assertSame(MerchantWithdrawal::STATUS_APPROVED, $approved->refresh()->status);
        $this->expectException(BalanceOperationException::class);
        $service->approveWithdrawal($approved, $operator);
    }

    public function test_partial_refund_reduces_only_this_orders_pending_principal_before_settlement(): void
    {
        $order = $this->paidOrder();
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($order);

        $service->refund($order, '40.00', $this->operator());

        $this->assertBalances('55.00', '55.00', '0.00', '0.00', '0.00');
        $this->assertSame('55.00', $this->settlement($order)->remaining_amount);
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));
        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->assertBalances('55.00', '0.00', '5.50', '0.00', '49.50');
    }

    public function test_full_refund_cancels_unsettled_funds_and_the_due_sweep_cannot_revive_them(): void
    {
        $order = $this->paidOrder();
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($order);

        $service->refund($order, '100.00', $this->operator());
        $this->travel(10)->days();
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertBalances('-5.00', '0.00', '0.00', '0.00', '-5.00');
        $this->assertSame('cancelled', $this->settlement($order)->status);
        $this->assertDatabaseCount('merchant_fund_freezes', 0);
    }

    public function test_chargeback_cancels_unsettled_funds_and_cannot_generate_reserve_later(): void
    {
        $order = $this->paidOrder(['chargeback_fee_snapshot' => '3.00']);
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($order);

        $service->chargeback($order, $this->operator());
        $this->travel(10)->days();
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertBalances('-8.00', '0.00', '0.00', '0.00', '-8.00');
        $this->assertSame('cancelled', $this->settlement($order)->status);
    }

    public function test_legacy_order_remains_immediately_available_without_creating_a_settlement(): void
    {
        $order = $this->paidOrder(['settlement_mode' => null, 'settlement_days' => null]);
        $this->merchant->update(['settlement_mode' => 'shipped', 'settlement_days' => 30]);

        app(BalanceService::class)->creditForPaidOrder($order);

        $this->assertBalances('95.00', '0.00', '0.00', '0.00', '95.00');
        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_zero_reserve_days_releases_the_full_amount_at_settlement(): void
    {
        $order = $this->paidOrder(['settlement_days' => 0, 'reserve_days' => 0]);

        app(BalanceService::class)->creditForPaidOrder($order);

        $this->assertBalances('95.00', '0.00', '0.00', '0.00', '95.00');
        $this->assertDatabaseCount('merchant_fund_freezes', 0);
    }

    public function test_refund_consumes_only_its_orders_reserve_and_expiry_releases_only_the_remainder(): void
    {
        $order = $this->paidOrder(['settlement_days' => 0]);
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($order);
        $freeze = MerchantFundFreeze::query()->where('order_id', $order->id)->sole();

        $service->refund($order, '4.00', $this->operator());

        $this->assertBalances('91.00', '0.00', '5.50', '0.00', '85.50');
        $this->assertSame('5.50', $freeze->refresh()->remainingAmount());
        $this->travel(8)->days();
        $this->artisan('fund-freezes:release-due')->assertSuccessful();

        $this->assertBalances('91.00', '0.00', '0.00', '0.00', '91.00');
        $this->assertSame('released', $freeze->refresh()->status);
    }

    public function test_fully_consumed_reserve_does_not_release_twice_at_expiry(): void
    {
        $order = $this->paidOrder(['settlement_days' => 0]);
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($order);

        $service->refund($order, '20.00', $this->operator());

        $this->assertBalances('75.00', '0.00', '0.00', '0.00', '75.00');
        $freeze = MerchantFundFreeze::query()->where('order_id', $order->id)->sole();
        $this->assertSame('consumed', $freeze->status);
        $this->assertSame('0.00', $freeze->remainingAmount());
        $this->travel(8)->days();
        $this->artisan('fund-freezes:release-due')->assertSuccessful();
        $this->assertBalances('75.00', '0.00', '0.00', '0.00', '75.00');
    }

    public function test_full_refund_does_not_consume_another_orders_pending_funds(): void
    {
        $order = $this->paidOrder();
        $other = $this->paidOrder(['order_no' => 'SSC-OTHER', 'merchant_order_no' => 'M-OTHER', 'payment_link_token' => 'tok-other']);
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($order);
        $service->creditForPaidOrder($other);

        $service->refund($order, '100.00', $this->operator());

        $this->assertBalances('90.00', '95.00', '0.00', '0.00', '-5.00');
        $this->assertSame('95.00', $this->settlement($other)->remaining_amount);
        $this->assertSame('cancelled', $this->settlement($order)->status);
    }

    public function test_dispute_pauses_settlement_without_double_freezing_pending_funds_or_resetting_the_due_date(): void
    {
        $order = $this->paidOrder();
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($order);
        $operator = $this->operator();
        $event = $service->freezeForDisputeEvent($order, $operator, [
            'event_no' => 'case-1', 'reason' => '订单争议', 'images' => [], 'final_action' => 'refund',
            'deadline_value' => 3, 'deadline_unit' => 'days', 'deadline_hours' => 72,
        ]);

        $this->assertSame('5.00', $event->frozen_amount);
        $this->assertBalances('95.00', '95.00', '5.00', '0.00', '-5.00');
        $this->travel(3)->days();
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertSame('pending', $this->settlement($order)->status);
        $this->assertSame('2026-10-18 12:00:00', $this->settlement($order)->due_at->toDateTimeString());

        $service->releaseForDisputeEvent($event, $operator, 'manual');
        $this->artisan('orders:settle-due')->assertSuccessful();

        $this->assertBalances('95.00', '0.00', '9.50', '0.00', '85.50');
        $this->assertSame('2026-10-18 12:00:00', $this->settlement($order)->due_at->toDateTimeString());
    }

    public function test_paused_order_does_not_starve_a_later_due_order_when_batch_limit_is_one(): void
    {
        $paused = $this->paidOrder();
        $normal = $this->paidOrder(['order_no' => 'SSC-NORMAL', 'merchant_order_no' => 'M-NORMAL', 'payment_link_token' => 'tok-normal']);
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($paused);
        $service->creditForPaidOrder($normal);
        $paused->update(['status' => 'disputing']);
        $this->travel(3)->days();

        $this->artisan('orders:settle-due', ['--limit' => 1])->assertSuccessful();

        $this->assertSame('pending', $this->settlement($paused)->status);
        $this->assertSame('settled', $this->settlement($normal)->status);
    }

    public function test_dispute_pauses_reserve_release_without_starving_other_mature_reserves(): void
    {
        $paused = $this->paidOrder(['settlement_days' => 0]);
        $normal = $this->paidOrder(['settlement_days' => 0, 'order_no' => 'SSC-NORMAL', 'merchant_order_no' => 'M-NORMAL', 'payment_link_token' => 'tok-normal']);
        $service = app(BalanceService::class);
        $service->creditForPaidOrder($paused);
        $service->creditForPaidOrder($normal);
        $paused->update(['status' => 'disputing']);
        $this->travel(8)->days();

        $this->artisan('fund-freezes:release-due', ['--limit' => 1])->assertSuccessful();

        $this->assertSame('frozen', MerchantFundFreeze::query()->where('order_id', $paused->id)->sole()->status);
        $this->assertSame('released', MerchantFundFreeze::query()->where('order_id', $normal->id)->sole()->status);
        $paused->update(['status' => 'paid']);
        $this->artisan('fund-freezes:release-due')->assertSuccessful();
        $this->assertBalances('190.00', '0.00', '0.00', '0.00', '190.00');
    }

    public function test_legacy_pending_withdrawal_keeps_its_original_frozen_bucket(): void
    {
        $operator = $this->operator();
        $this->merchant->update(['balance' => '200.00', 'frozen_balance' => '50.00']);
        $withdrawal = MerchantWithdrawal::query()->create([
            'merchant_id' => $this->merchant->id, 'amount' => '50.00', 'status' => 'pending',
            'requested_by' => $operator->id, 'uses_pending_balance' => false,
        ]);

        app(BalanceService::class)->approveWithdrawal($withdrawal, $operator);

        $this->assertBalances('150.00', '0.00', '0.00', '0.00', '150.00');
    }

    private function paidOrder(array $overrides = []): Order
    {
        return $this->makeOrder('SSC-SETTLEMENT', 'M-SETTLEMENT', array_merge([
            'status' => 'paid', 'paid_at' => now(), 'settlement_amount' => '95.00',
            'settlement_mode' => 'immediate', 'settlement_days' => 2,
            'reserve_percent' => '10.0000', 'reserve_days' => 7,
            'refund_fee_snapshot' => '0.00', 'chargeback_fee_snapshot' => '0.00',
        ], $overrides));
    }

    private function settlement(Order $order): OrderSettlement
    {
        return OrderSettlement::query()->withoutGlobalScopes()->where('order_id', $order->id)->sole();
    }

    private function ship(Order $order, string $tracking = 'tracking'): OrderShipping
    {
        return OrderShipping::recordShipment($order->id, [
            'merchant_id' => $order->merchant_id, 'operator_id' => 0,
            'logistics_company' => 'ups', 'tracking_number' => $tracking,
            'shipped_at' => now()->subDays(5),
        ]);
    }

    private function operator(): User
    {
        return User::factory()->create(['merchant_id' => $this->merchant->id]);
    }

    private function assertBalances(string $total, string $pending, string $reserve, string $withdrawal, string $available): void
    {
        $merchant = $this->merchant->refresh();
        $this->assertSame($total, $merchant->balance);
        $this->assertSame($pending, $merchant->pending_settlement_balance);
        $this->assertSame($reserve, $merchant->frozen_balance);
        $this->assertSame($withdrawal, $merchant->pending_withdrawal_balance);
        $this->assertSame($available, $merchant->availableBalance());
    }
}
