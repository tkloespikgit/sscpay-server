<?php
/** Run: php tests/payment-session-lifecycle.php; no WordPress bootstrap, real DB or network. */
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s', \PaymentGatewayAggregator\Tests\Sessions\Fixture::$clock ?? time()); }
    function get_option($name, $default = false) { return $default; }
    function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
    function home_url($path = '') { return 'https://example.invalid' . $path; }
    function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
    function add_query_arg($key, $value, $url) { return $url . '?' . rawurlencode($key) . '=' . rawurlencode($value); }
    function wp_generate_uuid4() { return sprintf('00000000-0000-4000-8000-%012d', ++\PaymentGatewayAggregator\Tests\Sessions\Fixture::$uuid); }
    class WP_REST_Request {
        private $body;
        public function __construct(array $body) { $this->body = $body; }
        public function get_json_params() { return $this->body; }
    }
    class WP_REST_Response {
        private $data, $status;
        public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
}

namespace PaymentGatewayAggregator\Tests\Sessions {
    class Fixture {
        public static $clock, $gateway, $inspection, $onCreate, $onRetire;
        public static $uuid = 0, $gets = 0, $retires = 0;
        public static $getError = false, $createError = false, $retireResult = true, $retireError = false;
        public static $creates = [], $remote = [], $references = [], $orders = [], $callbacks = [], $logs = [], $steps = [];
        public static function reset($method = 'paypal_rest', $cached = false) {
            global $wpdb;
            $wpdb = new MemoryWpdb();
            self::$clock = \time();
            self::$gateway = new FakeGateway();
            self::$uuid = self::$gets = self::$retires = 0;
            self::$getError = self::$createError = self::$retireError = false;
            self::$retireResult = true;
            self::$onCreate = self::$onRetire = null;
            self::$creates = self::$remote = self::$references = self::$orders = self::$callbacks = self::$logs = self::$steps = [];
            self::$inspection = self::inspection('reusable');
            $session = $cached ? ['external_ref' => 'ORDER-OLD', 'redirect_url' => 'https://example.invalid/old',
                '_attempt_key' => 'initial-key', '_attempt_started_at' => self::$clock - 60,
                'local_expires_at' => self::$clock - 60 + self::ttl($method)] : null;
            $wpdb->rows['S-001'] = [
                's_order_id' => 'S-001', 'wp_order_id' => 1, 'payment_method' => $method, 'status' => 'pending',
                'payment_intent' => $cached ? 'ORDER-OLD' : null, 'transaction_id' => null,
                'gateway_session' => $session ? json_encode($session) : null,
                'gateway_session_expires_at' => $cached ? gmdate('Y-m-d H:i:s', $session['local_expires_at']) : null,
                'gateway_config' => 'synthetic-snapshot', 'amount' => '12.34', 'currency' => 'USD',
                'expires_at' => '9999-12-31 23:59:59', 'paid_at' => null, 'pay_token' => 'synthetic-token',
                'trans_type' => 'VIRTUAL', 'invoice_number' => 'INV-001',
                'created_at' => gmdate('Y-m-d H:i:s', self::$clock - 60), 'updated_at' => gmdate('Y-m-d H:i:s', self::$clock),
            ];
        }
        public static function ttl($method) { return $method === 'stripe' ? 82800 : 7200; }
        public static function row() { global $wpdb; return $wpdb->rows['S-001']; }
        public static function session() { return json_decode(self::row()['gateway_session'] ?? 'null', true); }
        public static function overdue() {
            global $wpdb;
            $session = self::session();
            $session['local_expires_at'] = self::$clock;
            $session['_attempt_started_at'] = self::$clock - self::ttl(self::row()['payment_method']);
            $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
            $wpdb->rows['S-001']['gateway_session_expires_at'] = gmdate('Y-m-d H:i:s', self::$clock);
        }
        public static function inspection($state, $status = null) {
            return ['session_state' => $state, 'status' => $status,
                'transaction_id' => $status === 'paid' ? 'CAPTURE-PAID' : null,
                'amount_minor' => $status === 'paid' ? 1234 : null, 'currency' => $status === 'paid' ? 'USD' : null];
        }
    }
    class FakeGateway extends \PaymentGatewayAggregator\Core\PaymentBase {
        public function code() { return Fixture::row()['payment_method']; }
        public function validateConfig(array $config) { return []; }
        public function renderPaymentPage(array $transaction, array $data) { return ''; }
        public function extractExternalRef($body, array $headers) { return null; }
        public function verifyCallbackSignature($body, array $headers, array $config) { return false; }
        public function parseCallbackEvent($body, array $headers) { return []; }
        public function healthCheck(array $config) { return ['status' => 'healthy']; }
        public function syncTrackingToRemote(array $transaction, array $tracking) { return ['supported' => false]; }
        public function queryOrderStatus(array $transaction) { return $this->inspectPaymentSession($transaction); }
        public function inspectPaymentSession(array $transaction) {
            Fixture::$gets++;
            if (Fixture::$getError instanceof \Throwable) { throw Fixture::$getError; }
            if (Fixture::$getError) { throw new \RuntimeException('Synthetic query outage'); }
            return Fixture::$inspection;
        }
        public function retirePaymentSession(array $transaction): bool {
            Fixture::$retires++;
            Fixture::$steps[] = 'retire';
            if (Fixture::$onRetire) { (Fixture::$onRetire)($transaction); }
            if (Fixture::$retireError) { throw new \RuntimeException('Synthetic retirement outage'); }
            return Fixture::$retireResult;
        }
        public function preparePayment(array $transaction) {
            $key = $transaction['payment_attempt_key'] ?? 'missing-key';
            Fixture::$creates[] = $transaction;
            Fixture::$steps[] = 'create';
            if (Fixture::$onCreate) { (Fixture::$onCreate)($transaction); }
            if (!isset(Fixture::$remote[$key])) {
                Fixture::$remote[$key] = ['external_ref' => 'ORDER-NEW', 'order_id' => 'ORDER-NEW',
                    'redirect_url' => 'https://example.invalid/new'];
            }
            if (Fixture::$createError) { throw new \RuntimeException('Synthetic timeout after platform creation'); }
            return Fixture::$remote[$key];
        }
    }
    class MemoryWpdb {
        public $prefix = 'isolated_', $last_error = '', $insert_id = 0;
        public $rows = [], $updates = [], $locks = [], $releases = [];
        public $lockAvailable = true, $failUpdates = false, $beforeUpdate;
        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public function get_var($prepared) {
            [$sql, $args] = $prepared;
            if (strpos($sql, 'GET_LOCK(') !== false) {
                if (!$this->lockAvailable || isset($this->locks[$args[0]])) { return '0'; }
                $this->locks[$args[0]] = true;
                return '1';
            }
            if (strpos($sql, 'RELEASE_LOCK(') !== false) {
                $this->releases[] = $args[0];
                unset($this->locks[$args[0]]);
                return '1';
            }
            throw new \RuntimeException('Unexpected scalar query in isolated runner');
        }
        public function get_row($prepared, $format) {
            [$sql, $args] = $prepared;
            if (strpos($sql, 'SELECT * FROM isolated_payment_transactions WHERE s_order_id = %s') !== false) {
                return $this->rows[$args[0]] ?? null;
            }
            throw new \RuntimeException('Unexpected row lookup in isolated runner');
        }
        public function insert($table, array $row) {
            if ($table !== 'isolated_payment_transactions' || isset($this->rows[$row['s_order_id']])) {
                throw new \RuntimeException('Unexpected insert in isolated runner');
            }
            $this->insert_id++;
            $this->rows[$row['s_order_id']] = array_merge(['payment_intent' => null, 'transaction_id' => null,
                'paid_at' => null, 'gateway_session_expires_at' => null], $row);
            return 1;
        }
        public function update($table, array $fields, array $where, $formats = null, $whereFormats = null) {
            if ($table !== 'isolated_payment_transactions' || !isset($where['s_order_id'])) {
                throw new \RuntimeException('Unexpected update in isolated runner');
            }
            if ($this->beforeUpdate) { $hook = $this->beforeUpdate; $this->beforeUpdate = null; $hook($fields, $where); }
            if ($this->failUpdates) { $this->last_error = 'Synthetic write failure'; return false; }
            $row = $this->rows[$where['s_order_id']] ?? null;
            $matched = $row !== null;
            foreach ($where as $key => $value) {
                if (!$row || !array_key_exists($key, $row) || $row[$key] !== $value) { $matched = false; }
            }
            $this->updates[] = ['where' => $where, 'fields' => $fields, 'matched' => $matched];
            if (!$matched) { return 0; }
            $this->rows[$where['s_order_id']] = array_merge($row, $fields);
            return 1;
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\Sessions\Fixture;
    function time() { return Fixture::$clock ?? \time(); }
    class GatewayFactory {
        public static function isSupported($method) { return in_array($method, ['stripe', 'paypal_js', 'paypal_rest', 'airwallex'], true); }
        public static function create($method, array $config = []) {
            if (!self::isSupported($method)) { throw new \RuntimeException('Unsupported synthetic gateway'); }
            return Fixture::$gateway;
        }
    }
    class ConfigEncryptor {
        public static function encrypt(array $config) { return 'synthetic-snapshot'; }
        public static function decrypt($snapshot) {
            if ($snapshot !== 'synthetic-snapshot') { throw new \RuntimeException('Unexpected synthetic snapshot'); }
            return ['mode' => 'sandbox'];
        }
    }
    class PaymentSessionReferences {
        public static function remember(...$args) { Fixture::$references[] = $args; }
        // Historical lookup/persistence has its own integration cases in the other runners.
        public static function findReference($externalRef) { return null; }
        public static function decodeSessionData(array $reference) { return json_decode($reference['session_data'] ?? '{}', true); }
    }
    class LogManager {
        const SYSTEM_ORDER_ID = 'SYSTEM';
        public static function __callStatic($level, $args) { Fixture::$logs[] = [$level, $args]; }
    }
    class OrderManager {
        public static function createOrder(array $body) { return 1; }
        public static function __callStatic($method, $args) { Fixture::$orders[] = [$method, $args]; }
    }
    class CallbackHandler {
        public static function forwardPaymentStatus(array $transaction, $forbidden = false) { Fixture::$callbacks[] = $transaction; }
    }
}

namespace {
    use PaymentGatewayAggregator\Core\Exceptions\PayPalOrderNotFoundException;
    use PaymentGatewayAggregator\Core\PaymentSessionManager;
    use PaymentGatewayAggregator\Core\TransactionManager;
    use PaymentGatewayAggregator\Core\WebhookRouter;
    use PaymentGatewayAggregator\Tests\Sessions\Fixture;
    set_error_handler(function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    function same($expected, $actual, $message) {
        if ($expected !== $actual) { throw new \RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    }
    function mustThrow($callback, $message) {
        try { $callback(); } catch (\RuntimeException $error) { return; }
        throw new \RuntimeException($message);
    }
    function ensure($forPaymentPage = true) { return PaymentSessionManager::ensure(Fixture::row(), $forPaymentPage); }
    function released() { global $wpdb; same([], $wpdb->locks, 'All acquired locks released'); }
    function noEffects() { same([], Fixture::$orders, 'No order effects'); same([], Fixture::$callbacks, 'No payment callbacks'); }
    function deadline($expected) {
        same($expected, Fixture::session()['local_expires_at'], 'Cached attempt deadline');
        same(gmdate('Y-m-d H:i:s', $expected), Fixture::row()['gateway_session_expires_at'], 'SQL UTC attempt deadline');
    }
    function missingPayPalOrderAfterNineHours($legacySource = null) {
        global $wpdb;
        $started = Fixture::$clock - 9 * 3600;
        $session = Fixture::session();
        $session['_attempt_started_at'] = $started;
        $session['local_expires_at'] = $started + 7200;
        $wpdb->rows['S-001']['created_at'] = gmdate('Y-m-d H:i:s', $started);
        $wpdb->rows['S-001']['gateway_session_expires_at'] = gmdate('Y-m-d H:i:s', $started + 7200);
        if ($legacySource !== null) {
            unset($session['local_expires_at'], $session['_attempt_started_at']);
            if ($legacySource !== 'created_at') { $session[$legacySource] = $started; }
            $wpdb->rows['S-001']['gateway_session_expires_at'] = null;
        }
        $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
        Fixture::$getError = new PayPalOrderNotFoundException('PayPal查询订单状态失败：synthetic RESOURCE_NOT_FOUND / INVALID_RESOURCE_ID');
    }
    function payBody() {
        return ['s_order_id' => 'S-NEW', 'amount' => '12.34', 'currency' => 'USD', 'payment_method' => 'paypal_rest',
            'trans_type' => 'VIRTUAL', 'invoice_number' => 'INV-NEW', 'subject' => 'Synthetic item', 'subtotal' => '12.34',
            'callback_url' => 'https://example.invalid/callback', 'return_url' => 'https://example.invalid/return',
            'cancel_url' => 'https://example.invalid/cancel', 'customer' => ['name' => 'Test', 'email' => 'customer@example.invalid'],
            'billing_address' => ['country' => 'US'], 'shipping_address' => ['country' => 'US'],
            'items' => [['name' => 'Synthetic item', 'quantity' => 1, 'price' => '12.34']],
            'gateway_config' => ['paypal_rest' => ['mode' => 'sandbox']]];
    }

    $tests = [];
    $tests['optional customer IP accepts single IPv4 and IPv6 addresses and rejects malformed values'] = function () {
        foreach (['198.51.100.42', '2001:db8::42', null] as $customerIp) {
            Fixture::reset();
            $response = \PaymentGatewayAggregator\API\Controllers\PayController::handle(new \WP_REST_Request(payBody() + ['customer_ip' => $customerIp]));
            same(200, $response->get_status(), 'A valid or absent customer IP allows payment');
            same(0, $response->get_data()['code'], 'Payment created');
        }
        foreach (['not-an-ip', ['198.51.100.42'], '192.0.2.1, 192.0.2.2'] as $customerIp) {
            Fixture::reset();
            $response = \PaymentGatewayAggregator\API\Controllers\PayController::handle(new \WP_REST_Request(payBody() + ['customer_ip' => $customerIp]));
            same(10005, $response->get_data()['code'], 'Invalid customer IP rejected');
            same([], Fixture::$creates, 'Invalid customer IP must not initiate a platform payment');
        }
    };
    $tests['pay API precreates once and idempotent repeats reuse cache without GET or renewal'] = function () {
        global $wpdb;
        Fixture::reset();
        foreach ([1, 2] as $request) {
            $response = \PaymentGatewayAggregator\API\Controllers\PayController::handle(new \WP_REST_Request(payBody()));
            same(200, $response->get_status(), 'Pay response status');
            same(0, $response->get_data()['code'], 'Pay response success');
            same('ORDER-NEW', $wpdb->rows['S-NEW']['payment_intent'], 'Platform order precreated');
            same(gmdate('Y-m-d\TH:i:s\Z', strtotime($wpdb->rows['S-NEW']['gateway_session_expires_at'] . ' UTC')),
                $response->get_data()['data']['gateway_session_expires_at'], 'Pay API exposes platform-session UTC deadline');
            same(1, count(Fixture::$creates), 'One precreation across requests');
            same(0, Fixture::$gets, 'Pay API does not inspect cached checkout');
            // An idempotent API retry must not renew even an old platform-session deadline.
            $wpdb->rows['S-NEW']['gateway_session_expires_at'] = '2000-01-01 00:00:00';
        }
        foreach (['2000-01-01 00:00:00', null] as $storedDeadline) {
            $wpdb->rows['S-NEW']['gateway_session_expires_at'] = $storedDeadline;
            $response = \PaymentGatewayAggregator\API\Controllers\OrderQueryController::handle(new \WP_REST_Request(['s_order_id' => 'S-NEW']));
            same($storedDeadline === null ? null : '2000-01-01T00:00:00Z', $response->get_data()['data']['gateway_session_expires_at'],
                'Query API exposes known UTC deadline or legacy null');
            same(1, count(Fixture::$creates), 'Order queries do not create replacement sessions');
        }
        noEffects(); released();
    };
    $tests['creation persists stable key before HTTP and writes PayPal or Stripe attempt TTL'] = function () {
        global $wpdb;
        foreach (['paypal_js', 'paypal_rest', 'stripe'] as $method) {
            Fixture::reset($method);
            Fixture::$onCreate = function ($transaction) {
                $marker = Fixture::session();
                same(true, $marker['_creating'], 'Marker persisted before remote creation');
                same($marker['_attempt_key'], $transaction['payment_attempt_key'] ?? null, 'Stable key passed to provider');
            };
            $result = ensure(false);
            same('ORDER-NEW', $result['transaction']['payment_intent'], 'Fresh platform reference returned');
            same('https://example.invalid/new', $result['sessionData']['redirect_url'], 'Checkout returned');
            deadline(Fixture::$clock + Fixture::ttl($method));
            same(0, Fixture::$gets, 'Initial precreation needs no query');
            same(true, array_key_exists('gateway_session', $wpdb->updates[0]['where']), 'CAS matches old session');
            same(true, array_key_exists('payment_intent', $wpdb->updates[0]['where']), 'CAS matches old reference');
            noEffects(); released();
        }
    };
    $tests['loading then checkout query a live cached attempt without double creation'] = function () {
        Fixture::reset('paypal_js', true);
        foreach ([1, 2] as $visit) {
            same('ORDER-OLD', ensure()['transaction']['payment_intent'], 'Live checkout reused');
        }
        same([], Fixture::$creates, 'No new order for two page requests');
        same(2, Fixture::$gets, 'Each page inspects payment');
        noEffects(); released();
    };
    $tests['new Stripe creation drafts persist metadata version before HTTP and retain it across timeout retries'] = function () {
        Fixture::reset('stripe');
        Fixture::$onCreate = function ($transaction) {
            same(1, Fixture::session()['_stripe_metadata_version'] ?? null, 'Version persisted before provider request');
            same(1, $transaction['stripe_metadata_version'] ?? null, 'Version passed to provider');
        };
        Fixture::$createError = true;
        mustThrow(function () { ensure(false); }, 'First provider timeout propagates');
        $key = Fixture::session()['_attempt_key'];
        Fixture::$clock += 60;
        Fixture::$createError = false;
        ensure(false);
        same($key, Fixture::$creates[1]['payment_attempt_key'], 'Draft retry keeps the same key');
        same(1, Fixture::$creates[1]['stripe_metadata_version'], 'Draft retry keeps the same version');
        same(1, Fixture::session()['_stripe_metadata_version'], 'Successful Session retains version');
        same(1, count(Fixture::$remote), 'One idempotent remote Session');
        noEffects(); released();
    };
    $tests['legacy Stripe timeout drafts retain parameter version zero without acquiring new metadata'] = function () {
        global $wpdb;
        Fixture::reset('stripe');
        $wpdb->rows['S-001']['gateway_session'] = json_encode([
            '_creating' => true, '_attempt_key' => 'legacy-persisted-key', '_attempt_started_at' => Fixture::$clock - 10,
            '_previous_session_ref' => null, '_previous_expires_at' => null,
        ]);
        Fixture::$onCreate = function ($transaction) {
            same(false, array_key_exists('_stripe_metadata_version', Fixture::session()), 'Legacy draft not rewritten before request');
            same(0, $transaction['stripe_metadata_version'] ?? null, 'Legacy request uses old parameter shape');
        };
        Fixture::$createError = true;
        mustThrow(function () { ensure(false); }, 'Legacy provider timeout propagates');
        Fixture::$clock += 60;
        Fixture::$createError = false;
        ensure(false);
        same('legacy-persisted-key', Fixture::$creates[1]['payment_attempt_key'], 'Old key retained');
        same(0, Fixture::$creates[1]['stripe_metadata_version'], 'Old request shape retained across retry');
        same(0, Fixture::session()['_stripe_metadata_version'], 'Successful old Session records old version');
        same(1, count(Fixture::$remote), 'No duplicate collection');
        noEffects(); released();
    };
    $tests['PayPal reaches its local deadline and renews with a fresh two-hour window'] = function () {
        foreach (['reusable', 'closed'] as $state) {
            Fixture::reset('paypal_rest', true); Fixture::overdue();
            Fixture::$inspection = Fixture::inspection($state);
            same('ORDER-NEW', ensure()['transaction']['payment_intent'], 'Deadline-inclusive renewal');
            deadline(Fixture::$clock + 7200);
            same(1, count(Fixture::$creates), 'One replacement');
            same(0, Fixture::$retires, 'No Stripe retirement applied to PayPal');
            noEffects(); released();
        }
    };
    $tests['PayPal JS and REST missing unpaid Order after nine hours renew with a new reference deadline and request key'] = function () {
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            Fixture::reset($method, true); missingPayPalOrderAfterNineHours();
            $before = Fixture::row();
            $result = ensure();
            same('ORDER-NEW', $result['transaction']['payment_intent'], 'Missing old PayPal order replaced');
            same('ORDER-NEW', $result['sessionData']['external_ref'], 'New checkout exposed');
            same('pending', Fixture::row()['status'], 'Renewal does not claim payment or expire the local order');
            same(1, Fixture::$gets, 'Old PayPal order inspected once');
            same(1, count(Fixture::$creates), 'One replacement order');
            same(false, 'initial-key' === Fixture::session()['_attempt_key'], 'New attempt gets a new key');
            same(Fixture::session()['_attempt_key'], Fixture::$creates[0]['payment_attempt_key'], 'Saved new key used for HTTP creation');
            same('ORDER-OLD', Fixture::$references[0][0]['payment_intent'], 'Old reference remembered for delayed callbacks');
            foreach (['s_order_id', 'amount', 'currency', 'expires_at', 'created_at'] as $field) {
                same($before[$field], Fixture::row()[$field], 'Root order ' . $field . ' retained');
            }
            same(null, Fixture::row()['transaction_id'], 'No payment identity invented');
            same(0, Fixture::$retires, 'No unsupported PayPal retirement call');
            deadline(Fixture::$clock + 7200); noEffects(); released();
        }
    };
    $tests['PayPal missing Order can renew at the exact local deadline'] = function () {
        Fixture::reset('paypal_rest', true); missingPayPalOrderAfterNineHours(); Fixture::overdue();
        same('ORDER-NEW', ensure()['transaction']['payment_intent'], 'Deadline is inclusive');
        deadline(Fixture::$clock + 7200); noEffects(); released();
    };
    $tests['PayPal missing legacy Order with NULL deadline uses historical creation timestamps'] = function () {
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            foreach (['_attempt_started_at', 'provider_created_at', 'created_at'] as $source) {
                Fixture::reset($method, true); missingPayPalOrderAfterNineHours($source);
                same('ORDER-NEW', ensure()['transaction']['payment_intent'], 'Legacy missing Order renews using ' . $source);
                same(1, count(Fixture::$creates), 'One legacy replacement');
                deadline(Fixture::$clock + 7200); noEffects(); released();
            }
        }
    };
    $tests['PayPal missing Order before expiry or with capture evidence cannot authorize renewal'] = function () {
        global $wpdb;
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            foreach (['future_deadline', 'transaction_id', 'paid_at', 'missing_cache', 'mismatched_reference'] as $guard) {
                Fixture::reset($method, true); missingPayPalOrderAfterNineHours();
                if ($guard === 'future_deadline') { $wpdb->rows['S-001']['gateway_session_expires_at'] = gmdate('Y-m-d H:i:s', Fixture::$clock + 60); }
                if ($guard === 'transaction_id') { $wpdb->rows['S-001']['transaction_id'] = 'CAPTURE-EXISTING'; }
                if ($guard === 'paid_at') { $wpdb->rows['S-001']['paid_at'] = gmdate('Y-m-d H:i:s', Fixture::$clock - 10); }
                if ($guard === 'missing_cache') { $wpdb->rows['S-001']['gateway_session'] = null; }
                if ($guard === 'mismatched_reference') {
                    $session = Fixture::session(); $session['external_ref'] = 'ORDER-UNRELATED';
                    $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
                }
                mustThrow(function () { ensure(); }, 'Missing Order cannot bypass ' . $guard);
                same([], Fixture::$creates, 'No replacement for ' . $guard);
                same('ORDER-OLD', Fixture::row()['payment_intent'], 'Old reference retained for ' . $guard);
                same('pending', Fixture::row()['status'], 'Query failure does not change local payment status');
                noEffects(); released();
            }
        }
    };
    $tests['PayPal missing Order never reopens paid or expired local payment links'] = function () {
        global $wpdb;
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            foreach (['paid', 'expired', 'pending'] as $status) {
                Fixture::reset($method, true); missingPayPalOrderAfterNineHours();
                $wpdb->rows['S-001']['status'] = $status;
                if ($status !== 'paid') { $wpdb->rows['S-001']['expires_at'] = gmdate('Y-m-d H:i:s', Fixture::$clock - 1); }
                $result = ensure();
                same($status === 'paid' ? 'paid' : 'expired', $result['transaction']['status'], 'Local final status retained');
                same(null, $result['gateway'], 'No gateway exposed after local completion or expiry');
                same([], $result['sessionData'], 'No checkout exposed');
                same([], Fixture::$creates, 'Final local order has no replacement');
                same('ORDER-OLD', Fixture::row()['payment_intent'], 'Existing reference retained');
                noEffects(); released();
            }
        }
    };
    $tests['PayPal missing Order renewal timeout retries preserve the saved new attempt key including legacy rows'] = function () {
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            foreach ([null, 'created_at'] as $legacySource) {
                Fixture::reset($method, true); missingPayPalOrderAfterNineHours($legacySource);
                $started = Fixture::$clock;
                Fixture::$createError = true;
                mustThrow(function () { ensure(); }, 'Replacement creation timeout surfaces');
                same(1, count(Fixture::$creates), 'First attempt reached replacement creation');
                $marker = Fixture::session();
                same(true, $marker['_creating'], 'Uncertain attempt remains persisted');
                same('ORDER-OLD', $marker['_previous_session_ref'], 'Old missing reference retained for retry inspection');
                same(false, $marker['_attempt_key'] === 'initial-key', 'Replacement uses a new attempt key');
                released();
                Fixture::$clock += 60;
                Fixture::$createError = false;
                same('ORDER-NEW', ensure(false)['transaction']['payment_intent'], 'API retry resumes existing draft');
                same(2, Fixture::$gets, 'Old missing reference rechecked on retry');
                same(2, count(Fixture::$creates), 'Two HTTP attempts');
                same($marker['_attempt_key'], Fixture::$creates[1]['payment_attempt_key'], 'Retry reuses saved replacement key');
                same(1, count(Fixture::$remote), 'One idempotent replacement order');
                deadline($started + 7200); noEffects(); released();
            }
        }
    };
    $tests['PayPal missing Order cannot borrow a new draft timestamp or mismatched previous reference as expiry evidence'] = function () {
        global $wpdb;
        foreach (['unknown_old_deadline', 'mismatched_old_reference'] as $guard) {
            Fixture::reset('paypal_rest', true); missingPayPalOrderAfterNineHours();
            $marker = ['_creating' => true, '_attempt_key' => 'persisted-retry-key', '_attempt_started_at' => Fixture::$clock - 10,
                '_previous_session_ref' => $guard === 'mismatched_old_reference' ? 'ORDER-UNRELATED' : 'ORDER-OLD'];
            $wpdb->rows['S-001']['gateway_session'] = json_encode($marker);
            if ($guard === 'unknown_old_deadline') { $wpdb->rows['S-001']['gateway_session_expires_at'] = null; }
            mustThrow(function () { ensure(false); }, 'Unproven previous expiry blocks draft retry');
            same([], Fixture::$creates, 'No new draft request for ' . $guard);
            same('persisted-retry-key', Fixture::session()['_attempt_key'], 'Existing draft key retained');
            noEffects(); released();
        }
    };
    $tests['PayPal ordinary 401 403 500 404 and timeout errors do not rebuild overdue sessions'] = function () {
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            foreach (['HTTP 401', 'HTTP 403', 'HTTP 500', 'HTTP 404 RESOURCE_NOT_FOUND INVALID_RESOURCE_ID', 'network timeout'] as $error) {
                Fixture::reset($method, true); Fixture::overdue();
                Fixture::$getError = new \RuntimeException($error);
                mustThrow(function () { ensure(); }, 'Generic query error must propagate');
                same([], Fixture::$creates, 'No replacement for ordinary ' . $error);
                same('ORDER-OLD', Fixture::row()['payment_intent'], 'Old reference kept');
                noEffects(); released();
            }
        }
        Fixture::reset('stripe', true); Fixture::overdue();
        Fixture::$getError = new PayPalOrderNotFoundException('Synthetic wrong-provider error');
        mustThrow(function () { ensure(); }, 'PayPal exception cannot authorize Stripe renewal');
        same([], Fixture::$creates, 'No Stripe replacement');
        same(0, Fixture::$retires, 'No Stripe retirement on query failure'); noEffects(); released();
    };
    $tests['Stripe retirement succeeds before renewing its twenty-three-hour window'] = function () {
        Fixture::reset('stripe', true); Fixture::overdue();
        same('ORDER-NEW', ensure()['transaction']['payment_intent'], 'Stripe replacement returned');
        same(['retire', 'create'], Fixture::$steps, 'Old Stripe checkout closed first');
        deadline(Fixture::$clock + 82800);
        noEffects(); released();
    };
    $tests['Stripe retirement false or outage never creates a replacement'] = function () {
        foreach (['false', 'error'] as $failure) {
            Fixture::reset('stripe', true); Fixture::overdue();
            Fixture::$retireResult = false; Fixture::$retireError = $failure === 'error';
            mustThrow(function () { ensure(); }, 'Unconfirmed retirement blocks replacement');
            same([], Fixture::$creates, 'No unsafe Stripe replacement');
            same('ORDER-OLD', Fixture::row()['payment_intent'], 'Old reference retained');
            noEffects(); released();
        }
    };
    $tests['existing Stripe reference with missing cache cannot create an unretired replacement'] = function () {
        global $wpdb;
        Fixture::reset('stripe', true);
        $wpdb->rows['S-001']['gateway_session'] = null;
        Fixture::$retireResult = false;
        mustThrow(function () { ensure(); }, 'Missing cache must not bypass old-session retirement');
        same([], Fixture::$creates, 'No new Stripe session while old checkout may accept payment');
        same('ORDER-OLD', Fixture::row()['payment_intent'], 'Old platform reference retained');
        noEffects(); released();
    };
    $tests['Stripe retirement race rechecks paid status instead of creating a second checkout'] = function () {
        Fixture::reset('stripe', true); Fixture::overdue();
        Fixture::$retireResult = false;
        Fixture::$onRetire = function () { Fixture::$inspection = Fixture::inspection('closed', 'paid'); };
        same('paid', ensure()['transaction']['status'], 'Payment discovered after retirement race');
        same(2, Fixture::$gets, 'Payment rechecked after retirement failed');
        same([], Fixture::$creates, 'No new collection after payment');
        same(1, count(Fixture::$callbacks), 'Payment callback occurs once'); released();
    };
    $tests['platform-confirmed closed session can renew before the local deadline'] = function () {
        foreach (['paypal_rest', 'stripe'] as $method) {
            Fixture::reset($method, true); Fixture::$inspection = Fixture::inspection('closed');
            same('ORDER-NEW', ensure()['transaction']['payment_intent'], 'Closed platform session replaced');
            same(1, count(Fixture::$creates), 'One replacement for closed session');
            deadline(Fixture::$clock + Fixture::ttl($method)); noEffects(); released();
        }
    };
    $tests['processing unknown false or failed queries do not renew overdue attempts'] = function () {
        foreach (['processing', 'unknown', 'false', 'outage'] as $state) {
            Fixture::reset('paypal_rest', true); Fixture::overdue();
            Fixture::$inspection = $state === 'false' ? false : Fixture::inspection($state);
            Fixture::$getError = $state === 'outage';
            mustThrow(function () { ensure(); }, 'Uncertain payment stops renewal');
            same([], Fixture::$creates, 'No create for ' . $state);
            same('pending', Fixture::row()['status'], 'Uncertain result does not close order');
            noEffects(); released();
        }
    };
    $tests['reopened PayPal approval reuses the same Order even past the local session TTL'] = function () {
        global $wpdb;
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            foreach ([false, true] as $overdue) {
                Fixture::reset($method, true);
                if ($overdue) { Fixture::overdue(); }
                $session = Fixture::session();
                $session['order_id'] = 'ORDER-OLD';
                $session['redirect_url'] = 'https://www.paypal.com/checkoutnow?token=ORDER-OLD';
                $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
                $original = Fixture::row();
                Fixture::$inspection = Fixture::inspection('requires_approval');
                $result = ensure();
                same('ORDER-OLD', $result['sessionData']['order_id'], 'Same payable Order resumed');
                same($session['redirect_url'], $result['sessionData']['redirect_url'], 'Original buyer approval URL resumed');
                same($original['gateway_session'], Fixture::row()['gateway_session'], 'Reopening does not replace the session');
                same($original['gateway_session_expires_at'], Fixture::row()['gateway_session_expires_at'], 'TTL not extended');
                same([], Fixture::$creates, 'Approved Order never leads to another payable attempt');
                same(1, Fixture::$gets, 'Fresh provider inspection required');
                noEffects(); released();
            }
        }
    };
    $tests['PayPal resumes the current provider action URL or the saved funding recovery URL'] = function () {
        global $wpdb;
        foreach ([false, true] as $providerLink) {
            Fixture::reset('paypal_rest', true); Fixture::overdue();
            $session = Fixture::session(); $session['order_id'] = 'ORDER-OLD';
            $recoveryUrl = 'https://www.paypal.com/checkoutnow?token=ORDER-OLD&recovery=1';
            $session['_paypal_funding_recovery'] = ['order_id' => 'ORDER-OLD', 'issue' => 'INSTRUMENT_DECLINED', 'redirect_url' => $recoveryUrl];
            $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
            Fixture::$inspection = Fixture::inspection('requires_approval');
            if ($providerLink) { Fixture::$inspection['approval_url'] = 'https://www.paypal.com/checkoutnow?token=ORDER-OLD&action=1'; }
            $result = ensure();
            same($providerLink ? Fixture::$inspection['approval_url'] : $recoveryUrl, $result['sessionData']['redirect_url'], 'Trusted current action URL used');
            same([], Fixture::$creates, 'Recovery never creates a second Order'); noEffects(); released();
        }
    };
    $tests['approved PayPal recovery rejects drafts mismatches invalid redirects and closed local links'] = function () {
        global $wpdb;
        foreach (['draft', 'ref', 'order', 'invalid_url', 'expired'] as $problem) {
            Fixture::reset('paypal_rest', true); Fixture::overdue();
            $session = Fixture::session(); $session['order_id'] = 'ORDER-OLD';
            $session['redirect_url'] = 'https://www.paypal.com/checkoutnow?token=ORDER-OLD';
            if ($problem === 'draft') { $session['_creating'] = true; }
            if ($problem === 'ref') { $session['external_ref'] = 'OTHER'; }
            if ($problem === 'order') { $session['order_id'] = 'OTHER'; }
            if ($problem === 'invalid_url') { $session['redirect_url'] = 'https://evil.invalid/checkout'; }
            if ($problem === 'expired') { $wpdb->rows['S-001']['expires_at'] = '2000-01-01 00:00:00'; }
            $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
            Fixture::$inspection = Fixture::inspection('requires_approval');
            if ($problem === 'expired') { same('expired', ensure()['transaction']['status'], 'Expired local link not reopened'); }
            else { mustThrow(function () { ensure(); }, 'Unsafe recovery must stop'); }
            same([], Fixture::$creates, 'Unsafe recovery never creates'); released();
        }
    };
    $tests['paid GET result wins over the local platform deadline'] = function () {
        Fixture::reset('paypal_rest', true); Fixture::overdue();
        Fixture::$inspection = Fixture::inspection('closed', 'paid');
        same('paid', ensure()['transaction']['status'], 'Paid status reconciled');
        same('CAPTURE-PAID', Fixture::row()['transaction_id'], 'Capture persisted');
        same([], Fixture::$creates, 'Paid attempt never replaced');
        same(1, count(Fixture::$orders), 'One paid order effect');
        same(1, count(Fixture::$callbacks), 'One payment callback');
        released();
    };
    $tests['paid evidence with mismatched amount blocks renewal and payment effects'] = function () {
        Fixture::reset('paypal_rest', true); Fixture::overdue();
        Fixture::$inspection = Fixture::inspection('reusable', 'paid');
        Fixture::$inspection['amount_minor'] = 9999;
        mustThrow(function () { ensure(); }, 'Mismatched paid amount stops collection');
        same([], Fixture::$creates, 'No second checkout after rejected paid evidence');
        same('pending', Fixture::row()['status'], 'Mismatched payment not applied');
        noEffects(); released();
    };
    $tests['legacy NULL deadline derives from attempt provider or transaction creation instead of updated time'] = function () {
        global $wpdb;
        foreach (['_attempt_started_at', 'provider_created_at', 'created_at'] as $source) {
            Fixture::reset('paypal_rest', true);
            $started = Fixture::$clock - 100;
            $session = ['external_ref' => 'ORDER-OLD', 'redirect_url' => 'https://example.invalid/old'];
            if ($source !== 'created_at') { $session[$source] = $started; }
            if ($source === '_attempt_started_at') { $session['provider_created_at'] = $started - 9000; }
            $wpdb->rows['S-001']['created_at'] = gmdate('Y-m-d H:i:s', $source === 'created_at' ? $started : $started - 9000);
            $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
            $wpdb->rows['S-001']['gateway_session_expires_at'] = null;
            ensure();
            deadline($started + 7200);
            same([], Fixture::$creates, 'Fresh legacy attempt reused'); released();
        }
        Fixture::reset('paypal_rest', true);
        $wpdb->rows['S-001']['gateway_session'] = json_encode(['external_ref' => 'ORDER-OLD', 'redirect_url' => 'https://example.invalid/old']);
        $wpdb->rows['S-001']['gateway_session_expires_at'] = null;
        $wpdb->rows['S-001']['created_at'] = gmdate('Y-m-d H:i:s', Fixture::$clock - 7201);
        ensure();
        same(1, count(Fixture::$creates), 'Recent metadata update does not extend old attempt'); released();
    };
    $tests['SQL deadline has priority and cached deadline backfills a missing SQL value'] = function () {
        global $wpdb;
        Fixture::reset('paypal_rest', true); Fixture::overdue();
        $wpdb->rows['S-001']['gateway_session_expires_at'] = gmdate('Y-m-d H:i:s', Fixture::$clock + 3600);
        same('ORDER-OLD', ensure()['transaction']['payment_intent'], 'Future SQL deadline wins over stale expired cache');
        same([], Fixture::$creates, 'No renewal based only on stale cache'); released();
        Fixture::reset('paypal_rest', true);
        $wpdb->rows['S-001']['gateway_session_expires_at'] = gmdate('Y-m-d H:i:s', Fixture::$clock - 1);
        same('ORDER-NEW', ensure()['transaction']['payment_intent'], 'Past SQL deadline wins over future cache'); released();
        Fixture::reset('paypal_rest', true);
        $session = Fixture::session(); $session['local_expires_at'] = Fixture::$clock + 500;
        $session['_attempt_started_at'] = Fixture::$clock - 9000;
        $wpdb->rows['S-001']['gateway_session'] = json_encode($session);
        $wpdb->rows['S-001']['gateway_session_expires_at'] = null;
        ensure(); deadline(Fixture::$clock + 500);
        same([], Fixture::$creates, 'Cached deadline precedes historical creation dates'); released();
    };
    $tests['renewed attempt deadline starts when preparation begins and is not extended by slow HTTP'] = function () {
        Fixture::reset('paypal_rest', true); Fixture::overdue();
        $started = Fixture::$clock;
        Fixture::$onCreate = function () { Fixture::$clock += 120; };
        ensure(); deadline($started + 7200);
        same($started, Fixture::session()['_attempt_started_at'], 'Original preparation start retained'); released();
    };
    $tests['other providers keep their existing cached session without rotation queries'] = function () {
        Fixture::reset('airwallex', true); Fixture::overdue();
        same('ORDER-OLD', ensure()['transaction']['payment_intent'], 'Unrelated provider cache retained');
        same(0, Fixture::$gets, 'No rotation query added for other provider'); same([], Fixture::$creates, 'No unrelated renewal');
        noEffects(); released();
    };
    $tests['fresh terminal status prevents queries and platform creation'] = function () {
        global $wpdb;
        foreach (['paid', 'expired', 'cancelled', 'failed', 'refunded', 'confused'] as $status) {
            Fixture::reset(); $stale = Fixture::row(); $wpdb->rows['S-001']['status'] = $status;
            same($status, PaymentSessionManager::ensure($stale)['transaction']['status'], 'Fresh terminal row returned');
            same([], Fixture::$creates, 'Terminal order never creates'); same(0, Fixture::$gets, 'Terminal order never queries');
            noEffects(); released();
        }
    };
    $tests['lock contender cannot write or create and cannot release another worker lock'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->lockAvailable = false;
        mustThrow(function () { ensure(false); }, 'Lock contention blocks creation');
        same([], Fixture::$creates, 'No unlocked creation'); same([], $wpdb->updates, 'No unlocked writes');
        same([], $wpdb->releases, 'No foreign lock release');
    };
    $tests['serialized requests with the same stale snapshot share the published attempt'] = function () {
        Fixture::reset(); $stale = Fixture::row();
        PaymentSessionManager::ensure($stale, false);
        same('ORDER-NEW', PaymentSessionManager::ensure($stale, true)['transaction']['payment_intent'], 'Second request refreshes its stale snapshot');
        same(1, count(Fixture::$creates), 'Stale request does not create another platform order');
        noEffects(); released();
    };
    $tests['timeout retries use the same persisted platform idempotency key'] = function () {
        Fixture::reset(); Fixture::$createError = true;
        mustThrow(function () { ensure(false); }, 'Remote timeout surfaces');
        $key = Fixture::session()['_attempt_key']; released();
        Fixture::$createError = false; ensure(false);
        same(2, count(Fixture::$creates), 'Two HTTP attempts');
        same($key, Fixture::$creates[1]['payment_attempt_key'], 'Same attempt key retried');
        same(1, count(Fixture::$remote), 'One idempotent platform checkout'); released();
    };
    $tests['uncertain attempts older than one hour are not blindly recreated'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->rows['S-001']['gateway_session'] = json_encode(['_creating' => true, '_attempt_key' => 'old-key', '_attempt_started_at' => Fixture::$clock - 7200]);
        mustThrow(function () { ensure(false); }, 'Stale uncertain attempt blocks retry');
        same([], Fixture::$creates, 'No stale idempotency retry'); released();
    };
    $tests['draft retries inspect the old platform reference before attempting another creation'] = function () {
        global $wpdb;
        foreach (['paid', 'processing'] as $state) {
            Fixture::reset('paypal_rest', true);
            $wpdb->rows['S-001']['gateway_session'] = json_encode(['_creating' => true, '_attempt_key' => 'retry-key',
                '_attempt_started_at' => Fixture::$clock - 10, '_previous_session_ref' => 'ORDER-OLD', '_previous_expires_at' => null]);
            Fixture::$inspection = Fixture::inspection($state === 'paid' ? 'closed' : 'processing', $state === 'paid' ? 'paid' : null);
            if ($state === 'paid') { same('paid', ensure(false)['transaction']['status'], 'Old payment recovered before retry'); }
            else { mustThrow(function () { ensure(false); }, 'Processing old attempt blocks retry'); }
            same(1, Fixture::$gets, 'Old reference inspected even for API draft retry');
            same([], Fixture::$creates, 'Old payment or processing cannot lead to second creation'); released();
        }
    };
    $tests['payment during remote creation prevents publishing new checkout metadata'] = function () {
        Fixture::reset();
        Fixture::$onCreate = function () { WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['transaction_id' => 'CAPTURE-WINNER']); };
        same('paid', ensure(false)['transaction']['status'], 'Concurrent paid winner returned');
        same('CAPTURE-WINNER', Fixture::row()['transaction_id'], 'Payment identity preserved');
        same(null, Fixture::row()['payment_intent'], 'New unpaid platform reference not published');
        same(1, count(Fixture::$callbacks), 'One payment callback'); released();
    };
    $tests['local payment-link expiry during creation prevents exposing checkout'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->rows['S-001']['expires_at'] = gmdate('Y-m-d H:i:s', Fixture::$clock + 60);
        Fixture::$onCreate = function () { Fixture::$clock += 120; };
        $result = ensure(false);
        same('expired', $result['transaction']['status'], 'Local deadline enforced after HTTP response');
        same(null, $result['gateway'], 'No gateway for expired link'); same([], $result['sessionData'], 'No checkout exposed');
        same(null, Fixture::row()['payment_intent'], 'No platform reference published after expiry'); noEffects(); released();
    };
    $tests['session CAS rejects stale status session and reference snapshots'] = function () {
        global $wpdb;
        foreach (['status', 'gateway_session', 'payment_intent'] as $field) {
            Fixture::reset(); $stale = Fixture::row(); $wpdb->rows['S-001'][$field] = $field === 'status' ? 'paid' : 'concurrent-value';
            same(false, TransactionManager::savePendingSession($stale, ['external_ref' => 'ORDER-NEW']), 'Stale publisher loses CAS');
            same($field === 'status' ? 'paid' : 'concurrent-value', Fixture::row()[$field], 'Concurrent value retained');
        }
    };
    $tests['marker write failure prevents HTTP creation and releases the lock'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->failUpdates = true;
        mustThrow(function () { ensure(false); }, 'Marker persistence failure surfaces');
        same([], Fixture::$creates, 'No create before marker persistence'); same(null, Fixture::row()['gateway_session'], 'No false marker'); released();
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        try { $test(); echo 'PASS ' . $name . PHP_EOL; }
        catch (\Throwable $error) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
    }
    echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}
