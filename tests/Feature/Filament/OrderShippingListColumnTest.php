<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\OrderShipping;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 订单列表「发货状态」列已与物流同步状态合并：显示运单号，颜色表达同步态。
 */
class OrderShippingListColumnTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Filament::setCurrentPanel('admin');
    }

    public function test_shipping_column_uses_sync_status_colors_and_hides_separate_sync_column(): void
    {
        $unshipped = $this->makeOrder('SSC-NONE', 'M-NONE', ['status' => 'paid']);
        $pending = $this->shippedOrder('SSC-PENDING', '1Z-PENDING', OrderShipping::SYNC_STATUS_PENDING);
        $synced = $this->shippedOrder('SSC-SYNCED', '1Z-SYNCED', OrderShipping::SYNC_STATUS_SYNCED);
        $failed = $this->shippedOrder('SSC-FAILED', '1Z-FAILED', OrderShipping::SYNC_STATUS_FAILED);

        $page = Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$unshipped, $pending, $synced, $failed]);

        $table = $page->instance()->getTable();
        $this->assertNull($table->getColumn('shipping.sync_status'));

        $column = $table->getColumn('shipping.tracking_number');
        $this->assertNotNull($column);

        $expectations = [
            [$unshipped, 'gray', null],
            [$pending->fresh(['shipping']), 'gray', '1Z-PENDING'],
            [$synced->fresh(['shipping']), 'success', '1Z-SYNCED'],
            [$failed->fresh(['shipping']), 'danger', '1Z-FAILED'],
        ];

        foreach ($expectations as [$order, $color, $tracking]) {
            $column->record($order);
            $this->assertSame($color, $column->getColor($column->getState()), $order->order_no);
            $this->assertSame($tracking, $column->getState());
        }
    }

    private function shippedOrder(string $orderNo, string $trackingNumber, string $syncStatus): Order
    {
        $order = $this->makeOrder($orderNo, 'M-'.$orderNo, ['status' => 'shipped']);
        $order->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => $trackingNumber,
            'shipped_at' => now(),
            'operator_id' => 0,
            'sync_status' => $syncStatus,
        ]);

        return $order;
    }
}
