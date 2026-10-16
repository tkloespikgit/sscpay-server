<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Filament\Resources\MerchantResource\Pages\ListMerchants;
use App\Models\MerchantWithdrawal;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class MerchantSettlementSettingsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    #[TestWith(['immediate', 0])]
    #[TestWith(['shipped', 7])]
    public function test_owner_can_save_the_merchant_settlement_terms(string $mode, int $days): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->manager();

        Livewire::actingAs($manager)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm(['settlement_mode' => $mode, 'settlement_days' => $days])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($mode, $this->merchant->fresh()->settlement_mode);
        $this->assertSame($days, $this->merchant->fresh()->settlement_days);
    }

    #[TestWith(['settlement_days', -1])]
    #[TestWith(['settlement_days', 36501])]
    #[TestWith(['settlement_days', 1.5])]
    #[TestWith(['settlement_mode', 'unknown'])]
    public function test_invalid_settlement_terms_do_not_change_the_merchant(string $field, int|float|string $value): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->manager();

        Livewire::actingAs($manager)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm([$field => $value])
            ->call('save')
            ->assertHasFormErrors([$field]);

        $this->assertSame('immediate', $this->merchant->fresh()->settlement_mode);
        $this->assertSame(0, $this->merchant->fresh()->settlement_days);
    }

    public function test_merchant_user_cannot_change_settlement_terms(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::FINANCE_VIEW);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()]);
    }

    public function test_balances_show_legacy_pending_withdrawals_separately_from_reserves(): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->manager();
        $this->merchant->update([
            'balance' => '1000.00',
            'frozen_balance' => '200.00',
            'pending_settlement_balance' => '100.00',
            'pending_withdrawal_balance' => '50.00',
        ]);
        MerchantWithdrawal::create([
            'merchant_id' => $this->merchant->id,
            'amount' => '120.00',
            'status' => 'pending',
            'requested_by' => $manager->id,
            'uses_pending_balance' => false,
        ]);

        Livewire::actingAs($manager)
            ->test(ListMerchants::class)
            ->assertTableColumnStateSet('reserve_balance', '80.00', record: $this->merchant)
            ->assertTableColumnStateSet('available_balance', '650.00', record: $this->merchant)
            ->assertTableColumnStateSet('pending_withdrawal_balance', '170.00', record: $this->merchant)
            ->assertTableColumnStateSet('pending_settlement_balance', '100.00', record: $this->merchant);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['merchant_id' => null]);
        $manager->givePermissionTo(Permissions::MERCHANTS_MANAGE);
        $this->merchant->update(['owner_id' => $manager->id]);

        return $manager;
    }
}
