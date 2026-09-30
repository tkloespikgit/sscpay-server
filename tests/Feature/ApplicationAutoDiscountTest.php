<?php

namespace Tests\Feature;

use App\Exceptions\OrderDetailsConflictException;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\SiteProduct;
use App\Services\OrderCreationService;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ApplicationAutoDiscountTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
        $this->makePaymentMethod('discount')->update(['fee_percent' => '2.00']);
        PaymentGroup::create(['merchant_id' => $this->merchant->id, 'group_key' => 'default', 'group_name' => 'Default', 'is_active' => true]);
    }

    private function data(string $amount): array
    {
        $subtotal = bcadd($amount, '2', 2);

        return [
            'merchant_order_no' => 'AUTO-'.$amount, 'currency' => 'USD',
            'group_key' => 'default', 'payment_method_key' => 'discount',
            'subtotal' => $subtotal, 'discount' => '2.00', 'shipping_fee' => '0.00', 'tax' => '0.00', 'amount' => $amount,
            'customer_first_name' => 'John', 'customer_last_name' => 'Doe', 'customer_email' => 'john@example.com', 'customer_phone' => null,
            'shipping_address_line1' => '123 Main St', 'shipping_city' => 'City', 'shipping_country' => 'US', 'shipping_zip' => null,
            'notify_url' => 'https://shop.example.com/notify', 'return_url' => 'https://shop.example.com/return', 'cancel_url' => 'https://shop.example.com/cancel',
            'items' => [['product_id' => '1', 'product_url' => 'https://shop.example.com/product/1', 'product_name' => 'Product', 'unit_price' => $subtotal, 'quantity' => 1]],
        ];
    }

    public function test_enabled_discount_updates_payment_fees_and_preserves_original_items(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);
        $this->application->update(['is_auto_discount_enabled' => true]);
        foreach (['100.00' => '0.10', '200.00' => '0.10', '200.01' => '0.50', '0.02' => '0.01', '0.01' => '0.00'] as $amount => $maximum) {
            $order = app(OrderCreationService::class)->createOrder($this->data($amount), $this->merchant, $this->application, 'api');
            $this->assertSame($amount, $order->original_amount);
            $this->assertTrue(bccomp($order->auto_discount, $maximum, 2) <= 0);
            $this->assertTrue(bccomp($order->auto_discount, $maximum === '0.00' ? '0.00' : '0.01', 2) >= 0);
            $this->assertSame(bcsub($amount, $order->auto_discount, 2), $order->amount);
            $this->assertSame(bcadd('2', $order->auto_discount, 2), $order->discount);
            $this->assertSame($order->amount, $order->converted_amount);
            $this->assertSame($order->discount, $order->discount_converted);
            $this->assertSame(bcmul($order->amount, '0.02', 2), $order->fee_percent_amount);
            $this->assertSame(bcsub($order->amount, $order->fee_percent_amount, 2), $order->settlement_amount);
            $this->assertSame(bcadd($amount, '2', 2), $order->items()->first()->total_price);
            Http::assertSent(fn ($request) => $request['amount'] == $order->amount && $request['discount_fee'] == $order->discount);
        }
    }

    public function test_auto_discount_combines_with_matching_overflow_on_routed_orders(): void
    {
        $this->application->update(['is_auto_discount_enabled' => true]);
        $method = PaymentMethod::where('method_code', 'discount')->firstOrFail();
        $product = SiteProduct::create([
            'merchant_id' => $this->merchant->id, 'payment_method_id' => $method->id,
            'woo_product_id' => 123, 'name' => 'Matched product', 'product_type' => 'simple',
            'currency' => 'USD', 'price_min' => '16.00', 'price_max' => '16.00',
        ]);
        $product->variations()->create(['woo_variation_id' => 123, 'sku' => 'SKU', 'price' => '16.00', 'currency' => 'USD']);
        $this->mock(PaymentService::class)->shouldReceive('resolvePaymentMethod')->once()
            ->withArgs(fn ($group, $amount) => $group->merchant_id === $this->merchant->id && $amount >= 14.50 && $amount <= 14.59)
            ->andReturn($method);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);
        $data = $this->data('14.60');
        unset($data['payment_method_key']);
        $data['return_url'] = 'https://other.example/return';
        $service = app(OrderCreationService::class);
        $order = $service->createOrder($data, $this->merchant, $this->application, 'api');
        $this->assertSame('32.00', $order->subtotal);
        $this->assertSame(bcadd('17.40', $order->auto_discount, 2), $order->discount);
        $this->assertSame(bcsub('14.60', $order->auto_discount, 2), $order->amount);
        $order->update(['pay_url' => null]);
        $retried = $service->createOrder($data, $this->merchant, $this->application, 'api');
        $this->assertSame($order->discount, $retried->discount);
        $this->assertSame($order->amount, $retried->amount);
        Http::assertSentCount(2);
    }

    public function test_disabled_by_default_and_old_orders_remain_idempotent(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);
        $this->assertFalse($this->application->fresh()->is_auto_discount_enabled);
        $data = $this->data('100.00');
        $service = app(OrderCreationService::class);
        $order = $service->createOrder($data, $this->merchant, $this->application, 'api');
        $this->assertSame('100.00', $order->amount);
        $this->assertSame('0.00', $order->auto_discount);
        $order->update(['original_amount' => null]);
        $this->application->update(['is_auto_discount_enabled' => true]);
        $this->assertSame($order->id, $service->createOrder($data, $this->merchant, $this->application, 'api')->id);
        Http::assertSentCount(1);
    }

    public function test_remote_retry_reuses_discount_even_after_application_setting_changes(): void
    {
        $this->application->update(['is_auto_discount_enabled' => true]);
        Http::fake(['*' => Http::sequence()->push(['code' => 10001, 'message' => 'temporary failure'], 500)->push(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/retry', 'wp_order_id' => 1]])]);
        $service = app(OrderCreationService::class);
        $data = $this->data('100.00');
        try {
            $service->createOrder($data, $this->merchant, $this->application, 'api');
            $this->fail('Expected gateway failure');
        } catch (PaymentGatewayException $e) {
            $order = Order::sole();
        }
        $this->application->update(['is_auto_discount_enabled' => false]);
        $retried = $service->createOrder($data, $this->merchant, $this->application, 'api');
        $this->assertSame($order->amount, $retried->amount);
        $this->assertSame($order->auto_discount, $retried->auto_discount);
        $this->assertSame($order->discount, $retried->discount);
        Http::assertSentCount(2);
        $this->expectException(OrderDetailsConflictException::class);
        $service->createOrder(array_replace($data, ['amount' => $order->amount]), $this->merchant, $this->application, 'api');
    }
}
