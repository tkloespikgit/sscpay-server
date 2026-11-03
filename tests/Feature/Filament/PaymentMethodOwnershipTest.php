<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentMethodResource;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PaymentMethodOwnershipService;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentMethodOwnershipTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    #[TestWith([true], 'super administrator')]
    #[TestWith([false], 'system administrator')]
    public function test_platform_staff_see_the_administrator_name_for_system_payment_methods_and_the_merchant_name_for_merchant_methods(bool $isSuperAdmin): void
    {
        $viewer = User::factory()->create(['merchant_id' => null, 'is_super_admin' => $isSuperAdmin, 'status' => true]);
        $owner = User::factory()->create(['name' => 'Channel Administrator', 'merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $systemMethod = $this->systemPaymentMethod($owner);
        $viewer->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $this->merchant->update(['owner_id' => $viewer->id]);
        $systemMethod->assignedMerchants()->attach($this->merchant);
        $merchantMethod = $this->makePaymentMethod('merchant-owned-list');
        $unownedMethod = $this->makePaymentMethod('unowned-system-list');
        $unownedMethod->merchant_id = null;
        $unownedMethod->save();
        $unownedMethod->assignedMerchants()->attach($this->merchant);

        $page = Livewire::actingAs($viewer)
            ->test(ListPaymentMethods::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$systemMethod, $merchantMethod, $unownedMethod])
            ->assertTableColumnStateSet('merchant.name', 'Channel Administrator', $systemMethod)
            ->assertTableColumnStateSet('merchant.name', $this->merchant->name, $merchantMethod)
            ->assertTableColumnStateSet('merchant.name', null, $unownedMethod)
            ->assertSee(__('admin.payment_method.columns.system_level'));

        $listedSystemMethod = $page->instance()->getFilteredTableQuery()->findOrFail($systemMethod->id);
        $this->assertTrue($listedSystemMethod->relationLoaded('owner'));
    }

    public function test_super_admin_can_transfer_a_system_payment_method_without_changing_its_configuration_or_assignments(): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $previousOwner = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $newOwner = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($previousOwner);
        $previousOwner->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $newOwner->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $this->merchant->update(['owner_id' => $previousOwner->id]);
        $paymentMethod->assignedMerchants()->attach($this->merchant);
        $group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'ownership-group',
            'group_name' => 'Ownership group',
            'is_active' => true,
        ]);
        $group->paymentMethods()->attach($paymentMethod, ['priority' => 8]);
        $originalConfiguration = Arr::except($paymentMethod->getAttributes(), ['owner_id', 'updated_at']);

        Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->assertActionVisible('changeOwner')
            ->fillForm(['method_name' => 'Unsaved configuration'])
            ->callAction('changeOwner', ['owner_id' => $newOwner->id])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $paymentMethod->refresh();
        $this->assertSame($newOwner->id, $paymentMethod->owner_id);
        $this->assertSame($originalConfiguration, Arr::except($paymentMethod->getAttributes(), ['owner_id', 'updated_at']));
        $this->assertSame([$this->merchant->id], $paymentMethod->assignedMerchants()->pluck('merchants.id')->all());
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $paymentMethod->id, 'priority' => 8]);
        Http::assertNothingSent();

        $this->actingAs($previousOwner);
        $this->assertTrue(PaymentMethod::query()->whereKey($paymentMethod)->exists());
        $this->assertFalse(PaymentMethodResource::canEdit($paymentMethod));
        $this->assertTrue(PaymentMethod::forMerchant($this->merchant->id)->whereKey($paymentMethod)->exists());

        $this->actingAs($newOwner);
        $this->assertTrue(PaymentMethod::query()->whereKey($paymentMethod)->exists());
        $this->assertTrue(PaymentMethodResource::canEdit($paymentMethod));
    }

    public function test_previous_owner_loses_visibility_of_an_unassigned_system_payment_method_after_transfer(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $previousOwner = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($previousOwner);

        $paymentMethod = app(PaymentMethodOwnershipService::class)->changeOwner($paymentMethod, $admin, $admin->id);

        $this->assertSame($admin->id, $paymentMethod->owner_id);
        $this->actingAs($previousOwner);
        $this->assertFalse(PaymentMethod::query()->whereKey($paymentMethod)->exists());
        $this->assertFalse(PaymentMethodResource::canManageRecord($paymentMethod));
    }

    public function test_system_administrator_cannot_mount_or_execute_the_transfer_action(): void
    {
        $owner = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($owner);
        $owner->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);

        Livewire::actingAs($owner)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->assertOk()
            ->assertActionHidden('changeOwner')
            ->mountAction('changeOwner')
            ->assertActionNotMounted()
            ->call('callMountedAction');

        $this->assertSame($owner->id, $paymentMethod->fresh()->owner_id);
    }

    public function test_super_admin_cannot_mount_the_transfer_action_for_a_merchant_owned_payment_method(): void
    {
        $this->createMerchantAndApplication();
        $paymentMethod = $this->makePaymentMethod('merchant-owned');
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);

        Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->assertActionHidden('changeOwner')
            ->mountAction('changeOwner')
            ->assertActionNotMounted();

        $this->assertNull($paymentMethod->fresh()->owner_id);
    }

    public function test_transfer_action_requires_an_owner(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($admin);

        Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->callAction('changeOwner', ['owner_id' => null])
            ->assertHasActionErrors(['owner_id' => 'required']);

        $this->assertSame($admin->id, $paymentMethod->fresh()->owner_id);
    }

    public function test_transfer_action_rejects_a_merchant_user_as_the_new_owner(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($admin);
        $merchantUser = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);

        Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->callAction('changeOwner', ['owner_id' => $merchantUser->id])
            ->assertHasActionErrors(['owner_id']);

        $this->assertSame($admin->id, $paymentMethod->fresh()->owner_id);
    }

    /**
     * @param  array<string, bool>  $changes
     */
    #[TestWith([['is_super_admin' => false]], 'super administrator revoked')]
    #[TestWith([['status' => false]], 'account disabled')]
    public function test_revoking_operator_access_after_opening_the_dialog_prevents_transfer(array $changes): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($admin);
        $admin->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $newOwner = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $page = Livewire::actingAs($admin)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->mountAction('changeOwner')
            ->assertActionMounted('changeOwner')
            ->setActionData(['owner_id' => $newOwner->id]);

        User::query()->whereKey($admin)->update($changes);
        $this->actingAs($admin->fresh());
        $page->call('callMountedAction');

        $this->assertSame($admin->id, $paymentMethod->fresh()->owner_id);
    }

    #[TestWith(['merchant'], 'merchant account')]
    #[TestWith(['disabled'], 'disabled platform account')]
    #[TestWith(['deleted'], 'deleted platform account')]
    #[TestWith(['missing'], 'nonexistent account')]
    public function test_service_rejects_an_ineligible_target_owner(string $kind): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($admin);
        $target = User::factory()->create([
            'merchant_id' => $kind === 'merchant' ? $this->merchant->id : null,
            'is_super_admin' => false,
            'status' => $kind !== 'disabled',
        ]);
        if ($kind === 'deleted') {
            $target->delete();
        }
        $ownerId = $kind === 'missing' ? $target->id + 1000 : $target->id;

        try {
            app(PaymentMethodOwnershipService::class)->changeOwner($paymentMethod, $admin, $ownerId);
            $this->fail('An ineligible owner must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('owner_id', $exception->errors());
        }

        $this->assertSame($admin->id, $paymentMethod->fresh()->owner_id);
    }

    /**
     * @param  array<string, bool>  $changes
     */
    #[TestWith([['is_super_admin' => false], false], 'non-super administrator')]
    #[TestWith([['status' => false], false], 'disabled super administrator')]
    #[TestWith([[], true], 'deleted super administrator')]
    public function test_service_rechecks_operator_privileges_instead_of_trusting_a_stale_user(array $changes, bool $deleted): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($admin);
        $newOwner = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        if ($deleted) {
            User::query()->whereKey($admin)->delete();
        } else {
            User::query()->whereKey($admin)->update($changes);
        }

        try {
            app(PaymentMethodOwnershipService::class)->changeOwner($paymentMethod, $admin, $newOwner->id);
            $this->fail('A stale privileged operator must not transfer ownership.');
        } catch (AuthorizationException) {
            $this->assertSame($admin->id, $paymentMethod->fresh()->owner_id);
        }
    }

    public function test_service_rejects_a_payment_method_that_became_merchant_owned_after_loading(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->systemPaymentMethod($admin);
        PaymentMethod::query()->whereKey($paymentMethod)->update(['merchant_id' => $this->merchant->id, 'owner_id' => null]);

        try {
            app(PaymentMethodOwnershipService::class)->changeOwner($paymentMethod, $admin, $admin->id);
            $this->fail('A merchant-owned payment method must not transfer ownership.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('payment_methods', [
                'id' => $paymentMethod->id,
                'merchant_id' => $this->merchant->id,
                'owner_id' => null,
            ]);
        }
    }

    private function systemPaymentMethod(User $owner): PaymentMethod
    {
        $this->createMerchantAndApplication();
        $paymentMethod = $this->makePaymentMethod('system-method');
        $paymentMethod->merchant_id = null;
        $paymentMethod->owner_id = $owner->id;
        $paymentMethod->save();

        return $paymentMethod->refresh();
    }
}
