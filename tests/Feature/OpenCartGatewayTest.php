<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sscpay\OpenCart\Admin;
use Sscpay\OpenCart\Gateway;
use Sscpay\OpenCart\PaymentState;
use Sscpay\OpenCart\Signature;
use Tests\Support\OpenCartRegistry;
use Tests\Support\OpenCartRepository;
use Tests\Support\OpenCartRuntime;
use Tests\TestCase;

require_once dirname(__DIR__).'/Support/OpenCartRuntime.php';

class OpenCartGatewayTest extends TestCase
{
    public static function gateways(): array
    {
        return ['3 PayPal' => [3, 'paypal'], '3 credit card' => [3, 'creditcard'],
            '4 PayPal' => [4, 'paypal'], '4 credit card' => [4, 'creditcard'],
            '4 legacy PayPal' => [4, 'paypal', true], '4 legacy credit card' => [4, 'creditcard', true]];
    }

    #[DataProvider('gateways')]
    public function test_checkout_retry_keeps_merchant_reference_and_uses_the_correct_opencart_history_api(int $major, string $channel, bool $legacy = false): void
    {
        $registry = OpenCartRuntime::registry($channel, $major);
        if ($legacy) {
            $registry->get('session')->data['payment_method'] = 'sscpay'.$channel;
        }
        $repository = new OpenCartRepository;
        $requests = [];
        $gateway = new Gateway($registry, $major, $channel, $repository, function ($url, $headers, $json) use (&$requests) {
            $requests[] = json_decode($json, true);
            if (count($requests) === 1) {
                throw new RuntimeException('Simulated timeout after remote creation.');
            }

            return ['status' => 200, 'body' => '{"code":0,"data":{"order_no":"REMOTE","payment_url":"https://pay.test/session"}}'];
        });
        try {
            $gateway->confirm();
            $this->fail('Simulated timeout was ignored.');
        } catch (RuntimeException) {
            $this->assertNotNull($repository->find(0, 42));
        }
        $gateway->confirm();
        $this->assertSame('OC'.$major.'-0-42', $requests[0]['merchant_order_no']);
        $this->assertSame($requests[0]['merchant_order_no'], $requests[1]['merchant_order_no']);
        $this->assertSame($requests[0]['return_url'], $requests[1]['return_url']);
        $this->assertSame('https://pay.test/session', json_decode($registry->get('response')->output, true)['redirect']);
        $this->assertCount(1, $registry->get('model_checkout_order')->histories);
        $this->assertSame((string) $major, $registry->get('model_checkout_order')->histories[0]['major']);
        $this->assertSame('100.00', $requests[1]['amount']);
        $this->assertStringNotContainsString('secret-test', $requests[1]['return_url']);
    }

    #[DataProvider('gateways')]
    public function test_partial_full_duplicate_and_shipment_notifications_update_the_store_once(int $major, string $channel, bool $legacy = false): void
    {
        $registry = OpenCartRuntime::registry($channel, $major);
        $repository = new OpenCartRepository;
        $record = OpenCartRuntime::record($major, $channel);
        $repository->insert($record);
        $gateway = new Gateway($registry, $major, $channel, $repository);
        $partial = OpenCartRuntime::event($record);
        $this->notify($gateway, $partial);
        $this->assertSame(3, $registry->get('model_checkout_order')->order['order_status_id']);
        $full = OpenCartRuntime::event($record, '100.00', 2);
        $this->notify($gateway, $full);
        $this->notify($gateway, $full);
        $this->notify($gateway, $partial);
        $this->assertSame(2, $registry->get('model_checkout_order')->order['order_status_id']);
        $this->assertCount(2, $registry->get('model_checkout_order')->histories);
        $shipment = ['merchant_order_no' => $record['merchant_order_no'], 'carrier' => 'DHL', 'tracking_number' => 'TRACK-1'];
        $this->notify($gateway, $shipment, 'shipment');
        $this->notify($gateway, $shipment, 'shipment');
        $this->assertCount(1, $repository->find(0, 42)['state']['shipments']);
        $this->assertCount(3, $registry->get('model_checkout_order')->histories);
        $this->assertSame(2, $registry->get('model_checkout_order')->order['order_status_id']);
    }

