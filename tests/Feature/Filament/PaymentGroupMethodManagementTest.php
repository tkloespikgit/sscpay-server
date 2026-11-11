<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentGroupResource\Pages\CreatePaymentGroup;
use App\Filament\Resources\PaymentGroupResource\Pages\EditPaymentGroup;
use App\Filament\Resources\PaymentGroupResource\RelationManagers\PaymentMethodsRelationManager;
use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentGroupMethodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_members_move_between_inline_columns_without_modals_and_group_changes_preserve_merchant_authorization_and_other_groups(): void
    {
        Http::preventStrayRequests();
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'inline-group');
        $otherGroup = $this->group($merchant, 'other-group');
        $owned = $this->method('owned-channel', $merchant);
        $shared = $this->method('shared-channel');
        $shared->owner_id = $admin->id;
        $shared->save();
        $shared->assignedMerchants()->attach($merchant);
        $setting = MerchantPaymentSetting::query()->sole();
        $setting->update(['fee_percent' => '3.25']);
        $group->paymentMethods()->attach($owned, ['priority' => 100]);
        $otherGroup->paymentMethods()->attach($shared, ['priority' => 15]);
        $inactive = $this->method('inactive-channel', $merchant, ['is_active' => false]);
        $foreign = $this->method('foreign-channel', $this->merchant());
        $unassigned = $this->method('unassigned-shared-channel');

        $component = Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->assertOk()
            ->assertSeeHtml("setPaymentMethodMembership({$owned->id}, false)")
            ->assertSeeHtml("setPaymentMethodMembership({$shared->id}, true)")
            ->assertSee($inactive->method_name)
            ->assertDontSee($foreign->method_name)
            ->assertDontSee($unassigned->method_name)
            ->assertActionDoesNotExist(TestAction::make('attach')->table())
            ->assertActionDoesNotExist(TestAction::make('detach')->table($owned))
            ->assertActionDoesNotExist(TestAction::make('edit')->table($owned))
            ->call('setPaymentMethodMembership', $shared->id, true)
            ->assertOk()
            ->assertActionNotMounted()
            ->assertSeeHtml("setPaymentMethodMembership({$shared->id}, false)");

        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $shared->id, 'priority' => 100]);
        $component->call('savePaymentMethodWeight', $shared->id, 37)
            ->call('setPaymentMethodMembership', $shared->id, true)
            ->assertOk();
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $shared->id, 'priority' => 37]);

        $component->call('setPaymentMethodMembership', $shared->id, false)
            ->assertOk()
            ->assertActionNotMounted()
            ->assertSeeHtml("setPaymentMethodMembership({$shared->id}, true)");

        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $shared->id]);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $otherGroup->id, 'method_id' => $shared->id, 'priority' => 15]);
        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $shared->id]);
        $this->assertSame('3.2500', $setting->fresh()->fee_percent);
        Http::assertNothingSent();
    }

    public function test_deleted_group_members_remain_removable_while_deleted_nonmembers_are_hidden(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'deleted-group');
        $member = $this->method('deleted-member', $merchant);
        $nonmember = $this->method('deleted-nonmember', $merchant);
        $group->paymentMethods()->attach($member, ['priority' => 100]);
        $member->delete();
        $nonmember->delete();

        Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->assertOk()
            ->assertSee($member->method_name)
            ->assertSeeHtml("setPaymentMethodMembership({$member->id}, false)")
            ->assertDontSee($nonmember->method_name)
            ->call('setPaymentMethodMembership', $member->id, false)
            ->assertOk()
            ->assertDontSee($member->method_name);

        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $member->id]);
    }

    #[TestWith([1])]
    #[TestWith([2147483647])]
    public function test_inline_weight_accepts_positive_database_integer_boundaries(int $weight): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'weight-group');
        $method = $this->method('weighted-channel', $merchant);
        $group->paymentMethods()->attach($method, ['priority' => 100]);

        Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->call('savePaymentMethodWeight', $method->id, $weight)
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => $weight]);
    }

    #[TestWith([null], 'missing weight')]
    #[TestWith([0], 'zero weight')]
    #[TestWith([-1], 'negative weight')]
    #[TestWith([1.5], 'fractional weight')]
    #[TestWith(['invalid'], 'nonnumeric weight')]
    #[TestWith([2147483648], 'weight exceeds database integer')]
    public function test_invalid_inline_weight_preserves_the_previous_value(mixed $weight): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'invalid-weight-group');
        $method = $this->method('invalid-weight-channel', $merchant);
        $group->paymentMethods()->attach($method, ['priority' => 100]);

        Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->call('savePaymentMethodWeight', $method->id, $weight)
            ->assertHasErrors(['weights.'.$method->id]);

        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 100]);
    }

    public function test_inline_weight_cannot_add_a_nonmember_to_the_group(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'nonmember-weight-group');
        $method = $this->method('nonmember-channel', $merchant);

        Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->call('savePaymentMethodWeight', $method->id, 50)
            ->assertForbidden();

        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
    }

    public function test_restoring_the_saved_weight_clears_the_row_validation_error(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'weight-error-recovery');
        $method = $this->method('weight-error-channel', $merchant);
        $group->paymentMethods()->attach($method, ['priority' => 100]);

        Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->call('savePaymentMethodWeight', $method->id, 0)
            ->assertHasErrors(['weights.'.$method->id])
            ->call('savePaymentMethodWeight', $method->id, 100)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 100]);
    }

    #[TestWith([true], 'foreign merchant owned method')]
    #[TestWith([false], 'unassigned shared method')]
    public function test_forged_membership_cannot_add_a_method_unavailable_to_the_group_merchant(bool $foreignMerchant): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'forged-group');
        $method = $this->method('forged-channel', $foreignMerchant ? $this->merchant() : null);

        Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->call('setPaymentMethodMembership', $method->id, true)
            ->assertForbidden();

        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
    }

    #[TestWith([true], 'merchant manager')]
    #[TestWith([false], 'merchant account')]
    public function test_directly_mounted_relation_manager_rejects_a_group_outside_the_operators_merchant_scope(bool $isManager): void
    {
        $merchant = $this->merchant();
        $actor = $isManager ? $this->manager() : User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);
        $actor->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        $merchant->update(['owner_id' => $actor->id]);
        $foreignGroup = $this->group($this->merchant(), 'foreign-group');

        Livewire::actingAs($actor)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $foreignGroup, 'pageClass' => EditPaymentGroup::class])
            ->assertForbidden();
    }

    #[TestWith([true], 'merchant manager')]
    #[TestWith([false], 'merchant account')]
    public function test_authorized_merchant_operators_can_add_channels_to_their_own_group(bool $isManager): void
    {
        $merchant = $this->merchant();
        $actor = $isManager ? $this->manager() : User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);
        $actor->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        $merchant->update(['owner_id' => $actor->id]);
        $group = $this->group($merchant, 'operator-group');
        $method = $this->method('operator-channel', $merchant);

        Livewire::actingAs($actor)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->call('setPaymentMethodMembership', $method->id, true)
            ->assertOk();

        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 100]);
    }

    #[TestWith([true], 'account disabled')]
    #[TestWith([false], 'group management permission revoked')]
    public function test_open_relation_manager_rechecks_operator_access_before_writing(bool $disabled): void
    {
        $manager = $this->manager();
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $group = $this->group($merchant, 'revoked-operator-group');
        $method = $this->method('revoked-operator-channel', $merchant);
        $component = Livewire::actingAs($manager)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->assertOk();
        if ($disabled) {
            User::query()->whereKey($manager)->update(['status' => false]);
        } else {
            $manager->revokePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        }

        $component->call('setPaymentMethodMembership', $method->id, true)->assertForbidden();

        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
    }

    public function test_previous_merchant_owner_cannot_change_membership_from_an_open_component_after_transfer(): void
    {
        $manager = $this->manager();
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $group = $this->group($merchant, 'transferred-merchant-group');
        $method = $this->method('transferred-merchant-channel', $merchant);
        $component = Livewire::actingAs($manager)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class]);
        $merchant->update(['owner_id' => null]);

        $component->call('setPaymentMethodMembership', $method->id, true)->assertForbidden();

        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
    }

    public function test_open_component_rechecks_merchant_scope_after_an_abnormal_database_merchant_change(): void
    {
        $manager = $this->manager();
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $group = $this->group($merchant, 'transferred-group');
        $method = $this->method('transferred-group-channel', $merchant);
        $group->paymentMethods()->attach($method, ['priority' => 100]);
        $component = Livewire::actingAs($manager)
            ->test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class]);
        DB::table('payment_groups')->where('id', $group->id)->update(['merchant_id' => $this->merchant()->id]);

        $component->call('savePaymentMethodWeight', $method->id, 50)->assertForbidden();

        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 100]);
    }

    public function test_saving_the_group_form_preserves_membership_and_weights_saved_through_the_inline_manager(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'independent-form-group');
        $removed = $this->method('removed-channel', $merchant);
        $added = $this->method('added-channel', $merchant);
        $group->paymentMethods()->attach($removed, ['priority' => 100]);
        $page = Livewire::actingAs($admin)
            ->test(EditPaymentGroup::class, ['record' => $group->id])
            ->assertFormFieldDoesNotExist('paymentMethods')
            ->fillForm(['group_name' => 'Updated group name']);
        Livewire::test(PaymentMethodsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditPaymentGroup::class])
            ->call('setPaymentMethodMembership', $removed->id, false)
            ->call('setPaymentMethodMembership', $added->id, true)
            ->call('savePaymentMethodWeight', $added->id, 41)
            ->assertOk();

        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame('Updated group name', $group->fresh()->group_name);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $removed->id]);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $added->id, 'priority' => 41]);
    }

    #[TestWith([true], 'super administrator')]
    #[TestWith([false], 'manager of both merchants')]
    public function test_forged_group_merchant_change_is_rejected_without_changing_the_group_or_its_members(bool $isSuperAdmin): void
    {
        $actor = $isSuperAdmin ? $this->administrator() : $this->manager();
        $merchant = $this->merchant(['owner_id' => $actor->id]);
        $other = $this->merchant(['owner_id' => $actor->id]);
        $group = $this->group($merchant, 'changed-merchant-group');
        $method = $this->method('previous-merchant-channel', $merchant);
        $group->paymentMethods()->attach($method, ['priority' => 75]);

        Livewire::actingAs($actor)
            ->test(EditPaymentGroup::class, ['record' => $group->id])
            ->assertFormFieldDisabled('merchant_id')
            ->fillForm(['group_name' => 'Forged name update'])
            ->set('data.merchant_id', $other->id)
            ->call('save')
            ->assertHasFormErrors(['merchant_id'])
            ->assertNotDispatched('payment-group-updated');

        $this->assertSame($merchant->id, $group->fresh()->merchant_id);
        $this->assertSame('changed-merchant-group', $group->fresh()->group_name);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 75]);
    }

    public function test_model_updates_cannot_transfer_a_payment_group_to_another_merchant(): void
    {
        $merchant = $this->merchant();
        $other = $this->merchant();
        $group = $this->group($merchant, 'model-owned-group');
        $method = $this->method('model-owned-channel', $merchant);
        $group->paymentMethods()->attach($method, ['priority' => 333]);

        try {
            $group->update(['merchant_id' => $other->id, 'group_name' => 'Forbidden transfer']);
            $this->fail('Payment groups must retain the merchant selected at creation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('merchant_id', $exception->errors());
        }

        $this->assertSame($merchant->id, $group->fresh()->merchant_id);
        $this->assertSame('model-owned-group', $group->fresh()->group_name);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 333]);
    }

    public function test_model_updates_with_the_same_merchant_preserve_channel_membership_and_weight(): void
    {
        $merchant = $this->merchant();
        $group = $this->group($merchant, 'unchanged-merchant-group');
        $method = $this->method('unchanged-merchant-channel', $merchant);
        $group->paymentMethods()->attach($method, ['priority' => 333]);

        $group->update(['merchant_id' => $merchant->id, 'group_name' => 'Updated group name']);

        $this->assertSame($merchant->id, $group->fresh()->merchant_id);
        $this->assertSame('Updated group name', $group->fresh()->group_name);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 333]);
    }

    public function test_group_creation_atomically_saves_multiple_selected_channels_with_their_individual_weights(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $method = $this->method('initial-channel', $merchant);
        $shared = $this->method('initial-shared-channel');
        $shared->owner_id = $admin->id;
        $shared->save();
        $shared->assignedMerchants()->attach($merchant);
        $inactive = $this->method('initial-inactive-channel', $merchant, ['is_active' => false]);

        $page = Livewire::actingAs($admin)
            ->test(CreatePaymentGroup::class)
            ->assertFormFieldEnabled('merchant_id')
            ->assertFormFieldExists('paymentMethods')
            ->fillForm($this->creationData($merchant, [
                ['id' => $method->id, 'priority' => 7],
                ['id' => $shared->id, 'priority' => 999],
                ['id' => $inactive->id, 'priority' => 100],
            ]));

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);

        $page->call('create')->assertHasNoFormErrors()->assertRedirect();

        $group = PaymentGroup::query()->sole();
        $this->assertSame($merchant->id, $group->merchant_id);
        $this->assertDatabaseCount('payment_group_methods', 3);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 7]);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $shared->id, 'priority' => 999]);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $inactive->id, 'priority' => 100]);
    }

    public function test_creation_candidates_follow_the_selected_merchant_and_switching_merchant_clears_unsaved_members_and_weights(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $other = $this->merchant();
        $owned = $this->method('creation-owned-channel', $merchant, ['is_active' => false]);
        $shared = $this->method('creation-shared-channel');
        $shared->assignedMerchants()->attach($merchant);
        $foreign = $this->method('creation-foreign-channel', $other);
        $unassigned = $this->method('creation-unassigned-channel');
        $deleted = $this->method('creation-deleted-channel', $merchant);
        $deleted->delete();

        Livewire::actingAs($admin)
            ->test(CreatePaymentGroup::class)
            ->assertDontSee($owned->method_name)
            ->assertDontSee($shared->method_name)
            ->assertDontSee($foreign->method_name)
            ->set('data.merchant_id', $merchant->id)
            ->assertSee($owned->method_name)
            ->assertSee($shared->method_name)
            ->assertDontSee($foreign->method_name)
            ->assertDontSee($unassigned->method_name)
            ->assertDontSee($deleted->method_name)
            ->fillForm(['paymentMethods' => [['id' => $owned->id, 'priority' => 345]]])
            ->set('data.merchant_id', $other->id)
            ->assertSchemaStateSet(['paymentMethods' => []])
            ->assertSee($foreign->method_name)
            ->assertDontSee($owned->method_name)
            ->assertDontSee($shared->method_name);

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);
    }

    #[TestWith(['zero_weight'])]
    #[TestWith(['fractional_weight'])]
    #[TestWith(['oversized_weight'])]
    #[TestWith(['duplicate_method'])]
    #[TestWith(['foreign_method'])]
    #[TestWith(['unassigned_method'])]
    #[TestWith(['deleted_method'])]
    #[TestWith(['missing_id'])]
    #[TestWith(['invalid_id'])]
    #[TestWith(['missing_weight'])]
    public function test_invalid_creation_members_are_rejected_without_leaving_a_group_or_any_membership(string $kind): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $valid = $this->method('creation-valid-channel', $merchant);
        $candidate = $this->method('creation-second-channel', $merchant);
        $invalid = match ($kind) {
            'zero_weight' => ['id' => $candidate->id, 'priority' => 0],
            'fractional_weight' => ['id' => $candidate->id, 'priority' => 1.5],
            'oversized_weight' => ['id' => $candidate->id, 'priority' => 2147483648],
            'duplicate_method' => ['id' => $valid->id, 'priority' => 5],
            'foreign_method' => ['id' => $this->method('creation-foreign-method', $this->merchant())->id, 'priority' => 5],
            'unassigned_method' => ['id' => $this->method('creation-unassigned-method')->id, 'priority' => 5],
            'deleted_method' => ['id' => $candidate->id, 'priority' => 5],
            'missing_id' => ['priority' => 5],
            'invalid_id' => ['id' => 'invalid', 'priority' => 5],
            'missing_weight' => ['id' => $candidate->id],
        };
        if ($kind === 'deleted_method') {
            $candidate->delete();
        }

        Livewire::actingAs($admin)
            ->test(CreatePaymentGroup::class)
            ->fillForm($this->creationData($merchant, [['id' => $valid->id, 'priority' => 31], $invalid]))
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);
    }

    #[TestWith([true], 'disabled account')]
    #[TestWith([false], 'revoked group management permission')]
    public function test_group_creation_rechecks_operator_access_before_persisting_staged_channels(bool $disabled): void
    {
        $manager = $this->manager();
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $method = $this->method('creation-access-channel', $merchant);
        $page = Livewire::actingAs($manager)
            ->test(CreatePaymentGroup::class)
            ->fillForm($this->creationData($merchant, [['id' => $method->id, 'priority' => 25]]));
        if ($disabled) {
            User::query()->whereKey($manager)->update(['status' => false]);
        } else {
            $manager->revokePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        }

        $page->call('create')->assertForbidden();

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);
    }

    public function test_group_creation_rejects_a_merchant_transferred_away_after_channels_were_selected(): void
    {
        $manager = $this->manager();
        $merchant = $this->merchant(['owner_id' => $manager->id]);
        $method = $this->method('creation-transferred-channel', $merchant);
        $page = Livewire::actingAs($manager)
            ->test(CreatePaymentGroup::class)
            ->fillForm($this->creationData($merchant, [['id' => $method->id, 'priority' => 25]]));
        $merchant->update(['owner_id' => null]);

        $page->call('create')->assertHasFormErrors(['merchant_id']);

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);
    }

    #[TestWith([true], 'merchant manager')]
    #[TestWith([false], 'merchant account')]
    public function test_scoped_operators_can_create_a_group_and_staged_members_for_their_own_merchant(bool $isManager): void
    {
        $merchant = $this->merchant();
        $actor = $isManager ? $this->manager() : User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);
        $actor->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        $merchant->update(['owner_id' => $isManager ? $actor->id : null]);
        $method = $this->method('creation-scoped-channel', $merchant);

        Livewire::actingAs($actor)
            ->test(CreatePaymentGroup::class)
            ->fillForm($this->creationData($merchant, [['id' => $method->id, 'priority' => 81]]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $group = PaymentGroup::query()->sole();
        $this->assertSame($merchant->id, $group->merchant_id);
        $this->assertDatabaseHas('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id, 'priority' => 81]);
    }

    public function test_merchant_managers_cannot_select_an_unmanaged_merchant_during_group_creation(): void
    {
        $merchant = $this->merchant();
        $other = $this->merchant();
        $actor = $this->manager();
        $merchant->update(['owner_id' => $actor->id]);
        $foreignMethod = $this->method('creation-forged-merchant-channel', $other);

        Livewire::actingAs($actor)
            ->test(CreatePaymentGroup::class)
            ->fillForm($this->creationData($other, [['id' => $foreignMethod->id, 'priority' => 81]]))
            ->assertDontSee($foreignMethod->method_name)
            ->call('create')
            ->assertHasFormErrors(['merchant_id']);

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);
    }

    public function test_merchant_accounts_cannot_forge_a_different_merchant_during_group_creation(): void
    {
        $merchant = $this->merchant();
        $other = $this->merchant();
        $actor = User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);
        $actor->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        $foreignMethod = $this->method('creation-forged-tenant-channel', $other);

        Livewire::actingAs($actor)
            ->test(CreatePaymentGroup::class)
            ->assertFormFieldDisabled('merchant_id')
            ->fillForm($this->creationData($other, [['id' => $foreignMethod->id, 'priority' => 81]]))
            ->assertDontSee($foreignMethod->method_name)
            ->call('create')
            ->assertForbidden();

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);
    }

    public function test_group_creation_rechecks_shared_channel_authorization_after_selection(): void
    {
        $admin = $this->administrator();
        $merchant = $this->merchant();
        $method = $this->method('creation-revoked-shared-channel');
        $method->assignedMerchants()->attach($merchant);
        $page = Livewire::actingAs($admin)
            ->test(CreatePaymentGroup::class)
            ->fillForm($this->creationData($merchant, [['id' => $method->id, 'priority' => 25]]));
        $method->assignedMerchants()->detach($merchant);

        $page->call('create')->assertHasFormErrors();

        $this->assertDatabaseCount('payment_groups', 0);
        $this->assertDatabaseCount('payment_group_methods', 0);
    }

    private function administrator(): User
    {
        return User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

        return $manager;
    }

    /**
     * @param  array<int, array<string, mixed>>  $methods
     * @return array<string, mixed>
     */
    private function creationData(Merchant $merchant, array $methods): array
    {
        return [
            'merchant_id' => $merchant->id, 'group_key' => 'created-group', 'group_name' => 'Created Group',
            'is_active' => true, 'paymentMethods' => $methods,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function merchant(array $attributes = []): Merchant
    {
        return Merchant::create(['name' => 'Test Merchant', 'status' => true, ...$attributes]);
    }

    private function group(Merchant $merchant, string $key): PaymentGroup
    {
        return PaymentGroup::create(['merchant_id' => $merchant->id, 'group_key' => $key, 'group_name' => $key, 'is_active' => true]);
    }

    /** @param array<string, mixed> $attributes */
    private function method(string $code, ?Merchant $merchant = null, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create([
            'merchant_id' => $merchant?->id, 'method_code' => $code, 'method_name' => $code,
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test',
            'product_match_mode' => 'MATCH', 'is_active' => true, ...$attributes,
        ]);
    }
}
