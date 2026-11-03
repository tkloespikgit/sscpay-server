<?php

namespace Tests\Feature;

use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentMethodFinancialContextTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_platform_staff_can_switch_merchants_to_view_each_merchants_effective_financial_terms(): void
    {
        [$other, $method] = $this->sharedMethodWithMerchantConfigurations();
        $unassigned = $this->makePaymentMethod('unassigned_financial_context');
        $unassigned->update(['merchant_id' => null]);
        $this->actingAs($this->platformStaff(true));
        Http::preventStrayRequests();

        $page = Livewire::test(ListPaymentMethods::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$method])
            ->assertTableColumnStateSet('fee_percent', '3.5000', $method)
            ->assertTableColumnStateSet('fee_fixed', '0.80', $method)
            ->assertTableColumnStateSet('refund_fee', '9.00', $method)
            ->assertTableColumnStateSet('chargeback_fee', '25.00', $method)
            ->assertTableColumnStateSet('reserve_percent', '15.0000', $method)
            ->assertTableColumnStateSet('reserve_days', 120, $method)
            ->filterTable('merchant_id', $this->merchant->id)
            ->assertCanSeeTableRecords([$method])
            ->assertCanNotSeeTableRecords([$unassigned])
            ->assertTableColumnStateSet('fee_percent', '14.0000', $method)
            ->assertTableColumnStateSet('fee_fixed', '0.30', $method)
            ->assertTableColumnStateSet('refund_fee', '5.00', $method)
            ->assertTableColumnStateSet('chargeback_fee', '15.00', $method)
            ->assertTableColumnStateSet('reserve_percent', '10.0000', $method)
            ->assertTableColumnStateSet('reserve_days', 90, $method)
            ->assertTableColumnStateSet('settlement_mode', 'shipped', $method)
            ->assertTableColumnStateSet('settlement_days', 7, $method)
            ->filterTable('merchant_id', $other->id)
            ->assertCanSeeTableRecords([$method])
            ->assertTableColumnStateSet('fee_percent', '9.0000', $method)
            ->assertTableColumnStateSet('fee_fixed', '0.00', $method)
            ->assertTableColumnStateSet('refund_fee', '0.00', $method)
            ->assertTableColumnStateSet('chargeback_fee', '30.00', $method)
            ->assertTableColumnStateSet('reserve_percent', '0.0000', $method)
            ->assertTableColumnStateSet('reserve_days', 0, $method)
            ->assertTableColumnStateSet('settlement_mode', 'immediate', $method)
            ->assertTableColumnStateSet('settlement_days', 0, $method);

        $page->filterTable('merchant_id', $this->merchant->id)
            ->assertTableColumnStateSet('fee_percent', '14.0000', $method)
            ->assertTableColumnStateSet('reserve_percent', '10.0000', $method)
            ->assertTableColumnStateSet('settlement_mode', 'shipped', $method)
            ->filterTable('merchant_id', null)
            ->assertTableColumnStateSet('fee_percent', '3.5000', $method)
            ->assertTableColumnStateSet('reserve_percent', '15.0000', $method);
        Http::assertNothingSent();
    }

    public function test_manager_cannot_select_another_managers_merchant_financial_context(): void
    {
        [$other, $method] = $this->sharedMethodWithMerchantConfigurations();
        $manager = $this->platformStaff();
        $this->merchant->update(['owner_id' => $manager->id]);
        $this->actingAs($manager);
        Http::preventStrayRequests();

        $page = Livewire::test(ListPaymentMethods::class)
            ->assertOk()
            ->filterTable('merchant_id', $this->merchant->id)
            ->assertCanSeeTableRecords([$method])
            ->assertTableColumnStateSet('fee_percent', '14.0000', $method)
            ->filterTable('merchant_id', $other->id)
            ->assertCanNotSeeTableRecords([$method]);

        $this->assertCount(0, $page->instance()->getTableRecords());
        $page->filterTable('merchant_id', 'not-a-merchant')
            ->assertCanNotSeeTableRecords([$method]);
        $this->assertCount(0, $page->instance()->getTableRecords());
        $page->filterTable('merchant_id', $this->merchant->id)
            ->assertCanSeeTableRecords([$method])
            ->assertTableColumnStateSet('fee_percent', '14.0000', $method);
        Http::assertNothingSent();
    }

    public function test_merchant_filter_tampering_does_not_change_its_financial_context(): void
    {
        [$other, $method] = $this->sharedMethodWithMerchantConfigurations();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true, 'is_super_admin' => false]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        Filament::setCurrentPanel('merchant');
        $this->actingAs($user);
        Http::preventStrayRequests();

        Livewire::test(ListPaymentMethods::class)
            ->set('tableFilters', ['merchant_id' => ['value' => $other->id]])
            ->assertCanSeeTableRecords([$method])
            ->assertTableColumnStateSet('fee_percent', '14.0000', $method)
            ->assertTableColumnStateSet('reserve_percent', '10.0000', $method)
            ->assertTableColumnStateSet('settlement_mode', 'shipped', $method)
            ->assertTableColumnStateSet('settlement_days', 7, $method);

        Http::assertNothingSent();
    }

    public function test_platform_list_shows_repaired_settings_after_saving_merchant_defaults(): void
    {
        [, $method] = $this->sharedMethodWithMerchantConfigurations();
        $this->actingAs($this->platformStaff(true));
        MerchantPaymentSetting::query()->forMerchant($this->merchant->id)
            ->where('payment_method_id', $method->id)->delete();
        Http::preventStrayRequests();

        Livewire::test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm(['default_fee_percent' => '14.0000'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ListPaymentMethods::class)
            ->filterTable('merchant_id', $this->merchant->id)
            ->assertCanSeeTableRecords([$method])
            ->assertTableColumnStateSet('fee_percent', '14.0000', $method)
            ->assertTableColumnStateSet('fee_fixed', '0.30', $method)
            ->assertTableColumnStateSet('reserve_percent', '10.0000', $method)
            ->assertTableColumnStateSet('reserve_days', 90, $method)
            ->assertTableColumnStateSet('settlement_mode', 'shipped', $method)
            ->assertTableColumnStateSet('settlement_days', 7, $method);

        Http::assertNothingSent();
    }

    /** @return array{Merchant, PaymentMethod} */
    private function sharedMethodWithMerchantConfigurations(): array
    {
        $this->createMerchantAndApplication();
        $this->merchant->update([
            'default_fee_percent' => '14.0000', 'default_fee_fixed' => '0.30',
            'default_refund_fee' => '5.00', 'default_chargeback_fee' => '15.00',
            'default_reserve_percent' => '10.0000', 'default_reserve_days' => 90,
            'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ]);
        $other = Merchant::create([
            'name' => 'Other Merchant Financial Context',
            'default_fee_percent' => '9.0000', 'default_fee_fixed' => '0.00',
            'default_refund_fee' => '0.00', 'default_chargeback_fee' => '30.00',
            'default_reserve_percent' => '0.0000', 'default_reserve_days' => 0,
            'settlement_mode' => 'immediate', 'settlement_days' => 0,
        ]);
        $method = $this->makePaymentMethod('shared_financial_context');
        $method->update([
            'merchant_id' => null,
            'fee_percent' => '3.5000', 'fee_fixed' => '0.80',
            'refund_fee' => '9.00', 'chargeback_fee' => '25.00',
            'reserve_percent' => '15.0000', 'reserve_days' => 120,
        ]);
        $method->assignedMerchants()->sync([$this->merchant->id, $other->id]);

        return [$other, $method];
    }

    private function platformStaff(bool $isSuperAdmin = false): User
    {
        $user = User::factory()->create(['merchant_id' => null, 'is_super_admin' => $isSuperAdmin, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $user->givePermissionTo(Permissions::MERCHANTS_MANAGE);

        return $user;
    }
}
