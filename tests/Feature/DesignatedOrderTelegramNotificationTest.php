<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\TelegramBot;
use App\Services\OrderCreationService;
use App\Services\OrderPaymentStatusService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 验证：payment_method_key 指定渠道下单 -> 建单时发 1 条 Telegram 提醒；
 * 该订单首次变为 paid -> 连发 5 条支付成功提醒。
 */
class DesignatedOrderTelegramNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_designated_order_sends_created_then_five_paid_telegram_messages(): void
    {
        $this->seed(PermissionSeeder::class);

        $domain = 'https://shop.example.com';

        Http::fake([
            '*/wp-json/payment-plugin/v1/pay*' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => ['pay_url' => 'https://pay.example.com/abc', 'wp_order_id' => 1],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $merchant = Merchant::create([
            'name' => 'Test Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);

        TelegramBot::create([
            'merchant_id' => $merchant->id,
            'bot_token' => 'test-bot-token',
            'chat_id' => '111222333',
            'is_enabled' => true,
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

        $paymentMethod = PaymentMethod::create([
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

        $data = [
            'merchant_order_no' => 'TEST-001',
            'currency' => 'USD',
            'group_key' => 'group_default',
            'payment_method_key' => 'test_method',
            'subtotal' => '100.00',
            'shipping_fee' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'amount' => '100.00',
            'customer_first_name' => 'John',
            'customer_last_name' => 'Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => '1234567890',
            'shipping_address_line1' => '123 Main St',
            'shipping_city' => 'City',
            'shipping_country' => 'US',
            'shipping_zip' => '12345',
            'notify_url' => $domain.'/notify',
            'return_url' => $domain.'/return',
            'cancel_url' => $domain.'/cancel',
            'items' => [[
                'product_id' => 'SKU1',
                'product_url' => $domain.'/product/1',
                'product_name' => 'Test Product',
                'unit_price' => '100.00',
                'quantity' => 1,
            ]],
        ];

        $order = app(OrderCreationService::class)->createOrder($data, $merchant, $application, 'api');

        $this->assertSame('test_method', $order->designated_payment_method_key);

        Http::assertSentCount(2); // /pay 建支付单 + 建单提醒各 1 次

        Http::assertSent(function ($request) use ($order) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($request['text'], $order->order_no)
                && str_contains($request['text'], 'john@example.com');
        });

        app(OrderPaymentStatusService::class)->handle([
            's_order_id' => $order->order_no,
            'status' => 'paid',
            'paid_at' => '2026-09-02 10:00:00', // 网关以 UTC 发送
        ]);

        $this->assertSame('2026-09-02 18:00:00', $order->refresh()->paid_at->format('Y-m-d H:i:s'));

        // 订单转 paid 还会触发商户 webhook 通知（notify_url）等其他无关请求，
        // 这里只关心 Telegram 这一路，不对总请求数做强断言。
        $telegramRequests = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'));

        $this->assertCount(6, $telegramRequests);

        $paidMessages = $telegramRequests->filter(
            fn ($pair) => str_contains((string) $pair[0]['text'], 'Payment received')
                || str_contains((string) $pair[0]['text'], '支付成功通知')
        );

        $this->assertCount(5, $paidMessages);
    }
}
