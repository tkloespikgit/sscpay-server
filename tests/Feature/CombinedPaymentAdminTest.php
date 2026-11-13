<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\CombinedOrders\CombinedOrderResource;
use App\Filament\Resources\CombinedOrders\Pages\ManageCombinedOrders;
use App\Filament\Resources\OrderResource;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_enabling_combined_payments_on_the_application_saves_v2_webhooks(): void
    {
        $this->application->update(['is_combine_payment_enabled' => false, 'webhook_version' => 1]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::APPLICATIONS_MANAGE);
        Livewire::actingAs($user)->test(EditApplication::class, ['record' => $this->application->id])
            ->fillForm(['is_combine_payment_enabled' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($this->application->fresh()->is_combine_payment_enabled);
        $this->assertSame(2, $this->application->fresh()->webhook_version);
    }
}
