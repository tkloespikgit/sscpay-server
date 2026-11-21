<?php
/**
 * Run: php tests/payment-expiry.php
 * Real transaction CAS, state machine, router, reconciler and PayPal event parsing.
 * All storage, credentials, remote requests, order effects and callbacks are isolated.
 */

namespace {
    if (PHP_SAPI !== 'cli') {
        exit(1);
    }
    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');

    function current_time($type, $gmt = false)
    {
        return gmdate('Y-m-d H:i:s');
    }

    function get_option($name, $default = false)
    {
        return array_key_exists($name, \PaymentGatewayAggregator\Tests\Expiry\Fixture::$options)
            ? \PaymentGatewayAggregator\Tests\Expiry\Fixture::$options[$name] : $default;
    }

    function wp_json_encode($value, $options = 0, $depth = 512)
    {
        return json_encode($value, $options, $depth);
    }

    function register_setting($group, $name, $args)
    {
        \PaymentGatewayAggregator\Tests\Expiry\Fixture::$settings[$name] = $args;
    }

    function sanitize_text_field($value)
    {
        return (string) $value;
    }

    function wp_unslash($value)
    {
        return stripslashes($value);
    }

    function home_url($path)
    {
        return 'https://example.invalid' . $path;
    }

    function add_query_arg($key, $value, $url)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($key) . '=' . rawurlencode($value);
    }

    class WP_REST_Request
    {
        private $body;
        public function __construct(array $body)
        {
            $this->body = $body;
        }
        public function get_json_params()
        {
            return $this->body;
        }
        public function get_param($key) { return $this->body[$key] ?? null; }
    }

    class WP_REST_Response
    {
        private $data;
        private $status;
        public function __construct($data, $status)
        {
            $this->data = $data;
            $this->status = $status;
        }
        public function get_data()
        {
            return $this->data;
        }
        public function get_status()
        {
            return $this->status;
        }
    }
}

namespace PaymentGatewayAggregator\Tests\Expiry {
    class Fixture
    {
        public static $queries = [];
        public static $remoteOrder = [];
        public static $remoteStripe = [];
        public static $remoteError = false;
        public static $verifications = 0;
        public static $orders = [];
        public static $callbacks = [];
        public static $logs = [];
        public static $options = [];
        public static $settings = [];
        public static $captures = [];
        public static $captureError = false;

