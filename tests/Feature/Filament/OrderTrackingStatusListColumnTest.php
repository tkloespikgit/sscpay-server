<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 订单列表「物流轨迹」列：供应商轨迹文案 + 异常红/有轨迹绿/无轨迹灰；发货列行为不变；
 * 点击独立轨迹弹窗（不进详情）。
 */
class OrderTrackingStatusListColumnTest extends TestCase
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

    public function test_tracking_status_column_colors_and_labels_independent_of_wp_sync(): void
    {
        $unshipped = $this->makeOrder('SSC-NONE', 'M-NONE', ['status' => 'paid']);
        $noTrack = $this->shippedOrder('SSC-GRAY', '1Z-GRAY', [
            'sync_status' => 'synced',
        ]);
        $normal = $this->shippedOrder('SSC-GREEN', '1Z-GREEN', [
            'sync_status' => 'failed',
            'tracking_status' => 'InTransit',
            'tracking_last_event_desc' => 'Arrived at hub',
            'logistics_anomaly' => false,
        ]);
        $anomaly = $this->shippedOrder('SSC-RED', '1Z-RED', [
            'sync_status' => 'synced',
            'tracking_status' => 'InTransit',
            'logistics_anomaly' => true,
            'logistics_anomaly_reason' => LogisticsTrackingSyncService::ANOMALY_STALE,
        ]);

        $page = Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$unshipped, $noTrack, $normal, $anomaly]);

        $table = $page->instance()->getTable();
        $shippingColumn = $table->getColumn('shipping.tracking_number');
        $trackingColumn = $table->getColumn('shipping.tracking_status');
        $this->assertNotNull($shippingColumn);
        $this->assertNotNull($trackingColumn);

        // 发货列仍按 WP sync 着色，不受轨迹异常影响。
        $shippingColumn->record($anomaly->fresh(['shipping']));
        $this->assertSame('success', $shippingColumn->getColor($shippingColumn->getState()));

        $expectations = [
            [$unshipped, 'gray', null],
            [$noTrack->fresh(['shipping']), 'gray', null],
            [$normal->fresh(['shipping']), 'success', 'InTransit'],
            [$anomaly->fresh(['shipping']), 'danger', 'InTransit'],
        ];

        foreach ($expectations as [$order, $color, $status]) {
            $this->assertSame($color, OrderResource::trackingStatusColumnColor($order), $order->order_no);
            $trackingColumn->record($order);
            $this->assertSame($status, $trackingColumn->getState(), $order->order_no);
            $this->assertNull($trackingColumn->getUrl(), $order->order_no);
        }

        $this->assertSame('viewTrackingTrail', OrderResource::viewTrackingTrailAction()->getName());
    }

    public function test_anomaly_reason_is_localized(): void
    {
        $this->assertSame(
            __('admin.order.logistics_anomaly_reasons.no_delivery_15d'),
            OrderResource::formatLogisticsAnomalyReason(LogisticsTrackingSyncService::ANOMALY_NO_DELIVERY)
        );
        $this->assertSame(
            __('admin.order.logistics_anomaly_reasons.stale_5d'),
            OrderResource::formatLogisticsAnomalyReason(LogisticsTrackingSyncService::ANOMALY_STALE)
        );
        $this->assertNull(OrderResource::formatLogisticsAnomalyReason(null));
    }

    /**
     * @param  array<string, mixed>  $shippingAttrs
     */
    private function shippedOrder(string $orderNo, string $trackingNumber, array $shippingAttrs = []): Order
    {
        $order = $this->makeOrder($orderNo, 'M-'.$orderNo, ['status' => 'shipped']);
        $order->shipping()->create(array_merge([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => $trackingNumber,
            'shipped_at' => now(),
            'operator_id' => 0,
            'sync_status' => 'pending',
        ], $shippingAttrs));

        return $order;
    }
}
