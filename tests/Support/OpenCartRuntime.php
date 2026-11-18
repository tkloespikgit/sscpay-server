<?php

namespace Tests\Support;

use RuntimeException;
use Sscpay\OpenCart\Repository;
use Sscpay\OpenCart\Signature;

if (! class_exists(Signature::class)) {
    require_once dirname(__DIR__, 2).'/plugins/opencart/shared/bootstrap.php';
}

if (! defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_test_');
}

class OpenCartRegistry
{
    public function __construct(public array $services = []) {}

    public function get(string $key): mixed
    {
        return $this->services[$key] ?? null;
    }
}

class OpenCartConfig
{
    public function __construct(public array $values) {}

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }
}

class OpenCartRepository extends Repository
{
    public array $records = [];

    public array $nonces = [];

    public bool $busy = false;

    public function __construct() {}

    public function install(): void {}

    public function locked(int $store, int $order, callable $callback): mixed
    {
        if ($this->busy) {
            throw new RuntimeException('Order is busy.');
        }
        $this->busy = true;
        try {
            return $callback();
        } finally {
            $this->busy = false;
        }
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function find(int $store, int $order): ?array
    {
        return $this->records[$store.':'.$order] ?? null;
    }

    public function byReference(int $store, string $reference): ?array
    {
        foreach ($this->records as $record) {
            if ((int) $record['store_id'] === $store && $record['merchant_order_no'] === $reference) {
                return $record;
            }
        }

        return null;
    }

    public function insert(array $record): void
    {
        if ($this->find((int) $record['store_id'], (int) $record['order_id'])) {
            throw new RuntimeException('Duplicate order.');
        }
        $this->save($record);
    }

    public function save(array $record): void
    {
        $this->records[$record['store_id'].':'.$record['order_id']] = $record;
    }

    public function nonce(string $app, string $nonce, array $body): void
    {
        $key = $app.':'.$nonce;
        if (isset($this->nonces[$key]) && $this->nonces[$key] !== $body) {
            throw new RuntimeException('Reused nonce.');
        }
        $this->nonces[$key] = $body;
    }
}

class OpenCartOrderModel
{
    public array $histories = [];

    public function __construct(public array $order) {}

    public function getOrder(int $id): array
    {
        return $id === (int) $this->order['order_id'] ? $this->order : [];
    }

    public function addOrderHistory(int $id, int $status, string $comment, bool $notify): void
    {
        $this->record('3', $id, $status, $comment, $notify);
    }

    public function addHistory(int $id, int $status, string $comment, bool $notify): void
    {
        $this->record('4', $id, $status, $comment, $notify);
    }

    private function record(string $major, int $id, int $status, string $comment, bool $notify): void
    {
        $this->histories[] = compact('major', 'id', 'status', 'comment', 'notify');
        $this->order['order_status_id'] = $status;
    }
}

class OpenCartResponse
{
    public array $headers = [];

    public string $output = '';

    public ?string $redirect = null;

    public function addHeader(string $header): void
    {
        $this->headers[] = $header;
    }

    public function setOutput(string $output): void
    {
        $this->output = $output;
    }

    public function redirect(string $url): void
    {
        $this->redirect = $url;
    }
}

class OpenCartRuntime
{
    public static function order(): array
    {
        return ['order_id' => 42, 'store_id' => 0, 'order_status_id' => 0, 'total' => '100.0000',
            'currency_code' => 'USD', 'currency_value' => '1.00000000', 'firstname' => 'Jane', 'lastname' => 'Doe',
            'email' => 'jane@example.test', 'telephone' => '123456', 'shipping_address_1' => '1 Main St',
            'shipping_city' => 'City', 'shipping_iso_code_2' => 'US'];
    }

