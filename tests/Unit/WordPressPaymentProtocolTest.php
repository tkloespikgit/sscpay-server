<?php

namespace Tests\Unit;

use App\Support\SignatureCanonicalizer;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WordPressPaymentProtocolTest extends TestCase
{
    private Container $previousContainer;

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('translator', new class
        {
            public function get(string $message, mixed $domain = [], ?string $locale = null): string
            {
                return $message;
            }
        });
        Container::setInstance($container);
        require_once dirname(__DIR__).'/Support/WordPressRuntime.php';
        $GLOBALS['wordpress_orders'] = $GLOBALS['wordpress_options'] = $GLOBALS['wordpress_transients'] = $GLOBALS['wordpress_gateways'] = [];
        $GLOBALS['wpdb'] = new \WordPressTestDatabase;
        $GLOBALS['wordpress_http'] = static fn (): never => throw new \RuntimeException('Unexpected WordPress HTTP request.');
    }

    public static function channels(): array
    {
        return ['PayPal' => ['paypal'], 'Credit card' => ['creditcard']];
    }

    private function gateway(string $channel): object
    {
        $gateway = (new ReflectionClass('SSCPay_'.ucfirst($channel).'_Gateway'))->newInstanceWithoutConstructor();
        $gateway->id = 'sscpay_'.$channel;
        $gateway->settings = ['testmode' => 'no', 'live_app_id' => 'APP_PLUGIN', 'live_api_key' => 'test-plugin-key', 'live_base_url' => 'https://pay.example', 'order_prefix' => 'WC'];
        $GLOBALS['wordpress_gateways'][$gateway->id] = $gateway;

        return $gateway;
    }

    private function order(string $channel): \WC_Order
    {
        $order = new \WC_Order(42, 'sscpay_'.$channel);
        $order->meta = ['_sscpay_merchant_order_no' => 'WC42', '_sscpay_order_no' => 'CMB42', '_sscpay_testmode' => 'no'];
        $order->save();

        return $order;
    }

    private function payload(bool $paid = false): array
    {
        return [
            'order_type' => 'combine', 'order_no' => 'CMB42', 'merchant_order_no' => 'WC42',
            'status' => $paid ? 'paid' : 'pending', 'order_status' => $paid ? 'paid' : 'partially_paid',
            'amount' => '100.00', 'currency' => 'USD', 'paid_amount' => $paid ? '100.00' : '40.00',
            'remaining_amount' => $paid ? '0.00' : '60.00', 'payment_method' => null,
            'version' => $paid ? 2 : 1, 'event_id' => $paid ? 'event-final' : 'event-partial',
            'paid_order_no' => $paid ? 'ORD2' : 'ORD1',
            'children' => array_slice([
                ['order_no' => 'ORD1', 'merchant_order_no' => 'WC42-S001', 'amount' => '40.00', 'payment_status' => 'paid', 'logistics' => null],
                ['order_no' => 'ORD2', 'merchant_order_no' => 'WC42-S002', 'amount' => '60.00', 'payment_status' => 'paid', 'logistics' => null],
            ], 0, $paid ? 2 : 1),
        ];
    }

    private function notify(string $channel, array $body, string $nonce = 'nonce-1', ?string $timestamp = null): mixed
    {
        $timestamp ??= (string) time();
        $body['sign'] ??= SignatureCanonicalizer::sign('APP_PLUGIN', $timestamp, $nonce, $body, 'test-plugin-key');
        $class = 'SSCPay_'.ucfirst($channel).'_Webhook';

        return $class::instance()->process_notification($body, 'APP_PLUGIN', $timestamp, $nonce);
    }

    #[DataProvider('channels')]
    public function test_partial_final_duplicate_and_out_of_order_events_keep_one_paid_transition(string $channel): void
    {
        $this->gateway($channel);
        $this->order($channel);
        $this->assertTrue($this->notify($channel, $this->payload()));
        $this->assertSame('on-hold', wc_get_order(42)->status);
        $this->assertSame(0, wc_get_order(42)->paidCalls);
        $this->assertSame('40.00', wc_get_order(42)->get_meta('_sscpay_payment_progress')['paid_amount']);
        $sameVersion = $this->payload();
        $sameVersion['event_id'] = 'another-event-at-the-same-version';
        $sameVersion['paid_amount'] = '20.00';
        $sameVersion['remaining_amount'] = '80.00';
        $this->assertFalse($this->notify($channel, $sameVersion, 'same-version'));
        $this->assertSame('40.00', wc_get_order(42)->get_meta('_sscpay_payment_progress')['paid_amount']);
        $this->assertTrue($this->notify($channel, $this->payload(true), 'nonce-2'));
        $this->assertFalse($this->notify($channel, $this->payload(true), 'nonce-3'));
        $this->assertFalse($this->notify($channel, $this->payload(), 'nonce-4'));
        $saved = wc_get_order(42);
        $this->assertSame('processing', $saved->status);
        $this->assertSame(1, $saved->paidCalls);
        $this->assertSame(2, $saved->get_meta('_sscpay_payment_progress')['version']);
        $this->assertSame('100.00', $saved->get_meta('_sscpay_payment_progress')['paid_amount']);
    }

    #[DataProvider('channels')]
    public function test_v1_and_v2_single_notifications_remain_compatible(string $channel): void
    {
        $this->gateway($channel);
        $this->order($channel);
        $body = ['order_no' => 'CMB42', 'merchant_order_no' => 'WC42', 'status' => 'paid', 'amount' => '100.00', 'currency' => 'USD'];
        $this->assertTrue($this->notify($channel, $body));
        $this->assertSame(1, wc_get_order(42)->paidCalls);
        $this->order($channel);
        $body += ['order_type' => 'single', 'event_id' => 'single-event', 'version' => 1, 'paid_order_no' => 'CMB42', 'paid_amount' => '100.00', 'remaining_amount' => '0.00', 'children' => []];
        $this->assertTrue($this->notify($channel, $body, 'nonce-2'));
        $this->assertSame(1, wc_get_order(42)->paidCalls);
    }

    #[DataProvider('channels')]
    public function test_full_body_signature_and_fresh_timestamp_are_required(string $channel): void
    {
        $this->gateway($channel);
        $this->order($channel);
        $body = $this->payload();
        $timestamp = (string) time();
        $body['sign'] = SignatureCanonicalizer::sign('APP_PLUGIN', $timestamp, 'nonce', $body, 'test-plugin-key');
        $body['children'][0]['amount'] = '0.01';
        $this->assertSame('sscpay_signature', $this->notify($channel, $body, 'nonce', $timestamp)->get_error_code());
        $this->assertSame('sscpay_signature', $this->notify($channel, $this->payload(), 'old', (string) (time() - 3600))->get_error_code());
        $this->assertSame('pending', wc_get_order(42)->status);
    }

    #[DataProvider('channels')]
    public function test_identity_amount_totals_and_order_lock_failures_do_not_confirm_payment(string $channel): void
    {
        $gateway = $this->gateway($channel);
        $order = $this->order($channel);
        $state = 'SSCPay_'.ucfirst($channel).'_Order_State';
        $body = $this->payload(true);
        $body['merchant_order_no'] = 'WC42-S001';
        $this->assertSame('sscpay_identity_mismatch', $state::apply($gateway, $order, $body, 'query')->get_error_code());
        $body = $this->payload(true);
        $body['paid_amount'] = '99.99';
        $body['remaining_amount'] = '0.01';
        $this->assertSame('sscpay_bad_progress', $state::apply($gateway, $order, $body, 'query')->get_error_code());
        $body = $this->payload(true);
        $body['amount'] = '100.01';
        $this->assertSame('sscpay_amount_mismatch', $state::apply($gateway, $order, $body, 'query')->get_error_code());
        $GLOBALS['wpdb']->busy = true;
        $this->assertSame('sscpay_busy', $state::apply($gateway, $order, $this->payload(true), 'query')->get_error_code());
        $this->assertSame(0, wc_get_order(42)->paidCalls);
    }

    #[DataProvider('channels')]
    public function test_retry_reuses_parent_reference_and_checkout_url(string $channel): void
    {
        $gateway = $this->gateway($channel);
        $order = $this->order($channel);
        $helper = 'SSCPay_'.ucfirst($channel).'_Order_Helper';
        $this->assertSame('WC42', $helper::build_merchant_order_no($order, 'WC'));
        $this->notify($channel, $this->payload());
        $order = wc_get_order(42);
        $order->meta['_sscpay_payment_url'] = 'https://pay.example/combine-payment/token';
        $order->save();
        $this->assertSame(['result' => 'success', 'redirect' => 'https://pay.example/combine-payment/token'], $gateway->process_payment(42));
        $this->assertSame('WC42', wc_get_order(42)->get_meta('_sscpay_merchant_order_no'));
    }

    #[DataProvider('channels')]
    public function test_shipping_targets_paid_children_and_never_the_combined_parent(string $channel): void
    {
        $this->gateway($channel);
        $this->order($channel);
        $state = 'SSCPay_'.ucfirst($channel).'_Order_State';
        $this->notify($channel, $this->payload());
        $shipment = ['logistics_company' => 'DHL', 'tracking_number' => 'TRACK-1'];
        $this->assertSame('sscpay_unpaid_parent', $state::shipping_payloads(wc_get_order(42), $shipment)->get_error_code());
        $this->notify($channel, $this->payload(true), 'final');
        $payloads = $state::shipping_payloads(wc_get_order(42), $shipment);
        $this->assertSame(['WC42-S001', 'WC42-S002'], array_column($payloads, 'merchant_order_no'));
        $this->assertSame(['TRACK-1', 'TRACK-1'], array_column($payloads, 'tracking_number'));
    }

    #[DataProvider('channels')]
    public function test_query_uses_both_identifiers_and_the_server_signature_algorithm(string $channel): void
    {
        $gateway = $this->gateway($channel);
        $order = $this->order($channel);
        $GLOBALS['wordpress_http'] = function (string $url, array $args): array {
            $this->assertSame('https://pay.example/api/order/query', $url);
            $body = json_decode($args['body'], true, flags: JSON_THROW_ON_ERROR);
            $headers = $args['headers'];
            $this->assertSame('WC42', $body['merchant_order_no']);
            $this->assertSame('CMB42', $body['order_no']);
            $this->assertSame(SignatureCanonicalizer::sign($headers['App-ID'], $headers['Timestamp'], $headers['X-Nonce'], $body, 'test-plugin-key'), $body['sign']);

            return ['response' => ['code' => 200], 'body' => json_encode(['code' => 0, 'data' => $this->payload(true)])];
        };
        $class = 'SSCPay_'.ucfirst($channel).'_Webhook';
        $this->assertTrue($class::instance()->sync_order($gateway, $order));
        $this->assertSame(1, wc_get_order(42)->paidCalls);
    }

    private function shipment(string $channel, array $body, string $nonce = 'shipment-nonce'): mixed
    {
        $timestamp = (string) time();
        $body['sign'] ??= SignatureCanonicalizer::sign('APP_PLUGIN', $timestamp, $nonce, $body, 'test-plugin-key');
        $class = 'SSCPay_'.ucfirst($channel).'_Webhook';

        return $class::instance()->process_shipment($body, 'APP_PLUGIN', $timestamp, $nonce);
    }

    #[DataProvider('channels')]
    public function test_signed_child_shipment_callbacks_use_the_parent_and_deduplicate_the_same_tracking_number(string $channel): void
    {
        $this->gateway($channel);
        $this->order($channel);
        $body = [
            'merchant_order_no' => 'WC42', 'order_type' => 'combine', 'order_no' => 'ORD1',
            'child_merchant_order_no' => 'WC42-S001', 'parent_order_no' => 'CMB42', 'parent_merchant_order_no' => 'WC42',
            'carrier' => 'DHL', 'tracking_number' => 'TRACK-1', 'shipped_at' => '2026-11-17T12:00:00+08:00',
        ];
        $this->assertTrue($this->shipment($channel, $body));
        $body['order_no'] = 'ORD2';
        $body['child_merchant_order_no'] = 'WC42-S002';
        $this->assertFalse($this->shipment($channel, $body, 'second-child'));
        $this->assertCount(1, wc_get_order(42)->get_meta('_sscpay_shipments'));
        $this->assertCount(1, wc_get_order(42)->notes);
        $this->assertSame(0, wc_get_order(42)->paidCalls);
        $body['parent_order_no'] = 'ANOTHER-PARENT';
        $this->assertSame('sscpay_identity_mismatch', $this->shipment($channel, $body, 'wrong-parent')->get_error_code());
    }

    #[DataProvider('channels')]
    public function test_single_shipment_accepts_the_documented_minimal_body_and_requires_its_full_signature(string $channel): void
    {
        $this->gateway($channel);
        $this->order($channel);
        $body = ['merchant_order_no' => 'WC42', 'carrier' => 'DHL', 'tracking_number' => 'TRACK-2', 'shipped_at' => '2026-11-17T12:00:00+08:00'];
        $body['sign'] = 'invalid';
        $this->assertSame('sscpay_signature', $this->shipment($channel, $body)->get_error_code());
        unset($body['sign']);
        $this->assertTrue($this->shipment($channel, $body));
        $this->assertCount(1, wc_get_order(42)->get_meta('_sscpay_shipments'));
        $this->assertSame('pending', wc_get_order(42)->status);
    }

    #[DataProvider('channels')]
    public function test_one_shipment_endpoint_can_accept_both_gateways_using_the_orders_own_credentials(string $receiver): void
    {
        $other = $receiver === 'paypal' ? 'creditcard' : 'paypal';
        $endpointGateway = $this->gateway($receiver);
        $endpointGateway->settings['live_api_key'] = 'a-different-application-key';
        $this->gateway($other);
        $this->order($other);
        $body = ['merchant_order_no' => 'WC42', 'carrier' => 'DHL', 'tracking_number' => 'SHARED-ENDPOINT', 'shipped_at' => '2026-11-17T12:00:00+08:00'];
        $this->assertTrue($this->shipment($receiver, $body));
        $this->assertCount(1, wc_get_order(42)->get_meta('_sscpay_shipments'));
    }

    #[DataProvider('channels')]
    public function test_update_is_scoped_to_the_plugin_and_download_checksum_is_verified(string $channel): void
    {
        $slug = 'sscpay-payment-'.$channel;
        $bytes = 'test-package-bytes';
        $metadata = ['slug' => $slug, 'version' => '1.1.0', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'download_url' => 'https://pay.example/plugins/'.$slug.'/1.1.0/download'];
        $GLOBALS['wordpress_http'] = function (string $url, array $args) use ($metadata, $bytes): array {
            $this->assertArrayNotHasKey('Authorization', $args['headers'] ?? []);
            if (isset($args['filename'])) {
                file_put_contents($args['filename'], $bytes);
            }

            return ['response' => ['code' => 200], 'body' => json_encode($metadata)];
        };
        $class = 'SSCPay_'.ucfirst($channel).'_Updater';
        $updater = new $class(['base_url' => 'https://pay.example', 'slug' => $slug, 'basename' => $slug.'/'.$slug.'.php', 'version' => '1.0.3']);
        $updates = $updater->inject_update((object) ['response' => [], 'no_update' => []]);
        $this->assertSame('1.1.0', $updates->response[$slug.'/'.$slug.'.php']->new_version);
        $file = $updater->download_package(false, $metadata['download_url']);
        $this->assertSame($bytes, file_get_contents($file));
        unlink($file);
        $GLOBALS['wordpress_http'] = static function (string $url, array $args): array {
            file_put_contents($args['filename'], 'tampered');

            return ['response' => ['code' => 200]];
        };
        $this->assertSame('sscpay_update_integrity', $updater->download_package(false, $metadata['download_url'])->get_error_code());
    }

    #[DataProvider('channels')]
    public function test_another_plugins_release_or_external_download_origin_is_rejected(string $channel): void
    {
        $class = 'SSCPay_'.ucfirst($channel).'_Updater';
        $slug = 'sscpay-payment-'.$channel;
        $metadata = ['slug' => 'another-plugin', 'version' => '9.0.0', 'sha256' => str_repeat('a', 64), 'size' => 100, 'download_url' => 'https://pay.example/download'];
        $GLOBALS['wordpress_http'] = static fn (): array => ['response' => ['code' => 200], 'body' => json_encode($metadata)];
        $updater = new $class(['base_url' => 'https://pay.example', 'slug' => $slug, 'basename' => $slug.'/'.$slug.'.php', 'version' => '1.0.3']);
        $this->assertFalse($updater->metadata(true));
        $metadata['slug'] = $slug;
        $metadata['download_url'] = 'https://another.example/download';
        $GLOBALS['wordpress_http'] = static fn (): array => ['response' => ['code' => 200], 'body' => json_encode($metadata)];
        $this->assertFalse($updater->metadata(true));
    }
}
