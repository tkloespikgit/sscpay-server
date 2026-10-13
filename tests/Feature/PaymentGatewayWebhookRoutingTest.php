<?php

namespace Tests\Feature;

use App\Services\OrderPaymentStatusService;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class PaymentGatewayWebhookRoutingTest extends TestCase
{
    private const SECRET = 'test-webhook-routing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.api_domain', 'api.example.com');
        config()->set('payment_gateway.webhook_secret', self::SECRET);

        // 域名在注册路由时固定；显式设置配置后重新加载实际 API 路由文件。
        Route::setRoutes(new RouteCollection);
        Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_valid_signed_webhooks_reach_status_service_on_custom_and_api_domains(): void
    {
        $payload = ['event' => 'payment_status', 's_order_id' => 'SSC-TEST', 'status' => 'paid'];
        $raw = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->mock(OrderPaymentStatusService::class, function (MockInterface $mock) use ($payload) {
            $mock->shouldReceive('handle')->with($payload)->twice();
        });

        foreach (['checkout.customer.example', 'api.example.com'] as $host) {
            $this->call('POST', "https://$host/api/webhooks/payment-gateway/status", [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PGA_SIGNATURE' => hash_hmac('sha256', $raw, self::SECRET),
            ], $raw)->assertOk()->assertExactJson(['code' => 0, 'message' => 'ok']);
        }

        $this->assertNull(Route::getRoutes()->getByName('webhooks.payment-gateway.status')->getDomain());
    }

    public function test_custom_domain_webhooks_still_require_a_valid_raw_body_signature(): void
    {
        $this->mock(OrderPaymentStatusService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('handle');
        });
        $raw = '{"event":"payment_status","status":"paid"}';

        foreach ([null, 'invalid', hash_hmac('sha256', $raw.' ', self::SECRET)] as $signature) {
            $headers = ['CONTENT_TYPE' => 'application/json'];
            if ($signature !== null) {
                $headers['HTTP_X_PGA_SIGNATURE'] = $signature;
            }
            $this->call('POST', 'https://checkout.customer.example/api/webhooks/payment-gateway/status', [], [], [], $headers, $raw)
                ->assertUnauthorized()->assertExactJson(['code' => 401, 'message' => 'invalid signature']);
        }
    }

    public function test_merchant_order_routes_remain_limited_to_api_domain_and_authenticated(): void
    {
        foreach (['create', 'query', 'ship'] as $action) {
            $this->postJson("https://checkout.customer.example/api/order/$action", [])->assertNotFound();
            $this->postJson("https://api.example.com/api/order/$action", [])->assertUnauthorized();
        }
    }
}
