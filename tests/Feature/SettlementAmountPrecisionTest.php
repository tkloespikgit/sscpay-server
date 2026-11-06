<?php

namespace Tests\Feature;

use App\Models\PaymentGroup;
use App\Services\BalanceService;
use App\Services\OrderCreationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class SettlementAmountPrecisionTest extends TestCase
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

    public function test_money_truncates_to_cents_without_rounding(): void
    {
        $this->assertSame('97.23', Money::truncate('97.239'));
        $this->assertSame('97.23', Money::truncate('97.235'));
        $this->assertSame('1.23', Money::truncate('1.239'));
        $this->assertSame('97.20', Money::truncate('97.20'));
        $this->assertSame('0.99', Money::truncate('0.999'));
    }

    public function test_new_orders_keep_two_decimal_net_amount(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);

        // 100.00 × 2.5% = 2.50 + 0.30 = 2.80，净额 97.20，不取整到 97.00
        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('100.00', 'NET-100'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->assertSame('100.00', $order->converted_amount);
        $this->assertSame('2.50', $order->fee_percent_amount);
        $this->assertSame('0.30', $order->fee_fixed_amount);
        $this->assertSame('97.20', $order->settlement_amount);

        app(BalanceService::class)->creditForPaidOrder($order->fresh());
        $this->assertSame('97.20', (string) $this->merchant->refresh()->balance);
    }

    public function test_percentage_fee_discards_thousandths_before_net_amount(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);
        $this->merchant->paymentMethods()->update([
            'fee_percent' => '1.2350',
            'fee_fixed' => '0.00',
        ]);

        // 100.00 × 1.235% = 1.235，舍去千分位后手续费 1.23，净额 98.77，不四舍五入成 1.24
        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('100.00', 'NET-TRUNCATE'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->assertSame('1.23', $order->fee_percent_amount);
        $this->assertSame('0.00', $order->fee_fixed_amount);
        $this->assertSame('98.77', $order->settlement_amount);
    }

    public function test_positive_cent_net_amount_is_accepted(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);

        // 0.80 × 2.5% = 0.02 + 0.30 = 0.32，净额 0.48，不因不足 1 美元拒单
        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('0.80', 'NET-CENTS'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->assertSame('0.02', $order->fee_percent_amount);
        $this->assertSame('0.48', $order->settlement_amount);
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
