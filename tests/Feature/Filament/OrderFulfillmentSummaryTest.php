<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Carrier;
use App\Models\OrderShipmentSync;
use App\Models\OrderShipping;
use App\Models\User;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderFulfillmentSummaryTest extends TestCase
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

    public function test_customer_address_and_tracking_link_render_in_one_block(): void
    {
        Carrier::create([
            'carrier_code' => 'ups',
            'carrier_name' => 'UPS',
            'status' => Carrier::STATUS_ENABLED,
        ]);
        $order = $this->makeOrder('FULFILL-LINK', 'M-FULFILL-LINK', [
            'customer_last_name' => 'Doe',
            'customer_first_name' => 'Jane',
            'customer_phone' => '555',
            'customer_email' => 'jane@example.com',
            'shipping_address_line1' => '1 Main',
            'shipping_address_line2' => 'Apt 2',
            'shipping_city' => 'Austin',
            'shipping_state' => 'TX',
            'shipping_country' => 'US',
            'shipping_zip' => '78701',
        ]);
        OrderShipping::recordShipment($order->id, [
            'merchant_id' => $order->merchant_id,
            'operator_id' => 0,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'tracking_url' => 'https://track.example.com/1Z999',
            'shipped_at' => now(),
        ]);

        $html = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->html();

        $this->assertStringContainsString('order-fulfillment-heading', $html);
        $this->assertStringContainsString(__('admin.order.sections.customer'), $html);
        $this->assertStringContainsString(__('admin.order.sections.shipping_address'), $html);
        $this->assertStringContainsString(__('admin.order.sections.shipping_info'), $html);
        foreach ([
            __('admin.order.fields.customer_last_name') => 'Doe',
            __('admin.order.fields.customer_first_name') => 'Jane',
            __('admin.order.fields.customer_phone') => '555',
            __('admin.order.fields.customer_email') => 'jane@example.com',
            __('admin.order.fields.address') => '1 Main',
            __('admin.order.fields.address_line2') => 'Apt 2',
            __('admin.order.fields.city') => 'Austin',
            __('admin.order.fields.state') => 'TX',
            __('admin.order.fields.country') => 'US',
            __('admin.order.fields.zip') => '78701',
        ] as $label => $value) {
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('order-fulfillment-line', $html);
        $this->assertStringContainsString('fi-color-primary', $html);
        $this->assertStringContainsString('1Z999', $html);
        $this->assertStringContainsString('（UPS）', $html);
        $this->assertStringContainsString('href="https://track.example.com/1Z999"', $html);
        $this->assertStringContainsString(__('admin.order.actions_shipping.view_tracking'), $html);
        $this->assertStringNotContainsString(__('admin.order.sections.tracking_info'), $html);
        $this->assertSame(2, substr_count($html, 'order-fulfillment-rule'));
        $this->assertStringNotContainsString(__('admin.order.fields.tracking_status'), $html);
    }

    public function test_missing_tracking_url_uses_the_17track_query_link(): void
    {
        $order = $this->makeOrder('FULFILL-17', 'M-FULFILL-17');
        OrderShipping::recordShipment($order->id, [
            'merchant_id' => $order->merchant_id,
            'operator_id' => 0,
            'logistics_company' => 'dhl',
            'tracking_number' => 'JD 123',
            'shipped_at' => now(),
        ]);

        $html = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->html();

        $this->assertStringContainsString(
            'href="https://t.17track.net/zh-cn#nums=JD%20123"',
            $html,
        );
        $this->assertStringContainsString('JD 123', $html);
        $this->assertStringContainsString('（dhl）', $html);
        $this->assertStringContainsString(__('admin.order.actions_shipping.view_tracking'), $html);
    }

    public function test_unshipped_order_shows_the_not_shipped_placeholder(): void
    {
        $order = $this->makeOrder('FULFILL-NONE', 'M-FULFILL-NONE');

        $html = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->html();

        $this->assertStringContainsString(__('admin.order.placeholders.not_shipped'), $html);
        $this->assertStringNotContainsString('t.17track.net', $html);
    }

    public function test_enabled_tracking_shows_provider_status_anomaly_and_delivery_time(): void
    {
        $eventAt = now()->subHour()->startOfMinute();
        $flaggedAt = now()->subMinutes(30)->startOfMinute();
        $deliveredAt = now()->subMinutes(10)->startOfMinute();
        $order = $this->makeOrder('FULFILL-TRACK', 'M-FULFILL-TRACK', [
            'logistics_tracking_enabled' => true,
        ]);
        $shipping = OrderShipping::recordShipment($order->id, [
            'merchant_id' => $order->merchant_id,
            'operator_id' => 0,
            'logistics_company' => 'ups',
            'tracking_number' => '1ZTRACK',
            'shipped_at' => now()->subDay(),
        ]);
        $shipping->forceFill([
            'sync_status' => OrderShipping::SYNC_STATUS_FAILED,
            'sync_message' => 'Plugin rejected the shipment',
            'tracking_provider' => SeventeenTrackDriver::NAME,
            'tracking_status' => 'Delivered',
            'tracking_sub_status' => 'Delivered_Other',
            'tracking_last_event_at' => $eventAt,
            'tracking_last_event_desc' => 'Delivered to the front door',
            'tracking_delivered_at' => $deliveredAt,
            'logistics_anomaly' => true,
            'logistics_anomaly_reason' => 'provider_exception',
            'logistics_anomaly_at' => $flaggedAt,
        ])->save();
        OrderShipmentSync::create([
            'merchant_id' => $order->merchant_id,
            'order_id' => $order->id,
            'application_id' => $order->application_id,
            'order_shipping_id' => $shipping->id,
            'payload' => ['tracking_number' => '1ZTRACK'],
            'status' => 'failed',
            'attempt_count' => 2,
            'last_error' => 'Merchant site timed out',
            'synced_at' => null,
        ])->attempts()->create([
            'merchant_id' => $order->merchant_id,
            'attempt_number' => 2,
            'request_url' => 'https://merchant.example.com/shipment',
            'attempted_at' => $flaggedAt,
        ]);

        $html = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->html();

        $this->assertStringContainsString(__('admin.order.sections.tracking_info'), $html);
        $this->assertStringContainsString('17TRACK', $html);
        $this->assertStringContainsString('成功签收（成功签收）', $html);
        $this->assertStringContainsString($eventAt->timezone(config('app.timezone'))->format('Y/m/d H:i:s').'（Delivered to the front door）', $html);
        $this->assertStringContainsString('异常（'.__('admin.order.logistics_anomaly_reasons.provider_exception').'，'.$flaggedAt->timezone(config('app.timezone'))->format('Y/m/d H:i:s').'）', $html);
        $this->assertStringContainsString(__('admin.order.delivery.delivered').'（'.$deliveredAt->timezone(config('app.timezone'))->format('Y/m/d H:i:s').'）', $html);
        $this->assertStringContainsString(__('admin.order.sync_statuses.failed'), $html);
        $this->assertStringContainsString('API', $html);
        $this->assertStringContainsString(__('admin.merchant_shipment_sync.statuses.failed'), $html);
        $this->assertStringContainsString('Merchant site timed out', $html);
        $this->assertStringContainsString($flaggedAt->timezone(config('app.timezone'))->format('Y/m/d H:i:s'), $html);
    }
}
