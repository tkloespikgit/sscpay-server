<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderSettlementResource\Pages\ListOrderSettlements;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderSettlementResourceTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('merchant');
    }

    public function test_merchant_only_sees_their_own_settlements(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->financeUser();
        $own = $this->settlement($this->makeOrder('own-order', 'own-merchant-order'));
        $other = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other',
            'contact_phone' => '123456',
            'contact_email' => 'other@example.com',
        ]);
        $application = Application::createWithCredentials([
            'merchant_id' => $other->id,
            'name' => 'Other App',
            'website' => 'https://other.example.com',
        ]);
        $hidden = $this->settlement($this->makeOrder('other-order', 'other-merchant-order', [
            'merchant_id' => $other->id,
            'application_id' => $application->id,
        ]));

        Livewire::actingAs($user)
            ->test(ListOrderSettlements::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_finance_permission_is_required_for_the_settlement_list(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);

        Livewire::actingAs($user)->test(ListOrderSettlements::class)->assertForbidden();
    }

    public function test_status_filter_and_actual_settlement_time_are_visible(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->financeUser();
        $settled = $this->settlement($this->makeOrder('settled-order', 'settled-merchant-order'), [
            'status' => 'settled',
            'remaining_amount' => '0.00',
            'available_amount' => '90.00',
            'settled_at' => '2026-10-15 12:00:00',
        ]);
        $pending = $this->settlement($this->makeOrder('pending-order', 'pending-merchant-order'));

        Livewire::actingAs($user)
            ->test(ListOrderSettlements::class)
            ->filterTable('status', 'settled')
            ->assertCanSeeTableRecords([$settled])
            ->assertCanNotSeeTableRecords([$pending])
            ->assertTableColumnFormattedStateSet('status', __('settlement.statuses.settled'), record: $settled)
            ->assertTableColumnStateSet('settled_at', $settled->settled_at, record: $settled);
    }

    private function financeUser(): User
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::FINANCE_VIEW);

        return $user;
    }

    private function settlement(Order $order, array $overrides = []): OrderSettlement
    {
        return OrderSettlement::create([
            'merchant_id' => $order->merchant_id,
            'order_id' => $order->id,
            'amount' => '90.00',
            'remaining_amount' => '90.00',
            'reserve_amount' => '0.00',
            'available_amount' => '0.00',
            'mode' => 'immediate',
            'cycle_days' => 7,
            'reserve_percent' => '10.0000',
            'reserve_days' => 30,
            'status' => 'pending',
            'started_at' => '2026-10-15 12:00:00',
            'due_at' => '2026-10-22 12:00:00',
            ...$overrides,
        ]);
    }
}
