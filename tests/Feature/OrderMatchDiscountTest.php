<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\SiteProduct;
use App\Services\OrderCreationService;
use App\Services\OrderItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderMatchDiscountTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private function product(PaymentMethod $method, string $price): void
    {
        $product = SiteProduct::create([
            'merchant_id' => $this->merchant->id, 'payment_method_id' => $method->id,
            'woo_product_id' => 123, 'name' => 'Original product', 'product_type' => 'simple',
            'currency' => 'USD', 'price_min' => $price, 'price_max' => $price,
        ]);
        $product->variations()->create(['woo_variation_id' => 123, 'sku' => 'SKU', 'price' => $price, 'currency' => 'USD']);
    }

    public function test_original_prices_and_currency_rounding_produce_order_discount(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('match');
        $this->product($method, '16.00');
        foreach ([['16.60', '1', '32.00', '15.40'], ['1.00', '1', '16.00', '15.00'], ['16.00', '1', '16.00', '0.00'], ['10.67', '3', '15.99', '5.32']] as [$target, $rate, $subtotal, $overflow]) {
            $result = app(OrderItemService::class)->matchItems($method, $target, $rate);
            $this->assertSame($subtotal, $result['subtotal']);
            $this->assertSame($overflow, $result['overflow']);
            foreach ($result['items'] as $item) {
                $this->assertSame('Original product', $item['product_name']);
                $this->assertSame('16.00', $item['converted_unit_price']);
                $this->assertSame(bcdiv('16', $rate, 2), $item['unit_price']);
            }
        }
    }

    public function test_capacity_limit_still_rejects_unfulfillable_orders(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('match');
        $this->product($method, '16.00');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('站点商品容量不足');
        app(OrderItemService::class)->matchItems($method, '48.01', '1');
    }

    public function test_retry_replaces_overflow_and_gateway_receives_discount_once(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('match');
        $this->product($method, '16.00');
        $order = $this->makeOrder('MATCH-1', 'MATCH-1', [
            'subtotal' => '16.60', 'discount' => '2.00', 'shipping_fee' => '3.00', 'tax' => '1.00',
            'amount' => '18.60', 'converted_amount' => '18.60',
            'payment_method_id' => $method->id, 'return_url' => 'https://merchant.example/return',
        ]);
        $order->items()->create(['product_name' => 'Merchant item', 'unit_price' => '16.60', 'quantity' => 1, 'total_price' => '16.60', 'converted_unit_price' => '16.60']);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);
        $invoke = new \ReflectionMethod(OrderCreationService::class, 'createRemotePayment');
        foreach ([1, 2] as $attempt) {
            $invoke->invoke(app(OrderCreationService::class), $order->fresh(), $method);
            $order->refresh();
            $this->assertSame('32.00', $order->subtotal);
            $this->assertSame('17.40', $order->discount);
            $this->assertSame('17.40', $order->discount_converted);
            $this->assertSame('0.00', $order->matched_discount);
            $this->assertSame('18.60', $order->amount);
            $this->assertSame('16.60', $order->items()->first()->total_price);
            $this->assertSame('32.00', number_format($order->matchedItems()->sum('total_price'), 2, '.', ''));
        }
        Http::assertSent(fn ($request) => $request['discount_fee'] == 17.4 && $request['subtotal'] == 32 && $request['amount'] == 18.6);
        Http::assertSentCount(2);
    }
}
