<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentMethodDisplayNameTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    #[TestWith(['merchant', 'Customer channel', false, false])]
    #[TestWith(['merchant', 'Customer channel', true, false])]
    #[TestWith(['merchant', 'Customer channel', true, true])]
    #[TestWith(['manager', 'Internal channel', false, false])]
    #[TestWith(['super_admin', 'Internal channel', true, false])]
    public function test_transaction_names_and_filter_labels_follow_the_account_type(string $accountType, string $expected, bool $systemLevel, bool $archived): void
    {
        $this->createMerchantAndApplication();
        $viewer = User::factory()->create([
            'merchant_id' => $accountType === 'merchant' ? $this->merchant->id : null,
            'is_super_admin' => $accountType === 'super_admin',
            'status' => true,
        ]);
        $viewer->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->merchant->update(['owner_id' => $viewer->id]);
        $method = $this->makePaymentMethod('named-channel');
        $method->update(['method_name' => 'Internal channel', 'display_name' => 'Customer channel']);
        if ($systemLevel) {
            $method->owner_id = $viewer->id;
            $method->update(['merchant_id' => null]);
            $method->assignedMerchants()->attach($this->merchant);
        }

        $order = $this->makeOrder('SSC-NAMED', 'M-NAMED', ['payment_method_id' => $method->id, 'payment_method' => $method->method_code]);
        if ($archived) {
            $method->delete();
        }
        $this->actingAs($viewer);
        Filament::setCurrentPanel($accountType === 'merchant' ? 'merchant' : 'admin');

        $page = Livewire::test(ListOrders::class)
            ->assertOk()
            ->assertTableColumnStateSet('paymentMethod.method_name', $expected, $order);

        $options = $page->instance()->getTable()->getFilter('payment_method')->getOptions();
        if ($accountType !== 'merchant') {
            $options = $options[$systemLevel ? __('admin.payment_method.columns.system_level') : $this->merchant->name];
        }
        $this->assertSame($expected, $options[$method->method_code]);
        $page->filterTable('payment_method', [$method->method_code])->assertCanSeeTableRecords([$order]);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_merchant_falls_back_to_original_name_for_an_unnamed_soft_deleted_channel(?string $displayName): void
    {
        $this->createMerchantAndApplication();
        $viewer = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $viewer->givePermissionTo(Permissions::ORDERS_VIEW);
        $method = $this->makePaymentMethod('legacy-channel');
        $method->update(['method_name' => 'Legacy channel', 'display_name' => $displayName]);
        $order = $this->makeOrder('SSC-LEGACY', 'M-LEGACY', ['payment_method_id' => $method->id, 'payment_method' => $method->method_code]);
        $method->delete();
        $this->actingAs($viewer);
        Filament::setCurrentPanel('merchant');

        $page = Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('paymentMethod.method_name', 'Legacy channel', $order);
        $this->assertSame('Legacy channel', $page->instance()->getTable()->getFilter('payment_method')->getOptions()[$method->method_code]);
    }

    public function test_transaction_without_a_related_channel_falls_back_to_the_stored_code(): void
    {
        $this->createMerchantAndApplication();
        $viewer = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $viewer->givePermissionTo(Permissions::ORDERS_VIEW);
        $order = $this->makeOrder('SSC-MISSING', 'M-MISSING', ['payment_method' => 'legacy-code']);
        $this->actingAs($viewer);
        Filament::setCurrentPanel('merchant');

        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('paymentMethod.method_name', 'legacy-code', $order);
    }

    public function test_payment_method_form_saves_and_clears_the_display_name_independently(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('editable-channel');
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['config_id' => 'test-config']])]);

        $page = Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['display_name' => 'Merchant facing name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('payment_methods', ['id' => $method->id, 'method_name' => $method->method_name, 'display_name' => 'Merchant facing name']);
        $page->fillForm(['display_name' => str_repeat('a', 101)])
            ->call('save')
            ->assertHasFormErrors(['display_name' => 'max']);
        $this->assertSame('Merchant facing name', $method->fresh()->display_name);
        $page->fillForm(['display_name' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($method->fresh()->display_name);
        Http::assertSentCount(2);
    }
}
