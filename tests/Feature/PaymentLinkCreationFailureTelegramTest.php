<?php

namespace Tests\Feature;

use App\Events\PaymentLinkCreationFailed;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\TelegramBot;
use App\Services\OrderCreationService;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;
use Throwable;
use TypeError;

class PaymentLinkCreationFailureTelegramTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const CONSUMER_KEY = 'ck_test_secret';

    private const CONSUMER_SECRET = 'cs_test_secret';

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('zh_CN');
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();
        $this->paymentMethod = $this->makePaymentMethod('failure-test');
        TelegramBot::create([
            'merchant_id' => $this->merchant->id,
            'bot_token' => 'merchant-bot-token', 'chat_id' => '111222333', 'is_enabled' => true,
        ]);
    }

    public function test_business_failure_sends_one_telegram_message_and_rethrows_the_gateway_error(): void
    {
        $order = $this->contextOrder();
        $this->fakePay(Http::response(['code' => 10005, 'message' => 'Invalid state for country'], 422));

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertInstanceOf(PaymentGatewayException::class, $error);
        $this->assertSame('Invalid state for country', $error->getMessage());
        $this->assertSame(10005, $error->pgaCode);
        $this->assertSame(422, $error->httpStatus);
        $request = $this->singleTelegramRequest();
        $this->assertSame('111222333', $request['chat_id']);
        $this->assertSame('HTML', $request['parse_mode']);
        foreach ([$order->order_no, $order->merchant_order_no, '100.00', 'USD',
            'failure-test', 'Invalid state for country', '422', '10005', 'shop.example.com'] as $value) {
            $this->assertStringContainsString($value, $request['text']);
        }
        $this->assertStringContainsString('创建支付链接失败', $request['text']);
        $this->assertNull($order->refresh()->pay_url);
        $this->assertSame('pending', $order->status);
    }

    public function test_non_json_failure_sends_the_original_http_status(): void
    {
        $order = $this->contextOrder();
        $this->fakePay(Http::response('<html>Bad gateway</html>', 502, ['Content-Type' => 'text/html']));

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertInstanceOf(PaymentGatewayException::class, $error);
        $this->assertSame(502, $error->httpStatus);
        $this->assertStringContainsString('非预期的响应格式', $error->getMessage());
        $this->assertStringContainsString('502', $this->singleTelegramRequest()['text']);
    }

    public function test_connection_retry_exhaustion_dispatches_only_one_failure_event(): void
    {
        $order = $this->contextOrder();
        Event::fake([PaymentLinkCreationFailed::class]);
        $attempts = 0;
        Http::fake([
            '*/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$attempts) {
                $attempts++;

                return Http::failedConnection('cURL error 28: connection timed out')($request);
            },
        ]);

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertInstanceOf(PaymentGatewayException::class, $error);
        $this->assertSame(2, $attempts);
        $this->assertSame(0, $error->httpStatus);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 1);
        Event::assertDispatched(PaymentLinkCreationFailed::class, fn ($event) => $event->merchantId === $this->merchant->id
            && str_contains($event->details['error'], 'connection timed out')
            && $event->details['order_no'] === $order->order_no);
    }

    public function test_a_success_response_without_a_payment_url_is_also_notified(): void
    {
        $order = $this->contextOrder();
        $this->fakePay(Http::response(['code' => 0, 'data' => ['wp_order_id' => 123]], 200));

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertInstanceOf(PaymentGatewayException::class, $error);
        $this->assertStringContainsString('pay_url', $error->getMessage());
        $this->assertStringContainsString('pay_url', $this->singleTelegramRequest()['text']);
    }

    public function test_an_invalid_data_type_is_notified_without_replacing_the_type_error(): void
    {
        $order = $this->contextOrder();
        $this->fakePay(Http::response(['code' => 0, 'data' => 'invalid data'], 200));

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertInstanceOf(TypeError::class, $error);
        $this->assertStringContainsString($order->order_no, $this->singleTelegramRequest()['text']);
    }

    public function test_success_neither_dispatches_a_failure_event_nor_sends_a_failure_message(): void
    {
        $order = $this->contextOrder();
        Event::fake([PaymentLinkCreationFailed::class]);
        $this->fakePay(Http::response(['code' => 0, 'data' => [
            'pay_url' => 'https://pay.example.com/checkout', 'wp_order_id' => 123,
        ]], 200));

        $result = $this->client()->createPayment($this->payload($order), $order);

        $this->assertSame('https://pay.example.com/checkout', $result['pay_url']);
        Event::assertNotDispatched(PaymentLinkCreationFailed::class);
        $this->assertNoTelegramRequests();
    }

    public function test_html_in_order_details_and_failure_reason_is_escaped(): void
    {
        $order = $this->contextOrder(['merchant_order_no' => 'M-<b>100</b>&"quoted"']);
        $reason = '<script>alert("bad")</script> & Invalid <state>';
        $this->fakePay(Http::response(['code' => 10005, 'message' => $reason], 400));

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertSame($reason, $error->getMessage());
        $text = $this->singleTelegramRequest()['text'];
        $this->assertStringContainsString('M-&lt;b&gt;100&lt;/b&gt;&amp;&quot;quoted&quot;', $text);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;bad&quot;)&lt;/script&gt; &amp; Invalid &lt;state&gt;', $text);
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringNotContainsString('<b>100</b>', $text);
    }

    public function test_secrets_are_redacted_before_the_event_is_queued_and_the_error_is_truncated(): void
    {
        $order = $this->contextOrder();
        Event::fake([PaymentLinkCreationFailed::class]);
        $key = self::CONSUMER_KEY.'/?& +';
        $secret = self::CONSUMER_SECRET.'/?& +';
        $inlineSecret = 'inline_gateway/secret?& +';
        $reason = 'Request failed https://shop.example.com/pay?consumer_key='.rawurlencode($key)
            .'&consumer_secret='.urlencode($secret).' inline='.rawurlencode($inlineSecret)
            .' raw='.$secret.' '.str_repeat('原始错误', 500);
        $this->fakePay(Http::response(['code' => 10005, 'message' => $reason], 400));
        $payload = $this->payload($order) + ['gateway_config' => ['stripe' => ['secret_key' => $inlineSecret]]];

        $this->failure(fn () => $this->client($key, $secret)->createPayment($payload, $order));

        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 1);
        Event::assertDispatched(PaymentLinkCreationFailed::class, function ($event) use ($order, $key, $secret, $inlineSecret) {
            $this->assertSame(['merchantId', 'details'], array_keys(get_object_vars($event)));
            $this->assertSame($this->merchant->id, $event->merchantId);
            $this->assertSame($order->order_no, $event->details['order_no']);
            $this->assertSame('shop.example.com', $event->details['gateway_host']);
            foreach ($event->details as $field => $value) {
                $this->assertIsString($value);
                $this->assertLessThanOrEqual($field === 'error' ? 1200 : 200, mb_strlen($value));
            }
            $serialized = serialize($event);
            foreach ([$key, $secret, $inlineSecret] as $credential) {
                foreach ([$credential, rawurlencode($credential), urlencode($credential)] as $value) {
                    $this->assertStringNotContainsString($value, $serialized);
                }
            }
            $this->assertStringContainsString('[REDACTED]', $event->details['error']);
            $this->assertArrayNotHasKey('payload', $event->details);
            $this->assertArrayNotHasKey('gateway_config', $event->details);
            $this->assertArrayNotHasKey('response', $event->details);

            return true;
        });
    }

    public function test_shared_channel_failures_only_notify_the_order_merchant(): void
    {
        $this->paymentMethod->update(['merchant_id' => null]);
        $other = Merchant::create([
            'name' => 'Another Merchant', 'contact_person' => 'Other',
            'contact_phone' => '123456', 'contact_email' => 'other@example.com',
        ]);
        TelegramBot::create([
            'merchant_id' => $other->id, 'bot_token' => 'other-bot-token',
            'chat_id' => '999888777', 'is_enabled' => true,
        ]);
        $order = $this->contextOrder();
        $this->fakePay(Http::response(['code' => 10004, 'message' => 'Authorization failed'], 401));

        $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $request = $this->singleTelegramRequest();
        $this->assertSame('111222333', $request['chat_id']);
        $this->assertStringContainsString('/botmerchant-bot-token/', $request->url());
        $this->assertStringNotContainsString('other-bot-token', $request->url());
    }

    public function test_a_telegram_listener_exception_does_not_replace_the_gateway_exception(): void
    {
        $order = $this->contextOrder();
        $this->mock(TelegramNotificationService::class)
            ->shouldReceive('send')->once()->with($this->merchant->id, \Mockery::type('string'))
            ->andThrow(new RuntimeException('Telegram queue or cache unavailable'));
        $this->fakePay(Http::response(['code' => 10005, 'message' => 'Original payment failure'], 400));

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertInstanceOf(PaymentGatewayException::class, $error);
        $this->assertSame('Original payment failure', $error->getMessage());
        $this->assertSame(10005, $error->pgaCode);
        $this->assertSame(400, $error->httpStatus);
    }

    public function test_disabled_telegram_configuration_skips_delivery_and_preserves_the_error(): void
    {
        TelegramBot::query()->forMerchant($this->merchant->id)->firstOrFail()->update(['is_enabled' => false]);
        $order = $this->contextOrder();
        $this->fakePay(Http::response(['code' => 10005, 'message' => 'Payment failed'], 400));

        $error = $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $this->assertSame('Payment failed', $error->getMessage());
        $this->assertNoTelegramRequests();
    }

    public function test_no_context_does_not_resolve_a_merchant_from_a_global_order_number(): void
    {
        $order = $this->contextOrder();
        Event::fake([PaymentLinkCreationFailed::class]);
        $this->fakePay(Http::response(['code' => 10005, 'message' => 'Payment failed'], 400));

        $this->failure(fn () => $this->client()->createPayment($this->payload($order) + ['merchant_id' => $this->merchant->id]));

        Event::assertNotDispatched(PaymentLinkCreationFailed::class);
        $this->assertNoTelegramRequests();
    }

    public function test_mismatched_order_context_does_not_dispatch_a_failure_event(): void
    {
        $order = $this->contextOrder();
        Event::fake([PaymentLinkCreationFailed::class]);
        $this->fakePay(Http::response(['code' => 10005, 'message' => 'Payment failed'], 400));
        $payload = array_replace($this->payload($order), ['s_order_id' => 'ANOTHER-ORDER']);

        $this->failure(fn () => $this->client()->createPayment($payload, $order));

        Event::assertNotDispatched(PaymentLinkCreationFailed::class);
        $this->assertNoTelegramRequests();
    }

    public function test_the_order_creation_entry_passes_context_and_keeps_the_order_for_retry(): void
    {
        $group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id, 'group_key' => 'failure-group',
            'group_name' => 'Failure Group', 'is_active' => true,
        ]);
        $group->paymentMethods()->attach($this->paymentMethod->id, ['priority' => 1]);
        $this->fakePay(Http::response(['code' => 10005, 'message' => 'Link creation rejected'], 400));
        $data = [
            'merchant_order_no' => 'ENTRY-FAILURE', 'currency' => 'USD', 'platform' => 'wordpress',
            'group_key' => 'failure-group', 'subtotal' => '100.00', 'shipping_fee' => '0.00',
            'discount' => '0.00', 'tax' => '0.00', 'amount' => '100.00',
            'customer_first_name' => 'John', 'customer_last_name' => 'Doe',
            'customer_email' => 'john@example.com', 'customer_phone' => '1234567890',
            'shipping_address_line1' => '123 Main St', 'shipping_city' => 'City',
            'shipping_country' => 'US', 'shipping_zip' => '12345',
            'notify_url' => 'https://shop.example.com/notify',
            'return_url' => 'https://shop.example.com/return',
            'cancel_url' => 'https://shop.example.com/cancel',
            'items' => [[
                'product_id' => 'SKU1', 'product_name' => 'Test Product',
                'product_url' => 'https://shop.example.com/product/1', 'unit_price' => '100.00', 'quantity' => 1,
            ]],
        ];

        $error = $this->failure(fn () => app(OrderCreationService::class)->createOrder($data, $this->merchant, $this->application, 'api'));

        $this->assertSame('Link creation rejected', $error->getMessage());
        $order = Order::query()->forMerchant($this->merchant->id)->where('merchant_order_no', 'ENTRY-FAILURE')->sole();
        $this->assertNull($order->pay_url);
        $this->assertSame('pending', $order->status);
        $this->assertStringContainsString($order->order_no, $this->singleTelegramRequest()['text']);
    }

    public function test_english_failure_notification_uses_the_english_translation(): void
    {
        $this->app->setLocale('en');
        $order = $this->contextOrder();
        $this->fakePay(Http::response(['code' => 10005, 'message' => 'Invalid address'], 400));

        $this->failure(fn () => $this->client()->createPayment($this->payload($order), $order));

        $text = $this->singleTelegramRequest()['text'];
        $this->assertStringNotContainsString('admin.telegram_notification.', $text);
        $this->assertStringContainsString('Invalid address', $text);
        $this->assertStringNotContainsString('创建支付链接失败', $text);
        $this->assertStringContainsString($order->order_no, $text);
    }

    private function contextOrder(array $overrides = []): Order
    {
        return $this->makeOrder('SSC-PAY-FAILURE', 'MERCHANT-PAY-FAILURE', array_merge([
            'status' => 'pending', 'source' => 'api',
            'payment_method' => $this->paymentMethod->method_code,
            'payment_method_id' => $this->paymentMethod->id,
        ], $overrides));
    }

    private function client(string $key = self::CONSUMER_KEY, string $secret = self::CONSUMER_SECRET): PaymentGatewayService
    {
        return (new PaymentGatewayService(['retry_times' => 2, 'retry_sleep_ms' => 0]))
            ->withConnection('https://shop.example.com/wp-json/payment-plugin/v1', $key, $secret);
    }

    private function payload(Order $order): array
    {
        return ['s_order_id' => $order->order_no, 'amount' => '100.00', 'currency' => 'USD', 'payment_method' => 'stripe'];
    }

    private function fakePay(mixed $response): void
    {
        Http::fake([
            '*/wp-json/payment-plugin/v1/pay*' => $response,
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $error) {
            return $error;
        }

        $this->fail('创建支付链接失败必须继续抛出原异常。');
    }

    private function singleTelegramRequest(): Request
    {
        $requests = Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.telegram.org/'));
        $this->assertCount(1, $requests);

        return $requests->first()[0];
    }

    private function assertNoTelegramRequests(): void
    {
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.telegram.org/'));
    }
}
