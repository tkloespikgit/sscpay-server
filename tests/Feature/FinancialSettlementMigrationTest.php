<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Models\MerchantBalanceTransaction;
use App\Models\MerchantFundFreeze;
use App\Models\MerchantWithdrawal;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class FinancialSettlementMigrationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase {
        beginDatabaseTransaction as private beginRefreshDatabaseTransaction;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
        Http::preventStrayRequests();
        Event::fake([OrderStatusChanged::class]);
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $connection = (string) config('database.default');
        $this->assertSame('', (string) config('database.connections.'.$connection.'.url'));

        if ($connection === 'sqlite') {
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));

            return;
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertSame('mysql', $connection);
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertSame('', getenv('DB_URL'));
        $this->assertMatchesRegularExpression('/\Asscpay_test_[a-f0-9]{16}\z/', $database);
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS database_name')->database_name);
        RefreshDatabaseState::$migrated = false;
    }

    /** MySQL DDL 隐式提交，隔离库中逐例重建，不能套测试外层事务。 */
    public function beginDatabaseTransaction(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->beginRefreshDatabaseTransaction();

            return;
        }

        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            DB::disconnect();
        });
    }

    public function test_upgrade_preserves_existing_balances_withdrawals_fixed_reserves_and_ledger_entries(): void
    {
        $this->settlementMigration()->down();
        $this->balanceMigration()->down();
        $this->createMerchantAndApplication();
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $this->merchant->update(['balance' => '1000.00', 'frozen_balance' => '200.00']);
        $order = $this->makeOrder('legacy-migration-order', 'legacy-migration-merchant-order');
        $withdrawal = MerchantWithdrawal::create([
            'merchant_id' => $this->merchant->id,
            'amount' => '120.00',
            'status' => MerchantWithdrawal::STATUS_PENDING,
            'requested_by' => $operator->id,
        ]);
        $freeze = MerchantFundFreeze::create([
            'merchant_id' => $this->merchant->id,
            'amount' => '80.00',
            'status' => MerchantFundFreeze::STATUS_FROZEN,
            'reason' => 'Existing fixed reserve',
            'frozen_by' => $operator->id,
            'frozen_at' => now(),
            'release_at' => '2026-11-01 12:00:00',
        ]);
        $ledger = MerchantBalanceTransaction::create([
            'merchant_id' => $this->merchant->id,
            'type' => MerchantBalanceTransaction::TYPE_MANUAL_ADJUST,
            'amount' => '1000.00',
            'balance_before' => '0.00',
            'balance_after' => '1000.00',
            'operator_id' => $operator->id,
            'reason' => 'Existing account balance',
            'idempotency_key' => 'legacy-account-migration',
        ]);
        $legacyRecords = [$this->merchant, $order, $withdrawal, $freeze, $ledger];
        $originalAttributes = array_map(static fn (Model $record): array => $record->fresh()->getAttributes(), $legacyRecords);
        $availableBefore = $this->merchant->availableBalance();

        $this->balanceMigration()->up();
        $this->settlementMigration()->up();

        foreach ($legacyRecords as $index => $record) {
            $this->assertSame($originalAttributes[$index], array_intersect_key($record->fresh()->getAttributes(), $originalAttributes[$index]));
        }

        $merchant = $this->merchant->refresh();
        $this->assertSame('1000.00', $merchant->balance);
        $this->assertSame('200.00', $merchant->frozen_balance);
        $this->assertSame('0.00', $merchant->pending_settlement_balance);
        $this->assertSame('0.00', $merchant->pending_withdrawal_balance);
        $this->assertSame('immediate', $merchant->settlement_mode);
        $this->assertSame(0, $merchant->settlement_days);
        $this->assertSame('80.00', $merchant->reserveBalance());
        $this->assertSame('120.00', $merchant->pendingWithdrawalBalance());
        $this->assertSame('800.00', $availableBefore);
        $this->assertSame($availableBefore, $merchant->availableBalance());
        $this->assertFalse($withdrawal->refresh()->uses_pending_balance);
        $this->assertNull($order->refresh()->settlement_mode);
        $this->assertNull($order->settlement_days);
        $this->assertNull($freeze->refresh()->order_id);
        $this->assertNull($freeze->remaining_amount);
        $this->assertSame('80.00', $freeze->remainingAmount());
        $this->assertSame('2026-11-01 12:00:00', $freeze->release_at->toDateTimeString());
        $this->assertSame('0.00', $ledger->refresh()->pending_settlement_change);
        $this->assertSame('0.00', $ledger->frozen_change);
        $this->assertSame('0.00', $ledger->pending_withdrawal_change);
        $this->assertNull($ledger->fund_freeze_id);
        $this->assertDatabaseCount('order_settlements', 0);

        $this->settlementMigration()->down();
        $this->balanceMigration()->down();

        foreach ($legacyRecords as $index => $record) {
            $this->assertSame($originalAttributes[$index], $record->fresh()->getAttributes());
        }

        $this->balanceMigration()->up();
        $this->settlementMigration()->up();

        foreach ($legacyRecords as $index => $record) {
            $this->assertSame($originalAttributes[$index], array_intersect_key($record->fresh()->getAttributes(), $originalAttributes[$index]));
        }

        $this->assertSame($availableBefore, $this->merchant->refresh()->availableBalance());
        $this->assertSame('80.00', $this->merchant->reserveBalance());
        $this->assertSame('120.00', $this->merchant->pendingWithdrawalBalance());
    }

    #[TestWith(['new_order'])]
    #[TestWith(['pending_settlement'])]
    #[TestWith(['pending_withdrawal'])]
    #[TestWith(['new_withdrawal'])]
    #[TestWith(['rolling_reserve'])]
    #[TestWith(['future_release'])]
    public function test_rollback_refuses_new_financial_state_without_partially_removing_the_schema(string $state): void
    {
        $this->createMerchantAndApplication();
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);

        match ($state) {
            'new_order' => $this->makeOrder('protected-new-order', 'protected-merchant-order', ['settlement_mode' => 'immediate', 'settlement_days' => 0]),
            'pending_settlement' => $this->merchant->update(['pending_settlement_balance' => '1.00']),
            'pending_withdrawal' => $this->merchant->update(['pending_withdrawal_balance' => '1.00']),
            'new_withdrawal' => MerchantWithdrawal::create([
                'merchant_id' => $this->merchant->id,
                'amount' => '1.00',
                'status' => MerchantWithdrawal::STATUS_PENDING,
                'requested_by' => $operator->id,
                'uses_pending_balance' => true,
            ]),
            'rolling_reserve' => MerchantFundFreeze::create([
                'merchant_id' => $this->merchant->id,
                'order_id' => $this->makeOrder('protected-rolling-order', 'protected-rolling-merchant-order')->id,
                'amount' => '1.00',
                'remaining_amount' => '1.00',
                'status' => MerchantFundFreeze::STATUS_FROZEN,
                'reason' => 'Protected rolling reserve',
                'frozen_at' => now(),
            ]),
            'future_release' => MerchantFundFreeze::create([
                'merchant_id' => $this->merchant->id,
                'amount' => '1.00',
                'status' => MerchantFundFreeze::STATUS_FROZEN,
                'reason' => 'Fixed reserve beyond timestamp range',
                'frozen_by' => $operator->id,
                'frozen_at' => now(),
                'release_at' => '2126-09-22 12:00:00',
            ]),
        };

        try {
            $this->balanceMigration()->down();
            $this->fail('New financial state must prevent destructive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('新结算账务已启用，禁止回滚删除余额或快照；请使用前向迁移。', $exception->getMessage());
        }

        $this->assertSettlementSchemaIntact();
    }

    #[TestWith(['type'])]
    #[TestWith(['pending_settlement_change'])]
    #[TestWith(['pending_withdrawal_change'])]
    #[TestWith(['frozen_change'])]
    #[TestWith(['fund_freeze_id'])]
    public function test_rollback_preserves_new_classification_audit_even_after_pending_balances_are_zero(string $audit): void
    {
        $this->createMerchantAndApplication();
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $attributes = match ($audit) {
            'type' => ['type' => MerchantBalanceTransaction::TYPE_DISPUTE_FREEZE],
            'fund_freeze_id' => ['fund_freeze_id' => MerchantFundFreeze::create([
                'merchant_id' => $this->merchant->id,
                'amount' => '1.00',
                'status' => MerchantFundFreeze::STATUS_FROZEN,
                'reason' => 'Fixed reserve linked by audit',
                'frozen_by' => $operator->id,
                'frozen_at' => now(),
            ])->id],
            default => [$audit => '1.00'],
        };
        $ledger = MerchantBalanceTransaction::create([
            'merchant_id' => $this->merchant->id,
            'type' => MerchantBalanceTransaction::TYPE_MANUAL_ADJUST,
            'amount' => '0.00',
            'balance_before' => '100.00',
            'balance_after' => '100.00',
            ...$attributes,
        ]);
        $original = $ledger->fresh()->getAttributes();

        try {
            $this->balanceMigration()->down();
            $this->fail('New classification audit must prevent destructive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('新结算账务已启用，禁止回滚删除余额或快照；请使用前向迁移。', $exception->getMessage());
        }

        $this->assertSame($original, $ledger->fresh()->getAttributes());
        $this->assertSettlementSchemaIntact();
    }

    public function test_settlement_audit_records_prevent_dropping_the_settlement_table(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->paidOrder(['settlement_days' => 1]);
        app(BalanceService::class)->creditForPaidOrder($order);
        $settlement = OrderSettlement::where('order_id', $order->id)->sole();
        $attributes = $settlement->getAttributes();

        try {
            $this->settlementMigration()->down();
            $this->fail('Settlement audit records must prevent destructive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('订单结算审计记录已产生，禁止回滚删除；请使用前向迁移。', $exception->getMessage());
        }

        $this->assertSame($attributes, $settlement->fresh()->getAttributes());
        $this->assertSettlementSchemaIntact();
    }

    public function test_a_long_settlement_cycle_persists_a_due_date_beyond_2038(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->paidOrder(['settlement_days' => 36500]);

        app(BalanceService::class)->creditForPaidOrder($order);

        $settlement = OrderSettlement::where('order_id', $order->id)->sole();
        $this->assertSame('2126-09-22 12:00:00', $settlement->due_at->toDateTimeString());
        $this->assertSame(OrderSettlement::STATUS_PENDING, $settlement->status);
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertSame('95.00', $this->merchant->pending_settlement_balance);
        $this->assertFalse(app(BalanceService::class)->settleOrder($settlement));
    }

    public function test_a_long_reserve_holding_period_persists_a_release_date_beyond_2038(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->paidOrder(['settlement_days' => 0, 'reserve_days' => 36500]);

        app(BalanceService::class)->creditForPaidOrder($order);

        $settlement = OrderSettlement::where('order_id', $order->id)->sole();
        $freeze = MerchantFundFreeze::where('order_id', $order->id)->sole();
        $this->assertSame(OrderSettlement::STATUS_SETTLED, $settlement->status);
        $this->assertSame('2126-09-22 12:00:00', $freeze->release_at->toDateTimeString());
        $this->assertSame('9.50', $freeze->remainingAmount());
        $this->assertSame('9.50', $this->merchant->refresh()->frozen_balance);
        $this->assertSame('85.50', $this->merchant->availableBalance());
    }

    private function assertSettlementSchemaIntact(): void
    {
        $this->assertTrue(Schema::hasColumns('merchants', ['pending_settlement_balance', 'pending_withdrawal_balance', 'settlement_mode', 'settlement_days']));
        $this->assertTrue(Schema::hasColumns('orders', ['settlement_mode', 'settlement_days']));
        $this->assertTrue(Schema::hasColumn('merchant_withdrawals', 'uses_pending_balance'));
        $this->assertTrue(Schema::hasColumns('merchant_fund_freezes', ['order_id', 'remaining_amount']));
        $this->assertTrue(Schema::hasColumns('merchant_balance_transactions', ['pending_settlement_change', 'pending_withdrawal_change', 'frozen_change', 'fund_freeze_id']));
        $this->assertTrue(Schema::hasTable('order_settlements'));
    }

    private function paidOrder(array $overrides): Order
    {
        return $this->makeOrder('financial-migration-order', 'financial-migration-merchant-order', [
            'status' => 'paid',
            'paid_at' => now(),
            'settlement_amount' => '95.00',
            'settlement_mode' => 'immediate',
            'settlement_days' => 0,
            'reserve_percent' => '10.0000',
            'reserve_days' => 7,
            'refund_fee_snapshot' => '0.00',
            'chargeback_fee_snapshot' => '0.00',
            ...$overrides,
        ]);
    }

    private function balanceMigration(): Migration
    {
        return require database_path('migrations/2026_10_16_183626_add_settlement_balances_and_order_terms.php');
    }

    private function settlementMigration(): Migration
    {
        return require database_path('migrations/2026_10_16_183626_create_order_settlements_table.php');
    }
}
