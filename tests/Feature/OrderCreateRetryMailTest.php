<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentLinkJob;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Support\SignatureCanonicalizer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCreateRetryMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_after_gateway_failure_queues_payment_link_once(): void
    {
        $this->seed(PermissionSeeder::class);
        Queue::fake([SendPaymentLinkJob::class]);

        $domain = 'https://shop.example.com';
        $merchant = Merchant::create([
            'name' => 'Test Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);
        $application = Application::createWithCredentials([
            'merchant_id' => $merchant->id,
            'name' => 'Test App',
            'website' => $domain,
        ]);
        PaymentGroup::create([
            'merchant_id' => $merchant->id,
            'group_key' => 'group_default',
            'group_name' => 'Default Group',
            'is_active' => true,
        ]);
        $configMap = PaymentMethodConfigMap::create([
            'name' => 'Test Gateway',
            'payment_config_tag' => 'stripe',
            'fields' => [],
        ]);
        PaymentMethod::create([
            'merchant_id' => $merchant->id,
            'method_code' => 'test_method',
            'method_name' => 'Test Method',
            'is_active' => true,
            'config_map_id' => $configMap->id,
            'domain' => $domain,
            'domain_client_id' => 'ck_test',
            'domain_client_sk' => 'cs_test',
            'product_match_mode' => PaymentMethod::MODE_MATCH,
        ]);

        Http::fake([
            '*/wp-json/payment-plugin/v1/pay*' => Http::sequence()
                ->push(['code' => 10001, 'message' => 'temporary failure'], 500)
                ->push(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/abc', 'wp_order_id' => 1]], 200),
        ]);

        $body = [
            'merchant_order_no' => 'RETRY-001',
            'platform' => 'wordpress',
            'currency' => 'USD',
            'group_key' => 'group_default',
            'payment_method_key' => 'test_method',
            'subtotal' => '100.00',
            'shipping_fee' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'amount' => '100.00',
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
            'shipping_address' => ['line1' => '123 Main St', 'city' => 'City', 'country' => 'US'],
            'items' => [[
                'product_id' => 'SKU1',
                'product_url' => $domain.'/product/1',
                'product_name' => 'Test Product',
                'unit_price' => '100.00',
                'quantity' => 1,
            ]],
            'notify_url' => $domain.'/notify',
            'return_url' => $domain.'/return',
            'cancel_url' => $domain.'/cancel',
            'send_mail' => 'Y',
        ];

        $send = function (array $overrides = []) use ($application, $body) {
            $requestBody = array_replace($body, $overrides);
            $timestamp = (string) now()->timestamp;
            $nonce = (string) Str::uuid();
            $signed = $requestBody + [
                'sign' => SignatureCanonicalizer::sign($application->app_id, $timestamp, $nonce, $requestBody, $application->api_key),
            ];

            return $this->withHeaders([
                'App-ID' => $application->app_id,
                'Timestamp' => $timestamp,
                'X-Nonce' => $nonce,
            ])->postJson('/api/order/create', $signed);
        };

        $send()->assertStatus(502);
        Queue::assertNotPushed(SendPaymentLinkJob::class);

        $send(['amount' => '100.01'])->assertStatus(409)->assertJsonPath('error_code', 'ORDER_DETAILS_CONFLICT');
        Http::assertSentCount(1); // 冲突必须在补建远端支付单之前拦截。

        $send()->assertOk()->assertJsonPath('data.pay_url', 'https://pay.example.com/abc');
        Queue::assertPushed(SendPaymentLinkJob::class, 1);

        $send()->assertOk();
        Queue::assertPushed(SendPaymentLinkJob::class, 1);

        // 数值格式不同但金额相同仍命中幂等；任一关键字段变化都不能复用旧链接。
        $send(['amount' => '100'])->assertOk()->assertJsonPath('data.pay_url', 'https://pay.example.com/abc');
        $send(['amount' => '100.01'])->assertStatus(409)->assertJsonPath('error_code', 'ORDER_DETAILS_CONFLICT');
        $send(['currency' => 'EUR'])->assertStatus(409)->assertJsonPath('error_code', 'ORDER_DETAILS_CONFLICT');
        Queue::assertPushed(SendPaymentLinkJob::class, 1);
        Http::assertSentCount(2);
    }
}
