<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethodResource\RelationManagers\PurchaseOrdersRelationManager;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\SiteProduct;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentMethodPurchaseOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_super_admin_can_generate_a_purchase_order_from_the_payment_method(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);

        Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->assertActionVisible('generatePurchaseOrder')
            ->assertSeeLivewire(PurchaseOrdersRelationManager::class)
            ->callAction('generatePurchaseOrder', [
                'ordered_at' => '2026-09-01 08:30:00',
                'shipping_fee' => '25.50',
                'discount' => '15.00',
            ])
            ->assertHasNoActionErrors()
            ->assertDispatched('purchase-order-generated.'.$paymentMethod->id)
            ->assertNotified();

        $purchaseOrder = PurchaseOrder::query()->sole();
        $this->assertSame($paymentMethod->id, $purchaseOrder->payment_method_id);
        $this->assertSame('2026-09-01 08:30:00', $purchaseOrder->ordered_at->format('Y-m-d H:i:s'));
        $this->assertSame('25.50', $purchaseOrder->shipping_fee);
        $this->assertSame('15.00', $purchaseOrder->discount);
        $this->assertCount(2, $purchaseOrder->items);
        Http::assertNothingSent();
    }

    public function test_merchant_manager_cannot_generate_or_view_purchase_orders(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $paymentMethod->merchant->update(['owner_id' => $manager->id]);

        Livewire::actingAs($manager)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->assertOk()
            ->assertActionHidden('generatePurchaseOrder')
            ->assertDontSeeLivewire(PurchaseOrdersRelationManager::class)
            ->mountAction('generatePurchaseOrder')
            ->assertActionNotMounted()
            ->call('callMountedAction');

        $this->assertDatabaseCount('purchase_orders', 0);
        Http::assertNothingSent();
    }

    public function test_merchant_user_cannot_generate_or_view_purchase_orders(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $merchantUser = User::factory()->create([
            'merchant_id' => $paymentMethod->merchant_id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $merchantUser->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        Filament::setCurrentPanel('merchant');

        Livewire::actingAs($merchantUser)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->assertOk()
            ->assertActionHidden('generatePurchaseOrder')
            ->assertDontSeeLivewire(PurchaseOrdersRelationManager::class)
            ->mountAction('generatePurchaseOrder')
            ->assertActionNotMounted()
            ->call('callMountedAction');

        $this->assertDatabaseCount('purchase_orders', 0);
        Http::assertNothingSent();
    }

    public function test_revoking_super_admin_after_opening_the_generation_dialog_prevents_creation(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $admin->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $paymentMethod->merchant->update(['owner_id' => $admin->id]);

        $page = Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->mountAction('generatePurchaseOrder')
            ->assertActionMounted('generatePurchaseOrder')
            ->setActionData(['ordered_at' => '2026-09-01 08:30:00']);

        $admin->update(['is_super_admin' => false]);
        $this->actingAs($admin->fresh());
        $page->call('callMountedAction');

        $this->assertDatabaseCount('purchase_orders', 0);
        Http::assertNothingSent();
    }

    public function test_super_admin_can_edit_purchase_order_shipping_fee_and_discount(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->actingAs($admin);
        $purchaseOrder = app(PurchaseOrderService::class)->generate($paymentMethod, $admin, [
            'ordered_at' => '2026-09-01 08:30:00',
            'shipping_fee' => '0.00',
            'discount' => '0.00',
        ]);
        $subtotal = $purchaseOrder->subtotal;

        Livewire::test(PurchaseOrdersRelationManager::class, [
            'ownerRecord' => $paymentMethod,
            'pageClass' => EditPaymentMethod::class,
        ])
            ->assertCanSeeTableRecords([$purchaseOrder])
            ->callAction(TestAction::make('editCharges')->table($purchaseOrder), [
                'shipping_fee' => '12.34',
                'discount' => '5.67',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $purchaseOrder->refresh();
        $this->assertSame($subtotal, $purchaseOrder->subtotal);
        $this->assertSame('12.34', $purchaseOrder->shipping_fee);
        $this->assertSame('5.67', $purchaseOrder->discount);
        $this->assertSame(bcadd($subtotal, '6.67', 2), $purchaseOrder->total);
        Http::assertNothingSent();
    }

    public function test_generating_without_site_products_displays_a_field_error_without_creating_an_order(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $paymentMethod->siteProducts()->delete();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);

        Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->callAction('generatePurchaseOrder', ['ordered_at' => '2026-09-01 08:30:00'])
            ->assertHasActionErrors(['ordered_at']);

        $this->assertDatabaseCount('purchase_orders', 0);
        Http::assertNothingSent();
    }

    public function test_discount_above_subtotal_displays_a_field_error_without_updating_charges(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->actingAs($admin);
        $purchaseOrder = app(PurchaseOrderService::class)->generate($paymentMethod, $admin, [
            'ordered_at' => '2026-09-01 08:30:00',
            'shipping_fee' => '10.00',
            'discount' => '5.00',
        ]);

        Livewire::test(PurchaseOrdersRelationManager::class, [
            'ownerRecord' => $paymentMethod,
            'pageClass' => EditPaymentMethod::class,
        ])
            ->callAction(TestAction::make('editCharges')->table($purchaseOrder), [
                'shipping_fee' => '12.34',
                'discount' => '9999.00',
            ])
            ->assertHasActionErrors(['discount']);

        $purchaseOrder->refresh();
        $this->assertSame('10.00', $purchaseOrder->shipping_fee);
        $this->assertSame('5.00', $purchaseOrder->discount);
        Http::assertNothingSent();
    }

    public function test_super_admin_can_view_the_purchase_order_transaction_and_items(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->actingAs($admin);
        $purchaseOrder = app(PurchaseOrderService::class)->generate($paymentMethod, $admin, [
            'ordered_at' => '2026-09-01 08:30:00',
        ]);

        $manager = Livewire::test(PurchaseOrdersRelationManager::class, [
            'ownerRecord' => $paymentMethod,
            'pageClass' => EditPaymentMethod::class,
        ])
            ->mountAction(TestAction::make('view')->table($purchaseOrder))
            ->assertActionMounted(TestAction::make('view')->table($purchaseOrder))
            ->assertSchemaComponentStateSet('transaction_id', $purchaseOrder->transaction_id);

        $schemaName = $manager->instance()->getMountedActionSchemaName();
        $html = $manager->instance()->getSchema($schemaName)->toHtml();
        $this->assertStringContainsString('Product 1', $html);
        $this->assertStringContainsString('Product 2', $html);

        Http::assertNothingSent();
    }

    public function test_super_admin_can_open_the_purchase_order_document_from_its_table_row(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->actingAs($admin);
        $purchaseOrder = app(PurchaseOrderService::class)->generate($paymentMethod, $admin, [
            'ordered_at' => '2026-09-01 08:30:00',
        ]);
        $action = TestAction::make('viewPurchaseDocument')->table($purchaseOrder);

        Livewire::test(PurchaseOrdersRelationManager::class, [
            'ownerRecord' => $paymentMethod,
            'pageClass' => EditPaymentMethod::class,
        ])
            ->assertActionVisible($action)
            ->assertActionHasUrl($action, route('purchase-orders.show', ['purchaseOrder' => $purchaseOrder->id]))
            ->assertActionShouldOpenUrlInNewTab($action);

        Http::assertNothingSent();
    }

    #[TestWith([false, true])]
    #[TestWith([true, false])]
    public function test_direct_access_to_purchase_order_relation_requires_an_enabled_super_admin(bool $superAdmin, bool $status): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $user = User::factory()->create(['is_super_admin' => $superAdmin, 'status' => $status]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $paymentMethod->merchant->update(['owner_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(PurchaseOrdersRelationManager::class, [
                'ownerRecord' => $paymentMethod,
                'pageClass' => EditPaymentMethod::class,
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_negative_shipping_fee_is_rejected_without_updating_the_purchase_order(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->actingAs($admin);
        $purchaseOrder = app(PurchaseOrderService::class)->generate($paymentMethod, $admin, [
            'ordered_at' => '2026-09-01 08:30:00',
            'shipping_fee' => '10.00',
            'discount' => '5.00',
        ]);

        Livewire::test(PurchaseOrdersRelationManager::class, [
            'ownerRecord' => $paymentMethod,
            'pageClass' => EditPaymentMethod::class,
        ])
            ->callAction(TestAction::make('editCharges')->table($purchaseOrder), [
                'shipping_fee' => '-1.00',
                'discount' => '5.00',
            ])
            ->assertHasActionErrors(['shipping_fee']);

        $this->assertSame('10.00', $purchaseOrder->fresh()->shipping_fee);
        Http::assertNothingSent();
    }

    public function test_revoking_super_admin_after_opening_the_charge_dialog_prevents_changes(): void
    {
        Http::preventStrayRequests();
        $paymentMethod = $this->paymentMethodWithProducts();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $admin->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $paymentMethod->merchant->update(['owner_id' => $admin->id]);
        $this->actingAs($admin);
        $purchaseOrder = app(PurchaseOrderService::class)->generate($paymentMethod, $admin, [
            'ordered_at' => '2026-09-01 08:30:00',
            'shipping_fee' => '10.00',
            'discount' => '5.00',
        ]);

        $manager = Livewire::test(PurchaseOrdersRelationManager::class, [
            'ownerRecord' => $paymentMethod,
            'pageClass' => EditPaymentMethod::class,
        ])
            ->mountAction(TestAction::make('editCharges')->table($purchaseOrder))
            ->assertActionMounted(TestAction::make('editCharges')->table($purchaseOrder))
            ->setActionData(['shipping_fee' => '100.00', 'discount' => '50.00']);

        $admin->update(['is_super_admin' => false]);
        $this->actingAs($admin->fresh());
        $manager->call('callMountedAction')->assertForbidden();

        $purchaseOrder->refresh();
        $this->assertSame('10.00', $purchaseOrder->shipping_fee);
        $this->assertSame('5.00', $purchaseOrder->discount);
        Http::assertNothingSent();
    }

    private function paymentMethodWithProducts(): PaymentMethod
    {
        $this->seed(PermissionSeeder::class);
        $merchant = Merchant::create([
            'name' => 'Purchase Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'purchase@example.com',
        ]);
        $paymentMethod = PaymentMethod::create([
            'merchant_id' => $merchant->id,
            'method_code' => 'purchase_method',
            'method_name' => 'Purchase Site',
            'domain' => 'https://purchase.example.com',
            'is_active' => true,
        ]);

        foreach ([1 => '10.00', 2 => '20.00'] as $productId => $price) {
            SiteProduct::create([
                'merchant_id' => $merchant->id,
                'payment_method_id' => $paymentMethod->id,
                'woo_product_id' => $productId,
                'product_type' => 'simple',
                'name' => 'Product '.$productId,
                'price_min' => $price,
                'price_max' => $price,
            ]);
        }

        return $paymentMethod;
    }
}