    public static function deniedCheckouts(): array
    {
        return ['wrong token' => ['csrf'], 'other method' => ['method'], 'missing order' => ['order'],
            'disabled' => ['disabled'], 'paid partial mapping' => ['mapping'], 'other store' => ['store']];
    }

    #[DataProvider('deniedCheckouts')]
    public function test_invalid_checkout_context_never_contacts_the_payment_server(string $case): void
    {
        $registry = OpenCartRuntime::registry();
        if ($case === 'csrf') {
            $registry->get('request')->post['sscpay_csrf'] = 'wrong';
        } elseif ($case === 'method') {
            $registry->get('session')->data['payment_method']['code'] = 'cod';
        } elseif ($case === 'order') {
            unset($registry->get('session')->data['order_id']);
        } elseif ($case === 'disabled') {
            $registry->get('config')->values['payment_sscpaypaypal_status'] = 0;
        } elseif ($case === 'mapping') {
            $registry->get('config')->values['payment_sscpaypaypal_partial_status_id'] = 2;
        } else {
            $registry->get('config')->values['config_store_id'] = 7;
        }
        $sent = false;
        $gateway = new Gateway($registry, 3, 'paypal', new OpenCartRepository, function () use (&$sent) {
            $sent = true;

            return [];
        });
        try {
            $gateway->confirm();
            $this->fail('An invalid checkout was accepted.');
        } catch (RuntimeException) {
            $this->assertFalse($sent);
        }
    }

    public function test_signed_notification_cannot_cross_stores_or_gateways(): void
    {
        $repository = new OpenCartRepository;
        $record = OpenCartRuntime::record();
        $repository->insert($record);
        foreach ([[7, 'paypal'], [0, 'creditcard']] as [$store, $channel]) {
            $registry = OpenCartRuntime::registry($channel);
            $registry->get('config')->values['config_store_id'] = $store;
            try {
                $this->notify(new Gateway($registry, 3, $channel, $repository), OpenCartRuntime::event($record, '100.00'));
                $this->fail('A different store or gateway accepted the callback.');
            } catch (RuntimeException) {
                $this->assertSame([], $repository->find(0, 42)['state']);
            }
        }
    }

    public function test_a_browser_claim_of_paid_is_ignored_until_server_query_confirms_payment(): void
    {
        $repository = new OpenCartRepository;
        $record = OpenCartRuntime::record();
        $repository->insert($record);
        $registry = OpenCartRuntime::registry();
        $registry->get('request')->get = ['sscpay_action' => 'return', 'order_id' => 42, 'key' => $record['return_token'], 'status' => 'paid'];
        $gateway = new Gateway($registry, 3, 'paypal', $repository, fn () => ['status' => 200, 'body' => json_encode(['code' => 0, 'data' => OpenCartRuntime::event($record)])]);
        $this->assertSame('rendered-view', $gateway->index());
        $this->assertNull($registry->get('response')->redirect);
        $this->assertSame(3, $registry->get('model_checkout_order')->order['order_status_id']);
        $this->assertFalse($repository->find(0, 42)['state']['paid_once']);
    }

    public function test_shipment_comments_escape_external_text_and_reject_wrong_parent_identity(): void
    {
        $repository = new OpenCartRepository;
        $record = OpenCartRuntime::record();
        $repository->insert($record);
        $registry = OpenCartRuntime::registry();
        $gateway = new Gateway($registry, 3, 'paypal', $repository);
        $body = ['merchant_order_no' => $record['merchant_order_no'], 'tracking_number' => 'TRACK', 'carrier' => '<script>alert(1)</script>'];
        $this->notify($gateway, $body, 'shipment');
        $comment = $registry->get('model_checkout_order')->histories[0]['comment'];
        $this->assertStringContainsString('&lt;script&gt;', $comment);
        $this->assertStringNotContainsString('<script>', $comment);
        $body += ['order_type' => 'combine', 'parent_order_no' => 'WRONG', 'parent_merchant_order_no' => $record['merchant_order_no'],
            'child_merchant_order_no' => $record['merchant_order_no'].'-S001'];
        $this->expectExceptionMessage('Shipment parent mismatch.');
        $this->notify($gateway, $body, 'shipment');
    }

