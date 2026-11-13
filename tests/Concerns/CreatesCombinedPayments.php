<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\CombinedOrder;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Services\OrderItemService;
use App\Support\SignatureCanonicalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Random\Engine\Mt19937;
use Random\Randomizer;

trait CreatesCombinedPayments
{
    use CreatesTestOrders;

    protected PaymentGroup $group;

    protected function setupCombinedPayments(): void
    {
        $this->createMerchantAndApplication();
        $this->application->update(['is_combine_payment_enabled' => true]);
        $this->group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id, 'group_key' => 'combine',
            'group_name' => 'Combined payments', 'is_active' => true,
        ]);
        config(['payment_gateway.retry_times' => 1]);
        $this->app->instance(Randomizer::class, new Randomizer(new Mt19937(0)));
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function combinedMethod(string $code = 'card', string $limit = '800.00', array $overrides = []): PaymentMethod
    {
        $method = $this->makePaymentMethod($code);
        $method->update(array_merge([
            'max_amount_per_transaction' => $limit, 'payment_config_id' => 11,
        ], $overrides));
        $this->group->paymentMethods()->attach($method->id, ['priority' => 100]);

        return $method;
    }

    protected function combinedParent(array $overrides = []): CombinedOrder
    {
        return CombinedOrder::factory()->forApplicationGroup($this->application, $this->group)->create($overrides);
    }

    protected function createCombined(array $overrides = []): TestResponse
    {
        return $this->signedCombinedRequest('/api/order/create', array_replace([
            'merchant_order_no' => 'COMBINE-001', 'platform' => 'wordpress',
            'currency' => 'USD', 'group_key' => 'combine', 'subtotal' => '4000.00',
            'shipping_fee' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'amount' => '4000.00',
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
            'shipping_address' => ['line1' => '123 Main St', 'city' => 'City', 'country' => 'US'],
            'items' => [[
                'product_id' => 'SOURCE-001', 'product_url' => 'https://shop.example.com/product/1',
                'product_name' => 'Original Product', 'unit_price' => $overrides['subtotal'] ?? '4000.00', 'quantity' => 1,
            ]],
            'notify_url' => 'https://shop.example.com/notify', 'return_url' => 'https://shop.example.com/return',
            'cancel_url' => 'https://shop.example.com/cancel',
        ], $overrides));
    }

    protected function signedCombinedRequest(string $path, array $body, ?Application $application = null): TestResponse
    {
        $application ??= $this->application;
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body['sign'] = SignatureCanonicalizer::sign($application->app_id, $timestamp, $nonce, $body, $application->api_key);

        return $this->withHeaders([
            'App-ID' => $application->app_id, 'Timestamp' => $timestamp, 'X-Nonce' => $nonce,
        ])->postJson($path, $body);
    }

    protected function fakeCombinedGateway(): void
    {
        $this->mock(OrderItemService::class)->shouldReceive('matchItems')->andReturnUsing(
            fn (PaymentMethod $method, string $amount, string $rate): array => [
                'items' => [[
                    'product_sku' => 'REMOTE-001', 'product_id' => '123',
                    'product_url' => 'https://shop.example.com/product/123', 'product_name' => 'Remote product',
                    'product_description' => '', 'unit_price' => $amount, 'quantity' => 1,
                    'total_price' => $amount, 'converted_unit_price' => bcmul($amount, $rate, 2),
                ]],
                'subtotal' => $amount, 'overflow' => '0.00',
            ],
        );
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::response([
                'code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/session', 'wp_order_id' => 123],
            ]),
            'https://shop.example.com/notify' => Http::response('', 200),
        ]);
    }
}
