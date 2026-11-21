<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\CombinedOrder;
use App\Models\OrderNotificationAttempt;
use App\Models\OrderShipmentSync;
use App\Services\CombinedOrderService;
use App\Services\MerchantShipmentSyncService;
use App\Services\OrderNotificationService;
use App\Services\OrderPaymentStatusService;
use App\Support\ShipmentSyncEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Sscpay\OpenCart\Gateway;
use Sscpay\OpenCart\PaymentState;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\Support\OpenCartRepository;
use Tests\Support\OpenCartRuntime;
use Tests\TestCase;

require_once dirname(__DIR__).'/Support/OpenCartRuntime.php';

class OpenCartV2ContractTest extends TestCase
{
    use CreatesCombinedPayments;
    use RefreshDatabase;

    public static function gateways(): array
    {
        return OpenCartGatewayTest::gateways();
    }

    #[DataProvider('gateways')]
    public function test_the_shipped_client_creates_and_retries_an_actual_api_order_then_consumes_notifications_query_and_shipping(int $major, string $channel, bool $legacy = false): void
    {
        $this->setupCombinedPayments();
        URL::forceScheme('https');
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $registry = OpenCartRuntime::registry($channel, $major);
        $customerIp = $major === 3 ? '198.51.100.25' : '2001:db8::25';
        $registry->get('request')->server += ['REMOTE_ADDR' => $customerIp, 'HTTP_X_FORWARDED_FOR' => '203.0.113.25'];
        if ($legacy) {
            $registry->get('session')->data['payment_method'] = 'sscpay'.$channel;
        }
        $prefix = 'payment_sscpay'.$channel.'_';
        $registry->get('config')->values[$prefix.'app_id'] = $this->application->app_id;
        $registry->get('config')->values[$prefix.'api_key'] = $this->application->api_key;
        $registry->get('model_checkout_order')->order['total'] = '4000.0000';
        $registry->get('db')->price = '4000.0000';
        $repository = new OpenCartRepository;
        $transport = function (string $url, array $headers, string $json): array {
            $parsedHeaders = [];
            foreach ($headers as $header) {
                [$key, $value] = explode(': ', $header, 2);
                $parsedHeaders[$key] = $value;
            }
            $response = $this->withHeaders($parsedHeaders)->postJson(parse_url($url, PHP_URL_PATH), json_decode($json, true));
            $response->assertOk();

            return ['status' => $response->getStatusCode(), 'body' => $response->getContent()];
        };
        $gateway = new Gateway($registry, $major, $channel, $repository, $transport);
        Http::fake([$gateway->url('notify') => Http::response('', 200)]);
        $gateway->confirm();
        $parent = CombinedOrder::sole();
        $this->assertSame($customerIp, $parent->order_snapshot['checkout_customer_ip']);
        $this->assertSame('OC'.$major.'-0-42', $parent->merchant_order_no);
        $this->assertSame('opencart', $parent->platform);
        $gateway->confirm();
        $this->assertSame(1, CombinedOrder::count());
        $payments = app(CombinedOrderService::class);
        while ($parent->refresh()->status !== 'paid') {
            $child = $payments->pay($parent, $payments->quote($parent)['id']);
            $this->assertSame($customerIp, $child->checkout_customer_ip);
            Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/payment-plugin/v1/pay')
                && $request['s_order_id'] === $child->gatewayOrderReference() && $request['customer_ip'] === $customerIp);
            app(OrderPaymentStatusService::class)->handle([
                's_order_id' => $child->gatewayOrderReference(), 'status' => 'paid', 'amount' => $child->amount,
                'currency' => $child->currency, 'paid_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]);
            $attempt = OrderNotificationAttempt::latest('id')->firstOrFail();
            app(OrderNotificationService::class)->attempt($attempt);
            $attempt->refresh();
            $gateway->notification('notify', $attempt->request_payload, $attempt->request_headers);
            $gateway->notification('notify', $attempt->request_payload, $attempt->request_headers);
            $this->assertSame($parent->fresh()->status === 'paid', $repository->find(0, 42)['state']['paid_once']);
        }
        $record = $gateway->query($repository->find(0, 42));
        $this->assertSame('paid', $record['state']['stage']);
        $paidHistories = array_filter($registry->get('model_checkout_order')->histories, fn ($history) => $history['status'] === 2);
        $this->assertCount(1, $paidHistories);
        $payloads = PaymentState::shippingPayloads($record, ['logistics_company' => 'DHL', 'tracking_number' => 'REAL-TRACK', 'shipped_at' => now()->toIso8601String()]);
        $this->assertSame($parent->children()->orderBy('id')->pluck('merchant_order_no')->all(), array_column($payloads, 'merchant_order_no'));
        $endpoint = $gateway->url('shipment');
        $this->application->update(['is_shipment_sync_enabled' => true, 'shipment_sync_url' => $endpoint]);
        Carrier::create(['carrier_code' => 'DHL', 'carrier_name' => 'DHL', 'status' => Carrier::STATUS_ENABLED]);
        $this->mock(ShipmentSyncEndpoint::class)->shouldReceive('options')->with($endpoint)->andReturn([]);
        Http::fake([$endpoint => function (Request $request) use ($gateway) {
            $headers = [];
            foreach (['App-ID', 'Timestamp', 'X-Nonce'] as $header) {
                $headers[$header] = $request->header($header)[0];
            }
            $gateway->notification('shipment', $request->data(), $headers);

            return Http::response(['code' => 0, 'msg' => 'success']);
        }]);
        foreach ($payloads as $payload) {
            $gateway->client($gateway->settings())->request('ship', $payload);
            $sync = OrderShipmentSync::latest('id')->firstOrFail();
            app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);
            $this->assertSame('success', $sync->fresh()->status);
        }
        $this->assertCount(1, $repository->find(0, 42)['state']['shipments']);
        $this->assertSame(2, $registry->get('model_checkout_order')->order['order_status_id']);
    }
}
