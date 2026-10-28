<?php

namespace Tests\Feature;

use App\Exceptions\MinimumAmountNotMetException;
use App\Models\PaymentGroup;
use App\Services\BalanceService;
use App\Services\OrderCreationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class SettlementAmountFloorTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
        $this->makePaymentMethod('floor')->update([
            'fee_percent' => '2.50',
            'fee_fixed' => '0.30',
        ]);
        PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'default',
            'group_name' => 'Default',
            'is_active' => true,
        ]);
    }

    public function test_money_helper_floors_usd_to_whole_units(): void
    {
        $this->assertSame('97.00', Money::floorUsdToUnit('97.89'));
        $this->assertSame('97.00', Money::floorUsdToUnit('97.00'));
        $this->assertSame('0.00', Money::floorUsdToUnit('0.99'));
    }

    public function test_new_orders_store_settlement_amount_floored_to_usd_units(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);

        // 100.00 × 2.5% = 2.50 + 0.30 = 2.80 → 97.20 → floor 97.00
        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('100.00', 'FLOOR-100'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->assertSame('100.00', $order->converted_amount);
        $this->assertSame('2.50', $order->fee_percent_amount);
        $this->assertSame('0.30', $order->fee_fixed_amount);
        $this->assertSame('97.00', $order->settlement_amount);

        app(BalanceService::class)->creditForPaidOrder($order->fresh());
        $this->assertSame('97.00', (string) $this->merchant->refresh()->balance);
    }

    public function test_orders_with_net_settlement_below_one_usd_are_rejected(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);

        $this->expectException(MinimumAmountNotMetException::class);

        // 0.80 − 2.5% − 0.30 → 不足 1 元，向下取整后为 0，拒单
        app(OrderCreationService::class)->createOrder(
            $this->payload('0.80', 'FLOOR-TINY'),
            $this->merchant,
            $this->application,
            'api',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $amount, string $merchantOrderNo): array
    {
        return [
            'merchant_order_no' => $merchantOrderNo,
            'currency' => 'USD',
            'group_key' => 'default',
            'payment_method_key' => 'floor',
            'subtotal' => $amount,
            'discount' => '0.00',
            'shipping_fee' => '0.00',
            'tax' => '0.00',
            'amount' => $amount,
            'customer_first_name' => 'John',
            'customer_last_name' => 'Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => null,
            'shipping_address_line1' => '123 Main St',
            'shipping_city' => 'City',
            'shipping_country' => 'US',
            'shipping_zip' => null,
            'notify_url' => 'https://shop.example.com/notify',
            'return_url' => 'https://shop.example.com/return',
            'cancel_url' => 'https://shop.example.com/cancel',
            'items' => [[
                'product_id' => '1',
                'product_url' => 'https://shop.example.com/product/1',
                'product_name' => 'Product',
                'unit_price' => $amount,
                'quantity' => 1,
            ]],
        ];
    }
}
