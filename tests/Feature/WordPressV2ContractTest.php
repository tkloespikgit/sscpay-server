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
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\TestCase;

class WordPressV2ContractTest extends TestCase
{
    use CreatesCombinedPayments;
    use RefreshDatabase;

    public static function channels(): array
    {
        return ['PayPal' => ['paypal'], 'Credit card' => ['creditcard']];
    }

    #[DataProvider('channels')]
    public function test_actual_server_create_notifications_query_and_shipping_match_the_shipped_plugin(string $channel): void
    {
        $this->setupCombinedPayments();
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $created = $this->createCombined()->assertOk()->json('data');
        $parent = CombinedOrder::sole();
        require_once dirname(__DIR__).'/Support/WordPressRuntime.php';
        $GLOBALS['wordpress_orders'] = $GLOBALS['wordpress_transients'] = $GLOBALS['wordpress_gateways'] = [];
        $GLOBALS['wpdb'] = new \WordPressTestDatabase;
        $prefix = 'SSCPay_'.ucfirst($channel);
        $gateway = (new ReflectionClass($prefix.'_Gateway'))->newInstanceWithoutConstructor();
        $gateway->id = 'sscpay_'.$channel;
        $gateway->settings = ['live_app_id' => $this->application->app_id, 'live_api_key' => $this->application->api_key, 'order_prefix' => 'WC'];
        $GLOBALS['wordpress_gateways'][$gateway->id] = $gateway;
        $storeOrder = new \WC_Order(42, 'sscpay_'.$channel, '4000.00');
        $storeOrder->meta = ['_sscpay_merchant_order_no' => $parent->merchant_order_no, '_sscpay_order_no' => $parent->order_no];
        $storeOrder->save();
        $state = $prefix.'_Order_State';
        $this->pluginCall(fn () => $state::apply($gateway, $storeOrder, $created, 'create'));
        $this->assertSame(0, wc_get_order(42)->get_meta('_sscpay_payment_progress')['version']);
        $payments = app(CombinedOrderService::class);
        $firstEvent = null;
        while ($parent->refresh()->status !== 'paid') {
            $child = $payments->pay($parent, $payments->quote($parent)['id']);
            app(OrderPaymentStatusService::class)->handle([
                's_order_id' => $child->gatewayOrderReference(), 'status' => 'paid',
                'amount' => $child->amount, 'currency' => $child->currency,
                'paid_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]);
            $attempt = OrderNotificationAttempt::latest('id')->firstOrFail();
            app(OrderNotificationService::class)->attempt($attempt);
            $attempt->refresh();
            $firstEvent ??= $attempt;
            $webhook = $prefix.'_Webhook';
            $headers = $attempt->request_headers;
            $result = $this->pluginCall(fn () => $webhook::instance()->process_notification(
                $attempt->request_payload, $headers['App-ID'], $headers['Timestamp'], $headers['X-Nonce'],
            ));
            $this->assertNotInstanceOf(\WP_Error::class, $result);
            $this->assertSame($parent->fresh()->status === 'paid', wc_get_order(42)->is_paid());
        }
        $this->assertSame(1, wc_get_order(42)->paidCalls);
        $query = $this->signedCombinedRequest('/api/order/query', ['order_no' => $parent->order_no, 'merchant_order_no' => $parent->merchant_order_no])->assertOk()->json('data');
        $this->pluginCall(fn () => $state::apply($gateway, wc_get_order(42), $query, 'query'));
        $this->assertSame(1, wc_get_order(42)->paidCalls);
        $this->pluginCall(fn () => $state::apply($gateway, wc_get_order(42), $firstEvent->request_payload, 'query'));
        $this->assertSame($parent->version, wc_get_order(42)->get_meta('_sscpay_payment_progress')['version']);
        $payloads = $state::shipping_payloads(wc_get_order(42), ['logistics_company' => 'DHL', 'tracking_number' => 'REAL-TRACKING', 'shipped_at' => now()->toIso8601String()]);
        $this->assertSame($parent->children()->orderBy('id')->pluck('merchant_order_no')->all(), array_column($payloads, 'merchant_order_no'));
        $endpoint = 'https://shop.example.com/wc-api/sscpay_'.$channel.'_shipment/';
        $this->application->update(['is_shipment_sync_enabled' => true, 'shipment_sync_url' => $endpoint]);
        Carrier::create(['carrier_code' => 'DHL', 'carrier_name' => 'DHL', 'status' => Carrier::STATUS_ENABLED]);
        $this->mock(ShipmentSyncEndpoint::class)->shouldReceive('options')->with($endpoint)->andReturn([]);
        $webhook = $prefix.'_Webhook';
        Http::fake([$endpoint => function (Request $request) use ($webhook) {
            $result = $this->pluginCall(fn () => $webhook::instance()->process_shipment(
                $request->data(), $request->header('App-ID')[0], $request->header('Timestamp')[0], $request->header('X-Nonce')[0],
            ));
            $this->assertNotInstanceOf(\WP_Error::class, $result);

            return Http::response(['code' => 0, 'msg' => 'success']);
        }]);
        foreach ($payloads as $payload) {
            $this->signedCombinedRequest('/api/order/ship', $payload)->assertOk();
            $sync = OrderShipmentSync::latest('id')->firstOrFail();
            app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);
            $this->assertSame('success', $sync->fresh()->status);
        }
        $this->assertCount(1, wc_get_order(42)->get_meta('_sscpay_shipments'));
        $this->assertSame(1, wc_get_order(42)->paidCalls);
    }

    private function pluginCall(callable $callback): mixed
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('translator', new class
        {
            public function get(string $message, mixed $domain = [], ?string $locale = null): string
            {
                return $message;
            }
        });
        Container::setInstance($container);
        try {
            return $callback();
        } finally {
            Container::setInstance($previous);
        }
    }
}
