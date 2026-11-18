<?php
namespace Sscpay\OpenCart;

use RuntimeException;
use Throwable;

final class Admin {
    private $registry;
    private int $major;
    private string $channel;
    private Gateway $gateway;
    private Repository $repository;

    public function __construct($registry, int $major, string $channel, ?Repository $repository = null, ?callable $transport = null) {
        $this->registry = $registry;
        $this->major = $major;
        $this->channel = $channel;
        $this->repository = $repository ?? new Repository($registry->get('db'));
        $this->gateway = new Gateway($registry, $major, $channel, $this->repository, $transport);
    }

    private function service(string $name) {
        return $this->registry->get($name);
    }

    public function install(): void {
        if (!$this->service('user')->hasPermission('modify', $this->gateway->route())) {
            throw new RuntimeException('Permission denied.');
        }
        $this->repository->install();
    }

    public function index(): void {
        $request = $this->service('request');
        $session = $this->service('session');
        $language = (string)$this->service('config')->get('config_language');
        $text = Text::all($language, $this->channel);
        $settings = $this->gateway->settings();
        $prefix = 'payment_sscpay' . $this->channel;
        $error = '';
        $success = '';
        $update = null;
        $lookup = null;
        $session->data['sscpay_admin_csrf'] = $session->data['sscpay_admin_csrf'] ?? bin2hex(random_bytes(32));
        if (($request->server['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!$this->service('user')->hasPermission('modify', $this->gateway->route())
                    || !hash_equals((string)($session->data['user_token'] ?? ''), (string)($request->get['user_token'] ?? ''))
                    || empty($session->data['user_token'])
                    || !hash_equals($session->data['sscpay_admin_csrf'], (string)($request->post['sscpay_csrf'] ?? ''))) {
                    throw new RuntimeException('Permission denied.');
                }
                $action = (string)($request->post['sscpay_action'] ?? 'save');
                if ($action === 'save') {
                    $settings = $this->saveSettings($settings, $prefix);
                } elseif ($action === 'ship') {
                    $this->ship($settings);
                } elseif ($action === 'query') {
                    $lookup = $this->lookup($settings);
                } elseif ($action === 'updates') {
                    $update = $this->updates($settings);
                } else {
                    throw new RuntimeException('Unknown operation.');
                }
                $success = $text['text_success'];
            } catch (Throwable $exception) {
                $error = $text['error_admin'];
            }
        }
        $this->service('load')->model('localisation/order_status');
        $statuses = $this->service('model_localisation_order_status')->getOrderStatuses();
        $this->service('load')->model('localisation/geo_zone');
        $zones = $this->service('model_localisation_geo_zone')->getGeoZones();
        $settings['platform'] = $settings['platform'] ?: 'opencart';
        $settings['api_key'] = '';
        $defaults = array_merge((array)$this->service('config')->get('config_processing_status'), (array)$this->service('config')->get('config_complete_status'));
        foreach (['pending', 'partial', 'cancelled', 'failed', 'refunded', 'chargeback', 'paid'] as $stage) {
            $settings[$stage . '_status_id'] = $settings[$stage . '_status_id'] ?: ($stage === 'paid' ? ($defaults[0] ?? 0) : $this->service('config')->get('config_order_status_id'));
        }
        $token = 'user_token=' . rawurlencode((string)($session->data['user_token'] ?? ''));
        $storeUrl = (string)($this->service('config')->get('config_secure') ?: $this->service('config')->get('config_url'));
        if ($storeUrl === '' && defined('HTTPS_CATALOG')) {
            $storeUrl = HTTPS_CATALOG;
        }
        $callbackBase = rtrim($storeUrl, '/') . '/index.php?route=' . $this->gateway->route() . '&sscpay_action=';
        $this->service('document')->setTitle($text['heading_title']);
        $data = ['text' => $text, 'settings' => $settings, 'order_statuses' => $statuses, 'geo_zones' => $zones,
            'csrf' => $session->data['sscpay_admin_csrf'], 'error_warning' => $error, 'success' => $success,
            'action' => $this->service('url')->link($this->gateway->route(), $token, true),
            'cancel' => $this->service('url')->link('marketplace/extension', $token . '&type=payment', true),
            'notify_url' => $callbackBase . 'notify', 'shipment_url' => $callbackBase . 'shipment',
            'update' => $update, 'lookup' => $lookup, 'major' => $this->major,
            'version' => is_file(__DIR__ . '/release-version.json') ? (json_decode(file_get_contents(__DIR__ . '/release-version.json'), true)['version'] ?? '') : 'development',
            'header' => $this->service('load')->controller('common/header'),
            'column_left' => $this->service('load')->controller('common/column_left'), 'footer' => $this->service('load')->controller('common/footer')];
        $template = $this->major === 3 ? 'extension/payment/sscpay_settings' : 'extension/sscpay/payment/sscpay_settings';
        $this->service('response')->setOutput($this->service('load')->view($template, $data));
    }

