<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\Order;
use App\Models\OrderShipping;
use App\Services\OrderShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 物流记录的写入语义。order_shippings.shipped_at 是 NOT NULL DEFAULT CURRENT_TIMESTAMP，
 * 任何把它写成 NULL 的路径都会在 MySQL 上炸成 1048——补发/改单是最容易踩到的场景。
 */
class OrderShippingRecordTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->createMerchantAndApplication();

        Carrier::create([
            'carrier_name' => 'UPS',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);

        $this->order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);
    }

    public function test_first_shipment_without_a_shipped_at_falls_back_to_the_database_default(): void
    {
        $shipping = OrderShipping::recordShipment($this->order->id, [
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => null,
            'operator_id' => 1,
        ]);

        $this->assertNotNull($shipping->refresh()->shipped_at);
    }

    /**
     * 这条是线上那个 1048 的回归：详情页「录入物流」在补发/改单时会带着
     * fillForm 的数据重开表单，字段上的 default(now()) 不再生效，
     * 发货时间没回填就会以 null 提交回来。
     */
    public function test_updating_an_existing_shipment_without_a_shipped_at_keeps_the_original(): void
    {
        $shippedAt = now()->subDays(3)->startOfSecond();

        OrderShipping::recordShipment($this->order->id, [
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => $shippedAt,
            'operator_id' => 1,
        ]);

        // 补发/改单：只改承运商，发货时间这次没给
        $shipping = OrderShipping::recordShipment($this->order->id, [
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z888',
            'shipped_at' => null,
            'operator_id' => 1,
        ]);

        $this->assertSame('1Z888', $shipping->tracking_number);
        $this->assertTrue($shippedAt->equalTo($shipping->refresh()->shipped_at));
        $this->assertSame(1, OrderShipping::query()->withoutGlobalScopes()->count());
    }

    public function test_an_explicit_shipped_at_still_overwrites_the_previous_one(): void
    {
        OrderShipping::recordShipment($this->order->id, [
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => now()->subDays(3),
            'operator_id' => 1,
        ]);

        $corrected = now()->subDay()->startOfSecond();

        $shipping = OrderShipping::recordShipment($this->order->id, [
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => $corrected,
            'operator_id' => 1,
        ]);

        $this->assertTrue($corrected->equalTo($shipping->refresh()->shipped_at));
    }

    public function test_service_record_survives_a_null_shipped_at(): void
    {
        app(OrderShippingService::class)->record($this->order, 1, [
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => null,
        ]);

        $this->assertNotNull($this->order->refresh()->shipping->shipped_at);
        $this->assertSame('shipped', $this->order->status);
    }
}
