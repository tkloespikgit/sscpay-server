<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Filament\Resources\MerchantResource\RelationManagers\PaymentSettingsRelationManager;
use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\MerchantPaymentMethodAssignmentService;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MerchantPaymentMethodAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_authorizing_multiple_channels_moves_them_between_columns_and_saves_without_submitting_the_merchant_form(): void
    {
        Http::preventStrayRequests();
        $admin = $this->administrator();
        $merchant = $this->merchant(['default_fee_percent' => '2.5', 'settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $first = $this->method('first-channel', $admin);
        $second = $this->method('second-channel', $admin, ['is_active' => false]);

        $page = Livewire::actingAs($admin)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->fillForm(['name' => 'Unsaved merchant name', 'default_fee_percent' => '9'])
            ->mountAction('authorizePaymentMethods')
            ->assertActionMounted('authorizePaymentMethods')
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$first->id}, true)")
            ->call('setPaymentMethodAuthorization', $first->id, true)
            ->assertOk()
            ->assertDispatchedTo(PaymentSettingsRelationManager::class, 'merchant-payment-methods-updated')
            ->assertActionMounted('authorizePaymentMethods')
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$first->id}, false)")
            ->call('setPaymentMethodAuthorization', $second->id, true)
            ->assertOk()
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$second->id}, false)");

        $this->assertSame('Test Merchant', $merchant->fresh()->name);
        $this->assertDatabaseCount('merchant_payment_methods', 2);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $first->id,
            'fee_percent' => '2.5', 'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ]);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $second->id, 'fee_percent' => '2.5',
        ]);
        $page->call('setPaymentMethodAuthorization', $first->id, false)
            ->assertOk()
            ->assertActionMounted('authorizePaymentMethods')
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$first->id}, true)");
        Livewire::test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods')
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$first->id}, true)")
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$second->id}, false)");
        Http::assertNothingSent();
    }

    public function test_revoking_a_channel_deletes_its_configuration_and_reauthorization_uses_latest_merchant_defaults(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant(['default_fee_percent' => '2']);
        $other = $this->merchant(['name' => 'Other Merchant', 'default_fee_percent' => '3']);
        $method = $this->method('shared-channel', $admin);
        $method->assignedMerchants()->attach([$merchant->id, $other->id]);
        $setting = MerchantPaymentSetting::query()->where('merchant_id', $merchant->id)->sole();
        $setting->update(['fee_percent' => '0', 'reserve_percent' => null, 'settlement_days' => 14]);
        $otherSetting = MerchantPaymentSetting::query()->where('merchant_id', $other->id)->sole();
        $retainedMethod = $this->method('retained-channel', $admin);
        $retainedMethod->assignedMerchants()->attach($merchant);
        $retainedSetting = MerchantPaymentSetting::query()->where('payment_method_id', $retainedMethod->id)->sole();
        $retainedSetting->update(['fee_percent' => '4']);
        $merchant->update(['default_fee_percent' => '9', 'settlement_mode' => 'shipped', 'settlement_days' => 21]);
        $group = $this->group($merchant, 'merchant-group');
        $deletedGroup = $this->group($merchant, 'deleted-merchant-group');
        $otherGroup = $this->group($other, 'other-group');
        $group->paymentMethods()->attach($method);
        $deletedGroup->paymentMethods()->attach($method);
        $deletedGroup->delete();
        $otherGroup->paymentMethods()->attach($method);
        $relationManager = Livewire::actingAs($admin)
            ->test(PaymentSettingsRelationManager::class, ['ownerRecord' => $merchant, 'pageClass' => EditMerchant::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$setting, $retainedSetting]);

        $page = Livewire::test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods')
            ->call('setPaymentMethodAuthorization', $method->id, false)
            ->assertOk()
            ->assertDispatchedTo(PaymentSettingsRelationManager::class, 'merchant-payment-methods-updated');

        $relationManager->dispatch('merchant-payment-methods-updated')
            ->assertOk()
            ->assertCanNotSeeTableRecords([$setting])
            ->assertCanSeeTableRecords([$retainedSetting]);
        $this->assertDatabaseMissing('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $method->id]);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $deletedGroup->id, 'method_id' => $method->id]);
        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $other->id, 'payment_method_id' => $method->id]);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $otherGroup->id, 'method_id' => $method->id]);
        $this->assertModelMissing($setting);
        $this->assertSame('3.0000', $otherSetting->fresh()->fee_percent);
        $this->assertSame('4.0000', $retainedSetting->fresh()->fee_percent);

        $page->call('setPaymentMethodAuthorization', $method->id, true)->assertOk();

        $newSetting = MerchantPaymentSetting::query()->where('merchant_id', $merchant->id)->where('payment_method_id', $method->id)->sole();
        $this->assertSame('9.0000', $newSetting->fee_percent);
        $this->assertSame('shipped', $newSetting->settlement_mode);
        $this->assertSame(21, $newSetting->settlement_days);
        $newSetting->update(['fee_percent' => '5', 'settlement_days' => 3]);

        $page->call('setPaymentMethodAuthorization', $method->id, true)->assertOk();

        $this->assertDatabaseCount('merchant_payment_methods', 3);
        $this->assertDatabaseCount('merchant_payment_settings', 3);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $deletedGroup->id, 'method_id' => $method->id]);
        $this->assertSame('5.0000', $newSetting->fresh()->fee_percent);
        $this->assertSame(3, $newSetting->fresh()->settlement_days);
    }

    public function test_repeated_revocation_removes_leftover_configuration_even_when_the_assignment_is_already_absent(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $method = $this->method('leftover-channel', $admin);
        $method->assignedMerchants()->attach($merchant);
        $method->assignedMerchants()->detach($merchant);
        $setting = MerchantPaymentSetting::query()->sole();
        $group = $this->group($merchant, 'leftover-group');
        $group->paymentMethods()->attach($method);

        Livewire::actingAs($admin)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods')
            ->call('setPaymentMethodAuthorization', $method->id, false)
            ->call('setPaymentMethodAuthorization', $method->id, false)
            ->assertOk();

        $this->assertModelMissing($setting);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
    }

    public function test_channel_names_are_escaped_and_gateway_credentials_are_not_rendered_in_the_modal(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $method = $this->method('escaped-channel', $admin, [
            'method_name' => '<img src=x onerror=alert(1)>',
            'domain_client_id' => 'private-client-id',
            'domain_client_sk' => 'private-client-secret',
        ]);

        Livewire::actingAs($admin)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods')
            ->assertMountedActionModalSee($method->method_name)
            ->assertMountedActionModalDontSeeHtml($method->method_name)
            ->assertMountedActionModalDontSee('private-client-id')
            ->assertMountedActionModalDontSee('private-client-secret');
    }

    #[TestWith([true], 'super administrator')]
    #[TestWith([false], 'merchant manager')]
    public function test_authorization_modal_lists_the_merchants_administrators_channels_and_merchant_owned_channels(bool $isSuperAdmin): void
    {
        $owner = $this->manager();
        $actor = $isSuperAdmin ? $this->administrator() : $owner;
        $merchant = $this->merchant(['owner_id' => $owner->id]);
        $otherOwner = $isSuperAdmin ? $actor : $this->administrator();
        $own = $this->method('own-channel', $owner);
        $ownAssigned = $this->method('own-assigned-channel', $owner);
        $ownAssigned->assignedMerchants()->attach($merchant);
        $externalAssigned = $this->method('external-assigned-channel', $otherOwner);
        $externalAssigned->assignedMerchants()->attach($merchant);
        $externalHidden = $this->method('external-hidden-channel', $otherOwner);
        $unowned = $this->method('unowned-channel', $owner);
        PaymentMethod::query()->whereKey($unowned)->update(['owner_id' => null]);
        $merchantOwned = $this->method('merchant-owned-channel', $owner, ['merchant_id' => $merchant->id]);
        $otherMerchant = $this->merchant(['name' => 'Other Merchant']);
        $foreignOwned = $this->method('foreign-merchant-owned-channel', $owner, ['merchant_id' => $otherMerchant->id]);
        $deleted = $this->method('deleted-channel', $owner);
        $deleted->delete();

        Livewire::actingAs($actor)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods')
            ->assertMountedActionModalSee($own->method_name)
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$own->id}, true)")
            ->assertMountedActionModalSee($ownAssigned->method_name)
            ->assertMountedActionModalSeeHtml("setPaymentMethodAuthorization({$ownAssigned->id}, false)")
            ->assertMountedActionModalDontSee($externalAssigned->method_name)
            ->assertMountedActionModalDontSee($externalHidden->method_name)
            ->assertMountedActionModalDontSee($unowned->method_name)
            ->assertMountedActionModalSee($merchantOwned->method_name)
            ->assertMountedActionModalSee(__('admin.merchant.payment_authorization.merchant_owned'))
            ->assertMountedActionModalDontSeeHtml("setPaymentMethodAuthorization({$merchantOwned->id}, true)")
            ->assertMountedActionModalDontSeeHtml("setPaymentMethodAuthorization({$merchantOwned->id}, false)")
            ->assertMountedActionModalDontSee($foreignOwned->method_name)
            ->assertMountedActionModalDontSee($deleted->method_name)
            ->call('setPaymentMethodAuthorization', $own->id, true)
            ->assertOk();

        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $externalAssigned->id]);
        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $own->id]);
        $this->assertDatabaseMissing('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $merchantOwned->id]);
        $this->assertTrue(app(MerchantPaymentMethodAssignmentService::class)->methods($actor, $merchant)->find($merchantOwned->id)->is_assigned);
    }

    #[TestWith([true, true], 'super administrator grants an external channel')]
    #[TestWith([true, false], 'super administrator revokes an external channel')]
    #[TestWith([false, true], 'merchant manager grants an external channel')]
    #[TestWith([false, false], 'merchant manager revokes an external channel')]
    public function test_administrator_cannot_change_external_channel_authorization_even_when_already_assigned(bool $isSuperAdmin, bool $authorized): void
    {
        $owner = $this->manager();
        $actor = $isSuperAdmin ? $this->administrator() : $owner;
        $merchant = $this->merchant(['owner_id' => $owner->id]);
        $method = $this->method('external-channel', $isSuperAdmin ? $actor : $this->administrator());
        $method->assignedMerchants()->attach($merchant);

        Livewire::actingAs($actor)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->call('setPaymentMethodAuthorization', $method->id, $authorized)
            ->assertForbidden();

        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $method->id]);
    }

    public function test_manager_without_channel_permission_cannot_mount_or_directly_execute_authorization(): void
    {
        $manager = $this->manager();
        $manager->revokePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $method = $this->method('permission-channel', $manager);

        Livewire::actingAs($manager)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->assertActionHidden('authorizePaymentMethods')
            ->mountAction('authorizePaymentMethods')
            ->assertActionNotMounted()
            ->call('setPaymentMethodAuthorization', $method->id, true)
            ->assertForbidden();

        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_merchant_account_cannot_authorize_channels_even_with_platform_permissions(): void
    {
        $merchant = $this->merchant();
        $user = User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);
        $user->givePermissionTo([Permissions::MERCHANTS_MANAGE, Permissions::PAYMENT_METHODS_MANAGE]);
        $method = $this->method('tenant-channel', $this->administrator());

        try {
            app(MerchantPaymentMethodAssignmentService::class)->setAssignment($user, $merchant, $method->id, true);
            $this->fail('Merchant accounts must not grant platform channel authorization.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('merchant_payment_methods', 0);
        }
    }

    #[TestWith([false], 'merchant owned channel')]
    #[TestWith([true], 'soft deleted channel')]
    public function test_ineligible_channel_cannot_be_granted_through_shared_channel_authorization(bool $deleted): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $method = $this->method('ineligible-channel', $admin, ['merchant_id' => $deleted ? null : $merchant->id]);
        if ($deleted) {
            $method->delete();
        }

        Livewire::actingAs($admin)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->call('setPaymentMethodAuthorization', $method->id, true)
            ->assertForbidden();

        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_forged_revocation_of_a_merchant_owned_channel_preserves_ownership_groups_and_configuration(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $method = $this->method('fixed-merchant-channel', $admin, ['merchant_id' => $merchant->id]);
        $group = $this->group($merchant, 'fixed-channel-group');
        $group->paymentMethods()->attach($method);
        $setting = MerchantPaymentSetting::create([
            'merchant_id' => $merchant->id, 'payment_method_id' => $method->id,
            'fee_percent' => '1.25', 'settlement_days' => 14,
        ]);

        Livewire::actingAs($admin)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods')
            ->call('setPaymentMethodAuthorization', $method->id, false)
            ->assertForbidden();

        $this->assertSame($merchant->id, $method->fresh()->merchant_id);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
        $this->assertSame('1.2500', $setting->fresh()->fee_percent);
        $this->assertSame(14, $setting->fresh()->settlement_days);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_previous_merchant_owner_cannot_authorize_from_an_open_page_after_transfer(): void
    {
        $manager = $this->manager();
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $method = $this->method('transferred-merchant-channel', $manager);
        $page = Livewire::actingAs($manager)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods');
        $merchant->update(['owner_id' => null]);

        $page->call('setPaymentMethodAuthorization', $method->id, true)->assertForbidden();

        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_super_administrator_uses_the_merchants_new_owner_after_transfer_from_an_open_dialog(): void
    {
        $admin = $this->administrator();
        $previousOwner = $this->manager();
        $newOwner = $this->manager();
        $merchant = $this->merchant(['owner_id' => $previousOwner->id]);
        $previousMethod = $this->method('previous-owner-channel', $previousOwner);
        $newMethod = $this->method('new-owner-channel', $newOwner);
        $previousMethod->assignedMerchants()->attach($merchant);
        $page = Livewire::actingAs($admin)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods')
            ->assertMountedActionModalSee($previousMethod->method_name)
            ->assertMountedActionModalDontSee($newMethod->method_name);
        $merchant->update(['owner_id' => $newOwner->id]);

        $page->call('$refresh')
            ->assertOk()
            ->assertActionMounted('authorizePaymentMethods')
            ->assertMountedActionModalSee($newMethod->method_name)
            ->assertMountedActionModalDontSee($previousMethod->method_name)
            ->call('setPaymentMethodAuthorization', $newMethod->id, true)
            ->assertOk()
            ->call('setPaymentMethodAuthorization', $previousMethod->id, false)
            ->assertForbidden();

        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $previousMethod->id]);
        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $newMethod->id]);
    }

    #[TestWith([true], 'super administrator')]
    #[TestWith([false], 'merchant manager')]
    public function test_previous_channel_owner_cannot_revoke_from_an_open_page_after_channel_transfer(bool $isSuperAdmin): void
    {
        $actor = $isSuperAdmin ? $this->administrator() : $this->manager();
        $merchant = $this->merchant(['owner_id' => $actor->id]);
        $method = $this->method('transferred-channel', $actor);
        $method->assignedMerchants()->attach($merchant);
        $page = Livewire::actingAs($actor)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods');
        $method->owner_id = $this->administrator()->id;
        $method->save();

        $page->call('setPaymentMethodAuthorization', $method->id, false)->assertForbidden();

        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $method->id]);
    }

    #[TestWith(['disabled'], 'account disabled')]
    #[TestWith(['merchants'], 'merchant management permission revoked')]
    #[TestWith(['channels'], 'channel management permission revoked')]
    public function test_authorization_rechecks_operator_access_after_the_dialog_is_opened(string $change): void
    {
        $manager = $this->manager();
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $method = $this->method('stale-access-channel', $manager);
        $page = Livewire::actingAs($manager)
            ->test(EditMerchant::class, ['record' => $merchant->id])
            ->mountAction('authorizePaymentMethods');
        if ($change === 'disabled') {
            User::query()->whereKey($manager)->update(['status' => false]);
        } else {
            $manager->revokePermissionTo($change === 'merchants' ? Permissions::MERCHANTS_MANAGE : Permissions::PAYMENT_METHODS_MANAGE);
        }

        $page->call('setPaymentMethodAuthorization', $method->id, true)->assertForbidden();

        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    /** @param array<string, mixed> $attributes */
    private function merchant(array $attributes = []): Merchant
    {
        return Merchant::create(['name' => 'Test Merchant', 'status' => true, ...$attributes]);
    }

    private function administrator(): User
    {
        return User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->givePermissionTo([Permissions::MERCHANTS_MANAGE, Permissions::PAYMENT_METHODS_MANAGE]);

        return $manager;
    }

    /** @param array<string, mixed> $attributes */
    private function method(string $code, User $owner, array $attributes = []): PaymentMethod
    {
        $method = new PaymentMethod([
            'merchant_id' => null, 'method_code' => $code, 'method_name' => $code,
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test',
            'product_match_mode' => 'MATCH', 'is_active' => true, ...$attributes,
        ]);
        $method->owner_id = $owner->id;
        $method->save();

        return $method;
    }

    private function group(Merchant $merchant, string $key): PaymentGroup
    {
        return PaymentGroup::create(['merchant_id' => $merchant->id, 'group_key' => $key, 'group_name' => $key]);
    }
}
