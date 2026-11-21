<?php

/** In-memory WordPress/WooCommerce boundary for testing the shipped plugin code. */
class WP_Error
{
    public function __construct(private string $code, private string $message, private mixed $data = null) {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

#[AllowDynamicProperties]
class WC_Payment_Gateway
{
    public array $settings = [];

    public function init_settings(): void
    {
        $this->settings = get_option('woocommerce_'.$this->id.'_settings', []);
    }

    public function get_option(string $key, mixed $default = ''): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}

class WC_Order
{
    public array $meta = [];

    public array $notes = [];

    public string $status = 'pending';

    public int $paidCalls = 0;

    public string $customerIp = '';

    public function __construct(private int $id, private string $method, private string $total = '100.00') {}

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_total(): string
    {
        return $this->total;
    }

    public function get_currency(): string
    {
        return 'USD';
    }

    public function get_customer_ip_address(): string
    {
        return $this->customerIp;
    }

    public function set_customer_ip_address(string $address): void
    {
        $this->customerIp = $address;
    }

    public function get_checkout_payment_url(bool $onCheckout = false): string
    {
        return 'https://shop.example.com/checkout/order-pay/'.$this->id;
    }

    public function get_order_number(): string
    {
        return (string) $this->id;
    }

    public function get_payment_method(): string
    {
        return $this->method;
    }

    public function get_meta(string $key): mixed
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function read_meta_data(bool $force = false): void {}

    public function save(): void
    {
        $GLOBALS['wordpress_orders'][$this->id] = clone $this;
    }

    public function add_order_note(string $note): void
    {
        $this->notes[] = $note;
    }

    public function has_status(string|array $status): bool
    {
        return in_array($this->status, (array) $status, true);
    }

    public function is_paid(): bool
    {
        return $this->has_status(['processing', 'completed']);
    }

    public function update_status(string $status, string $note = ''): void
    {
        $this->status = $status;
        $this->add_order_note($note);
    }

    public function payment_complete(string $transaction): void
    {
        $this->paidCalls++;
        $this->status = 'processing';
    }

    public function get_checkout_order_received_url(): string
    {
        return 'https://shop.example/received';
    }

    public function get_order_key(): string
    {
        return 'order-key';
    }

    public function get_items(string $type = ''): array
    {
        return [];
    }

    public function __call(string $method, array $args): mixed
    {
        return str_contains($method, 'total') ? '0.00' : '';
    }
}

class WordPressTestDatabase
{
    public string $prefix = 'wp_';

    public bool $busy = false;

    public array $queries = [];

    public function prepare(string $sql, mixed ...$args): string
    {
        return $sql;
    }

    public function get_var(string $sql): string
    {
        $this->queries[] = $sql;

        return str_contains($sql, 'GET_LOCK') && $this->busy ? '0' : '1';
    }
}

function add_action(mixed ...$args): void {}
function add_filter(mixed ...$args): void {}
function do_action(mixed ...$args): void {}
function apply_filters(string $name, mixed $value, mixed ...$args): mixed
{
    return $value;
}
function add_query_arg(array $parameters, string $url): string
{
    return $url.'?'.http_build_query($parameters);
}
function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['wordpress_options'][$name] ?? $default;
}
function get_site_transient(string $name): mixed
{
    return $GLOBALS['wordpress_transients'][$name] ?? false;
}
function set_site_transient(string $name, mixed $value, int $ttl): void
{
    $GLOBALS['wordpress_transients'][$name] = $value;
}
function get_transient(string $name): mixed
{
    return get_site_transient($name);
}
function set_transient(string $name, mixed $value, int $ttl): void
{
    set_site_transient($name, $value, $ttl);
}
function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}
function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}
function untrailingslashit(string $url): string
{
    return rtrim($url, '/');
}
function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}
function wp_remote_get(string $url, array $args): array|WP_Error
{
    return ($GLOBALS['wordpress_http'])($url, $args);
}
function wp_remote_post(string $url, array $args): array|WP_Error
{
    return ($GLOBALS['wordpress_http'])($url, $args);
}
function wp_remote_retrieve_body(array $response): string
{
    return $response['body'] ?? '';
}
function wp_remote_retrieve_response_code(array $response): int
{
    return $response['response']['code'];
}
function wp_tempnam(string $name): string|false
{
    return tempnam(sys_get_temp_dir(), 'plugin-update-');
}
function wp_delete_file(string $name): void
{
    unlink($name);
}
function wc_get_order(int $id): ?WC_Order
{
    return isset($GLOBALS['wordpress_orders'][$id]) ? clone $GLOBALS['wordpress_orders'][$id] : null;
}
function wc_create_order(array $args): WC_Order
{
    $order = new WC_Order(100 + count($GLOBALS['wordpress_orders']), '');
    $order->status = $args['status'];
    $order->set_customer_ip_address('192.0.2.60');
    $order->save();

    return $order;
}
function wc_get_orders(array $args): array
{
    $result = [];
    foreach ($GLOBALS['wordpress_orders'] as $id => $order) {
        $condition = $args['meta_query'][0] ?? null;
        if ($condition === null || $order->get_meta($condition['key']) === $condition['value']) {
            $result[] = $id;
        }
    }

    return $result;
}
function WC(): object
{
    return new class
    {
        public function api_request_url(string $endpoint): string
        {
            return 'https://shop.example.com/wc-api/'.$endpoint.'/';
        }

        public function payment_gateways(): object
        {
            return new class
            {
                public function payment_gateways(): array
                {
                    return $GLOBALS['wordpress_gateways'];
                }
            };
        }
    };
}
function wc_get_price_decimals(): int
{
    return 2;
}
function wc_is_valid_url(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
function wp_strip_all_tags(string $text): string
{
    return strip_tags($text);
}
function wc_add_notice(string $text, string $type): void {}
function home_url(string $path): string
{
    return 'https://shop.example'.$path;
}

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
foreach (['paypal', 'creditcard'] as $channel) {
    define('SSCPAY_'.strtoupper($channel).'_GATEWAY_ID', 'sscpay_'.$channel);
    define('SSCPAY_'.strtoupper($channel).'_VERSION', '1.2.0');
    foreach (['logger', 'signature', 'api-client', 'order-helper', 'order-state', 'gateway', 'webhook', 'updater', 'ad-tracking'] as $module) {
        require_once dirname(__DIR__, 2).'/plugins/wordpress/sscpay-payment-'.$channel.'/includes/class-sscpay-'.$channel.'-'.$module.'.php';
    }
}