    public static function registry(string $channel = 'paypal', int $major = 3): OpenCartRegistry
    {
        $prefix = 'payment_sscpay'.$channel.'_';
        $settings = ['status' => '1', 'api_url' => 'https://sscpay.example.test', 'app_id' => 'app-test', 'api_key' => 'secret-test',
            'group_key' => 'combine', 'payment_method_key' => '', 'platform' => 'opencart', 'update_url' => '',
            'pending_status_id' => '1', 'partial_status_id' => '3', 'paid_status_id' => '2',
            'cancelled_status_id' => '7', 'failed_status_id' => '10', 'refunded_status_id' => '11', 'chargeback_status_id' => '13',
            'geo_zone_id' => '0', 'sort_order' => '1'];
        $values = ['config_store_id' => 0, 'config_processing_status' => [2], 'config_complete_status' => [5], 'config_language' => 'en-gb'];
        foreach ($settings as $key => $value) {
            $values[$prefix.$key] = $value;
        }
        $registry = new OpenCartRegistry;
        $registry->services = [
            'config' => new OpenCartConfig($values),
            'request' => (object) ['get' => [], 'post' => ['sscpay_csrf' => 'checkout-token'], 'server' => ['REQUEST_METHOD' => 'POST']],
            'session' => (object) ['data' => ['order_id' => 42, 'payment_method' => ['code' => 'sscpay'.$channel.($major === 4 ? '.sscpay'.$channel : '')], 'sscpay_csrf' => 'checkout-token']],
            'model_checkout_order' => new OpenCartOrderModel(self::order()),
            'response' => new OpenCartResponse,
            'db' => new class
            {
                public string $price = '100.0000';

                public function query(string $sql): object
                {
                    if (str_contains($sql, 'order_product')) {
                        return (object) ['rows' => [['product_id' => 7, 'model' => 'SKU', 'name' => 'Product', 'price' => $this->price, 'quantity' => 1]]];
                    }
                    if (str_contains($sql, 'order_total')) {
                        return (object) ['rows' => []];
                    }
                    if (str_contains($sql, 'zone_to_geo_zone')) {
                        return (object) ['num_rows' => 0];
                    }
                    throw new RuntimeException('Unexpected database call.');
                }
            },
            'cart' => new class
            {
                public function hasSubscription(): bool
                {
                    return false;
                }

                public function hasRecurringProducts(): bool
                {
                    return false;
                }
            },
            'load' => new class
            {
                public array $views = [];

                public function model(string $route): void {}

                public function view(string $route, array $data): string
                {
                    $this->views[] = [$route, $data];

                    return 'rendered-view';
                }
            },
            'url' => new class
            {
                public function link(string $route, string $args, bool $secure): string
                {
                    return 'https://shop.example.test/index.php?route='.$route.($args ? '&'.$args : '');
                }
            },
        ];

        return $registry;
    }

    public static function record(int $major = 3, string $channel = 'paypal'): array
    {
        return ['store_id' => 0, 'order_id' => 42, 'gateway' => $channel, 'app_id' => 'app-test', 'api_url' => 'https://sscpay.example.test',
            'merchant_order_no' => 'OC'.$major.'-0-42', 'remote_order_no' => 'SSCPAY-42', 'amount' => '100.00', 'currency' => 'USD',
            'return_token' => str_repeat('a', 64), 'pay_url' => 'https://pay.example.test/session', 'state' => []];
    }

    public static function event(array $record, string $paid = '40.00', int $version = 1): array
    {
        return ['order_type' => 'combine', 'order_no' => $record['remote_order_no'], 'merchant_order_no' => $record['merchant_order_no'],
            'currency' => 'USD', 'amount' => '100.00', 'status' => $paid === '100.00' ? 'paid' : 'pending',
            'order_status' => $paid === '100.00' ? 'paid' : 'partially_paid', 'paid_amount' => $paid,
            'remaining_amount' => $paid === '100.00' ? '0.00' : '60.00', 'version' => $version, 'event_id' => 'event-'.$version,
            'children' => [['order_no' => 'SSCPAY-CHILD-1', 'merchant_order_no' => $record['merchant_order_no'].'-S001',
                'currency' => 'USD', 'amount' => $paid, 'payment_status' => 'paid', 'sequence' => 1]]];
    }
}
