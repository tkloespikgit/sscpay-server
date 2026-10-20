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
use PragmaRX\Google2FA\Google2FA;
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

    /**
     * 回归：创建固定保证金时选了「计划解冻时间」不能报 TypeError。
     *
     * 背景：Filament 的 DateTimePicker 提交的表单值是 'Y-m-d H:i:s' **字符串**，
     * 而 BalanceService::freezeFunds() 第 5 参声明为 ?DateTimeInterface，
     * 直接把字符串透传过去会抛：
     *   Argument #5 ($releaseAt) must be of type ?DateTimeInterface, string given
     *
     * 这条用例把整条链路（表单 → Action → 服务层）跑一遍，
     * 断言 release_at 真的落库了，且不是空值。
     */
    public function test_creating_a_fixed_reserve_with_a_release_at_accepts_the_datetime_picker_string(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->reserveUser();
        $user->givePermissionTo(Permissions::FUND_FREEZES_CREATE);
        $user->givePermissionTo(Permissions::FINANCE_VIEW);

        $this->giveMerchantBalance('100.00');

        $releaseAt = now()->addDays(7)->startOfSecond();

        Livewire::actingAs($user)
            ->test(ListMerchantFundFreezes::class)
            ->callAction(
                TestAction::make('openFundFreeze'),
                [
                    'merchant_id' => $this->merchant->id,
                    'amount' => '30.00',
                    // 关键：模拟 DateTimePicker 交上来的字符串
                    'release_at' => $releaseAt->format('Y-m-d H:i:s'),
                    'reason' => '固定保证金',
                    'mfa_code' => $this->validTotp($user),
                ],
            );

        $freeze = MerchantFundFreeze::query()
            ->where('reason', '固定保证金')
            ->latest('id')
            ->first();

        $this->assertNotNull($freeze, '应成功创建冻结记录');
        $this->assertSame('30.00', number_format((float) $freeze->amount, 2, '.', ''));
        $this->assertNotNull($freeze->release_at, 'release_at 应已落库');
        $this->assertSame(
            $releaseAt->format('Y-m-d H:i:s'),
            $freeze->release_at->format('Y-m-d H:i:s'),
            'release_at 应与表单选择的时间一致'
        );
    }

    /**
     * 回归：不选解冻时间（留空）时 release_at 应为 null，而不是空字符串。
     */
    public function test_creating_a_fixed_reserve_without_a_release_at_stores_null(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->reserveUser();
        $user->givePermissionTo(Permissions::FUND_FREEZES_CREATE);
        $user->givePermissionTo(Permissions::FINANCE_VIEW);

        $this->giveMerchantBalance('100.00');

        Livewire::actingAs($user)
            ->test(ListMerchantFundFreezes::class)
            ->callAction(
                TestAction::make('openFundFreeze'),
                [
                    'merchant_id' => $this->merchant->id,
                    'amount' => '15.00',
                    'release_at' => null,
                    'reason' => '无解冻时间',
                    'mfa_code' => $this->validTotp($user),
                ],
            );

        $freeze = MerchantFundFreeze::query()
            ->where('reason', '无解冻时间')
            ->latest('id')
            ->first();

        $this->assertNotNull($freeze, '应成功创建冻结记录');
        $this->assertNull($freeze->release_at, 'release_at 应为 null（只能人工解冻）');
    }

    /**
     * 资金操作要过 step-up 2FA（FinanceSecurity::assertVerified），
     * 这里给测试用户绑定 TOTP secret 并生成一个当前有效的验证码。
     */
    private function validTotp(User $user): string
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user->forceFill(['app_authentication_secret' => $secret])->save();

        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    /**
     * 冻结只能冻结"可提现余额"（availableBalance），新商户余额是 0，
     * 所以先给它充值，否则 freezeFunds 会被
     * settlement.errors.freeze_exceeds_available 拦掉——测的就不是日期类型问题了。
     */
    private function giveMerchantBalance(string $amount): void
    {
        $this->merchant->forceFill(['balance' => $amount])->save();
    }

    private function reserveUser(): User
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::FUND_FREEZES_VIEW, Permissions::FUND_FREEZES_RELEASE]);

        return $user;
    }
}
