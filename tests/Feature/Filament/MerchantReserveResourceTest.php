<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MerchantBalanceTransactionResource\Pages\ListMerchantBalanceTransactions;
use App\Filament\Resources\MerchantFundFreezeResource\Pages\ListMerchantFundFreezes;
use App\Models\MerchantBalanceTransaction;
use App\Models\MerchantFundFreeze;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class MerchantReserveResourceTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('merchant');
    }

    public function test_rolling_reserves_show_remaining_funds_without_an_early_release_action(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->reserveUser();
        $order = $this->makeOrder('reserve-order', 'reserve-merchant-order');
        $rolling = MerchantFundFreeze::create([
            'merchant_id' => $this->merchant->id,
            'order_id' => $order->id,
            'amount' => '10.00',
            'remaining_amount' => '6.00',
            'status' => 'frozen',
            'reason' => 'Order rolling reserve',
            'frozen_at' => '2026-10-15 12:00:00',
            'release_at' => '2026-11-14 12:00:00',
        ]);

        Livewire::actingAs($user)
            ->test(ListMerchantFundFreezes::class)
            ->assertCanSeeTableRecords([$rolling])
            ->assertTableColumnFormattedStateSet('source', __('settlement.reserve.sources.rolling'), record: $rolling)
            ->assertTableColumnStateSet('remaining_amount', '6.00', record: $rolling)
            ->assertActionHidden(TestAction::make('releaseFundFreeze')->table($rolling));
    }

    public function test_fixed_reserves_keep_their_manual_release_action_and_legacy_amount(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->reserveUser();
        $fixed = MerchantFundFreeze::create([
            'merchant_id' => $this->merchant->id,
            'amount' => '20.00',
            'status' => 'frozen',
            'reason' => 'Fixed reserve',
            'frozen_by' => $user->id,
            'frozen_at' => '2026-10-15 12:00:00',
        ]);

        Livewire::actingAs($user)
            ->test(ListMerchantFundFreezes::class)
            ->filterTable('source', 'fixed')
            ->assertCanSeeTableRecords([$fixed])
            ->assertTableColumnFormattedStateSet('source', __('settlement.reserve.sources.fixed'), record: $fixed)
            ->assertTableColumnStateSet('remaining_amount', '20.00', record: $fixed)
            ->assertActionVisible(TestAction::make('releaseFundFreeze')->table($fixed));
    }

    public function test_consumed_rolling_reserves_are_distinct_from_released_reserves(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->reserveUser();
        $order = $this->makeOrder('consumed-order', 'consumed-merchant-order');
        $consumed = MerchantFundFreeze::create([
            'merchant_id' => $this->merchant->id,
            'order_id' => $order->id,
            'amount' => '10.00',
            'remaining_amount' => '0.00',
            'status' => MerchantFundFreeze::STATUS_CONSUMED,
            'reason' => 'Applied to refund',
            'frozen_at' => '2026-10-15 12:00:00',
        ]);

        Livewire::actingAs($user)
            ->test(ListMerchantFundFreezes::class)
            ->filterTable('status', 'consumed')
            ->assertCanSeeTableRecords([$consumed])
            ->assertTableColumnFormattedStateSet('status', __('settlement.reserve.statuses.consumed'), record: $consumed)
            ->assertTableColumnStateSet('remaining_amount', '0.00', record: $consumed)
            ->assertActionHidden(TestAction::make('releaseFundFreeze')->table($consumed));
    }

    public function test_zero_total_settlement_entries_show_each_balance_transfer(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->reserveUser();
        $user->givePermissionTo(Permissions::FINANCE_VIEW);
        $transaction = MerchantBalanceTransaction::create([
            'merchant_id' => $this->merchant->id,
            'type' => MerchantBalanceTransaction::TYPE_ORDER_SETTLEMENT,
            'amount' => '0.00',
            'balance_before' => '90.00',
            'balance_after' => '90.00',
            'pending_settlement_change' => '-90.00',
            'frozen_change' => '9.00',
            'pending_withdrawal_change' => '0.00',
        ]);

        Livewire::actingAs($user)
            ->test(ListMerchantBalanceTransactions::class)
            ->filterTable('type', 'order_settlement')
            ->assertCanSeeTableRecords([$transaction])
            ->assertTableColumnFormattedStateSet('type', __('settlement.txn.types.order_settlement'), record: $transaction)
            ->assertTableColumnStateSet('amount', '0.00', record: $transaction)
            ->assertTableColumnStateSet('pending_settlement_change', '-90.00', record: $transaction)
            ->assertTableColumnStateSet('frozen_change', '9.00', record: $transaction)
            ->assertTableColumnStateSet('available_change', '81.00', record: $transaction);
    }

    private function reserveUser(): User
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::FUND_FREEZES_VIEW, Permissions::FUND_FREEZES_RELEASE]);

        return $user;
    }
}
