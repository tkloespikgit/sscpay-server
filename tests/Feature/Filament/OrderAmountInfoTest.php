<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\MerchantFundFreeze;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderAmountInfoTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        Filament::setCurrentPanel('admin');
    }

    public function test_merchant_sees_settlement_summary_without_the_rate_and_fee_breakdown(): void
    {
        $order = $this->amountOrder('shipped');
        $merchant = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $merchant->givePermissionTo(Permissions::ORDERS_VIEW);

        $html = Livewire::actingAs($merchant)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->html();

        $this->assertStringContainsString(__('admin.order.settlement_modes.shipped'), $html);
        $this->assertStringContainsString(__('admin.order.fields.original_transaction_amount'), $html);
        $this->assertStringContainsString(__('admin.order.fields.settlement_amount'), $html);
        $this->assertStringContainsString(__('settlement.statuses.settled'), $html);
        $this->assertStringContainsString(__('admin.order.settlement_summary.reserve'), $html);
        $this->assertStringContainsString(__('admin.order.settlement_summary.available'), $html);
        $this->assertStringContainsString('$10.00', $html);
        $this->assertStringContainsString('$87.20', $html);
        $this->assertStringNotContainsString('1.0200', $html);
        $this->assertStringNotContainsString(__('settlement.modes.shipped'), $html);
        foreach ($this->breakdownLabels() as $label) {
            $this->assertStringNotContainsString($label, $html);
        }
        $this->assertEmphasizedAmounts($order);
    }

    #[TestWith(['super_admin'])]
    #[TestWith(['merchant_manager'])]
    public function test_platform_staff_see_exchange_surcharge_and_fees(string $role): void
    {
        $order = $this->amountOrder('immediate');
        $user = $role === 'super_admin'
            ? User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true])
            : $this->merchantManager();

        $html = Livewire::actingAs($user)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->html();

        $this->assertStringContainsString(__('admin.order.settlement_modes.immediate'), $html);
        $this->assertStringContainsString(__('admin.order.fields.original_transaction_amount'), $html);
        $this->assertStringContainsString(__('admin.order.fields.settlement_amount'), $html);
        foreach ($this->breakdownLabels() as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('color:#dc2626', $html);
        $this->assertStringContainsString('1.0200', $html);
        $this->assertStringContainsString('text-decoration:line-through', $html);
        $this->assertStringContainsString('1.0000', $html);
        $this->assertStringNotContainsString(__('admin.order.fields.original_exchange_rate'), $html);
        $this->assertStringContainsString(__('admin.order.settlement_summary.release_at'), $html);
    }

    public function test_pending_settlement_shows_due_time_without_settled_amounts(): void
    {
        $dueAt = Carbon::parse('2026-11-20 15:30:00', (string) config('app.timezone'));
        $order = $this->amountOrder('immediate', OrderSettlement::STATUS_PENDING);
        $user = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);

        $html = Livewire::actingAs($user)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->html();

        $this->assertStringContainsString(__('settlement.statuses.pending'), $html);
        $this->assertStringContainsString(__('admin.order.settlement_summary.due_at'), $html);
        $this->assertStringContainsString($dueAt->format('Y-m-d H:i:s'), $html);
        $this->assertStringNotContainsString(__('admin.order.sections.settlement_summary'), $html);
        $this->assertStringNotContainsString(__('admin.order.settlement_summary.available'), $html);
        $this->assertStringNotContainsString(__('admin.order.settlement_summary.reserve'), $html);
        $this->assertStringNotContainsString('$87.20', $html);
        $this->assertStringNotContainsString('$10.00', $html);
    }

    public function test_unsettled_order_hides_settlement_time_and_summary(): void
    {
        $order = $this->amountOrder('shipped', OrderSettlement::STATUS_WAITING_SHIPMENT);
        $user = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);

        $html = Livewire::actingAs($user)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->html();

        $this->assertStringContainsString(__('settlement.statuses.waiting_shipment'), $html);
        $this->assertStringNotContainsString(__('admin.order.settlement_summary.due_at'), $html);
        $this->assertStringNotContainsString(__('admin.order.sections.settlement_summary'), $html);
        $this->assertStringNotContainsString(__('admin.order.settlement_summary.available'), $html);
        $this->assertStringNotContainsString(__('admin.order.settlement_summary.reserve'), $html);
    }

    public function test_settled_order_without_reserve_hides_release_time(): void
    {
        $order = $this->amountOrder('immediate', withFreeze: false);
        $user = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);

        $html = Livewire::actingAs($user)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->html();

        $this->assertStringContainsString(__('admin.order.sections.settlement_summary'), $html);
        $this->assertStringContainsString(__('admin.order.settlement_summary.available'), $html);
        $this->assertStringContainsString(__('admin.order.settlement_summary.reserve'), $html);
        $this->assertStringContainsString('$87.20', $html);
        $this->assertStringContainsString('$10.00', $html);
        $this->assertStringNotContainsString(__('admin.order.settlement_summary.release_at'), $html);
    }

    public function test_missing_settlement_mode_has_no_mode_label(): void
    {
        $order = $this->amountOrder(null);
        $page = Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()]);
        $entry = $page->instance()->infolist(Schema::make($page->instance())->record($order))
            ->getComponentByStatePath('settlement_mode');

        $this->assertInstanceOf(TextEntry::class, $entry);
        $this->assertNull($entry->formatState(null));
        $this->assertSame(__('admin.order.settlement_modes.immediate'), $entry->formatState('immediate'));
        $this->assertSame(__('admin.order.settlement_modes.shipped'), $entry->formatState('shipped'));
        $this->assertStringNotContainsString(__('admin.order.settlement_modes.immediate'), $page->html());
        $this->assertStringNotContainsString(__('admin.order.settlement_modes.shipped'), $page->html());
    }

    private function amountOrder(
        ?string $mode,
        string $status = OrderSettlement::STATUS_SETTLED,
        bool $withFreeze = true,
    ): Order {
        $order = $this->makeOrder('AMOUNT-'.($mode ?? 'none').'-'.$status.($withFreeze ? '' : '-nofreeze'), 'M-AMOUNT-'.($mode ?? 'none').'-'.$status.($withFreeze ? '' : '-nofreeze'), [
            'settlement_mode' => $mode,
            'settlement_amount' => '97.20',
            'fee_percent_amount' => '2.50',
            'fee_fixed_amount' => '0.30',
            'exchange_rate' => '1.020000',
            'original_exchange_rate' => '1.000000',
            'surcharge_percent' => '2.0000',
            'surcharge_fee' => '2.00',
        ]);
        $settled = $status === OrderSettlement::STATUS_SETTLED;
        $waiting = $status === OrderSettlement::STATUS_WAITING_SHIPMENT;
        OrderSettlement::create([
            'merchant_id' => $order->merchant_id,
            'order_id' => $order->id,
            'amount' => '97.20',
            'remaining_amount' => $settled ? '0.00' : '97.20',
            'mode' => $mode ?? 'immediate',
            'cycle_days' => $waiting ? 7 : 0,
            'reserve_percent' => '0',
            'reserve_days' => 0,
            'status' => $status,
            'reserve_amount' => $settled ? '10.00' : '0.00',
            'available_amount' => $settled ? '87.20' : '0.00',
            'started_at' => $waiting ? null : Carbon::parse('2026-11-13 15:30:00', (string) config('app.timezone')),
            'due_at' => $waiting ? null : Carbon::parse('2026-11-20 15:30:00', (string) config('app.timezone')),
            'settled_at' => $settled ? Carbon::parse('2026-11-18 09:00:00', (string) config('app.timezone')) : null,
        ]);
        if ($withFreeze && $settled) {
            MerchantFundFreeze::create([
                'merchant_id' => $order->merchant_id,
                'order_id' => $order->id,
                'amount' => '10.00',
                'remaining_amount' => '10.00',
                'status' => MerchantFundFreeze::STATUS_FROZEN,
                'reason' => 'rolling reserve',
                'frozen_at' => now(),
                'release_at' => now()->addDays(30),
            ]);
        }

        return $order;
    }

    private function merchantManager(): User
    {
        $manager = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $manager->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->merchant->update(['owner_id' => $manager->id]);

        return $manager;
    }

    /**
     * @return array<string>
     */
    private function breakdownLabels(): array
    {
        return [
            __('admin.order.fields.converted_amount'),
            __('admin.order.fields.exchange_rate'),
            __('admin.order.fields.surcharge_percent'),
            __('admin.order.fields.surcharge_fee'),
            __('admin.order.fields.fee_percent_amount'),
            __('admin.order.fields.fee_fixed_amount'),
        ];
    }

    private function assertEmphasizedAmounts(Order $order): void
    {
        $page = new ViewOrder;
        $schema = $page->infolist(Schema::make($page)->record($order));
        $amount = $schema->getComponentByStatePath('amount');
        $settlement = $schema->getComponentByStatePath('settlement_amount');

        $this->assertInstanceOf(TextEntry::class, $amount);
        $this->assertInstanceOf(TextEntry::class, $settlement);
        $this->assertSame(FontWeight::Bold, $amount->getWeight($amount->getState()));
        $this->assertSame('primary', $amount->getColor($amount->getState()));
        $this->assertSame(FontWeight::Bold, $settlement->getWeight($settlement->getState()));
        $this->assertSame('success', $settlement->getColor($settlement->getState()));
    }
}