        public static function reset($status = 'expired')
        {
            global $wpdb;
            $wpdb = new MemoryWpdb();
            $wpdb->rows['S-001'] = [
                's_order_id' => 'S-001', 'wp_order_id' => 1,
                'payment_method' => 'paypal_rest', 'payment_intent' => 'ORDER-001',
                'transaction_id' => null, 'amount' => '12.34', 'currency' => 'USD',
                'status' => $status, 'gateway_config' => 'synthetic-test-snapshot',
                'pay_token' => 'synthetic-valid-token',
                'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
                'paid_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 7200),
                'created_at' => gmdate('Y-m-d H:i:s', time() - 7200),
            ];
            self::$queries = self::$orders = self::$callbacks = self::$logs = [];
            self::$options = self::$settings = [];
            self::$captures = [];
            self::$captureError = false;
            self::$verifications = 0;
            self::$remoteError = false;
            self::$remoteOrder = [
                'id' => 'ORDER-001', 'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [[
                    'id' => 'CAPTURE-001', 'status' => 'COMPLETED',
                    'amount' => ['value' => '12.34', 'currency_code' => 'USD'],
                ]]]]],
            ];
            self::$remoteStripe = [
                'id' => 'cs_test_001', 'status' => 'complete', 'payment_status' => 'paid',
                'payment_intent' => 'pi_test_001', 'amount_total' => 1234, 'currency' => 'usd',
            ];
        }

        public static function row()
        {
            global $wpdb;
            return $wpdb->rows['S-001'];
        }

        public static function recordedEvent()
        {
            global $wpdb;
            $wpdb->events['paypal_rest:WH-001'] = ['paypal_rest', 'WH-001', 'S-001', 'paid', 'old'];
        }
    }

    class StripeSessions
    {
        public function retrieve($sessionId)
        {
            Fixture::$queries[] = ['session_id' => $sessionId, 'local_status' => Fixture::row()['status']];
            if (Fixture::$remoteError) { throw new \RuntimeException('Synthetic Stripe query failure'); }
            return (object) Fixture::$remoteStripe;
        }
    }

    class MemoryWpdb
    {
        public $prefix = 'isolated_';
        public $last_error = '';
        public $rows = [];
        public $events = [];
        public $references = [];
        public $failReferenceWrites = false;
        public $failReferenceReads = false;
        public $locks = [];
        public $lockResult = '1';
        public $beforeLock;
        public $updates = [];
        public $beforeUpdate;
        public $afterUpdate;
        public $failUpdates = false;
        public $failedWriteAttempts = 0;
        public $rejectUpdates = false;
        public $insert_id = 0;
        private $snapshot;

        public function prepare($sql, ...$args)
        {
            return [$sql, $args];
        }

        public function insert($table, array $row)
        {
            if ($table !== 'isolated_payment_transactions' || isset($this->rows[$row['s_order_id']])) {
                throw new \RuntimeException('Unexpected INSERT in isolated test');
            }
            $this->insert_id++;
            $this->rows[$row['s_order_id']] = array_merge([
                'payment_intent' => null, 'transaction_id' => null, 'paid_at' => null,
            ], $row);
            return 1;
        }

        public function get_row($prepared, $format)
        {
            [$sql, $args] = $prepared;
            $this->last_error = '';
            if (strpos($sql, 'SELECT * FROM isolated_payment_session_references') === 0) {
                $this->last_error = $this->failReferenceReads ? 'Synthetic reference read failure' : '';
                return $this->failReferenceReads ? null : ($this->references[$args[0]] ?? null);
            }
            if (strpos($sql, 'SELECT * FROM isolated_payment_transactions') !== 0) {
                throw new \RuntimeException('Unexpected SELECT in isolated test');
            }
            if (strpos($sql, 'WHERE s_order_id = %s') !== false) {
                return $this->rows[$args[0]] ?? null;
            }
            if (strpos($sql, 'WHERE pay_token = %s') !== false) {
                foreach ($this->rows as $row) {
                    if ($row['pay_token'] === $args[0]) { return $row; }
                }
                return null;
            }
            if (strpos($sql, 'WHERE payment_intent = %s OR transaction_id = %s') !== false) {
                foreach ($this->rows as $row) {
                    if ($row['payment_intent'] === $args[0] || $row['transaction_id'] === $args[1]) {
                        return $row;
                    }
                }
                return null;
            }
            throw new \RuntimeException('Unexpected lookup in isolated test');
        }

        public function get_results($prepared, $format = ARRAY_A)
        {
            $sql = is_array($prepared) ? $prepared[0] : $prepared;
            if (strpos($sql, 'SHOW TABLE STATUS') === 0) {
                return array_map(function ($suffix) {
                    return ['Name' => $this->prefix . $suffix, 'Engine' => 'InnoDB'];
                }, ['payment_transactions', 'payment_session_references', 'payment_webhook_events', 'payment_callback_queue']);
            }
            throw new \RuntimeException('Unexpected multi lookup in isolated test');
        }

        public function update($table, array $fields, array $where, $formats = null, $whereFormats = null)
        {
            if ($table === 'isolated_payment_session_references') {
                if ($this->failReferenceWrites) { return false; }
                $this->references[$where['ref_hash']] = array_merge($this->references[$where['ref_hash']], $fields);
                return 1;
            }
            if ($table !== 'isolated_payment_transactions' || !isset($where['s_order_id'])) {
                throw new \RuntimeException('Unexpected UPDATE in isolated test');
            }
            if ($this->beforeUpdate) {
                $hook = $this->beforeUpdate;
                $this->beforeUpdate = null;
                $hook($fields, $where);
            }
            if ($this->failUpdates) {
                $this->failedWriteAttempts++;
                $this->last_error = 'Synthetic write failure';
                return false;
            }
            $row = $this->rows[$where['s_order_id']] ?? null;
            $matched = !$this->rejectUpdates && $row !== null;
            foreach ($where as $key => $value) {
                if (!$row || !array_key_exists($key, $row) || $row[$key] !== $value) {
                    $matched = false;
                }
            }
            $this->updates[] = ['fields' => $fields, 'where' => $where, 'matched' => $matched];
            if (!$matched) {
                return 0;
            }
            $this->rows[$where['s_order_id']] = array_merge($row, $fields);
            if ($this->afterUpdate) {
                $hook = $this->afterUpdate;
                $this->afterUpdate = null;
                $hook($fields, $where);
            }
            return 1;
        }

        public function query($prepared)
        {
            [$sql, $args] = is_array($prepared) ? $prepared : [$prepared, []];
            if ($sql === 'START TRANSACTION') {
                $this->snapshot = [$this->rows, $this->events, $this->references];
                return 1;
            }
            if ($sql === 'COMMIT') { $this->snapshot = null; return 1; }
            if ($sql === 'ROLLBACK') {
                if ($this->snapshot) { [$this->rows, $this->events, $this->references] = $this->snapshot; }
                $this->snapshot = null;
                return 1;
            }
            if (strpos($sql, 'INSERT INTO isolated_payment_session_references') === 0) {
                $this->last_error = $this->failReferenceWrites ? 'Synthetic reference write failure' : '';
                if ($this->failReferenceWrites) { return false; }
                if (isset($this->references[$args[0]])) { return 0; }
                $this->references[$args[0]] = array_combine(
                    ['ref_hash', 'external_ref', 's_order_id', 'session_ref', 'session_data', 'created_at'], $args
                );
                return 1;
            }
            if (strpos($sql, 'INSERT IGNORE INTO isolated_payment_webhook_events') === false || count($args) !== 5) {
                throw new \RuntimeException('Unexpected query in isolated test');
            }
            $key = $args[0] . ':' . $args[1];
            if (isset($this->events[$key])) {
                return 0;
            }
            $this->events[$key] = $args;
            return 1;
        }

        public function get_var($prepared)
        {
            [$sql, $args] = $prepared;
            $this->locks[] = [$sql, $args[0]];
            if (strpos($sql, 'SELECT GET_LOCK') === 0) {
                if ($this->beforeLock) {
                    $hook = $this->beforeLock;
                    $this->beforeLock = null;
                    $hook();
                }
                return $this->lockResult;
            }
            if (strpos($sql, 'SELECT RELEASE_LOCK') === 0) { return '1'; }
            throw new \RuntimeException('Unexpected get_var');
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\Expiry\Fixture;

    class ConfigEncryptor
    {
        public static function decrypt($snapshot)
        {
            if ($snapshot !== 'synthetic-test-snapshot') {
                throw new \RuntimeException('Unexpected test snapshot');
            }
            return [
                'mode' => 'sandbox', 'webhook_id' => 'synthetic-webhook',
                'secret_key' => 'synthetic-stripe-key', 'webhook_secret' => 'synthetic-stripe-webhook-secret',
            ];
        }
    }

    class LogManager
    {
        const SYSTEM_ORDER_ID = 'SYSTEM';
        public static function __callStatic($level, $args)
        {
            Fixture::$logs[] = [$level, $args];
        }
    }

    class OrderManager
    {
        public static function __callStatic($method, $args)
        {
            Fixture::$orders[] = [$method, $args];
        }
    }

    class CallbackHandler
    {
        public static function forwardPaymentStatus(array $transaction, $accountForbidden = false)
        {
            Fixture::$callbacks[] = $transaction;
        }
    }
}

namespace PaymentGatewayAggregator\Gateways {
    use PaymentGatewayAggregator\Tests\Expiry\Fixture;

    class PayPalRestClient
    {
        public static function captureOrder(array $config, $orderId)
        {
            global $wpdb;
            Fixture::$captures[] = ['order_id' => $orderId, 'locks' => $wpdb->locks];
            if (Fixture::$captureError) { throw new \RuntimeException('Synthetic capture failure'); }
            return ['status' => 'COMPLETED'];
        }
        public static function verifyWebhookSignature(array $config, array $headers, $rawBody)
        {
            Fixture::$verifications++;
            return ($headers['paypal-transmission-sig'] ?? '') === 'synthetic-valid-signature'
                && $config['webhook_id'] === 'synthetic-webhook';
        }

        public static function getOrder(array $config, $orderId)
        {
            Fixture::$queries[] = ['order_id' => $orderId, 'local_status' => Fixture::row()['status']];
            if (Fixture::$remoteError) {
                throw new \RuntimeException('Synthetic remote failure');
            }
            return Fixture::$remoteOrder;
        }
    }
}

namespace Stripe {
    class StripeClient
    {
        public $checkout;
        public $disputes;
        public function __construct($key)
        {
            $this->checkout = (object) ['sessions' => new \PaymentGatewayAggregator\Tests\Expiry\StripeSessions()];
            $this->disputes = new class {
                public function retrieve($id) {
                    return (object) ['id' => $id, 'payment_intent' => 'pi_test_001', 'status' => 'won',
                        'amount' => 321, 'currency' => 'usd'];
                }
            };
        }
    }
}

namespace {
    use PaymentGatewayAggregator\Core\OrderStatusReconciler;
    use PaymentGatewayAggregator\Core\PaymentSessionReferences;
    use PaymentGatewayAggregator\Core\TransactionManager;
    use PaymentGatewayAggregator\Core\TransactionStateMachine;
    use PaymentGatewayAggregator\Core\WebhookRouter;
    use PaymentGatewayAggregator\Tests\Expiry\Fixture;

    require dirname(__DIR__) . '/vendor/autoload.php';

    set_error_handler(function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
        return false;
    });

    function same($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    function mustThrow($callback, $message)
    {
        try {
            $callback();
        } catch (\RuntimeException $error) {
            return;
        }
        throw new \RuntimeException($message);
    }

    function event($amount = '12.34', $withOrderId = true)
    {
        $resource = ['id' => 'CAPTURE-001', 'amount' => ['value' => $amount, 'currency_code' => 'USD']];
        if ($withOrderId) {
            $resource['supplementary_data'] = ['related_ids' => ['order_id' => 'ORDER-001']];
        }
        return json_encode(['id' => 'WH-001', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $resource]);
    }

    function webhook($amount = '12.34', $validSignature = true, $withOrderId = true)
    {
        return WebhookRouter::handle('paypal', event($amount, $withOrderId), [
            'paypal-transmission-sig' => $validSignature ? 'synthetic-valid-signature' : 'invalid',
        ]);
    }

    function paidOnce()
    {
        same('paid', Fixture::row()['status'], 'Paid status');
        same('ORDER-001', Fixture::row()['payment_intent'], 'Original Order ID preserved');
        same('CAPTURE-001', Fixture::row()['transaction_id'], 'Capture ID stored');
        same([['markPaid', [1, 'CAPTURE-001']]], Fixture::$orders, 'One order effect');
        same(1, count(Fixture::$callbacks), 'One callback');
    }

    function noPaymentEffects($status)
    {
        same($status, Fixture::row()['status'], 'State unchanged');
        same([], Fixture::$orders, 'No order effects');
        same([], Fixture::$callbacks, 'No callbacks');
    }

    function failedStripe($status = 'failed')
    {
        global $wpdb;
        Fixture::reset($status);
        $wpdb->rows['S-001']['payment_method'] = 'stripe';
        $wpdb->rows['S-001']['payment_intent'] = 'cs_test_001';
        $wpdb->rows['S-001']['transaction_id'] = 'pi_test_001';
    }

    function stripePaidOnce()
    {
        same('paid', Fixture::row()['status'], 'Stripe payment recovered');
        same('cs_test_001', Fixture::row()['payment_intent'], 'Checkout Session retained');
        same('pi_test_001', Fixture::row()['transaction_id'], 'PaymentIntent retained');
        same(true, !empty(Fixture::row()['paid_at']), 'Paid timestamp recorded');
        same([['markPaid', [1, 'pi_test_001']]], Fixture::$orders, 'One Stripe order payment effect');
        same(1, count(Fixture::$callbacks), 'One Stripe success callback');
        same('paid', Fixture::$callbacks[0]['status'], 'Stripe callback reports paid');
    }

    function queryOrder()
    {
        $response = \PaymentGatewayAggregator\API\Controllers\OrderQueryController::handle(
            new \WP_REST_Request(['s_order_id' => 'S-001'])
        );
        same(200, $response->get_status(), 'Order query remains successful');
        same(0, $response->get_data()['code'], 'Order query success code');
        return $response->get_data()['data'];
    }

    function samePaidMetadata(array $before)
    {
        foreach (['status', 'payment_intent', 'paid_at'] as $field) {
            same($before[$field], Fixture::row()[$field], 'Existing ' . $field . ' preserved');
        }
        noPaymentEffects('paid');
    }

    function createTransaction()
    {
        global $wpdb;
        unset($wpdb->rows['S-001']);
        return TransactionManager::create([
            's_order_id' => 'S-001', 'wp_order_id' => 1,
            'payment_method' => 'paypal_rest', 'currency' => 'USD', 'amount' => '12.34',
            'customer_name' => 'Synthetic Customer', 'customer_email' => 'customer@example.invalid',
            'gateway_config_encrypted' => 'synthetic-test-snapshot',
            'callback_url' => 'https://example.invalid/callback',
            'return_url' => 'https://example.invalid/return', 'cancel_url' => 'https://example.invalid/cancel',
        ]);
    }

    function payResponse(array $transaction)
    {
        $builder = new \ReflectionMethod(\PaymentGatewayAggregator\API\Controllers\PayController::class, 'buildPayResponseData');
        if (PHP_VERSION_ID < 80100) {
            $builder->setAccessible(true);
        }
        return $builder->invoke(null, $transaction);
    }

    function rotateSession()
    {
        global $wpdb;
        $old = ['external_ref' => 'ORDER-001', 'payment_request_id' => 'REQUEST-OLD', 'local_expires_at' => 2000000000];
        $new = ['external_ref' => 'ORDER-002', 'payment_request_id' => 'REQUEST-NEW', 'local_expires_at' => 2000007200];
        $wpdb->rows['S-001']['gateway_session'] = json_encode($old);
        PaymentSessionReferences::remember(Fixture::row(), $new);
        $wpdb->rows['S-001']['payment_intent'] = 'ORDER-002';
        $wpdb->rows['S-001']['transaction_id'] = null;
        $wpdb->rows['S-001']['gateway_session'] = json_encode($new);
        $wpdb->rows['S-001']['gateway_session_expires_at'] = gmdate('Y-m-d H:i:s', $new['local_expires_at']);
    }

    function returnCapture(array $params)
    {
        Fixture::$options['pga_route_identity'] = [
            'namespace' => 'isolated-api/v1', 'return' => 'return', 'cancel' => 'cancel',
            'capture' => 'capture', 'notify' => 'notify', 'token_param' => 'sid',
        ];
        $method = new \ReflectionMethod(\PaymentGatewayAggregator\API\Controllers\PayPalRestController::class, 'captureApprovedOrder');
        if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
        return $method->invoke(null, new \WP_REST_Request($params), Fixture::row());
    }

    $tests = [];
    $tests['historical aliases preserve their original provider query metadata'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        $wpdb->rows['S-001']['transaction_id'] = 'CAPTURE-OLD';
        rotateSession();
        foreach (['ORDER-001', 'CAPTURE-OLD'] as $alias) {
            $found = TransactionManager::findByExternalRef($alias);
            same('S-001', $found['s_order_id'], 'Historical owner');
            same('ORDER-001', $found['_pga_matched_session_ref'], 'Original attempt');
            same('REQUEST-OLD', $found['_pga_matched_session_data']['payment_request_id'], 'Original query metadata');
        }
        $wpdb->rows['S-001']['payment_intent'] = 'ORDER-001';
        $wpdb->rows['S-001']['gateway_session'] = json_encode(['_creating' => true, '_attempt_key' => 'new-key']);
        PaymentSessionReferences::remember(Fixture::row());
        same('REQUEST-OLD', PaymentSessionReferences::find('ORDER-001')['_pga_matched_session_data']['payment_request_id'], 'Renewal draft does not overwrite old metadata');
    };
    $tests['a late paid attempt restores its real provider identity and deadline'] = function () {
        Fixture::reset('pending');
        rotateSession();
        same(200, webhook()['http_status'], 'Historical paid event accepted');
        paidOnce();
        same('REQUEST-OLD', TransactionManager::getGatewaySession(Fixture::row())['payment_request_id'], 'Winning query metadata restored');
        same(gmdate('Y-m-d H:i:s', 2000000000), Fixture::row()['gateway_session_expires_at'], 'Winning deadline restored');
    };
    $tests['old failed events cannot close a replacement pending attempt'] = function () {
        global $wpdb;
        Fixture::reset('pending'); rotateSession();
        $body = json_decode(event(), true); $body['event_type'] = 'PAYMENT.CAPTURE.DENIED';
        same(200, WebhookRouter::handle('paypal', json_encode($body), ['paypal-transmission-sig' => 'synthetic-valid-signature'])['http_status'], 'Old failure acknowledged');
        same('ORDER-002', Fixture::row()['payment_intent'], 'Replacement preserved');
        noPaymentEffects('pending'); same([], $wpdb->updates, 'No status write');
    };
    $tests['payment received during a renewal draft restores complete winning session data'] = function () {
        global $wpdb;
        Fixture::reset('pending'); rotateSession();
        $wpdb->rows['S-001']['payment_intent'] = 'ORDER-001';
        $wpdb->rows['S-001']['gateway_session'] = json_encode(['_creating' => true, '_attempt_key' => 'unpublished']);
        same(200, webhook()['http_status'], 'Paid event during renewal accepted');
        paidOnce();
        same('REQUEST-OLD', TransactionManager::getGatewaySession(Fixture::row())['payment_request_id'], 'Paid session replaces draft');
        same(false, isset(TransactionManager::getGatewaySession(Fixture::row())['_creating']), 'Draft marker removed for paid winner');
    };
    $tests['active paid query during a renewal draft restores complete winning session and deadline'] = function () {
        global $wpdb;
        Fixture::reset('pending'); rotateSession();
        $wpdb->rows['S-001']['payment_intent'] = 'ORDER-001';
        $wpdb->rows['S-001']['gateway_session'] = json_encode(['_creating' => true, '_attempt_key' => 'unpublished']);
        $result = OrderStatusReconciler::reconcile(Fixture::row());
        same('paid', $result['status'], 'Real gateway query confirms paid');
        paidOnce();
        $session = TransactionManager::getGatewaySession($result);
        same('REQUEST-OLD', $session['payment_request_id'], 'Query restores winning provider metadata');
        same(false, isset($session['_creating']), 'Paid query clears renewal draft');
        same(gmdate('Y-m-d H:i:s', 2000000000), $result['gateway_session_expires_at'], 'Paid query restores winning deadline');
        OrderStatusReconciler::reconcile($result);
        paidOnce();
    };
    $tests['same-order different Capture notifications retain first paid identity and dedup safely'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        same(200, webhook()['http_status'], 'Initial payment succeeds');
        $paidAt = Fixture::row()['paid_at'];
        $body = json_decode(event(), true);
        $body['id'] = 'WH-SECOND-CAPTURE';
        $body['resource']['id'] = 'CAPTURE-SECOND';
        $headers = ['paypal-transmission-sig' => 'synthetic-valid-signature'];
        same(200, WebhookRouter::handle('paypal', json_encode($body), $headers)['http_status'], 'Additional capture acknowledged');
        same('CAPTURE-001', Fixture::row()['transaction_id'], 'First capture remains authoritative');
        same('ORDER-001', Fixture::row()['payment_intent'], 'First provider Order retained');
        same($paidAt, Fixture::row()['paid_at'], 'Initial payment time retained');
        same(10013, WebhookRouter::handle('paypal_rest', json_encode($body), $headers)['code'], 'Canonical dedup still applies to second Capture event');
        same(2, count($wpdb->events), 'One event record for each provider event');
        same(1, count(Fixture::$orders), 'No extra order effect');
        same(1, count(Fixture::$callbacks), 'No duplicate callback');
    };
    $tests['second-attempt paid events preserve the first paid identity'] = function () {
        Fixture::reset('pending'); rotateSession();
        WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['payment_intent' => 'ORDER-002', 'transaction_id' => 'CAPTURE-NEW']);
        $before = Fixture::row();
        same(200, webhook()['http_status'], 'Additional payment acknowledged');
        same($before, Fixture::row(), 'Paid winner preserved');
        same(1, count(Fixture::$callbacks), 'No duplicate callback');
    };
    $tests['two attempts racing to paid preserve the first successful CAS identity'] = function () {
        global $wpdb;
        Fixture::reset('pending'); rotateSession();
        $wpdb->beforeUpdate = function () {
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['payment_intent' => 'ORDER-002', 'transaction_id' => 'CAPTURE-NEW']);
        };
        same(200, webhook()['http_status'], 'Losing payment acknowledged');
        same('ORDER-002', Fixture::row()['payment_intent'], 'Winning Order');
        same('CAPTURE-NEW', Fixture::row()['transaction_id'], 'Winning capture');
        same('REQUEST-NEW', TransactionManager::getGatewaySession(Fixture::row())['payment_request_id'], 'Winning query metadata');
        same(1, count(Fixture::$orders), 'Single order effect');
        same(1, count(Fixture::$callbacks), 'Single callback');
    };
    $tests['provider reference ownership and raw identifiers are checked strictly'] = function () {
        global $wpdb;
        Fixture::reset('pending'); PaymentSessionReferences::remember(Fixture::row());
        $other = array_merge(Fixture::row(), ['s_order_id' => 'S-OTHER']);
        mustThrow(function () use ($other) { PaymentSessionReferences::remember($other); }, 'Reference ownership conflict must fail');
        mustThrow(function () { PaymentSessionReferences::remember(Fixture::row(), ['external_ref' => 'ORDER-002', 'transaction_id' => 'ORDER-001']); }, 'Attempt conflict must fail');
        $wpdb->references[hash('sha256', 'ORDER-001')]['external_ref'] = 'order-001';
        same(null, PaymentSessionReferences::findReference('ORDER-001'), 'Hash does not relax raw equality');
        mustThrow(function () { PaymentSessionReferences::remember(Fixture::row()); }, 'Corrupted hash cannot be reused');
    };
    $tests['reference database errors preserve webhook retries'] = function () {
        global $wpdb;
        Fixture::reset('pending'); $wpdb->failReferenceWrites = true;
        same(500, webhook()['http_status'], 'Reference write failure is retryable');
        same([], $wpdb->events, 'Event not consumed'); noPaymentEffects('pending');
        $wpdb->failReferenceWrites = false; same(200, webhook()['http_status'], 'Retry succeeds'); paidOnce();
        Fixture::reset('pending'); $wpdb->failReferenceReads = true;
        same(500, webhook()['http_status'], 'Reference read failure is retryable');
        same([], $wpdb->events, 'Read failure does not consume event'); noPaymentEffects('pending');
    };
    $tests['a stale failure cannot close an attempt replaced during CAS'] = function () {
        global $wpdb;
        Fixture::reset('pending'); $stale = Fixture::row();
        $wpdb->beforeUpdate = function () use ($wpdb) { $wpdb->rows['S-001']['payment_intent'] = 'ORDER-002'; };
        mustThrow(function () use ($stale) { WebhookRouter::applyStatusTransition($stale, 'failed', ['payment_intent' => 'ORDER-001']); }, 'Attempt change stops stale failure');
        same('ORDER-002', Fixture::row()['payment_intent'], 'New attempt preserved'); noPaymentEffects('pending');
    };
    $tests['PayPal JS capture rechecks current Order under the shared session lock'] = function () {
        global $wpdb;
        Fixture::reset('pending'); $wpdb->rows['S-001']['payment_method'] = 'paypal_js';
        $request = new \WP_REST_Request(['s_order_id' => 'S-001', 'token' => 'synthetic-valid-token', 'order_id' => 'ORDER-001']);
        $wpdb->beforeLock = function () use ($wpdb) { $wpdb->rows['S-001']['payment_intent'] = 'ORDER-002'; };
        same(409, \PaymentGatewayAggregator\API\Controllers\PayPalJsController::capture($request)->get_status(), 'Stale tab rejected after lock');
        same([], Fixture::$captures, 'Neither old nor replacement captured');
        same('SELECT RELEASE_LOCK(%s)', $wpdb->locks[1][0], 'Lock released');
        $wpdb->rows['S-001']['payment_intent'] = 'ORDER-001';
        $wpdb->rows['S-001']['gateway_session'] = json_encode(['_creating' => true]);
        same(409, \PaymentGatewayAggregator\API\Controllers\PayPalJsController::capture($request)->get_status(), 'Creating draft blocks old Order');
        same([], Fixture::$captures, 'Draft cannot capture');
        $wpdb->rows['S-001']['gateway_session'] = null;
        same(true, \PaymentGatewayAggregator\API\Controllers\PayPalJsController::capture($request)->get_data()['data']['captured'], 'Current Order captures');
        same('ORDER-001', Fixture::$captures[0]['order_id'], 'Only approved current Order captured');
        same('pga_session_' . sha1($wpdb->prefix . '|S-001'), $wpdb->locks[0][1], 'Lock name matches renewer');
    };
    $tests['PayPal REST return validates real approved Order and rejects in-progress drafts'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        foreach ([null, 'ORDER-OLD'] as $orderId) {
            returnCapture(['pga_token' => 'synthetic-valid-token', 'token' => $orderId]);
            same([], Fixture::$captures, 'Missing or old PayPal Order not captured');
        }
        $wpdb->rows['S-001']['gateway_session'] = json_encode(['_creating' => true]);
        returnCapture(['pga_token' => 'synthetic-valid-token', 'token' => 'ORDER-001']);
        same([], Fixture::$captures, 'In-progress renewal blocks old capture');
        $wpdb->rows['S-001']['gateway_session'] = null;
        returnCapture(['pga_token' => 'synthetic-valid-token', 'token' => 'ORDER-001']);
        same('ORDER-001', Fixture::$captures[0]['order_id'], 'Current approved Order captured');
        same('SELECT RELEASE_LOCK(%s)', $wpdb->locks[count($wpdb->locks)-1][0], 'Return releases before redirect');
    };
    $tests['capture lock timeout and capture exceptions release resources safely'] = function () {
        global $wpdb;
        Fixture::reset('pending'); $wpdb->rows['S-001']['payment_method'] = 'paypal_js';
        $request = new \WP_REST_Request(['s_order_id' => 'S-001', 'token' => 'synthetic-valid-token', 'order_id' => 'ORDER-001']);
        $wpdb->lockResult = '0';
        same(503, \PaymentGatewayAggregator\API\Controllers\PayPalJsController::capture($request)->get_status(), 'Busy lock asks for retry');
        same([], Fixture::$captures, 'Lock timeout never captures');
        $wpdb->lockResult = '1'; Fixture::$captureError = true;
        same(false, \PaymentGatewayAggregator\API\Controllers\PayPalJsController::capture($request)->get_data()['data']['captured'], 'Remote failure does not mark paid');
        same('SELECT RELEASE_LOCK(%s)', $wpdb->locks[count($wpdb->locks)-1][0], 'Remote error releases lock');
        noPaymentEffects('pending');
    };

    $tests['missing TTL option creates a permanent payment link by default'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        $transaction = createTransaction();
        same('9999-12-31 23:59:59', $transaction['expires_at'], 'Permanent deadline stored as valid non-NULL datetime');
        same(true, TransactionManager::isNonExpiring($transaction), 'Permanent marker recognized');
        same('pending', TransactionManager::maybeExpire($transaction)['status'], 'Permanent link stays pending');
        same([], $wpdb->updates, 'No expiry write for permanent link');
    };

    $tests['explicit integer or stored-string zero TTL creates permanent links'] = function () {
        foreach ([0, '0'] as $ttl) {
            Fixture::reset('pending');
            Fixture::$options['pga_pay_link_ttl_minutes'] = $ttl;
            $transaction = createTransaction();
            same('9999-12-31 23:59:59', $transaction['expires_at'], 'Zero TTL is permanent');
            same(true, TransactionManager::isNonExpiring($transaction), 'Zero TTL recognized as permanent');
            noPaymentEffects('pending');
        }
    };

    $tests['positive TTL preserves configured minutes and still expires unpaid links'] = function () {
        global $wpdb;
        foreach ([1, 30] as $ttl) {
            Fixture::reset('pending');
            Fixture::$options['pga_pay_link_ttl_minutes'] = $ttl;
            $before = time();
            $transaction = createTransaction();
            $after = time();
            $deadline = strtotime($transaction['expires_at'] . ' UTC');
            same(true, $deadline >= $before + $ttl * 60 && $deadline <= $after + $ttl * 60, 'Configured positive deadline respected');
            same($ttl, Fixture::$options['pga_pay_link_ttl_minutes'], 'Saved positive setting not rewritten');
            same(false, TransactionManager::isNonExpiring($transaction), 'Positive TTL is not permanent');
            same('pending', TransactionManager::maybeExpire($transaction)['status'], 'Positive link valid before its deadline');
            $wpdb->rows['S-001']['expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);
            same('expired', TransactionManager::maybeExpire(Fixture::row())['status'], 'Positive link expires after its deadline');
            noPaymentEffects('expired');
        }
    };

    $tests['only the explicit permanent marker is recognized and legacy deadlines remain effective'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        foreach ([[], ['expires_at' => null], ['expires_at' => ''], ['expires_at' => 'invalid'], ['expires_at' => '2000-01-01 00:00:00']] as $transaction) {
            same(false, TransactionManager::isNonExpiring($transaction), 'Missing or legacy date is not a permanent marker');
        }
        Fixture::$options['pga_pay_link_ttl_minutes'] = 0;
        $oldDeadline = Fixture::row()['expires_at'];
        same('expired', TransactionManager::maybeExpire(Fixture::row())['status'], 'Changing default does not reopen existing expired deadlines');
        same($oldDeadline, Fixture::row()['expires_at'], 'Existing deadline remains unchanged');
        noPaymentEffects('expired');
        same(1, count($wpdb->updates), 'Existing unpaid row still expires once');
    };

    $tests['permanent link APIs retain ISO deadline strings and finite links retain their timestamps'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        Fixture::$options['pga_pay_link_ttl_minutes'] = 0;
        $transaction = createTransaction();
        $wpdb->rows['S-001']['payment_intent'] = 'ORDER-001';
        Fixture::$remoteOrder = ['id' => 'ORDER-001', 'status' => 'APPROVED'];
        same('9999-12-31T23:59:59Z', payResponse($transaction)['expires_at'], 'Pay API returns permanent ISO marker');
        same('9999-12-31T23:59:59Z', queryOrder()['expires_at'], 'Query API returns permanent ISO marker');
        same('pending', OrderStatusReconciler::reconcileForResultPage(Fixture::row())['status'], 'Unpaid permanent result remains available');
        same([], $wpdb->updates, 'Neither query nor result expires permanent link');
        $wpdb->rows['S-001']['expires_at'] = '2026-10-20 10:11:12';
        same('2026-10-20T10:11:12Z', payResponse(Fixture::row())['expires_at'], 'Pay API retains finite UTC deadline');
        same('2026-10-20T10:11:12Z', queryOrder()['expires_at'], 'Query API retains finite UTC deadline');
    };

    $tests['shared expiry formatter preserves permanent finite and absent deadline representations'] = function () {
        same('9999-12-31T23:59:59Z', TransactionManager::formatExpiresAt('9999-12-31 23:59:59'), 'Permanent sentinel has explicit ISO representation');
        same('2026-10-20T10:11:12Z', TransactionManager::formatExpiresAt('2026-10-20 10:11:12'), 'Legacy finite date keeps UTC representation');
        same(null, TransactionManager::formatExpiresAt(null), 'Null date remains null');
        same(null, TransactionManager::formatExpiresAt(''), 'Empty date remains null');
    };

    $tests['registered payment link TTL default is zero'] = function () {
        Fixture::reset();
        \PaymentGatewayAggregator\Admin\SettingsPage::registerSettings();
        same(0, Fixture::$settings['pga_pay_link_ttl_minutes']['default'], 'Settings default agrees with transaction creation');
    };

    $tests['late authenticated paid webhook recovers an expired transaction'] = function () {
        Fixture::reset();
        same(0, webhook()['code'], 'Webhook succeeds');
        paidOnce();
    };

    $tests['active order-query recovers an expired payment without reopening it'] = function () {
        Fixture::reset();
        $result = OrderStatusReconciler::reconcile(Fixture::row());
        same('paid', $result['status'], 'Query returns paid');
        paidOnce();
        same([['order_id' => 'ORDER-001', 'local_status' => 'expired']], Fixture::$queries, 'Queries original Order ID');
        OrderStatusReconciler::reconcile(Fixture::row());
        paidOnce();
    };

    $tests['result page asks the gateway before applying the local deadline'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        $result = OrderStatusReconciler::reconcileForResultPage(Fixture::row());
        same('paid', $result['status'], 'Paid result after deadline');
        same('pending', Fixture::$queries[0]['local_status'], 'Gateway queried before expiry');
        same(['paid'], array_column(array_column($wpdb->updates, 'fields'), 'status'), 'Never transiently expires paid order');
        paidOnce();
    };

    $tests['result page can also recover an already expired payment'] = function () {
        Fixture::reset();
        same('paid', OrderStatusReconciler::reconcileForResultPage(Fixture::row())['status'], 'Expired result is reconciled');
        paidOnce();
    };

    foreach (['reconcile', 'reconcileForResultPage'] as $queryMethod) {
        $tests['Stripe failed payment recovers once through ' . $queryMethod] = function () use ($queryMethod) {
            failedStripe();
            $result = OrderStatusReconciler::$queryMethod(Fixture::row());
            same('paid', $result['status'], 'Stripe query returns paid after retry');
            same([['session_id' => 'cs_test_001', 'local_status' => 'failed']], Fixture::$queries, 'Queries the failed Stripe session');
            stripePaidOnce();
            OrderStatusReconciler::$queryMethod(Fixture::row());
            stripePaidOnce();
        };
    }

    $tests['Stripe failed order query API reports recovered payment'] = function () {
        failedStripe();
        same('paid', queryOrder()['status'], 'API exposes recovered Stripe payment');
        stripePaidOnce();
    };

    $tests['Stripe failed result stays failed without a valid paid query result'] = function () {
        foreach (['open', 'processing', 'query_error', 'amount', 'currency', 'missing_amount', 'missing_currency'] as $scenario) {
            global $wpdb;
            failedStripe();
            if ($scenario === 'open' || $scenario === 'processing') {
                Fixture::$remoteStripe['payment_status'] = 'unpaid';
                Fixture::$remoteStripe['status'] = $scenario === 'open' ? 'open' : 'complete';
            } elseif ($scenario === 'query_error') {
                Fixture::$remoteError = true;
            } elseif ($scenario === 'amount') {
                Fixture::$remoteStripe['amount_total'] = 321;
            } elseif ($scenario === 'currency') {
                Fixture::$remoteStripe['currency'] = 'eur';
            } else {
                unset(Fixture::$remoteStripe[$scenario === 'missing_amount' ? 'amount_total' : 'currency']);
            }
            same('failed', OrderStatusReconciler::reconcileForResultPage(Fixture::row())['status'], 'Invalid query cannot recover: ' . $scenario);
            same(1, count(Fixture::$queries), 'Failed Stripe query was attempted');
            same([], $wpdb->updates, 'Invalid query performs no status write');
            noPaymentEffects('failed');
        }
    };

    $tests['failed recovery remains Stripe-only and does not reopen other terminal states'] = function () {
        same(false, TransactionStateMachine::canTransition('failed', 'paid'), 'Default failure remains terminal');
        same(true, TransactionStateMachine::canTransition('failed', 'paid', 'stripe'), 'Stripe paid confirmation can recover failure');
        foreach (['paypal_js', 'paypal_rest', 'airwallex', 'antom'] as $method) {
            global $wpdb;
            Fixture::reset('failed');
            $wpdb->rows['S-001']['payment_method'] = $method;
            same(false, TransactionStateMachine::canTransition('failed', 'paid', $method), 'No recovery for ' . $method);
            same('failed', OrderStatusReconciler::reconcileForResultPage(Fixture::row())['status'], 'Other failed results retain state');
            same([], Fixture::$queries, 'Other failed result does not query');
            mustThrow(function () { TransactionManager::updateStatus(Fixture::row(), 'paid'); }, 'Other failed transaction cannot become paid');
            noPaymentEffects('failed');
        }
        foreach (['pending', 'cancelled', 'refunded', 'disputing', 'confused'] as $target) {
            same(false, TransactionStateMachine::canTransition('failed', $target, 'stripe'), 'Stripe failure cannot become ' . $target);
        }
        foreach (['cancelled', 'refunded', 'confused'] as $status) {
            failedStripe($status);
            same($status, OrderStatusReconciler::reconcileForResultPage(Fixture::row())['status'], 'Stripe terminal result preserved');
            same([], Fixture::$queries, 'Other Stripe terminal result does not query');
            same($status, OrderStatusReconciler::reconcile(Fixture::row())['status'], 'Active query cannot override other terminal state');
            noPaymentEffects($status);
        }
    };

    $tests['concurrent Stripe failed recovery produces one payment effect and callback'] = function () {
        global $wpdb;
        failedStripe();
        $stale = Fixture::row();
        $wpdb->beforeUpdate = function () {
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['transaction_id' => 'pi_test_001'], false, true);
        };
        same('paid', OrderStatusReconciler::reconcileForResultPage($stale)['status'], 'Result returns concurrent paid winner');
        stripePaidOnce();
        same([true, false], array_column($wpdb->updates, 'matched'), 'Exactly one failed-to-paid CAS succeeds');
    };

    $tests['Stripe paid CAS recovers when a failure wins the first status race'] = function () {
        global $wpdb;
        failedStripe('pending');
        $stale = Fixture::row();
        $wpdb->beforeUpdate = function () use ($wpdb) { $wpdb->rows['S-001']['status'] = 'failed'; };
        WebhookRouter::applyStatusTransition($stale, 'paid', ['transaction_id' => 'pi_test_001'], false, true);
        stripePaidOnce();
        same([false, true], array_column($wpdb->updates, 'matched'), 'Paid write retries the concurrent failure');
        same(['pending', 'failed'], array_column(array_column($wpdb->updates, 'where'), 'status'), 'CAS rereads failed Stripe context');
    };

    $tests['superseded Stripe failure cannot repeat the concurrent recovery payment effects'] = function () {
        global $wpdb;
        failedStripe('pending');
        $wpdb->afterUpdate = function ($fields) {
            same('failed', $fields['status'], 'Failure is persisted before recovery begins');
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['transaction_id' => 'pi_test_001'], false, true);
        };
        same(null, WebhookRouter::applyStatusTransition(Fixture::row(), 'failed', ['transaction_id' => 'pi_test_001']), 'Superseded failure skips order effects and callbacks');
        stripePaidOnce();
        same(['failed', 'paid'], array_column(array_column($wpdb->updates, 'fields'), 'status'), 'Recovery supersedes the persisted failure');
        same([true, true], array_column($wpdb->updates, 'matched'), 'Both transitions persist once');
    };

    $tests['Stripe failed recovery requires an explicitly confirmed payment source'] = function () {
        global $wpdb;
        failedStripe();
        mustThrow(function () {
            TransactionManager::updateStatus(Fixture::row(), 'paid', ['transaction_id' => 'pi_test_001']);
        }, 'Direct status update cannot authorize failed payment recovery');
        mustThrow(function () {
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['transaction_id' => 'pi_test_001']);
        }, 'Shared status update cannot authorize failed payment recovery by default');
        same([], $wpdb->updates, 'Unconfirmed sources perform no payment writes');
        noPaymentEffects('failed');
    };

    $tests['Stripe dispute victory cannot recover a concurrently failed payment'] = function () {
        global $wpdb;
        failedStripe('pending');
        $body = json_encode([
            'id' => 'evt_dispute_race', 'type' => 'charge.dispute.closed',
            'data' => ['object' => [
                'id' => 'dp_dispute_race', 'payment_intent' => 'pi_test_001',
                'status' => 'won', 'amount' => 321, 'currency' => 'usd',
            ]],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'synthetic-stripe-webhook-secret');
        $wpdb->beforeLock = function () use ($wpdb) { $wpdb->rows['S-001']['status'] = 'failed'; };
        $response = WebhookRouter::handle('stripe', $body, ['stripe-signature' => 't=' . $timestamp . ',v1=' . $signature]);
        same(503, $response['http_status'], 'Verified dispute waits for actual payment confirmation');
        same('Payment confirmation pending; retry required', $response['message'], 'Dispute does not substitute for a payment recovery source');
        same([], $wpdb->updates, 'No payment write after concurrent failure');
        same(null, Fixture::row()['paid_at'], 'Dispute does not create payment evidence');
        noPaymentEffects('failed');
    };

    $tests['result link resolver can defer expiry while its default still enforces it'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        $previousGet = $_GET;
        $_GET['token'] = 'synthetic-valid-token';
        try {
            $resolver = new \ReflectionMethod(\PaymentGatewayAggregator\Frontend\PaymentPage::class, 'resolveTransactionOrExit');
            if (PHP_VERSION_ID < 80100) {
                $resolver->setAccessible(true);
            }
            same('pending', $resolver->invoke(null, 'S-001', false)['status'], 'Result lookup defers expiry');
            same([], $wpdb->updates, 'Deferred lookup does not write expiry');
            same('expired', $resolver->invoke(null, 'S-001')['status'], 'Ordinary lookup still enforces expiry');
            noPaymentEffects('expired');
        } finally {
            $_GET = $previousGet;
        }
    };

    $tests['unpaid past-deadline links still expire and cannot return to pending'] = function () {
        Fixture::reset('pending');
        Fixture::$remoteOrder = ['id' => 'ORDER-001', 'status' => 'APPROVED'];
        same('expired', OrderStatusReconciler::reconcileForResultPage(Fixture::row())['status'], 'Unpaid link expires');
        same('pending', Fixture::$queries[0]['local_status'], 'Unpaid gateway queried first');
        mustThrow(function () {
            TransactionManager::updateStatus(Fixture::row(), 'pending');
        }, 'Expired checkout must not reopen');
        noPaymentEffects('expired');
    };

    $tests['gateway query failures keep expired links blocked'] = function () {
        Fixture::reset('pending');
        Fixture::$remoteError = true;
        same('expired', OrderStatusReconciler::reconcileForResultPage(Fixture::row())['status'], 'Deadline still enforced on query failure');
        noPaymentEffects('expired');
    };

    $tests['ordinary order queries do not expire pending payments by the clock'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        Fixture::$remoteOrder = ['id' => 'ORDER-001', 'status' => 'APPROVED'];
        same('pending', OrderStatusReconciler::reconcile(Fixture::row())['status'], 'Query keeps unpaid state');
        same([], $wpdb->updates, 'Query does not apply link deadline');
        noPaymentEffects('pending');
    };

    $tests['previously recorded paid event can recover expired state once'] = function () {
        global $wpdb;
        Fixture::reset();
        Fixture::recordedEvent();
        same(0, webhook()['code'], 'Recorded event allowed to recover expiry');
        paidOnce();
        same(10013, webhook()['code'], 'Repeat is deduplicated');
        paidOnce();
        same(1, count($wpdb->events), 'No duplicate event rows');
    };

    $tests['recorded events do not bypass dedup for other local states'] = function () {
        foreach (['pending', 'paid', 'cancelled', 'failed', 'refunded', 'confused'] as $status) {
            Fixture::reset($status);
            Fixture::recordedEvent();
            same(10013, webhook()['code'], 'Duplicate stays ignored for ' . $status);
            noPaymentEffects($status);
        }
    };

    $tests['recorded event recovery still requires a valid signature and amount'] = function () {
        global $wpdb;
        Fixture::reset();
        Fixture::recordedEvent();
        same(10012, webhook('12.34', false)['code'], 'Invalid signature rejected');
        same([], $wpdb->updates, 'Invalid signature never writes status');
        noPaymentEffects('expired');
        same(10014, webhook('99.99')['code'], 'Mismatched amount rejected');
        same([], $wpdb->updates, 'Invalid amount never writes status');
        noPaymentEffects('expired');
    };

    $tests['capture-only recovery preserves the stored PayPal Order ID'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->rows['S-001']['transaction_id'] = 'CAPTURE-001';
        same(0, webhook('12.34', true, false)['code'], 'Lookup by stored Capture ID succeeds');
        paidOnce();
    };

    $tests['late completion enriches already-paid metadata without repeating effects'] = function () {
        global $wpdb;
        Fixture::reset('paid');
        $wpdb->rows['S-001']['paid_at'] = '2026-01-01 01:02:03';
        same(0, webhook()['code'], 'New completion event is accepted');
        same('CAPTURE-001', Fixture::row()['transaction_id'], 'Missing Capture ID populated');
        same('ORDER-001', Fixture::row()['payment_intent'], 'Original Order ID preserved');
        same('2026-01-01 01:02:03', Fixture::row()['paid_at'], 'Original payment time preserved');
        noPaymentEffects('paid');
        same(1, count($wpdb->updates), 'One metadata update');
        same('paid', $wpdb->updates[0]['where']['status'] ?? null, 'Metadata write also uses status CAS');
        same(10013, webhook()['code'], 'Recorded completion stays deduplicated');
        same(1, count($wpdb->updates), 'Repeat does not rewrite metadata');
        noPaymentEffects('paid');
    };

    foreach ([null, ''] as $missingId) {
        $tests['paid order query backfills ' . ($missingId === null ? 'NULL' : 'empty') . ' transaction ID once'] = function () use ($missingId) {
            global $wpdb;
            Fixture::reset('paid');
            $wpdb->rows['S-001']['transaction_id'] = $missingId;
            $wpdb->rows['S-001']['paid_at'] = '2026-01-01 01:02:03';
            $before = Fixture::row();
            $result = queryOrder();
            same('CAPTURE-001', $result['transaction_id'], 'Query response includes Capture ID');
            same('CAPTURE-001', Fixture::row()['transaction_id'], 'Capture ID persisted');
            same('paid', $result['status'], 'Query remains paid');
            same('2026-01-01T01:02:03Z', $result['paid_at'], 'Response retains original payment time');
            samePaidMetadata($before);
            same(1, count($wpdb->updates), 'Exactly one metadata write');
            same('paid', $wpdb->updates[0]['where']['status'] ?? null, 'Backfill requires current paid state');
            same(true, array_key_exists('transaction_id', $wpdb->updates[0]['where']), 'CAS includes previous transaction ID');
            same($missingId, $wpdb->updates[0]['where']['transaction_id'], 'CAS preserves NULL versus empty distinction');
            same('CAPTURE-001', queryOrder()['transaction_id'], 'Repeated query returns stored Capture ID');
            same(1, count($wpdb->updates), 'Repeated query does not rewrite metadata');
            samePaidMetadata($before);
        };
    }

    $tests['paid query never replaces a nonempty local transaction ID'] = function () {
        global $wpdb;
        foreach (['CAPTURE-EXISTING', '0'] as $existingId) {
            Fixture::reset('paid');
            $wpdb->rows['S-001']['transaction_id'] = $existingId;
            $before = Fixture::row();
            same($existingId, queryOrder()['transaction_id'], 'Existing local ID returned');
            same($existingId, Fixture::row()['transaction_id'], 'Existing local ID retained');
            same([], $wpdb->updates, 'Existing ID never rewritten');
            samePaidMetadata($before);
        }
    };

    $tests['remote NULL empty or missing Capture ID cannot clear local data'] = function () {
        global $wpdb;
        foreach ([null, '', 'CAPTURE-EXISTING'] as $localId) {
            foreach (['null', 'empty', 'missing'] as $variant) {
                Fixture::reset('paid');
                $wpdb->rows['S-001']['transaction_id'] = $localId;
                if ($variant === 'missing') {
                    unset(Fixture::$remoteOrder['purchase_units'][0]['payments']['captures'][0]['id']);
                } else {
                    Fixture::$remoteOrder['purchase_units'][0]['payments']['captures'][0]['id'] = $variant === 'null' ? null : '';
                }
                $before = Fixture::row();
                same($localId, queryOrder()['transaction_id'], 'Missing remote ID leaves response unchanged');
                same($localId, Fixture::row()['transaction_id'], 'Missing remote ID leaves database unchanged');
                same([], $wpdb->updates, 'No write without remote Capture ID');
                samePaidMetadata($before);
            }
        }
    };

    $tests['paid metadata backfill still rejects mismatched amounts and query failures'] = function () {
        global $wpdb;
        foreach (['amount', 'query_failure'] as $failure) {
            Fixture::reset('paid');
            if ($failure === 'amount') {
                Fixture::$remoteOrder['purchase_units'][0]['payments']['captures'][0]['amount']['value'] = '99.99';
            } else {
                Fixture::$remoteError = true;
            }
            $before = Fixture::row();
            same(null, queryOrder()['transaction_id'], 'Untrusted remote result cannot fill ID');
            same([], $wpdb->updates, 'No backfill write after ' . $failure);
            samePaidMetadata($before);
        }
    };

    $tests['paid metadata backfill preserves a concurrently filled different Capture ID'] = function () {
        global $wpdb;
        Fixture::reset('paid');
        $before = Fixture::row();
        $wpdb->beforeUpdate = function () use ($wpdb) {
            $wpdb->rows['S-001']['transaction_id'] = 'CAPTURE-CONCURRENT';
        };
        same('CAPTURE-CONCURRENT', queryOrder()['transaction_id'], 'Response reflects concurrent ID winner');
        same('CAPTURE-CONCURRENT', Fixture::row()['transaction_id'], 'Concurrent Capture ID preserved');
        same([false], array_column($wpdb->updates, 'matched'), 'Backfill CAS loses to concurrent writer');
        samePaidMetadata($before);
    };

    $tests['paid metadata backfill cannot overwrite a concurrent status change'] = function () {
        global $wpdb;
        Fixture::reset('paid');
        $before = Fixture::row();
        $wpdb->beforeUpdate = function () use ($wpdb) {
            $wpdb->rows['S-001']['status'] = 'refunded';
        };
        $result = queryOrder();
        same('refunded', $result['status'], 'Response reflects concurrent status winner');
        same(null, $result['transaction_id'], 'No Capture ID written after state changes');
        same('ORDER-001', Fixture::row()['payment_intent'], 'Order ID retained');
        same($before['paid_at'], Fixture::row()['paid_at'], 'Payment time retained');
        same([false], array_column($wpdb->updates, 'matched'), 'Backfill CAS requires paid state');
        noPaymentEffects('refunded');
    };

    $tests['paid metadata write failures degrade to the fresh local query response'] = function () {
        global $wpdb;
        Fixture::reset('paid');
        $before = Fixture::row();
        $wpdb->failUpdates = true;
        same(null, queryOrder()['transaction_id'], 'Write failure returns unchanged ID');
        same(1, $wpdb->failedWriteAttempts, 'Metadata write was attempted once before rereading fresh data');
        samePaidMetadata($before);
    };

    $tests['metadata helper checks fresh paid state empty ID and valid remote ID'] = function () {
        global $wpdb;
        foreach (['CAPTURE-EXISTING', '0'] as $existingId) {
            Fixture::reset('paid');
            $wpdb->rows['S-001']['transaction_id'] = $existingId;
            same(false, TransactionManager::fillMissingPaidTransactionId('S-001', 'CAPTURE-001'), 'Fresh nonempty ID prevents write');
            same($existingId, Fixture::row()['transaction_id'], 'Fresh existing ID preserved');
            same([], $wpdb->updates, 'No metadata write for filled ID');
            noPaymentEffects('paid');
        }
        foreach (['pending', 'expired', 'cancelled', 'failed', 'refunded', 'disputing', 'confused'] as $status) {
            Fixture::reset($status);
            same(false, TransactionManager::fillMissingPaidTransactionId('S-001', 'CAPTURE-001'), 'Fresh non-paid state prevents write');
            same(null, Fixture::row()['transaction_id'], 'Non-paid row not enriched');
            same([], $wpdb->updates, 'No metadata write for non-paid state');
            noPaymentEffects($status);
        }
        Fixture::reset('paid');
        unset($wpdb->rows['S-001']);
        same(false, TransactionManager::fillMissingPaidTransactionId('S-001', 'CAPTURE-001'), 'Missing row prevents write');
        same([], $wpdb->rows, 'Missing row is not created');
        same([], $wpdb->updates, 'No write for missing row');
        foreach ([null, '', '   ', "\n\t", 123, true, false, [], ['id' => 'CAPTURE-001'], new \stdClass()] as $invalidId) {
            Fixture::reset('paid');
            same(false, TransactionManager::fillMissingPaidTransactionId('S-001', $invalidId), 'Invalid remote ID rejected');
            same(null, Fixture::row()['transaction_id'], 'Invalid remote ID never stored');
            same([], $wpdb->updates, 'Invalid remote ID performs no write');
            noPaymentEffects('paid');
        }
    };

    $tests['only expired-to-paid is added and other terminal protections remain'] = function () {
        foreach (['pending', 'failed', 'cancelled', 'refunded', 'disputing', 'confused'] as $target) {
            same(false, TransactionStateMachine::canTransition('expired', $target), 'Expired cannot become ' . $target);
        }
        same(true, TransactionStateMachine::canTransition('expired', 'paid'), 'Late paid is allowed');
        foreach (['failed', 'cancelled', 'refunded', 'confused'] as $status) {
            Fixture::reset($status);
            mustThrow(function () {
                TransactionManager::updateStatus(Fixture::row(), 'paid');
            }, 'Terminal state ' . $status . ' must reject paid');
            noPaymentEffects($status);
        }
    };

    $tests['stale expiry cannot overwrite a concurrent paid transition'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        $stale = Fixture::row();
        $wpdb->beforeUpdate = function ($fields) {
            same('expired', $fields['status'], 'Expiry update is about to race');
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['transaction_id' => 'CAPTURE-001']);
        };
        same('paid', TransactionManager::maybeExpire($stale)['status'], 'Expiry returns winner status');
        paidOnce();
        same([true, false], array_column($wpdb->updates, 'matched'), 'Paid CAS wins and expiry loses');
        foreach ($wpdb->updates as $attempt) {
            same('pending', $attempt['where']['status'] ?? null, 'Expected status included in CAS');
        }
    };

    $tests['two concurrent paid writers produce only one order effect and callback'] = function () {
        global $wpdb;
        Fixture::reset();
        $stale = Fixture::row();
        $wpdb->beforeUpdate = function () {
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['transaction_id' => 'CAPTURE-001']);
        };
        same(null, WebhookRouter::applyStatusTransition($stale, 'paid', ['transaction_id' => 'CAPTURE-001']), 'Losing writer becomes noop');
        paidOnce();
        same([true, false], array_column($wpdb->updates, 'matched'), 'Exactly one CAS write succeeds');
        same('noop', TransactionManager::updateStatus(Fixture::row(), 'paid'), 'Same target remains noop');
        same(2, count($wpdb->updates), 'No SQL write for already-paid target');
    };

    $tests['result page returns the concurrent paid winner instead of stale expired state'] = function () {
        global $wpdb;
        Fixture::reset();
        $stale = Fixture::row();
        $wpdb->beforeUpdate = function () {
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid', ['transaction_id' => 'CAPTURE-001']);
        };
        $result = OrderStatusReconciler::reconcileForResultPage($stale);
        same('paid', $result['status'], 'Result reflects concurrent paid winner');
        paidOnce();
    };

    $tests['paid CAS retries when pending concurrently becomes expired'] = function () {
        global $wpdb;
        Fixture::reset('pending');
        $stale = Fixture::row();
        $wpdb->beforeUpdate = function () use ($wpdb) {
            $wpdb->rows['S-001']['status'] = 'expired';
        };
        WebhookRouter::applyStatusTransition($stale, 'paid', ['transaction_id' => 'CAPTURE-001']);
        paidOnce();
        same([false, true], array_column($wpdb->updates, 'matched'), 'Fresh expired state retried');
        same(['pending', 'expired'], array_column(array_column($wpdb->updates, 'where'), 'status'), 'Retry uses refreshed status');
    };

    $tests['database failures and persistent CAS contention are reported without effects'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->failUpdates = true;
        mustThrow(function () {
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid');
        }, 'Write failure must be reported');
        noPaymentEffects('expired');
        Fixture::reset();
        $wpdb->rejectUpdates = true;
        mustThrow(function () {
            WebhookRouter::applyStatusTransition(Fixture::row(), 'paid');
        }, 'Persistent CAS contention must be reported');
        same(3, count($wpdb->updates), 'CAS retry count is bounded');
        noPaymentEffects('expired');
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo 'PASS ' . $name . PHP_EOL;
        } catch (\Throwable $error) {
            $failures++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
    echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}
