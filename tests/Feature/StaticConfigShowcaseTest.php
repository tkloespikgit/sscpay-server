<?php

namespace Tests\Feature;

use App\Filament\Pages\OnboardMerchant;
use App\Filament\Resources\MerchantResource\Pages\CreateMerchant;
use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class StaticConfigShowcaseTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        Filament::setCurrentPanel('admin');
    }

    public function test_guest_cannot_open_the_showcase(): void
    {
        $this->get(route('showcase.merchant-admin'))->assertForbidden();
    }

    public function test_disabled_platform_staff_cannot_open_the_showcase(): void
    {
        $admin = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => false,
        ]);

        $this->actingAs($admin)->get(route('showcase.merchant-admin'))->assertForbidden();
    }

    public function test_merchant_without_payment_permission_cannot_open_the_showcase(): void
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);

        $this->actingAs($user)->get(route('showcase.merchant-admin'))->assertForbidden();
    }

    public function test_merchant_of_a_disabled_merchant_cannot_open_the_showcase(): void
    {
        $this->merchant->update(['status' => false]);
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);

        $this->actingAs($user)->get(route('showcase.merchant-admin'))->assertForbidden();
    }

    public function test_merchant_only_receives_the_payment_method_screen(): void
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);

        $this->actingAs($user)
            ->get(route('showcase.merchant-admin'))
            ->assertOk()
            ->assertSee('data-screens="payment"', false)
            ->assertSee('id="screen-payment"', false)
            ->assertSee('id="user-name">商户', false)
            ->assertDontSee('id="screen-onboard"', false)
            ->assertDontSee('id="screen-merchant"', false)
            ->assertDontSee('data-nav="onboard"', false)
            ->assertDontSee('data-nav="merchant"', false)
            ->assertDontSee('演示身份', false);
    }

    public function test_platform_staff_receive_every_screen(): void
    {
        $manager = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $admin = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => true,
        ]);

        foreach ([$manager, $admin] as $user) {
            $this->actingAs($user)
                ->get(route('showcase.merchant-admin'))
                ->assertOk()
                ->assertSee('data-screens="onboard merchant payment"', false)
                ->assertSee('id="screen-onboard"', false)
                ->assertSee('id="screen-merchant"', false)
                ->assertSee('id="screen-payment"', false)
                ->assertSee('id="user-name">管理员', false)
                ->assertDontSee('演示身份', false);
        }
    }

    public function test_showcase_is_not_a_public_file(): void
    {
        $this->assertFileDoesNotExist(public_path('showcase/index.html'));
        $this->assertFileDoesNotExist(public_path('showcase/merchant-admin.html'));
    }

    public function test_platform_config_pages_link_to_the_matching_demo(): void
    {
        $admin = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => true,
        ]);
        $manager = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $manager->givePermissionTo(Permissions::MERCHANTS_MANAGE);
        $this->merchant->update(['owner_id' => $manager->id]);

        Livewire::actingAs($admin)
            ->test(OnboardMerchant::class)
            ->assertOk()
            ->assertActionVisible('staticConfigShowcase')
            ->assertSee('/showcase#onboard', false);

        Livewire::actingAs($admin)
            ->test(CreateMerchant::class)
            ->assertOk()
            ->assertActionVisible('staticConfigShowcase')
            ->assertSee('/showcase#merchant', false);

        Livewire::actingAs($manager)
            ->test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->assertOk()
            ->assertActionVisible('staticConfigShowcase')
            ->assertSee('/showcase#merchant', false);
    }

    public function test_payment_demo_entry_is_only_on_the_list_page(): void
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $method = $this->makePaymentMethod('showcase-payment');
        Filament::setCurrentPanel('merchant');

        Livewire::actingAs($user)
            ->test(ListPaymentMethods::class)
            ->assertOk()
            ->assertActionVisible('staticConfigShowcase')
            ->assertSee('/showcase#payment', false);

        Livewire::actingAs($user)
            ->test(CreatePaymentMethod::class)
            ->assertOk()
            ->assertActionDoesNotExist('staticConfigShowcase');

        Livewire::actingAs($user)
            ->test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertOk()
            ->assertActionDoesNotExist('staticConfigShowcase');
    }
}
