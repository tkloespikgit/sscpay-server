<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentLinkJob;
use App\Models\CheckoutLink;
use App\Models\CryptoCheckout;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\SystemConfig;
use App\Services\Checkout\CheckoutLinkOrderService;
use App\Services\ManualOrderService;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class CryptoOrderCreationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_signed_api_crypto_order_uses_raw_fiat_rate_local_payment_page_and_frozen_fee(): void
    {
        $method = $this->arrangeChannel();
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'AUD', 'rate' => '0.650000', 'fetched_at' => now()]);
        SystemConfig::set('exchange.surcharge_type', 'percent');
        SystemConfig::set('exchange.surcharge_percent', '-5');
        Http::preventStrayRequests();
        $response = $this->sendOrder(['currency' => 'AUD']);
        $response->assertOk()->assertJsonPath('data.payment_type', 'crypto')
            ->assertJsonPath('data.crypto_payment.state', 'checking')
            ->assertJsonPath('data.converted_amount', '65.00')->assertJsonPath('data.exchange_rate', '0.650000');
        $order = Order::query()->sole();
        $this->assertSame('http://localhost/payment/'.$order->payment_link_token, $order->pay_url);
        $this->assertSame('100.00', $order->amount);
        $this->assertSame('0.00', $order->settlement_amount);
        $this->assertSame('0.00', $order->surcharge_fee);
        $this->assertSame('0.0000', $order->reserve_percent);
        $this->assertNull($order->settlement_mode);
        $this->assertSame('1.0000', CryptoCheckout::query()->sole()->fee_snapshot['fee_percent']);
        $method->update(['fee_percent' => '9']);
        $this->sendOrder(['currency' => 'AUD'])->assertOk()->assertJsonPath('data.order_no', $order->order_no);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('crypto_checkouts', 1);
        $this->assertDatabaseCount('crypto_payment_requests', 0);
        $this->assertDatabaseCount('order_matched_items', 0);
        Http::assertNothingSent();
    }

    public function test_designated_crypto_channel_uses_application_domain_and_preserves_authorization(): void
    {
        $method = $this->arrangeChannel();
        Http::preventStrayRequests();
        $this->sendOrder(['payment_method_key' => $method->method_code, 'return_url' => 'https://foreign.example/return'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYMENT_METHOD_DOMAIN_MISMATCH');
        $this->assertDatabaseCount('orders', 0);
        $this->sendOrder(['payment_method_key' => $method->method_code])->assertOk()->assertJsonPath('data.payment_type', 'crypto');
        Http::assertNothingSent();
    }

    public function test_disabled_crypto_checkout_cannot_be_selected_by_default_or_designated_route(): void
    {
        $method = $this->arrangeChannel();
        config(['services.tatum.checkout_enabled' => false]);
        Http::preventStrayRequests();
        $this->sendOrder()->assertStatus(409)->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');
        $this->sendOrder(['payment_method_key' => $method->method_code])->assertStatus(422)->assertJsonPath('error_code', 'PAYMENT_METHOD_NOT_AVAILABLE');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_assigned_platform_channel_can_collect_for_merchant_and_revoked_assignment_cannot(): void
    {
        $method = $this->arrangeChannel();
        $method->update(['merchant_id' => null]);
        Http::preventStrayRequests();
        $this->sendOrder()->assertStatus(409);
        $method->assignedMerchants()->attach($this->merchant->id);
        $this->sendOrder()->assertOk();
        $this->assertSame($this->merchant->id, CryptoCheckout::query()->sole()->merchant_id);
        Http::assertNothingSent();
    }

    public function test_manual_order_uses_same_crypto_checkout_and_sends_local_payment_link(): void
    {
        $this->arrangeChannel();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentLinkJob::class]);
        $data = $this->body();
        unset($data['customer'], $data['shipping_address']);
        $data += ['customer_first_name' => 'John', 'customer_last_name' => 'Doe', 'customer_email' => 'john@example.com',
            'customer_phone' => null, 'shipping_address_line1' => '123 Main', 'shipping_city' => 'City', 'shipping_country' => 'US', 'shipping_zip' => null];
        $order = app(ManualOrderService::class)->createOrder($data, $this->merchant, $this->application, 1);
        $this->assertSame('crypto', $order->payment_type);
        $this->assertSame('manual', $order->source);
        $this->assertDatabaseCount('crypto_checkouts', 1);
        Queue::assertPushed(SendPaymentLinkJob::class, fn ($job) => $job->orderId === $order->id);
        Http::assertNothingSent();
    }

    private function arrangeChannel(): PaymentMethod
    {
        $this->createMerchantAndApplication();
        config(['app.url' => 'http://localhost', 'services.tatum.checkout_enabled' => true, 'services.tatum.subscriptions_enabled' => true,
            'services.tatum.history_enabled' => true, 'services.tatum.verification_enabled' => true, 'services.tatum.settlement_enabled' => true]);
        $map = PaymentMethodConfigMap::create(['name' => 'Crypto', 'payment_type' => 'crypto', 'fields' => [], 'is_active' => true]);
        $method = PaymentMethod::create(['merchant_id' => $this->merchant->id, 'method_code' => 'eth', 'method_name' => 'ETH',
            'payment_type' => 'crypto', 'is_active' => true, 'config_map_id' => $map->id, 'config' => CryptoConfigurationTest::publicConfig(),
            'fee_percent' => '1', 'fee_fixed' => '0']);
        $group = PaymentGroup::create(['merchant_id' => $this->merchant->id, 'group_key' => 'crypto', 'group_name' => 'Crypto', 'is_active' => true]);
        $group->paymentMethods()->attach($method->id, ['priority' => 100]);

        return $method;
    }

    public function test_checkout_link_creates_local_crypto_payment_using_server_side_price(): void
    {
        $method = $this->arrangeChannel();
        $group = PaymentGroup::query()->sole();
        $link = CheckoutLink::create([
            'merchant_id' => $this->merchant->id, 'application_id' => $this->application->id, 'payment_group_id' => $group->id,
            'title' => 'Crypto checkout', 'currency' => 'USD', 'amount_mode' => 'fixed', 'fixed_amount' => '100.00',
            'shipping_fee' => '0.00', 'tax' => '0.00', 'discount' => '0.00', 'is_active' => true,
        ]);
        Http::preventStrayRequests();
        $order = app(CheckoutLinkOrderService::class)->createOrder($link, [
            'first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com', 'phone' => '+49 151 1234 5678',
            'address_line1' => 'Alexanderplatz 1', 'city' => 'Berlin', 'state' => 'Berlin', 'country' => 'DE', 'zip' => '10178', 'amount' => '1.00',
        ]);
        $this->assertSame('100.00', $order->amount);
        $this->assertSame('crypto', $order->payment_type);
        $this->assertSame($method->id, $order->payment_method_id);
        $this->assertSame('checkout_link', $order->source);
        $this->assertSame('http://localhost/payment/'.$order->payment_link_token, $order->pay_url);
        $this->assertDatabaseCount('crypto_checkouts', 1);
        $this->assertSame(1, $link->fresh()->orders_count);
        Http::assertNothingSent();
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'merchant_order_no' => 'CRYPTO-001', 'platform' => 'wordpress', 'currency' => 'USD', 'group_key' => 'crypto',
            'subtotal' => '100.00', 'shipping_fee' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'amount' => '100.00',
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
            'shipping_address' => ['line1' => '123 Main St', 'city' => 'City', 'country' => 'US'],
            'items' => [['product_id' => '1', 'product_url' => 'https://shop.example.com/product/1', 'product_name' => 'Product', 'unit_price' => '100.00', 'quantity' => 1]],
            'notify_url' => 'https://shop.example.com/notify', 'return_url' => 'https://shop.example.com/return', 'cancel_url' => 'https://shop.example.com/cancel',
        ], $overrides);
    }

    private function sendOrder(array $overrides = []): TestResponse
    {
        $body = $this->body($overrides);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $signed = $body + ['sign' => SignatureCanonicalizer::sign($this->application->app_id, $timestamp, $nonce, $body, $this->application->api_key)];

        return $this->withHeaders(['App-ID' => $this->application->app_id, 'Timestamp' => $timestamp, 'X-Nonce' => $nonce])->postJson('/api/order/create', $signed);
    }
}
