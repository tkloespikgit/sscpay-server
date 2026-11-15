<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\CombinedOrders\CombinedOrderResource;
use App\Filament\Resources\CombinedOrders\Pages\ManageCombinedOrders;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PaymentGroupResource\Pages\CreatePaymentGroup;
use App\Filament\Resources\PaymentGroupResource\Pages\EditPaymentGroup;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\PaymentGroupMethodService;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\TestCase;

class CombinedPaymentAdminTest extends TestCase
{
    use CreatesCombinedPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCombinedPayments();
        Filament::setCurrentPanel('merchant');
    }

    public function test_parent_list_is_read_only_and_isolated_by_merchant(): void
    {
        $own = $this->combinedParent();
        $child = $this->makeOrder('COMBINED-CHILD', 'COMBINED-CHILD-S001', [
            'combined_order_id' => $own->id, 'combined_sequence' => 1,
        ]);
        $other = $this->combinedParent(['merchant_id' => Merchant::create(['name' => 'Other'])->id]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::COMBINED_ORDERS_VIEW);
        $page = Livewire::actingAs($user)->test(ManageCombinedOrders::class)
            ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
            ->mountAction(TestAction::make('view')->table($own))
            ->assertActionMounted(TestAction::make('view')->table($own));
        $html = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString($child->merchant_order_no, $html);
        $this->assertStringNotContainsString(OrderResource::getUrl('view', ['record' => $child]), $html);
        $this->assertFalse(OrderResource::canView($child));
        $this->assertFalse(CombinedOrderResource::canView($other));
        $this->assertFalse(CombinedOrderResource::canEdit($own));
        $this->assertFalse(CombinedOrderResource::canDelete($own));
        $this->assertFalse(CombinedOrderResource::canCreate());
    }

    public function test_parent_list_requires_its_own_view_permission(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::ORDERS_VIEW);
        Livewire::actingAs($user)->test(ManageCombinedOrders::class)->assertForbidden();
    }

    public function test_parent_list_rejects_guests_and_disabled_accounts_but_allows_super_admins(): void
    {
        Livewire::test(ManageCombinedOrders::class)->assertForbidden();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => false]);
        $user->givePermissionTo(Permissions::COMBINED_ORDERS_VIEW);
        Livewire::actingAs($user)->test(ManageCombinedOrders::class)->assertForbidden();

        Filament::setCurrentPanel('admin');
        $admin = User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
        Livewire::actingAs($admin)->test(ManageCombinedOrders::class)->assertOk();
    }

    public function test_application_form_has_no_combined_switch_or_webhook_version_setting(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::APPLICATIONS_MANAGE);
        Livewire::actingAs($user)->test(EditApplication::class, ['record' => $this->application->id])
            ->assertFormFieldDoesNotExist('is_combine_payment_enabled')
            ->assertFormFieldDoesNotExist('webhook_version')
            ->fillForm(['name' => 'Updated application'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Updated application', $this->application->fresh()->name);
        $this->assertArrayNotHasKey('webhook_version', $this->application->fresh()->getAttributes());
    }

    #[DataProvider('combinedAdministrators')]
    public function test_payment_group_form_saves_combined_settings_without_changing_the_application(bool $isSuperAdmin): void
    {
        $this->group->update(['is_combine_payment_enabled' => false, 'max_combined_amount' => null]);
        $user = $this->combinedAdministrator($isSuperAdmin);

        Livewire::actingAs($user)->test(EditPaymentGroup::class, ['record' => $this->group->id])
            ->assertFormFieldEnabled('is_combine_payment_enabled')
            ->fillForm(['is_combine_payment_enabled' => true, 'max_combined_amount' => '5000.25'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertTrue($this->group->fresh()->is_combine_payment_enabled);
        $this->assertSame('5000.25', $this->group->fresh()->max_combined_amount);
        $this->assertArrayNotHasKey('webhook_version', $this->application->fresh()->getAttributes());
    }

    #[DataProvider('combinedAdministrators')]
    public function test_new_payment_group_persists_the_combined_switch_and_maximum(bool $isSuperAdmin): void
    {
        $user = $this->combinedAdministrator($isSuperAdmin);

        Livewire::actingAs($user)->test(CreatePaymentGroup::class)->fillForm([
            'merchant_id' => $this->merchant->id,
            'group_name' => 'Combined limit', 'group_key' => 'combined-limit',
            'is_combine_payment_enabled' => true, 'max_combined_amount' => '8000.00',
        ])->assertFormFieldEnabled('is_combine_payment_enabled')
            ->call('create')->assertHasNoFormErrors();

        $group = PaymentGroup::query()->where('group_key', 'combined-limit')->sole();
        $this->assertTrue($group->is_combine_payment_enabled);
        $this->assertSame('8000.00', $group->max_combined_amount);
        $this->assertSame($this->merchant->id, $group->merchant_id);
    }

    #[DataProvider('invalidMaximums')]
    public function test_enabled_combined_payments_require_a_valid_maximum(?string $maximum, string $rule): void
    {
        $this->group->update(['is_combine_payment_enabled' => false]);
        $user = $this->combinedAdministrator(true);

        Livewire::actingAs($user)->test(EditPaymentGroup::class, ['record' => $this->group->id])
            ->fillForm(['is_combine_payment_enabled' => true, 'max_combined_amount' => $maximum])
            ->call('save')->assertHasFormErrors(['max_combined_amount' => $rule]);

        $this->assertFalse($this->group->fresh()->is_combine_payment_enabled);
    }

    /** @return array<string, array{?string, string}> */
    public static function invalidMaximums(): array
    {
        return [
            'missing' => [null, 'required'], 'zero' => ['0', 'min'], 'negative' => ['-1', 'min'],
            'fraction precision' => ['4000.001', 'decimal'], 'non numeric' => ['invalid', 'numeric'],
            'storage overflow' => ['10000000000000', 'max'],
        ];
    }

    #[DataProvider('enabledSwitchValues')]
    public function test_group_creation_service_requires_a_maximum_for_all_enabled_switch_representations(bool|int|string $enabled): void
    {
        $user = $this->combinedAdministrator(true);

        try {
            app(PaymentGroupMethodService::class)->createGroup($user, [
                'merchant_id' => $this->merchant->id, 'group_key' => 'missing-limit',
                'group_name' => 'Missing maximum', 'is_combine_payment_enabled' => $enabled,
            ], []);
            $this->fail('Enabling combined payments without a maximum must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('max_combined_amount', $exception->errors());
        }

        $this->assertDatabaseMissing('payment_groups', ['group_key' => 'missing-limit']);
    }

    /** @return array<string, array{bool|int|string}> */
    public static function enabledSwitchValues(): array
    {
        return ['boolean' => [true], 'integer' => [1], 'string' => ['1']];
    }

    public function test_merchant_cannot_enable_combined_payments_by_forging_the_disabled_create_field(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

        Livewire::actingAs($user)->test(CreatePaymentGroup::class)
            ->assertFormFieldDisabled('is_combine_payment_enabled')
            ->fillForm(['group_name' => 'Forbidden combined group', 'group_key' => 'forbidden-combined'])
            ->set('data.is_combine_payment_enabled', true)
            ->set('data.max_combined_amount', '5000.00')
            ->call('create')->assertForbidden();

        $this->assertDatabaseMissing('payment_groups', ['group_key' => 'forbidden-combined']);
    }

    public function test_merchant_cannot_enable_combined_payments_by_forging_the_disabled_edit_field(): void
    {
        $this->group->update(['is_combine_payment_enabled' => false, 'max_combined_amount' => null]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

        Livewire::actingAs($user)->test(EditPaymentGroup::class, ['record' => $this->group->id])
            ->assertFormFieldDisabled('is_combine_payment_enabled')
            ->fillForm(['group_name' => 'Forbidden group update'])
            ->set('data.is_combine_payment_enabled', true)
            ->set('data.max_combined_amount', '5000.00')
            ->call('save')->assertForbidden();

        $this->assertFalse($this->group->fresh()->is_combine_payment_enabled);
        $this->assertNull($this->group->fresh()->max_combined_amount);
        $this->assertSame('Combined payments', $this->group->fresh()->group_name);
    }

    #[DataProvider('enabledSwitchValues')]
    public function test_group_creation_service_rejects_merchant_enablement(bool|int|string $enabled): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

        try {
            app(PaymentGroupMethodService::class)->createGroup($user, [
                'merchant_id' => $this->merchant->id, 'group_key' => 'forbidden-service-combined',
                'group_name' => 'Forbidden combined group', 'is_combine_payment_enabled' => $enabled,
                'max_combined_amount' => '5000.00',
            ], []);
            $this->fail('Merchant accounts must not enable combined payments.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(__('combined.enable_restricted'), $exception->getMessage());
        }

        $this->assertDatabaseMissing('payment_groups', ['group_key' => 'forbidden-service-combined']);
    }

    public function test_merchant_can_preserve_or_disable_existing_combined_payments_but_cannot_reenable_them(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        $page = Livewire::actingAs($user)->test(EditPaymentGroup::class, ['record' => $this->group->id])
            ->fillForm(['group_name' => 'Updated combined group'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($this->group->fresh()->is_combine_payment_enabled);
        $this->assertSame('10000.00', $this->group->fresh()->max_combined_amount);

        $page->fillForm(['is_combine_payment_enabled' => false])
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse($this->group->fresh()->is_combine_payment_enabled);

        $page->assertFormFieldDisabled('is_combine_payment_enabled')
            ->set('data.is_combine_payment_enabled', true)
            ->call('save')->assertForbidden();
        $this->assertFalse($this->group->fresh()->is_combine_payment_enabled);
    }

    public function test_previous_owner_cannot_enable_combined_payments_after_the_merchant_is_transferred(): void
    {
        $this->group->update(['is_combine_payment_enabled' => false]);
        $owner = $this->combinedAdministrator(false);
        $page = Livewire::actingAs($owner)->test(EditPaymentGroup::class, ['record' => $this->group->id])
            ->fillForm(['is_combine_payment_enabled' => true]);
        $this->merchant->update(['owner_id' => null]);

        $page->call('save')->assertForbidden();

        $this->assertFalse($this->group->fresh()->is_combine_payment_enabled);
    }

    public function test_merchant_cannot_restore_combined_payments_from_a_form_opened_before_it_was_disabled(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        $page = Livewire::actingAs($user)->test(EditPaymentGroup::class, ['record' => $this->group->id])
            ->fillForm(['group_name' => 'Stale form update']);
        $this->group->update(['is_combine_payment_enabled' => false]);

        $page->call('save')->assertForbidden();

        $this->assertFalse($this->group->fresh()->is_combine_payment_enabled);
        $this->assertSame('Combined payments', $this->group->fresh()->group_name);
    }

    #[DataProvider('combinedEnablementActors')]
    public function test_combined_enablement_requires_an_active_super_admin_or_the_current_direct_manager(string $kind, bool $allowed): void
    {
        $actor = $kind === 'guest' ? null : User::factory()->create([
            'merchant_id' => in_array($kind, ['merchant', 'merchant_as_owner'], true) ? $this->merchant->id : null,
            'is_super_admin' => in_array($kind, ['super', 'disabled_super'], true),
            'status' => ! in_array($kind, ['disabled_super', 'disabled_owner'], true),
        ]);
        if ($actor !== null && $kind !== 'owner_without_permission') {
            $actor->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        }
        if (in_array($kind, ['owner', 'disabled_owner', 'owner_without_permission', 'merchant_as_owner'], true)) {
            $this->merchant->update(['owner_id' => $actor->id]);
        }

        $this->assertSame($allowed, PaymentGroupMethodService::canEnableCombinedPayments($actor, $this->merchant->fresh()));
    }

    /** @return array<string, array{string, bool}> */
    public static function combinedEnablementActors(): array
    {
        return [
            'super administrator' => ['super', true],
            'direct manager' => ['owner', true],
            'unrelated manager' => ['other_manager', false],
            'merchant operator with group permission' => ['merchant', false],
            'merchant account cannot gain the platform role from ownership' => ['merchant_as_owner', false],
            'disabled super administrator' => ['disabled_super', false],
            'disabled direct manager' => ['disabled_owner', false],
            'direct manager without group permission' => ['owner_without_permission', false],
            'guest' => ['guest', false],
        ];
    }

    /** @return array<string, array{bool}> */
    public static function combinedAdministrators(): array
    {
        return ['super administrator' => [true], 'direct merchant administrator' => [false]];
    }

    private function combinedAdministrator(bool $isSuperAdmin): User
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create(['merchant_id' => null, 'is_super_admin' => $isSuperAdmin, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);
        if (! $isSuperAdmin) {
            $this->merchant->update(['owner_id' => $user->id]);
        }

        return $user;
    }
}