    private function saveSettings(array $current, string $prefix): array {
        $post = $this->service('request')->post;
        $settings = [];
        foreach ($current as $key => $value) {
            $settings[$key] = trim((string)($post[$key] ?? ''));
        }
        $settings['api_key'] = $settings['api_key'] !== '' ? $settings['api_key'] : (string)$current['api_key'];
        $settings['update_url'] = trim((string)($post['update_url'] ?? ''));
        Client::https($settings['api_url']);
        if ($settings['update_url'] !== '') {
            Client::https($settings['update_url']);
        }
        if ($settings['app_id'] === '' || $settings['api_key'] === '' || !preg_match('/^[\w-]{1,50}$/D', $settings['group_key'])
            || !preg_match('/^[\w-]{1,50}$/D', $settings['platform']) || !$this->gateway->validStatuses($settings)) {
            throw new RuntimeException('Invalid gateway settings.');
        }
        $this->service('load')->model('localisation/order_status');
        $allowed = array_map('intval', array_column($this->service('model_localisation_order_status')->getOrderStatuses(), 'order_status_id'));
        $paid = array_map('intval', array_merge((array)$this->service('config')->get('config_processing_status'), (array)$this->service('config')->get('config_complete_status')));
        foreach (['pending', 'partial', 'paid', 'cancelled', 'failed', 'refunded', 'chargeback'] as $stage) {
            $id = (int)$settings[$stage . '_status_id'];
            if (!in_array($id, $allowed, true) || ($stage !== 'paid' && in_array($id, $paid, true))) {
                throw new RuntimeException('Invalid order status mapping.');
            }
        }
        $save = [];
        foreach ($settings as $key => $value) {
            $save[$prefix . '_' . $key] = $value;
        }
        $this->service('load')->model('setting/setting');
        $this->service('model_setting_setting')->editSetting($prefix, $save, 0);
        $this->repository->install();
        return $settings;
    }

    private function record(array $settings): array {
        $orderId = (int)($this->service('request')->post['order_id'] ?? 0);
        $this->service('load')->model('sale/order');
        $order = $this->service('model_sale_order')->getOrder($orderId);
        if (!$order) {
            throw new RuntimeException('Unknown order.');
        }
        $record = $this->repository->find((int)$order['store_id'], $orderId);
        if (!$record || $record['remote_order_no'] === '') {
            throw new RuntimeException('Unknown payment.');
        }
        $this->gateway->assertBinding($record, $settings);
        return $record;
    }

    private function lookup(array $settings): array {
        $record = $this->record($settings);
        $body = $this->gateway->client($settings)->request('query', ['order_no' => $record['remote_order_no'], 'merchant_order_no' => $record['merchant_order_no']]);
        $state = PaymentState::apply($record, $body, 'query');
        return ['order_id' => $record['order_id'], 'merchant_order_no' => $record['merchant_order_no'],
            'stage' => $state['stage'], 'order_status' => $state['order_status'], 'paid_amount' => $state['paid_amount'],
            'remaining_amount' => $state['remaining_amount'], 'currency' => $record['currency']];
    }

    private function ship(array $settings): void {
        $record = $this->record($settings);
        $post = $this->service('request')->post;
        $shipment = ['logistics_company' => trim((string)($post['logistics_company'] ?? '')),
            'tracking_number' => trim((string)($post['tracking_number'] ?? '')),
            'tracking_url' => trim((string)($post['tracking_url'] ?? '')), 'shipped_at' => trim((string)($post['shipped_at'] ?? ''))];
        if ($shipment['logistics_company'] === '' || strlen($shipment['logistics_company']) > 100
            || $shipment['tracking_number'] === '' || strlen($shipment['tracking_number']) > 100
            || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d)?(?:Z|[+-]\d\d:\d\d)$/D', $shipment['shipped_at'])
            || strtotime($shipment['shipped_at']) === false
            || ($shipment['tracking_url'] !== '' && (!filter_var($shipment['tracking_url'], FILTER_VALIDATE_URL)
                || !in_array(parse_url($shipment['tracking_url'], PHP_URL_SCHEME), ['https', 'http'], true) || strlen($shipment['tracking_url']) > 255))) {
            throw new RuntimeException('Invalid shipment.');
        }
        $this->repository->locked((int)$record['store_id'], (int)$record['order_id'], function () use ($record, $shipment, $settings) {
            $fresh = $this->repository->find((int)$record['store_id'], (int)$record['order_id']);
            $this->gateway->assertBinding($fresh, $settings);
            $client = $this->gateway->client($settings);
            $query = $client->request('query', ['order_no' => $fresh['remote_order_no'], 'merchant_order_no' => $fresh['merchant_order_no']]);
            $verified = PaymentState::apply($fresh, $query, 'query');
            $shippingRecord = $fresh;
            $shippingRecord['state'] = $verified;
            foreach (PaymentState::shippingPayloads($shippingRecord, $shipment) as $payload) {
                $key = hash('sha256', Signature::canonical($payload));
                if (empty($fresh['state']['shipping_sent'][$key])) {
                    $client->request('ship', $payload);
                    $fresh['state']['shipping_sent'][$key] = true;
                    $this->repository->save($fresh);
                }
            }
        });
    }

    private function updates(array $settings): array {
        $base = rtrim((string)($settings['update_url'] ?: $settings['api_url']), '/');
        Client::https($base);
        $slug = 'sscpay-opencart' . $this->major;
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL required.');
        }
        $handle = curl_init($base . '/plugins/updates/v1/' . $slug);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $release = is_string($body) && strlen($body) < 65536 ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($release) || ($release['slug'] ?? '') !== $slug
            || !preg_match('/^\d+\.\d+\.\d+$/D', $release['version'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $release['sha256'] ?? '')
            || !is_string($release['download_url'] ?? null) || !filter_var($release['download_url'], FILTER_VALIDATE_URL)
            || parse_url($release['download_url'], PHP_URL_SCHEME) !== 'https'
            || parse_url($release['download_url'], PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)
            || parse_url($release['download_url'], PHP_URL_USER) !== null) {
            throw new RuntimeException('Invalid release information.');
        }
        return ['version' => $release['version'], 'sha256' => $release['sha256'], 'download_url' => $release['download_url']];
    }
}