    public function test_expired_partial_orders_retain_payment_progress_without_offering_payment_again(): void
    {
        $repository = new OpenCartRepository;
        $record = OpenCartRuntime::record();
        $repository->insert($record);
        $registry = OpenCartRuntime::registry();
        $registry->get('request')->get = ['sscpay_action' => 'return', 'order_id' => 42, 'key' => $record['return_token']];
        $query = OpenCartRuntime::event($record);
        $query['status'] = $query['order_status'] = 'expired';
        $gateway = new Gateway($registry, 3, 'paypal', $repository, fn () => ['status' => 200, 'body' => json_encode(['code' => 0, 'data' => $query])]);
        $gateway->index();
        $this->assertSame('', $registry->get('load')->views[0][1]['pay_url']);
        $this->assertSame('40.00', $repository->find(0, 42)['state']['paid_amount']);
        $this->assertFalse($repository->find(0, 42)['state']['paid_once']);
    }

    public function test_foreign_credentials_cannot_be_substituted_on_retry(): void
    {
        $repository = new OpenCartRepository;
        $repository->insert(OpenCartRuntime::record());
        $registry = OpenCartRuntime::registry();
        $registry->get('config')->values['payment_sscpaypaypal_app_id'] = 'another-app';
        $this->expectExceptionMessage('credentials or gateway changed');
        (new Gateway($registry, 3, 'paypal', $repository))->confirm();
    }

    public function test_invalid_customer_data_leaves_the_order_editable_before_any_payment_request(): void
    {
        $repository = new OpenCartRepository;
        $registry = OpenCartRuntime::registry();
        $registry->get('model_checkout_order')->order['shipping_address_1'] = '';
        $sent = false;
        $gateway = new Gateway($registry, 3, 'paypal', $repository, function () use (&$sent) {
            $sent = true;

            return [];
        });
        try {
            $gateway->confirm();
            $this->fail('Missing address was accepted.');
        } catch (RuntimeException) {
            $this->assertFalse($sent);
            $this->assertNull($repository->find(0, 42));
            $this->assertSame(0, $registry->get('model_checkout_order')->order['order_status_id']);
            $this->assertSame([], $registry->get('model_checkout_order')->histories);
        }
    }

    public function test_payment_method_shapes_support_legacy_and_modern_four_x_checkout(): void
    {
        $gateway = new Gateway(OpenCartRuntime::registry('creditcard', 4), 4, 'creditcard', new OpenCartRepository);
        $this->assertSame('sscpaycreditcard', $gateway->method([], false)['code']);
        $this->assertSame('sscpaycreditcard.sscpaycreditcard', $gateway->method([], true)['option']['sscpaycreditcard']['code']);
    }

    public function test_admin_denies_unprivileged_writes_and_never_echoes_api_keys(): void
    {
        $registry = $this->adminRegistry(false);
        $registry->get('request')->post['api_key'] = 'sensitive-posted-secret';
        (new Admin($registry, 3, 'paypal', new OpenCartRepository))->index();
        $data = $registry->get('load')->views[0][1];
        $this->assertNotSame('', $data['error_warning']);
        $this->assertSame('', $data['settings']['api_key']);
        $this->assertSame([], $registry->get('model_setting_setting')->saved);
        $this->assertStringNotContainsString('sensitive-posted-secret', json_encode($data));
        $this->assertStringNotContainsString('secret-test', json_encode($data));
    }

    public function test_admin_blank_secret_preserves_credentials_and_rejects_paid_status_for_partial(): void
    {
        $registry = $this->adminRegistry(true);
        $gateway = new Gateway($registry, 3, 'paypal', new OpenCartRepository);
        $registry->get('request')->post = array_merge($gateway->settings(), ['api_key' => '', 'sscpay_csrf' => 'admin-token']);
        (new Admin($registry, 3, 'paypal', new OpenCartRepository))->index();
        $this->assertSame('secret-test', $registry->get('model_setting_setting')->saved['payment_sscpaypaypal_api_key']);
        $registry->get('model_setting_setting')->saved = [];
        $registry->get('request')->post['partial_status_id'] = 2;
        (new Admin($registry, 3, 'paypal', new OpenCartRepository))->index();
        $this->assertSame([], $registry->get('model_setting_setting')->saved);
        $this->assertNotSame('', $registry->get('load')->views[1][1]['error_warning']);
    }

