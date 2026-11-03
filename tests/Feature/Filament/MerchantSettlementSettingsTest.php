<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MerchantResource;
use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Filament\Resources\MerchantResource\Pages\ListMerchants;
use App\Models\MerchantWithdrawal;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
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

        Livewire::actingAs($user)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->assertNotFound();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_form_opened_by_previous_owner_cannot_save_after_merchant_transfer(bool $transferToAnotherManager): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->manager();
        $newOwner = $transferToAnotherManager ? User::factory()->create(['merchant_id' => null]) : null;
        $originalValues = $this->merchant->fresh()->only(['name', 'status', 'remark', 'settlement_mode', 'settlement_days']);
        $staleMerchant = $this->merchant->fresh();
        $component = Livewire::actingAs($manager)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm([
                'name' => 'Changed after transfer',
                'status' => false,
                'remark' => 'Unsaved previous owner changes',
                'settlement_mode' => 'shipped',
                'settlement_days' => 365,
            ]);
        $this->merchant->update(['owner_id' => $newOwner?->id]);

        $this->assertFalse(MerchantResource::canEdit($staleMerchant));
        $component->call('save')->assertForbidden();

        $this->assertSame($originalValues, $this->merchant->fresh()->only(array_keys($originalValues)));
        $this->assertSame($newOwner?->id, $this->merchant->fresh()->owner_id);
    }

    public function test_new_owner_can_save_settlement_terms_after_merchant_transfer(): void
    {
        $this->createMerchantAndApplication();
        $this->manager();
        $newOwner = $this->manager();

        Livewire::actingAs($newOwner)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm(['settlement_mode' => 'shipped', 'settlement_days' => 7])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('shipped', $this->merchant->fresh()->settlement_mode);
        $this->assertSame(7, $this->merchant->fresh()->settlement_days);
    }

    public function test_super_administrator_can_save_another_managers_merchant_settlement_terms(): void
    {
        $this->createMerchantAndApplication();
        $this->manager();
        $administrator = User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);

        Livewire::actingAs($administrator)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm(['settlement_mode' => 'shipped', 'settlement_days' => 7])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('shipped', $this->merchant->fresh()->settlement_mode);
        $this->assertSame(7, $this->merchant->fresh()->settlement_days);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_disabled_administrator_cannot_open_merchant_editor(bool $isSuperAdmin): void
    {
        $this->createMerchantAndApplication();
        $administrator = $this->manager();
        $administrator->update(['is_super_admin' => $isSuperAdmin, 'status' => false]);

        Livewire::actingAs($administrator)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->assertForbidden();

        $this->assertSame('immediate', $this->merchant->fresh()->settlement_mode);
        $this->assertSame(0, $this->merchant->fresh()->settlement_days);
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
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->givePermissionTo(Permissions::MERCHANTS_MANAGE);
        $this->merchant->update(['owner_id' => $manager->id]);

        return $manager;
    }
}
