<?php

namespace Tests\Feature;

use App\Exceptions\NoAvailablePaymentMethodException;
use App\Models\ExchangeRate;
use App\Models\PaymentGroup;
use App\Services\PaymentService;
use App\Services\TelegramNotificationService;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class NoAvailablePaymentMethodTelegramTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    #[DataProvider('channelAvailability')]
    public function test_rejected_creation_reports_available_order_details_without_creating_an_order(bool $riskBlocked): void
    {
        $group = $this->createGroup();
        ExchangeRate::updateBatchRates(['EUR' => 2]);

        if ($riskBlocked) {
            $method = $this->makePaymentMethod('blocked');
            $method->update(['max_amount_per_transaction' => '1.00']);
            $group->paymentMethods()->attach($method->id, ['priority' => 100]);
        }

        $message = '';
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->once()
            ->withArgs(function (int $merchantId, string $text) use (&$message): bool {
                $this->assertSame($this->merchant->id, $merchantId);
                $message = $text;

                return true;
            })->andReturn(true);

        $this->sendOrder('SHOP-<1>&')->assertConflict()
            ->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');

        $this->assertStringContainsString('系统订单号：暂无', $message);
        $this->assertStringContainsString('商户订单号：SHOP-&lt;1&gt;&amp;', $message);
        $this->assertStringNotContainsString('SHOP-<1>&', $message);
        $this->assertStringContainsString('三方交易号：暂无', $message);
        $this->assertStringContainsString('支付方式名称：暂无', $message);
        $this->assertStringContainsString('付款时间：未付款', $message);
        $this->assertStringContainsString('原始交易金额：EUR 100.00', $message);
        $this->assertStringContainsString('200.00', $message);
        $this->assertStringNotContainsString('原始交易金额：USD', $message);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public static function channelAvailability(): array
    {
        return [
            'no active channel' => [false],
            'risk control blocked every channel' => [true],
        ];
    }

    public function test_standalone_routing_without_order_context_uses_localized_empty_details(): void
    {
        $group = $this->createGroup();
        app()->setLocale('en');
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->once()
            ->withArgs(function (int $merchantId, string $message): bool {
                $this->assertSame($this->merchant->id, $merchantId);
                $this->assertStringContainsString('System order: Unavailable', $message);
                $this->assertStringContainsString('Merchant order: Unavailable', $message);
                $this->assertStringContainsString('Third-party transaction: Unavailable', $message);
                $this->assertStringContainsString('Payment method name: Unavailable', $message);
                $this->assertStringContainsString('Paid at: Not paid', $message);
                $this->assertStringContainsString('Original transaction amount: Unavailable Unavailable', $message);
                $this->assertStringContainsString('10.00', $message);
                $this->assertStringNotContainsString('admin.telegram_notification.', $message);

                return true;
            })->andReturn(true);

        $this->expectException(NoAvailablePaymentMethodException::class);

        app(PaymentService::class)->resolvePaymentMethod($group, 10);
    }

    public function test_order_context_does_not_change_per_group_alert_deduplication(): void
    {
        $this->createGroup();
        ExchangeRate::updateBatchRates(['EUR' => 2]);
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->once()
            ->with($this->merchant->id, \Mockery::type('string'))->andReturn(true);

        $this->sendOrder('FIRST-ORDER')->assertConflict()
            ->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');
        $this->sendOrder('SECOND-ORDER')->assertConflict()
            ->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    private function createGroup(): PaymentGroup
    {
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();

        return PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'telegram-routing',
            'group_name' => 'Telegram Routing',
            'is_active' => true,
        ]);
    }

    private function sendOrder(string $merchantOrderNo): TestResponse
    {
        $body = [
            'merchant_order_no' => $merchantOrderNo,
            'platform' => 'wordpress',
            'currency' => 'EUR',
            'group_key' => 'telegram-routing',
            'subtotal' => '100.00',
            'shipping_fee' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'amount' => '100.00',
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
            'shipping_address' => ['line1' => '123 Main St', 'city' => 'City', 'country' => 'US'],
            'items' => [[
                'product_id' => 'PRODUCT-001',
                'product_url' => 'https://shop.example.com/product/1',
                'product_name' => 'Product',
                'unit_price' => '100.00',
                'quantity' => 1,
            ]],
            'notify_url' => 'https://shop.example.com/notify',
            'return_url' => 'https://shop.example.com/return',
            'cancel_url' => 'https://shop.example.com/cancel',
        ];
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body['sign'] = SignatureCanonicalizer::sign($this->application->app_id, $timestamp, $nonce, $body, $this->application->api_key);

        return $this->withHeaders([
            'App-ID' => $this->application->app_id,
            'Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
        ])->postJson('/api/order/create', $body);
    }
}
