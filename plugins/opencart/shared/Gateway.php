<?php
namespace Sscpay\OpenCart;

use RuntimeException;
use Throwable;

/** OpenCart registry adapter shared by the 3.x and 4.x entry points. */
final class Gateway {
    private $registry;
    private int $major;
    private string $channel;
    private string $code;
    private Repository $repository;
    private $transport;

    public function __construct($registry, int $major, string $channel, ?Repository $repository = null, ?callable $transport = null) {
        if (!in_array($major, [3, 4], true) || !in_array($channel, ['paypal', 'creditcard'], true)) {
            throw new RuntimeException('Unsupported gateway.');
        }
        $this->registry = $registry;
        $this->major = $major;
        $this->channel = $channel;
        $this->code = 'sscpay' . $channel;
        $this->repository = $repository ?? new Repository($registry->get('db'));
        $this->transport = $transport;
    }

    private function service(string $name) {
        return $this->registry->get($name);
    }

    public function route(): string {
        return $this->major === 3 ? 'extension/payment/' . $this->code : 'extension/sscpay/payment/' . $this->code;
    }

    public function settings(): array {
        $settings = [];
        foreach (['status', 'api_url', 'app_id', 'api_key', 'group_key', 'payment_method_key', 'platform', 'update_url',
            'pending_status_id', 'partial_status_id', 'paid_status_id', 'cancelled_status_id', 'failed_status_id',
            'refunded_status_id', 'chargeback_status_id', 'geo_zone_id', 'sort_order'] as $key) {
            $settings[$key] = $this->service('config')->get('payment_' . $this->code . '_' . $key);
        }
        return $settings;
    }

    public function method(array $address, bool $modern): array {
        $settings = $this->settings();
        if (empty($settings['status']) || empty($settings['app_id']) || empty($settings['api_key'])
            || empty($settings['group_key']) || !$this->validStatuses($settings)) {
            return [];
        }
        $cart = $this->service('cart');
        if ($cart && (($this->major === 4 && $cart->hasSubscription()) || ($this->major === 3 && $cart->hasRecurringProducts()))) {
            return [];
        }
        if (!empty($settings['geo_zone_id'])) {
            $zone = $this->service('db')->query("SELECT `geo_zone_id` FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = "
                . (int)$settings['geo_zone_id'] . ' AND `country_id` = ' . (int)($address['country_id'] ?? 0)
                . ' AND (`zone_id` = 0 OR `zone_id` = ' . (int)($address['zone_id'] ?? 0) . ')');
            if (!$zone->num_rows) {
                return [];
            }
        }
        $name = $this->channel === 'paypal' ? 'PayPal (SSCPay)' : Text::all($this->language(), $this->channel)['heading_title'];
        if ($modern) {
            return ['code' => $this->code, 'name' => $name, 'option' => [$this->code => ['code' => $this->code . '.' . $this->code, 'name' => $name]],
                'sort_order' => (int)$settings['sort_order']];
        }
        return ['code' => $this->code, 'title' => $name, 'name' => $name, 'terms' => '', 'sort_order' => (int)$settings['sort_order']];
    }

    public function validStatuses(array $settings): bool {
        $paid = array_map('intval', array_merge((array)$this->service('config')->get('config_processing_status'), (array)$this->service('config')->get('config_complete_status')));
        return in_array((int)$settings['paid_status_id'], $paid, true) && (int)$settings['paid_status_id'] > 0
            && (int)$settings['pending_status_id'] > 0 && (int)$settings['partial_status_id'] > 0
            && !in_array((int)$settings['pending_status_id'], $paid, true)
            && !in_array((int)$settings['partial_status_id'], $paid, true);
    }

