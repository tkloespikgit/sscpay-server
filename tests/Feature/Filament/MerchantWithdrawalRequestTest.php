<?php

namespace Tests\Feature\Filament;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\MerchantResource\Pages\ListMerchants;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Models\User;
use App\Services\BalanceService;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 商户列表页的「代发起提现」行动作：平台侧（超管 / 商户级管理员）代商户发起提现。
 * 语义必须和商户自助申请一致——冻结可提现余额、落 pending 单，不直接放款。
 */
class MerchantWithdrawalRequestTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '1000.00', 'frozen_balance' => '200.00']);

        Filament::setCurrentPanel('admin');
    }

    public function test_super_admin_can_request_a_withdrawal_for_a_merchant(): void
    {
        $admin = $this->platformUser(superAdmin: true);

        $this->callWithdrawal($admin, $this->merchant, [
            'amount' => '300.00',
            'payout_account' => 'TRC20: TXXXX',
            'remark' => '商户电话申请',
        ])->assertHasNoActionErrors();

        $withdrawal = MerchantWithdrawal::query()->withoutGlobalScopes()->sole();

        $this->assertSame($this->merchant->id, $withdrawal->merchant_id);
        $this->assertSame('300.00', (string) $withdrawal->amount);
        $this->assertSame(MerchantWithdrawal::STATUS_PENDING, $withdrawal->status);
        $this->assertSame('TRC20: TXXXX', $withdrawal->payout_account);
        // 代发起也要留痕：申请人记的是平台侧操作人，不是商户自己
        $this->assertSame($admin->id, $withdrawal->requested_by);

        // 冻结增加，总余额不动——放款要等审核
        $this->merchant->refresh();
        $this->assertSame('500.00', (string) $this->merchant->frozen_balance);
        $this->assertSame('1000.00', (string) $this->merchant->balance);
    }

    public function test_merchant_manager_can_request_for_an_owned_merchant(): void
    {
        $manager = $this->platformUser();
        $this->merchant->update(['owner_id' => $manager->id]);

        $this->callWithdrawal($manager, $this->merchant, ['amount' => '100.00'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, MerchantWithdrawal::query()->withoutGlobalScopes()->count());
    }

    public function test_merchant_manager_only_sees_merchants_they_own(): void
    {
        $manager = $this->platformUser();
        $this->merchant->update(['owner_id' => $manager->id]);

        $other = Merchant::create([
            'name' => 'Not Mine',
            'contact_person' => 'Someone',
            'contact_phone' => '000',
            'contact_email' => 'someone@example.com',
        ]);

        // getEloquentQuery() 按 owner_id 收窄，别人名下的商户根本不在这张表里，
        // 所以行动作也无从触发——action() 里的 manageableMerchantIds() 是第二道闸。
        Livewire::actingAs($manager)
            ->test(ListMerchants::class)
            ->assertCanSeeTableRecords([$this->merchant])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_amount_above_available_balance_fails_form_validation(): void
    {
        $admin = $this->platformUser(superAdmin: true);

        // 可提现 = 1000 - 200 = 800
        $this->callWithdrawal($admin, $this->merchant, ['amount' => '800.01'])
            ->assertHasActionErrors(['amount']);

        $this->assertSame(0, MerchantWithdrawal::query()->withoutGlobalScopes()->count());
        $this->assertSame('200.00', (string) $this->merchant->refresh()->frozen_balance);
    }

    /**
     * 表单上的 maxValue 只是提示：弹窗打开到提交之间余额可能已经变了。
     * 真正守住钱的是 BalanceService 行锁事务里的那次比较，单独验一遍。
     */
    public function test_service_rejects_an_overdraft_even_if_the_form_is_bypassed(): void
    {
        $admin = $this->platformUser(superAdmin: true);

        $this->expectException(BalanceOperationException::class);

        try {
            app(BalanceService::class)->requestWithdrawal($this->merchant, '800.01', $admin);
        } finally {
            $this->assertSame(0, MerchantWithdrawal::query()->withoutGlobalScopes()->count());
            $this->assertSame('200.00', (string) $this->merchant->refresh()->frozen_balance);
        }
    }

    public function test_a_wrong_two_factor_code_blocks_the_withdrawal(): void
    {
        $admin = $this->platformUser(superAdmin: true);

        $this->callWithdrawal($admin, $this->merchant, ['amount' => '100.00'], mfaCode: '000000');

        $this->assertSame(0, MerchantWithdrawal::query()->withoutGlobalScopes()->count());
        $this->assertSame('200.00', (string) $this->merchant->refresh()->frozen_balance);
    }

    public function test_action_is_hidden_without_the_withdrawal_request_permission(): void
    {
        $manager = $this->platformUser();
        $manager->syncPermissions([Permissions::MERCHANTS_MANAGE]);
        $this->merchant->update(['owner_id' => $manager->id]);

        Livewire::actingAs($manager)
            ->test(ListMerchants::class)
            ->assertActionHidden(TestAction::make('requestWithdrawal')->table($this->merchant));
    }

    /** 调用商户列表行上的「代发起提现」动作，默认带上一枚当前有效的 TOTP 验证码。 */
    private function callWithdrawal(User $user, Merchant $merchant, array $data, ?string $mfaCode = null)
    {
        return Livewire::actingAs($user)
            ->test(ListMerchants::class)
            ->callAction(
                TestAction::make('requestWithdrawal')->table($merchant),
                [...$data, 'mfa_code' => $mfaCode ?? $this->currentMfaCode($user)],
            );
    }

    /**
     * 平台侧账号：merchant_id 为 NULL；超管靠 Gate::before 全通权限，
     * 商户级管理员显式授予 withdrawals.request。两者都绑定验证器，
     * 否则 FinanceSecurity 会先一步拦下所有资金操作。
     */
    private function platformUser(bool $superAdmin = false): User
    {
        $user = User::create([
            'name' => $superAdmin ? 'Root' : 'Manager',
            'email' => $superAdmin ? 'root@example.com' : 'manager@example.com',
            'password' => bcrypt('secret'),
            'is_super_admin' => $superAdmin,
        ]);

        if (! $superAdmin) {
            $user->givePermissionTo([Permissions::MERCHANTS_MANAGE, Permissions::WITHDRAWALS_REQUEST]);
        }

        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());

        return $user;
    }

    private function currentMfaCode(User $user): string
    {
        return app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret());
    }
}
