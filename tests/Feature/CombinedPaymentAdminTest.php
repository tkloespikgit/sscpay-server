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

    public function test_payment_group_form_saves_combined_settings_without_changing_the_application(): void
    {
        $this->group->update(['is_combine_payment_enabled' => false, 'max_combined_amount' => null]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

        Livewire::actingAs($user)->test(EditPaymentGroup::class, ['record' => $this->group->id])
            ->fillForm(['is_combine_payment_enabled' => true, 'max_combined_amount' => '5000.25'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertTrue($this->group->fresh()->is_combine_payment_enabled);
        $this->assertSame('5000.25', $this->group->fresh()->max_combined_amount);
        $this->assertArrayNotHasKey('webhook_version', $this->application->fresh()->getAttributes());
    }

    public function test_new_payment_group_persists_the_combined_switch_and_maximum(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

        Livewire::actingAs($user)->test(CreatePaymentGroup::class)->fillForm([
            'group_name' => 'Combined limit', 'group_key' => 'combined-limit',
            'is_combine_payment_enabled' => true, 'max_combined_amount' => '8000.00',
        ])->call('create')->assertHasNoFormErrors();

        $group = PaymentGroup::query()->where('group_key', 'combined-limit')->sole();
        $this->assertTrue($group->is_combine_payment_enabled);
        $this->assertSame('8000.00', $group->max_combined_amount);
        $this->assertSame($this->merchant->id, $group->merchant_id);
    }

    #[DataProvider('invalidMaximums')]
    public function test_enabled_combined_payments_require_a_valid_maximum(?string $maximum, string $rule): void
    {
        $this->group->update(['is_combine_payment_enabled' => false]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

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
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_GROUPS_MANAGE);

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
}