    public function index(): string {
        $action = (string)($this->service('request')->get['sscpay_action'] ?? '');
        try {
            if ($action === 'notify' || $action === 'shipment') {
                $this->callback($action);
                return '';
            }
            if ($action === 'confirm') {
                $this->confirm();
                return '';
            }
            if ($action === 'return' || $action === 'cancel') {
                return $this->returned();
            }
            if ($action !== '') {
                throw new RuntimeException('Unknown payment action.');
            }
            $session = $this->service('session');
            $session->data['sscpay_csrf'] = $session->data['sscpay_csrf'] ?? bin2hex(random_bytes(32));
            return $this->view('sscpay_checkout', ['confirm_url' => $this->url('confirm'), 'csrf' => $session->data['sscpay_csrf'],
                'text' => Text::all($this->language(), $this->channel)]);
        } catch (Throwable $error) {
            if (in_array($action, ['notify', 'shipment', 'confirm'], true)) {
                $this->json(['code' => 1, 'error' => $this->safeError($error)], in_array($action, ['notify', 'shipment'], true) ? 503 : 400);
                return '';
            }
            return $this->view('sscpay_result', ['text' => Text::all($this->language(), $this->channel),
                'message' => $this->safeError($error), 'progress' => [], 'pay_url' => '', 'refresh_url' => '',
                'continue_url' => $this->service('url')->link('checkout/cart', '', true)]);
        }
    }