    public function test_admin_shipping_retries_only_children_not_successfully_sent_before(): void
    {
        $registry = $this->adminRegistry(true);
        $repository = new OpenCartRepository;
        $record = OpenCartRuntime::record();
        $query = OpenCartRuntime::event($record, '100.00', 2);
        $query['children'][0]['amount'] = '40.00';
        $query['children'][] = ['order_no' => 'CHILD-2', 'merchant_order_no' => 'OC3-0-42-S002', 'currency' => 'USD', 'amount' => '60.00', 'payment_status' => 'paid'];
        $record['state'] = PaymentState::apply($record, $query, 'query');
        $repository->insert($record);
        $registry->get('request')->post = ['sscpay_csrf' => 'admin-token', 'sscpay_action' => 'ship', 'order_id' => '42',
            'logistics_company' => 'DHL', 'tracking_number' => 'TRACK', 'shipped_at' => '2026-11-17T12:30:00+08:00'];
        $sent = [];
        $failed = false;
        $transport = function ($url, $headers, $json) use (&$sent, &$failed, $query) {
            if (str_ends_with($url, '/query')) {
                return ['status' => 200, 'body' => json_encode(['code' => 0, 'data' => $query])];
            }
            $payload = json_decode($json, true);
            $sent[] = $payload['merchant_order_no'];
            if (! $failed && $payload['merchant_order_no'] === 'OC3-0-42-S002') {
                $failed = true;

                return ['status' => 503, 'body' => ''];
            }

            return ['status' => 200, 'body' => '{"code":0}'];
        };
        $admin = new Admin($registry, 3, 'paypal', $repository, $transport);
        $admin->index();
        $admin->index();
        $this->assertSame(['OC3-0-42-S001', 'OC3-0-42-S002', 'OC3-0-42-S002'], $sent);
        $this->assertCount(2, $repository->find(0, 42)['state']['shipping_sent']);
    }

    private function notify(Gateway $gateway, array $body, string $action = 'notify'): void
    {
        $time = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $body['sign'] = Signature::sign('app-test', $time, $nonce, $body, 'secret-test');
        $gateway->notification($action, $body, ['App-ID' => 'app-test', 'Timestamp' => $time, 'X-Nonce' => $nonce]);
    }

    private function adminRegistry(bool $allowed): OpenCartRegistry
    {
        $registry = OpenCartRuntime::registry();
        $registry->get('session')->data += ['user_token' => 'user-token', 'sscpay_admin_csrf' => 'admin-token'];
        $registry->get('request')->get['user_token'] = 'user-token';
        $registry->get('request')->post = ['sscpay_csrf' => 'admin-token'];
        $registry->services['user'] = new class($allowed)
        {
            public function __construct(private bool $allowed) {}

            public function hasPermission(string $action, string $route): bool
            {
                return $this->allowed;
            }
        };
        $registry->services['model_localisation_order_status'] = new class
        {
            public function getOrderStatuses(): array
            {
                return array_map(fn ($id) => ['order_status_id' => $id, 'name' => 'Status '.$id], [1, 2, 3, 5, 7, 10, 11, 13]);
            }
        };
        $registry->services['model_localisation_geo_zone'] = new class
        {
            public function getGeoZones(): array
            {
                return [];
            }
        };
        $registry->services['model_setting_setting'] = new class
        {
            public array $saved = [];

            public function editSetting(string $prefix, array $values, int $store): void
            {
                $this->saved = $values;
            }
        };
        $registry->services['document'] = new class
        {
            public function setTitle(string $title): void {}
        };
        $registry->services['model_sale_order'] = $registry->get('model_checkout_order');
        $registry->services['load'] = new class
        {
            public array $views = [];

            public function model(string $route): void {}

            public function controller(string $route): string
            {
                return '';
            }

            public function view(string $route, array $data): string
            {
                $this->views[] = [$route, $data];

                return 'admin-view';
            }
        };

        return $registry;
    }
}
