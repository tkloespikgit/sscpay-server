<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\CombinedOrders\CombinedOrderResource;
use App\Filament\Resources\CombinedOrders\Pages\ManageCombinedOrders;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Permissions;
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
        $other = $this->combinedParent(['merchant_id' => Merchant::create(['name' => 'Other'])->id]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::ORDERS_VIEW);
        Livewire::actingAs($user)->test(ManageCombinedOrders::class)
            ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
            ->mountTableAction('view', $own)->assertSuccessful();
        $this->assertFalse(CombinedOrderResource::canView($other));
        $this->assertFalse(CombinedOrderResource::canEdit($own));
        $this->assertFalse(CombinedOrderResource::canDelete($own));
        $this->assertFalse(CombinedOrderResource::canCreate());
    }

    public function test_parent_list_requires_order_view_permission(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        Livewire::actingAs($user)->test(ManageCombinedOrders::class)->assertForbidden();
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