    public function confirm(): void {
        $request = $this->service('request');
        $session = $this->service('session');
        $method = $session->data['payment_method'] ?? '';
        $methodCode = is_array($method) ? ($method['code'] ?? '') : $method;
        if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST' || empty($session->data['sscpay_csrf'])
            || !hash_equals($session->data['sscpay_csrf'], (string)($request->post['sscpay_csrf'] ?? ''))
            || !in_array($methodCode, [$this->code, $this->code . '.' . $this->code], true)
            || empty($session->data['order_id'])) {
            throw new RuntimeException('Invalid checkout session.');
        }
        $settings = $this->settings();
        if (empty($settings['status']) || !$this->validStatuses($settings)) {
            throw new RuntimeException('The payment gateway is not configured.');
        }
        $store = (int)$this->service('config')->get('config_store_id');
        $orderId = (int)$session->data['order_id'];
        $customerIp = $request->server['REMOTE_ADDR'] ?? null;
        $redirect = $this->repository->locked($store, $orderId, function () use ($store, $orderId, $settings, $customerIp) {
            $order = $this->order($orderId, $store);
            $record = $this->repository->find($store, $orderId);
            $isNewRecord = $record === null;
            if ($record === null) {
                $paidStatuses = array_merge((array)$this->service('config')->get('config_processing_status'), (array)$this->service('config')->get('config_complete_status'));
                if (in_array((int)$order['order_status_id'], array_map('intval', $paidStatuses), true)) {
                    throw new RuntimeException('This store order has already been paid.');
                }
                $record = ['store_id' => $store, 'order_id' => $orderId, 'gateway' => $this->channel,
                    'app_id' => (string)$settings['app_id'], 'api_url' => rtrim((string)$settings['api_url'], '/'),
                    'merchant_order_no' => Payload::reference($this->major, $store, $orderId), 'remote_order_no' => '',
                    'amount' => Decimal::convert($order['total'], $order['currency_value']), 'currency' => $order['currency_code'],
                    'return_token' => bin2hex(random_bytes(32)), 'pay_url' => '', 'state' => []];
            }
            $this->assertBinding($record, $settings);
            if ($record['amount'] !== Decimal::convert($order['total'], $order['currency_value']) || $record['currency'] !== $order['currency_code']) {
                throw new RuntimeException('The order total changed after payment was started.');
            }
            if (!empty($record['state']['paid_once'])) {
                return $this->returnUrl($record);
            }
            if ($record['remote_order_no'] !== '') {
                $record = $this->query($record);
                if (!empty($record['state']['paid_once']) || !in_array($record['state']['stage'] ?? '', ['pending', 'partial'], true)
                    || in_array($record['state']['order_status'] ?? '', ['expired', 'review', 'cancelled', 'failed'], true)) {
                    return $this->returnUrl($record);
                }
                if ($record['pay_url'] !== '') {
                    return $record['pay_url'];
                }
            }
            $db = $this->service('db');
            $products = $db->query("SELECT * FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = " . $orderId)->rows;
            $totals = $db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE `order_id` = " . $orderId . ' ORDER BY sort_order')->rows;
            $productUrls = [];
            foreach ($products as $product) {
                $productUrls[(string)$product['product_id']] = html_entity_decode($this->service('url')->link('product/product', 'product_id=' . (int)$product['product_id'], true), ENT_QUOTES, 'UTF-8');
            }
            $urls = ['store' => html_entity_decode($this->service('url')->link('common/home', '', true), ENT_QUOTES, 'UTF-8'),
                'products' => $productUrls,
                'notify' => $this->url('notify'), 'return' => $this->returnUrl($record), 'cancel' => $this->returnUrl($record, 'cancel')];
            $client = $this->client($settings);
            $payload = Payload::create($order, $products, $totals, $settings, $urls, $record['merchant_order_no']);
            if (is_string($customerIp) && filter_var($customerIp, FILTER_VALIDATE_IP) !== false) {
                $payload['customer_ip'] = $customerIp;
            }
            if ($isNewRecord) {
                $this->repository->insert($record);
            }
            if (empty($record['state']['initialized'])) {
                $this->repository->transaction(function () use (&$record, $settings) {
                    $this->history($record, (int)$settings['pending_status_id'], 'SSCPay: awaiting payment.');
                    $record['state']['initialized'] = true;
                    $this->repository->save($record);
                });
            }
            $created = $client->request('create', $payload);
            $remote = $created['order_no'] ?? '';
            $payUrl = $created['payment_url'] ?? $created['pay_url'] ?? '';
            if (!is_string($remote) || $remote === '' || strlen($remote) > 80
                || !is_string($payUrl) || !filter_var($payUrl, FILTER_VALIDATE_URL) || parse_url($payUrl, PHP_URL_SCHEME) !== 'https'
                || parse_url($payUrl, PHP_URL_USER) !== null || parse_url($payUrl, PHP_URL_PASS) !== null) {
                throw new RuntimeException('Invalid payment creation response.');
            }
            $record['remote_order_no'] = $remote;
            $record['pay_url'] = $payUrl;
            $this->repository->save($record);
            if (($created['status'] ?? '') === 'paid') {
                $this->query($record);
                return $this->returnUrl($record);
            }
            return $payUrl;
        });
        $this->json(['redirect' => $redirect]);
    }

    public function query(array $record): array {
        $settings = $this->settings();
        $this->assertBinding($record, $settings);
        $body = $this->client($settings)->request('query', ['merchant_order_no' => $record['merchant_order_no'], 'order_no' => $record['remote_order_no']]);
        return $this->apply($record, $body, 'query');
    }

    public function apply(array $record, array $body, string $source): array {
        $next = PaymentState::apply($record, $body, $source);
        return $this->repository->transaction(function () use ($record, $next, $body) {
            $old = $record['state'];
            $record['state'] = $next;
            $record['remote_order_no'] = $body['order_no'];
            if (($old['stage'] ?? '') !== ($next['stage'] ?? '')) {
                $settings = $this->settings();
                if (!$this->validStatuses($settings)) {
                    throw new RuntimeException('Invalid order status configuration.');
                }
                $status = (int)($settings[$next['stage'] . '_status_id'] ?? 0);
                if ($status < 1) {
                    throw new RuntimeException('Missing order status mapping.');
                }
                $this->history($record, $status, 'SSCPay: ' . $next['stage'] . '; paid ' . $next['paid_amount']
                    . ' ' . $record['currency'] . '; remaining ' . $next['remaining_amount'] . '; ' . $record['remote_order_no']);
            }
            $this->repository->save($record);
            return $record;
        });
    }

    private function callback(string $action): void {
        $request = $this->service('request');
        if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['code' => 1, 'error' => 'POST required'], 405);
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, 1048577);
        if (!is_string($raw) || strlen($raw) > 1048576) {
            $this->json(['code' => 1, 'error' => 'Invalid notification body'], 413);
            return;
        }
        $body = json_decode($raw, true);
        $headers = ['App-ID' => (string)($request->server['HTTP_APP_ID'] ?? ''),
            'Timestamp' => (string)($request->server['HTTP_TIMESTAMP'] ?? ''), 'X-Nonce' => (string)($request->server['HTTP_X_NONCE'] ?? '')];
        if (!is_array($body)) {
            throw new RuntimeException('Invalid notification body.');
        }
        $this->notification($action, $body, $headers);
        $this->json(['code' => 0, 'msg' => 'success']);
    }

    public function notification(string $action, array $body, array $headers): void {
        $settings = $this->settings();
        Signature::verify($body, $headers, (string)$settings['app_id'], (string)$settings['api_key']);
        $store = (int)$this->service('config')->get('config_store_id');
        $record = $this->repository->byReference($store, (string)($body['merchant_order_no'] ?? ''));
        if (!$record || $record['gateway'] !== $this->channel) {
            throw new RuntimeException('Unknown payment order.');
        }
        $this->repository->locked($store, (int)$record['order_id'], function () use ($store, $record, $body, $headers, $action, $settings) {
            $fresh = $this->repository->find($store, (int)$record['order_id']);
            $this->assertBinding($fresh, $settings);
            $this->order((int)$record['order_id'], $store);
            $normalized = array_change_key_case($headers, CASE_LOWER);
            $this->repository->nonce((string)$settings['app_id'], $normalized['x-nonce'], $body);
            if ($action === 'notify') {
                $this->apply($fresh, $body, 'notify');
            } elseif ($action === 'shipment') {
                $this->receiveShipment($fresh, $body);
            } else {
                throw new RuntimeException('Unknown notification action.');
            }
        });
    }

    private function receiveShipment(array $record, array $body): void {
        $tracking = $body['tracking_number'] ?? '';
        $carrier = $body['carrier'] ?? '';
        if (!is_string($tracking) || $tracking === '' || strlen($tracking) > 100
            || !is_string($carrier) || $carrier === '' || strlen($carrier) > 100) {
            throw new RuntimeException('Invalid shipment.');
        }
        if (($body['order_type'] ?? '') === 'combine'
            && (($body['parent_order_no'] ?? '') !== $record['remote_order_no']
                || ($body['parent_merchant_order_no'] ?? '') !== $record['merchant_order_no']
                || !is_string($body['child_merchant_order_no'] ?? null)
                || !preg_match('/^' . preg_quote($record['merchant_order_no'], '/') . '-S\d{3,}$/D', $body['child_merchant_order_no']))) {
            throw new RuntimeException('Shipment parent mismatch.');
        }
        $url = $body['tracking_url'] ?? '';
        $shipped = $body['shipped_at'] ?? '';
        if (!is_string($url) || strlen($url) > 255 || ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL)
                || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)))
            || !is_string($shipped) || ($shipped !== '' && (strlen($shipped) > 50 || strtotime($shipped) === false))) {
            throw new RuntimeException('Invalid shipment metadata.');
        }
        $key = hash('sha256', $record['merchant_order_no'] . "\0" . $tracking);
        if (isset($record['state']['shipments'][$key])) {
            return;
        }
        $this->repository->transaction(function () use ($record, $tracking, $carrier, $key, $url, $shipped) {
            $order = $this->order((int)$record['order_id'], (int)$record['store_id']);
            $this->history($record, (int)$order['order_status_id'], 'SSCPay shipment: ' . $carrier . ' / ' . $tracking);
            $record['state']['shipments'][$key] = ['carrier' => $carrier, 'tracking_number' => $tracking, 'tracking_url' => $url, 'shipped_at' => $shipped];
            $this->repository->save($record);
        });
    }

    private function returned(): string {
        $request = $this->service('request');
        $store = (int)$this->service('config')->get('config_store_id');
        $orderId = (int)($request->get['order_id'] ?? 0);
        $record = $this->repository->find($store, $orderId);
        if (!$record || $record['gateway'] !== $this->channel
            || !hash_equals($record['return_token'], (string)($request->get['key'] ?? ''))) {
            throw new RuntimeException('Invalid payment return link.');
        }
        $record = $this->repository->locked($store, $orderId, function () use ($record) {
            $fresh = $this->repository->find((int)$record['store_id'], (int)$record['order_id']);
            return $this->query($fresh);
        });
        if (!empty($record['state']['paid_once']) && ($record['state']['stage'] ?? '') === 'paid'
            && (int)($this->service('session')->data['order_id'] ?? 0) === $orderId) {
            $this->service('response')->redirect($this->service('url')->link('checkout/success', '', true));
            return '';
        }
        $canPay = in_array($record['state']['stage'] ?? '', ['pending', 'partial'], true)
            && !in_array($record['state']['order_status'] ?? '', ['expired', 'review', 'cancelled', 'failed'], true);
        return $this->view('sscpay_result', ['text' => Text::all($this->language(), $this->channel), 'message' => '',
            'progress' => $record['state'], 'pay_url' => $canPay ? $record['pay_url'] : '', 'refresh_url' => $this->returnUrl($record),
            'continue_url' => $this->service('url')->link('common/home', '', true)]);
    }

    private function order(int $orderId, int $store): array {
        $this->service('load')->model('checkout/order');
        $order = $this->service('model_checkout_order')->getOrder($orderId);
        if (!$order || (int)$order['store_id'] !== $store) {
            throw new RuntimeException('Unknown store order.');
        }
        return $order;
    }

    private function history(array $record, int $status, string $comment): void {
        $order = $this->order((int)$record['order_id'], (int)$record['store_id']);
        $model = $this->service('model_checkout_order');
        $comment = htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($this->major === 3) {
            $model->addOrderHistory((int)$order['order_id'], $status, $comment, false);
        } else {
            $model->addHistory((int)$order['order_id'], $status, $comment, false);
        }
    }

    public function assertBinding(array $record, array $settings): void {
        if ($record['gateway'] !== $this->channel || !hash_equals($record['app_id'], (string)$settings['app_id'])
            || $record['api_url'] !== rtrim((string)$settings['api_url'], '/')) {
            throw new RuntimeException('Payment credentials or gateway changed for an existing order.');
        }
    }

    public function client(array $settings): Client {
        return new Client((string)$settings['api_url'], (string)$settings['app_id'], (string)$settings['api_key'], $this->transport);
    }

    public function url(string $action, array $query = []): string {
        return html_entity_decode($this->service('url')->link($this->route(), http_build_query(array_merge(['sscpay_action' => $action], $query), '', '&'), true), ENT_QUOTES, 'UTF-8');
    }

    private function returnUrl(array $record, string $action = 'return'): string {
        return $this->url($action, ['order_id' => $record['order_id'], 'key' => $record['return_token']]);
    }

    private function view(string $name, array $data): string {
        $route = $this->major === 3 ? 'extension/payment/' . $name : 'extension/sscpay/payment/' . $name;
        return $this->service('load')->view($route, $data);
    }

    private function language(): string {
        return (string)$this->service('config')->get('config_language');
    }

    private function safeError(Throwable $error): string {
        // Exception details from SQL, extensions and transports may contain credentials or customer data.
        return Text::all($this->language(), $this->channel)['error_payment'];
    }

    private function json(array $body, int $status = 200): void {
        $response = $this->service('response');
        $response->addHeader('HTTP/1.1 ' . $status);
        $response->addHeader('Content-Type: application/json');
        $response->addHeader('Cache-Control: no-store');
        $response->setOutput(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
