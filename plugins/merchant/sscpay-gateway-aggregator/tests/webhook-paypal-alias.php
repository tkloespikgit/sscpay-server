<?php
/**
 * Isolated regression runner; no WordPress bootstrap, database or network.
 * Run: php tests/webhook-paypal-alias.php
 *
 * Uses the production router, factory, gateways, event parsers and state machine.
 * Only persistence, order effects, callbacks and PayPal's remote verifier are doubled.
 */

namespace {
    if (PHP_SAPI !== 'cli') {
        exit(1);
    }

    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    function wp_json_encode($value) { return json_encode($value); }

    function current_time($type, $gmt = false)
    {
        return '2026-10-16 00:00:00';
    }
}

namespace PaymentGatewayAggregator\Tests {
    class Fixture
    {
        public static $rows = [];
        public static $decrypts = 0;
        public static $verifications = [];
        public static $updates = 0;
        public static $callbacks = [];
        public static $orders = [];
        public static $logs = [];
        public static $remoteDispute;

        public static function reset($method = 'paypal_js', $reference = 'ORDER-001')
        {
            global $wpdb;
            $wpdb = new MemoryWpdb();
            self::$decrypts = self::$updates = 0;
            self::$verifications = self::$callbacks = self::$orders = self::$logs = [];
            self::$remoteDispute = null;
            self::$rows = ['S-001' => [
                's_order_id' => 'S-001',
                'wp_order_id' => 1,
                'payment_method' => $method,
                'payment_intent' => $reference,
                'transaction_id' => null,
                'amount' => '12.34',
                'currency' => 'USD',
                'status' => 'pending',
                'gateway_config' => [
                    'webhook_id' => 'test-webhook-' . $method,
                    'webhook_secret' => 'whsec_isolated_test_only',
                    'secret_key' => 'sk_test_isolated_only',
                ],
            ]];
        }
    }

    class MemoryWpdb
    {
        public $prefix = 'isolated_';
        public $events = [];
        public $references = [];
        public $last_error = '';
        private $snapshot;

        public function get_var($prepared)
        {
            if (strpos($prepared[0], 'GET_LOCK(') !== false || strpos($prepared[0], 'RELEASE_LOCK(') !== false) { return '1'; }
            throw new \RuntimeException('Unexpected scalar lookup');
        }

        public function get_results($prepared, $format = ARRAY_A)
        {
            $sql = is_array($prepared) ? $prepared[0] : $prepared;
            if (strpos($sql, 'SHOW TABLE STATUS') === 0) {
                return array_map(function ($suffix) {
                    return ['Name' => $this->prefix . $suffix, 'Engine' => 'InnoDB'];
                }, ['payment_transactions', 'payment_session_references', 'payment_webhook_events', 'payment_callback_queue']);
            }
            throw new \RuntimeException('Unexpected multi lookup');
        }

        public function prepare($sql, ...$args)
        {
            return [$sql, $args];
        }

        public function query($prepared)
        {
            [$sql, $args] = is_array($prepared) ? $prepared : [$prepared, []];
            if ($sql === 'START TRANSACTION') {
                $this->snapshot = [Fixture::$rows, $this->events, $this->references];
                return 1;
            }
            if ($sql === 'COMMIT') { $this->snapshot = null; return 1; }
            if ($sql === 'ROLLBACK') {
                if ($this->snapshot) { [Fixture::$rows, $this->events, $this->references] = $this->snapshot; }
                $this->snapshot = null;
                return 1;
            }
            if (strpos($sql, 'INSERT INTO isolated_payment_session_references') === 0) {
                if (isset($this->references[$args[0]])) { return 0; }
                $this->references[$args[0]] = array_combine(
                    ['ref_hash', 'external_ref', 's_order_id', 'session_ref', 'session_data', 'created_at'], $args
                );
                return 1;
            }
            if (strpos($sql, 'INSERT IGNORE INTO isolated_payment_webhook_events') === false
                || count($args) !== 5
            ) {
                throw new \RuntimeException('Unexpected database operation in isolated runner');
            }
            $key = $args[0] . ':' . $args[1];
            if (isset($this->events[$key])) {
                return 0;
            }
            $this->events[$key] = $args;
            return 1;
        }
        public function get_row($prepared, $format)
        {
            [$sql, $args] = $prepared;
            if (strpos($sql, 'SELECT * FROM isolated_payment_session_references') === 0) {
                return $this->references[$args[0]] ?? null;
            }
            throw new \RuntimeException('Unexpected reference lookup');
        }
        public function update($table, array $fields, array $where)
        {
            if ($table !== 'isolated_payment_session_references' || !isset($this->references[$where['ref_hash']])) {
                throw new \RuntimeException('Unexpected reference update');
            }
            $this->references[$where['ref_hash']] = array_merge($this->references[$where['ref_hash']], $fields);
            return 1;
        }
    }

    class StripeDisputes
    {
        public function retrieve($id) { return (object) Fixture::$remoteDispute; }
    }
    class StripeSessions
    {
        public function all($params) { return (object) ['data' => []]; }
    }
}

namespace Stripe {
    class StripeClient
    {
        public $checkout;
        public $disputes;
        public function __construct($key)
        {
            $this->checkout = (object) ['sessions' => new \PaymentGatewayAggregator\Tests\StripeSessions()];
            $this->disputes = new \PaymentGatewayAggregator\Tests\StripeDisputes();
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\Fixture;

    class TransactionManager
    {
        public static function stripeWebhookConfigs() { return array_values(Fixture::$rows); }
        public static function findByExternalRef($reference)
        {
            foreach (Fixture::$rows as $row) {
                if ($row['payment_intent'] === $reference || $row['transaction_id'] === $reference) {
                    return $row;
                }
            }
            return PaymentSessionReferences::find($reference);
        }
        public static function getGatewaySession(array $transaction)
        {
            return json_decode($transaction['gateway_session'] ?? '', true) ?: [];
        }

        public static function decryptGatewayConfig(array $transaction)
        {
            Fixture::$decrypts++;
            return $transaction['gateway_config'];
        }

        public static function updateStatus(array $transaction, $status, array $fields, $allowFailedRecovery = false)
        {
            Fixture::$updates++;
            $result = TransactionStateMachine::assertTransition(
                $transaction['status'], $status, $allowFailedRecovery ? $transaction['payment_method'] : null
            );
            if ($result !== 'noop') {
                Fixture::$rows[$transaction['s_order_id']] = array_merge($transaction, $fields, ['status' => $status]);
            }
            return $result;
        }

        public static function findBySOrderId($orderId)
        {
            return Fixture::$rows[$orderId] ?? null;
        }
    }

    class LogManager
    {
        const SYSTEM_ORDER_ID = '__system__';

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
    use PaymentGatewayAggregator\Tests\Fixture;

    class PayPalRestClient
    {
        public static function verifyWebhookSignature(array $config, array $headers, $rawBody)
        {
            Fixture::$verifications[] = compact('config', 'headers', 'rawBody');
            // Simulate the remote verifier while checking the transaction's own webhook ID.
            return ($headers['paypal-transmission-sig'] ?? '') === 'signed-' . $config['webhook_id'];
        }
    }
}

namespace {
    use PaymentGatewayAggregator\Core\GatewayFactory;
    use PaymentGatewayAggregator\Core\WebhookRouter;
    use PaymentGatewayAggregator\Tests\Fixture;

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

    function response($actual, $status, $code, $message)
    {
        same($status, $actual['http_status'], 'HTTP status');
        same($code, $actual['code'], 'Response code');
        same($message, $actual['message'], 'Response message');
        same($status === 200, $actual['success'], 'Success flag');
    }

    function paypalEvent($type = 'PAYMENT.CAPTURE.COMPLETED', $reference = 'ORDER-001')
    {
        return json_encode([
            'id' => 'WH-001',
            'event_type' => $type,
            'resource' => [
                'id' => 'CAPTURE-001',
                'supplementary_data' => ['related_ids' => ['order_id' => $reference]],
                'amount' => ['value' => '12.34', 'currency_code' => 'USD'],
            ],
        ]);
    }

    function paypalHeaders()
    {
        return ['paypal-transmission-sig' => 'signed-' . Fixture::$rows['S-001']['gateway_config']['webhook_id']];
    }

    function paypalDisputeEvent($type, $amount = '3.21', $currency = 'USD', $reference = 'CAPTURE-001', $outcome = '')
    {
        $resource = [
            'dispute_id' => 'PP-D-001',
            'reason' => 'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED',
            'disputed_transactions' => [['seller_transaction_id' => $reference]],
            'dispute_amount' => ['value' => $amount, 'currency_code' => $currency],
        ];
        if ($outcome !== '') {
            $resource['dispute_outcome'] = ['outcome_code' => $outcome];
        }
        if ($type === 'PAYMENT.CAPTURE.REVERSED') {
            $resource = [
                'id' => $reference,
                'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-001']],
                'amount' => ['value' => $amount, 'currency_code' => $currency],
                'reason' => 'CHARGEBACK',
            ];
        }
        return json_encode(['id' => 'WH-DISPUTE-001', 'event_type' => $type, 'resource' => $resource]);
    }

    function paidPayPal($method = 'paypal_js', $status = 'paid')
    {
        Fixture::reset($method);
        Fixture::$rows['S-001']['transaction_id'] = 'CAPTURE-001';
        Fixture::$rows['S-001']['status'] = $status;
    }

    function stripeDisputeEvent($type = 'charge.dispute.created', $status = 'needs_response', $amount = 321, $currency = 'usd', $reference = 'pi_test_001')
    {
        return json_encode([
            'id' => 'evt_dispute_001', 'type' => $type,
            'data' => ['object' => [
                'id' => 'dp_test_001', 'payment_intent' => $reference,
                'amount' => $amount, 'currency' => $currency,
                'reason' => 'fraudulent', 'status' => $status,
            ]],
        ]);
    }

    function stripeHeaders($body)
    {
        $event = json_decode($body, true);
        if (strpos($event['type'] ?? '', 'charge.dispute.') === 0) { Fixture::$remoteDispute = $event['data']['object']; }
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, Fixture::$rows['S-001']['gateway_config']['webhook_secret']);
        return ['stripe-signature' => 't=' . $timestamp . ',v1=' . $signature];
    }

    function stripePaymentEvent($type, $eventId, $amount = 1234, $currency = 'usd')
    {
        return json_encode([
            'id' => $eventId, 'type' => $type,
            'data' => ['object' => [
                'id' => $type === 'checkout.session.completed' ? 'cs_test_001' : 'pi_test_001',
                'payment_status' => 'paid', 'payment_intent' => 'pi_test_001',
                'amount' => $amount, 'amount_total' => $amount, 'amount_received' => $amount,
                'currency' => $currency,
            ]],
        ]);
    }

    function paidStripe($status = 'paid')
    {
        Fixture::reset('stripe', 'cs_test_001');
        Fixture::$rows['S-001']['transaction_id'] = 'pi_test_001';
        Fixture::$rows['S-001']['status'] = $status;
    }

    function noWrites($status = 'pending')
    {
        global $wpdb;
        same([], $wpdb->events, 'No event writes');
        same(0, Fixture::$updates, 'No state updates');
        same([], Fixture::$orders, 'No order effects');
        same([], Fixture::$callbacks, 'No callbacks');
        same($status, Fixture::$rows['S-001']['status'], 'Order status unchanged');
    }

    $tests = [];
    foreach (['paypal_js', 'paypal_rest'] as $method) {
        $tests['shared alias completes ' . $method] = function () use ($method) {
            global $wpdb;
            Fixture::reset($method);
            $body = paypalEvent();
            response(WebhookRouter::handle('paypal', $body, paypalHeaders()), 200, 0, 'ok');
            same('paid', Fixture::$rows['S-001']['status'], 'Payment status');
            same('CAPTURE-001', Fixture::$rows['S-001']['transaction_id'], 'Capture ID');
            same([$method . ':WH-001'], array_keys($wpdb->events), 'Canonical event key');
            same(1, Fixture::$updates, 'One state transition');
            same([['markPaid', [1, 'CAPTURE-001']]], Fixture::$orders, 'Order marked paid');
            same(1, count(Fixture::$callbacks), 'One callback');
            same($method, Fixture::$callbacks[0]['payment_method'], 'Callback uses stored method');
            same(Fixture::$rows['S-001']['gateway_config'], Fixture::$verifications[0]['config'], 'Stored verification config');
            same($body, Fixture::$verifications[0]['rawBody'], 'Original bytes reach verifier');
            $lastLog = Fixture::$logs[count(Fixture::$logs) - 1];
            same($method, $lastLog[1][2]['payment_method'], 'Log uses stored method');
        };

        $tests['alias and typed URLs share dedup for ' . $method] = function () use ($method) {
            global $wpdb;
            Fixture::reset($method);
            response(WebhookRouter::handle('paypal', paypalEvent(), paypalHeaders()), 200, 0, 'ok');
            foreach (['paypal_js', 'paypal_rest', 'paypal'] as $route) {
                response(WebhookRouter::handle($route, paypalEvent(), paypalHeaders()), 200, 10013, 'Event already processed (idempotent)');
            }
            same([$method . ':WH-001'], array_keys($wpdb->events), 'One canonical event row');
            same(1, Fixture::$updates, 'Duplicate never reaches state transition');
            same(1, count(Fixture::$orders), 'One order effect');
            same(1, count(Fixture::$callbacks), 'One callback across URL variants');
        };

        $disputes = [
            'created' => ['CUSTOMER.DISPUTE.CREATED', '', 'paid', 'disputing', 'dispute_created', '3.21', 321],
            'updated' => ['CUSTOMER.DISPUTE.UPDATED', '', 'paid', 'disputing', 'dispute_updated', '3.21', 321],
            'won' => ['CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_SELLER_FAVOUR', 'disputing', 'paid', 'dispute_won', '18.45', 1845],
            'lost' => ['CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_BUYER_FAVOUR', 'disputing', 'confused', 'dispute_lost', '3.21', 321],
            'reversed' => ['PAYMENT.CAPTURE.REVERSED', '', 'paid', 'confused', 'capture_reversed', '3.21', 321],
        ];
        foreach (['paypal', $method] as $route) {
            foreach ($disputes as $label => $case) {
                $tests[$route . ' accepts differing ' . $label . ' amount for ' . $method] = function () use ($method, $route, $case) {
                    global $wpdb;
                    [$type, $outcome, $initialStatus, $targetStatus, $eventStatus, $amount, $minorAmount] = $case;
                    paidPayPal($method, $initialStatus);
                    $body = paypalDisputeEvent($type, $amount, 'USD', 'CAPTURE-001', $outcome);
                    response(WebhookRouter::handle($route, $body, paypalHeaders()), 200, 0, 'ok');
                    same($targetStatus, Fixture::$rows['S-001']['status'], 'Dispute status applied');
                    same('CAPTURE-001', Fixture::$rows['S-001']['transaction_id'], 'Original capture retained');
                    same('12.34', Fixture::$rows['S-001']['amount'], 'Order amount retained');
                    same('USD', Fixture::$rows['S-001']['currency'], 'Order currency retained');
                    same([$method . ':WH-DISPUTE-001'], array_keys($wpdb->events), 'Dispute uses canonical event key');
                    same(1, count(Fixture::$callbacks), 'One dispute callback');
                    same('12.34', Fixture::$callbacks[0]['amount'], 'Callback keeps transaction amount');
                    same('USD', Fixture::$callbacks[0]['currency'], 'Callback keeps transaction currency');
                    same($targetStatus, Fixture::$callbacks[0]['status'], 'Callback has dispute status');
                    same('error', Fixture::$logs[0][0], 'Dispute remains visible in error logs');
                    $log = Fixture::$logs[0][1];
                    same(true, strpos($log[1], '涉及金额：USD ' . $amount) !== false, 'Log shows actual dispute amount');
                    same($minorAmount, $log[2]['callback_data']['amount_minor'], 'Log retains parsed dispute amount');
                    same($eventStatus, $log[2]['callback_data']['event_status'], 'Log retains dispute type');
                    same($method, $log[2]['payment_method'], 'Log uses actual payment method');
                    if ($type !== 'PAYMENT.CAPTURE.REVERSED') {
                        same('PP-D-001', $log[2]['callback_data']['dispute_id'], 'Log retains dispute ID');
                    }
                };
            }
        }

        $tests['partial dispute deduplicates across URLs for ' . $method] = function () use ($method) {
            global $wpdb;
            paidPayPal($method);
            $body = paypalDisputeEvent('CUSTOMER.DISPUTE.CREATED');
            response(WebhookRouter::handle('paypal', $body, paypalHeaders()), 200, 0, 'ok');
            foreach (['paypal_js', 'paypal_rest', 'paypal'] as $route) {
                response(WebhookRouter::handle($route, $body, paypalHeaders()), 200, 10013, 'Event already processed (idempotent)');
            }
            same([$method . ':WH-DISPUTE-001'], array_keys($wpdb->events), 'One dispute event row');
            same(1, Fixture::$updates, 'Duplicate dispute never reaches state transition');
            same(1, count(Fixture::$callbacks), 'One callback across dispute URL variants');
            same([], Fixture::$orders, 'Dispute creation has no WooCommerce payment effect');
        };

        $tests['partial dispute currency is normalized for ' . $method] = function () use ($method) {
            paidPayPal($method);
            Fixture::$rows['S-001']['currency'] = ' usd ';
            response(WebhookRouter::handle($method, paypalDisputeEvent('CUSTOMER.DISPUTE.CREATED', '3.21', 'usd'), paypalHeaders()), 200, 0, 'ok');
            same('disputing', Fixture::$rows['S-001']['status'], 'Normalized matching currency accepted');
            same(' usd ', Fixture::$callbacks[0]['currency'], 'Callback retains stored currency');
        };

        $tests['PayPal dispute currency mismatch is rejected for ' . $method] = function () use ($method) {
            global $wpdb;
            foreach (['paypal', $method] as $route) {
                paidPayPal($method);
                response(WebhookRouter::handle($route, paypalDisputeEvent('CUSTOMER.DISPUTE.CREATED', '3.21', 'EUR'), paypalHeaders()), 400, 10014, 'Amount or currency mismatch');
                same(1, count(Fixture::$verifications), 'Dispute signature checked first');
                same([], $wpdb->references, 'Mismatched dispute creates no references');
                noWrites('paid');
            }
        };

        $tests['partial dispute invalid signature is rejected for ' . $method] = function () use ($method) {
            paidPayPal($method);
            response(WebhookRouter::handle('paypal', paypalDisputeEvent('CUSTOMER.DISPUTE.CREATED'), ['paypal-transmission-sig' => 'invalid']), 400, 10012, 'Signature verification failed');
            same(1, count(Fixture::$verifications), 'Dispute signature checked');
            noWrites('paid');
        };

        $tests['partial dispute unknown capture is rejected for ' . $method] = function () use ($method) {
            paidPayPal($method);
            response(WebhookRouter::handle('paypal', paypalDisputeEvent('CUSTOMER.DISPUTE.CREATED', '3.21', 'USD', 'MISSING-CAPTURE'), paypalHeaders()), 400, 10001, 'Order not found');
            same([], Fixture::$verifications, 'Unknown capture does not use order credentials');
            noWrites('paid');
        };

        $tests['partial dispute reference owned by another order is rejected for ' . $method] = function () use ($method) {
            global $wpdb;
            paidPayPal($method);
            $wpdb->references[hash('sha256', 'CAPTURE-001')] = [
                'external_ref' => 'CAPTURE-001', 's_order_id' => 'S-OTHER',
                'session_ref' => 'ORDER-OTHER', 'session_data' => '{}',
            ];
            response(WebhookRouter::handle('paypal', paypalDisputeEvent('CUSTOMER.DISPUTE.CREATED'), paypalHeaders()), 400, 10001, 'Payment reference belongs to another order');
            noWrites('paid');
        };

        $tests['PayPal payment amount mismatch is still rejected for ' . $method] = function () use ($method) {
            foreach (['paypal', $method] as $route) {
                Fixture::reset($method);
                $event = json_decode(paypalEvent(), true);
                $event['resource']['amount']['value'] = '3.21';
                response(WebhookRouter::handle($route, json_encode($event), paypalHeaders()), 400, 10014, 'Amount or currency mismatch');
                noWrites();
            }
        };
    }

    $tests['PayPal URLs reject a non-PayPal transaction before credentials or verification'] = function () {
        foreach (['paypal', 'paypal_js', 'paypal_rest'] as $route) {
            Fixture::reset('stripe');
            response(WebhookRouter::handle($route, paypalEvent(), paypalHeaders()), 400, 10016, 'Payment gateway does not match transaction');
            same(0, Fixture::$decrypts, 'No config decryption');
            same([], Fixture::$verifications, 'No verification request');
            noWrites();
        }
    };

    $tests['invalid PayPal signature cannot record or update an event'] = function () {
        Fixture::reset();
        response(WebhookRouter::handle('paypal', paypalEvent(), ['paypal-transmission-sig' => 'invalid']), 400, 10012, 'Signature verification failed');
        same(1, count(Fixture::$verifications), 'Signature was checked');
        noWrites();
    };

    $tests['empty shared webhook is ignored without transaction or signature work'] = function () {
        Fixture::reset();
        response(WebhookRouter::handle('paypal', '{}', []), 200, 0, 'Event ignored');
        same(0, Fixture::$decrypts, 'No config lookup');
        same([], Fixture::$verifications, 'No verification request');
        noWrites();
    };

    $tests['unknown PayPal reference keeps the existing order-not-found error'] = function () {
        Fixture::reset();
        response(WebhookRouter::handle('paypal', paypalEvent('PAYMENT.CAPTURE.COMPLETED', 'MISSING-ORDER'), []), 400, 10001, 'Order not found');
        same(0, Fixture::$decrypts, 'No config lookup');
        same([], Fixture::$verifications, 'No verification request');
        noWrites();
    };

    $tests['unhandled PayPal event is verified and recorded without payment effects'] = function () {
        global $wpdb;
        Fixture::reset('paypal_rest');
        response(WebhookRouter::handle('paypal', paypalEvent('TEST.UNHANDLED'), paypalHeaders()), 200, 0, 'Event type requires no action');
        same(['paypal_rest:WH-001'], array_keys($wpdb->events), 'Ignored event uses canonical method');
        same(1, count(Fixture::$verifications), 'Event was verified');
        same(0, Fixture::$updates, 'No payment transition');
        same([], Fixture::$orders, 'No order effects');
        same([], Fixture::$callbacks, 'No callbacks');
    };

    $tests['Stripe payment still verifies with its real SDK and completes normally'] = function () {
        global $wpdb;
        Fixture::reset('stripe', 'cs_test_001');
        $body = json_encode([
            'id' => 'evt_test_001',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_001',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_001',
                'amount_total' => 1234,
                'currency' => 'usd',
            ]],
        ]);
        response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 200, 0, 'ok');
        same('paid', Fixture::$rows['S-001']['status'], 'Stripe paid status');
        same('pi_test_001', Fixture::$rows['S-001']['transaction_id'], 'Stripe payment intent');
        same(['stripe:evt_test_001'], array_keys($wpdb->events), 'Stripe dedup key');
        same('stripe', Fixture::$callbacks[0]['payment_method'], 'Stripe callback method');
        same([], Fixture::$verifications, 'No PayPal verifier');
    };

    foreach ([
        'created' => ['charge.dispute.created', 'needs_response', 'paid', 'disputing', 321, '3.21'],
        'updated' => ['charge.dispute.updated', 'under_review', 'paid', 'disputing', 321, '3.21'],
        'won' => ['charge.dispute.closed', 'won', 'disputing', 'paid', 1845, '18.45'],
        'lost' => ['charge.dispute.closed', 'lost', 'disputing', 'confused', 321, '3.21'],
    ] as $label => $case) {
        $tests['Stripe accepts differing ' . $label . ' dispute amount'] = function () use ($label, $case) {
            global $wpdb;
            [$type, $gatewayStatus, $initialStatus, $targetStatus, $amount, $displayAmount] = $case;
            paidStripe($initialStatus);
            $body = stripeDisputeEvent($type, $gatewayStatus, $amount);
            response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 200, 0, 'ok');
            same($targetStatus, Fixture::$rows['S-001']['status'], 'Stripe dispute state applied');
            same('pi_test_001', Fixture::$rows['S-001']['transaction_id'], 'Payment intent retained');
            same('12.34', Fixture::$rows['S-001']['amount'], 'Original Stripe transaction amount retained');
            same('USD', Fixture::$rows['S-001']['currency'], 'Original Stripe transaction currency retained');
            same(['stripe:evt_dispute_001'], array_keys($wpdb->events), 'Stripe dispute event key');
            same(1, count(Fixture::$callbacks), 'One Stripe dispute callback');
            same('12.34', Fixture::$callbacks[0]['amount'], 'Stripe callback retains transaction total');
            same('USD', Fixture::$callbacks[0]['currency'], 'Stripe callback retains transaction currency');
            same($targetStatus, Fixture::$callbacks[0]['status'], 'Stripe callback has dispute status');
            same('error', Fixture::$logs[0][0], 'Stripe dispute remains visible in error logs');
            $log = Fixture::$logs[0][1];
            same(true, strpos($log[1], '涉及金额：USD ' . $displayAmount) !== false, 'Log shows actual Stripe dispute amount');
            same($amount, $log[2]['callback_data']['amount_minor'], 'Log retains Stripe dispute amount');
            same('dispute_' . $label, $log[2]['callback_data']['event_status'], 'Log retains Stripe dispute type');
            same('dp_test_001', $log[2]['callback_data']['dispute_id'], 'Log retains Stripe dispute ID');
            same([], Fixture::$verifications, 'Stripe uses its real SDK verifier');
        };
    }

    $tests['partial Stripe dispute duplicate has no repeated state or callback effects'] = function () {
        global $wpdb;
        paidStripe();
        $body = stripeDisputeEvent();
        response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 200, 0, 'ok');
        response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 200, 10013, 'Event already processed (idempotent)');
        same(['stripe:evt_dispute_001'], array_keys($wpdb->events), 'One Stripe dispute event');
        same(1, Fixture::$updates, 'One Stripe dispute state transition');
        same(1, count(Fixture::$callbacks), 'One Stripe dispute callback');
        same([], Fixture::$orders, 'Stripe dispute creation has no WooCommerce payment effects');
    };

    $tests['partial Stripe dispute accepts normalized matching currencies'] = function () {
        paidStripe();
        Fixture::$rows['S-001']['currency'] = ' usd ';
        $body = stripeDisputeEvent();
        response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 200, 0, 'ok');
        same('disputing', Fixture::$rows['S-001']['status'], 'Normalized Stripe currency accepted');
    };

    $tests['partial Stripe dispute currency mismatch is rejected'] = function () {
        paidStripe();
        $body = stripeDisputeEvent('charge.dispute.created', 'needs_response', 321, 'eur');
        response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 400, 10014, 'Amount or currency mismatch');
        noWrites('paid');
    };

    $tests['partial Stripe dispute invalid signature is rejected'] = function () {
        paidStripe();
        $body = stripeDisputeEvent();
        response(WebhookRouter::handle('stripe', $body, ['stripe-signature' => 't=' . time() . ',v1=invalid']), 400, 10012, 'Signature verification failed');
        noWrites('paid');
    };

    $tests['partial Stripe dispute unknown payment intent is rejected'] = function () {
        paidStripe();
        $body = stripeDisputeEvent('charge.dispute.created', 'needs_response', 321, 'usd', 'pi_missing');
        response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 400, 10001, 'Order not found');
        same(1, Fixture::$decrypts, 'Unknown PI attempts resolution using signature-matched historical credentials');
        noWrites('paid');
    };

    foreach (['checkout.session.completed', 'payment_intent.succeeded'] as $type) {
        $tests['Stripe ' . $type . ' amount mismatch is still rejected'] = function () use ($type) {
            Fixture::reset('stripe', 'cs_test_001');
            Fixture::$rows['S-001']['transaction_id'] = 'pi_test_001';
            $body = json_encode([
                'id' => 'evt_payment_001', 'type' => $type,
                'data' => ['object' => [
                    'id' => $type === 'checkout.session.completed' ? 'cs_test_001' : 'pi_test_001',
                    'payment_status' => 'paid', 'payment_intent' => 'pi_test_001',
                    'amount_total' => 321, 'amount_received' => 321, 'currency' => 'usd',
                ]],
            ]);
            response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 400, 10014, 'Amount or currency mismatch');
            noWrites();
        };

        $tests['Stripe failed attempt recovers through ' . $type . ' once'] = function () use ($type) {
            global $wpdb;
            paidStripe('pending');
            $failure = stripePaymentEvent('payment_intent.payment_failed', 'evt_failure_001');
            response(WebhookRouter::handle('stripe', $failure, stripeHeaders($failure)), 200, 0, 'ok');
            same('failed', Fixture::$rows['S-001']['status'], 'Failed attempt is recorded');
            response(WebhookRouter::handle('stripe', $failure, stripeHeaders($failure)), 200, 10013, 'Event already processed (idempotent)');
            same(['failed'], array_column(Fixture::$callbacks, 'status'), 'Duplicate failure has no extra callback');

            $success = stripePaymentEvent($type, 'evt_recovery_001');
            response(WebhookRouter::handle('stripe', $success, stripeHeaders($success)), 200, 0, 'ok');
            same('paid', Fixture::$rows['S-001']['status'], 'Confirmed payment recovers failed attempt');
            same('cs_test_001', Fixture::$rows['S-001']['payment_intent'], 'Checkout Session retained');
            same('pi_test_001', Fixture::$rows['S-001']['transaction_id'], 'PaymentIntent retained');
            same(['failed', 'paid'], array_column(Fixture::$callbacks, 'status'), 'Recovery sends updated payment status');
            same([['markFailed', [1]], ['markPaid', [1, 'pi_test_001']]], Fixture::$orders, 'Recovery marks WooCommerce paid');
            response(WebhookRouter::handle('stripe', $success, stripeHeaders($success)), 200, 10013, 'Event already processed (idempotent)');
            same(2, Fixture::$updates, 'Repeated payment event does not update state');
            same(2, count(Fixture::$callbacks), 'Repeated payment event does not send another callback');
            same(['stripe:evt_failure_001', 'stripe:evt_recovery_001'], array_keys($wpdb->events), 'Failure and recovery each retain one event row');
        };

        $tests['previously consumed Stripe ' . $type . ' can recover failed state'] = function () use ($type) {
            global $wpdb;
            paidStripe('failed');
            // Old code recorded the success before rejecting failed -> paid.
            $wpdb->events['stripe:evt_recovery_001'] = ['stripe', 'evt_recovery_001', 'S-001', 'paid', current_time('mysql', true)];
            $body = stripePaymentEvent($type, 'evt_recovery_001');
            response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 200, 0, 'ok');
            same('paid', Fixture::$rows['S-001']['status'], 'Stored event does not prevent payment recovery');
            same(['paid'], array_column(Fixture::$callbacks, 'status'), 'Recovery callback sent once');
            same([['markPaid', [1, 'pi_test_001']]], Fixture::$orders, 'Recovery order effect once');
            response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 200, 10013, 'Event already processed (idempotent)');
            same(1, Fixture::$updates, 'Recovered event is idempotent once paid');
            same(1, count(Fixture::$callbacks), 'Replayed recovery does not duplicate callback');
            same(1, count($wpdb->events), 'Recovery retains original event record');
        };

        $tests['Stripe failed recovery through ' . $type . ' still requires signature'] = function () use ($type) {
            paidStripe('failed');
            $body = stripePaymentEvent($type, 'evt_recovery_001');
            response(WebhookRouter::handle('stripe', $body, ['stripe-signature' => 't=' . time() . ',v1=invalid']), 400, 10012, 'Signature verification failed');
            noWrites('failed');
        };

        foreach (['wrong amount', 'wrong currency', 'missing amount', 'missing currency'] as $invalid) {
            $tests['Stripe failed recovery through ' . $type . ' rejects ' . $invalid] = function () use ($type, $invalid) {
                paidStripe('failed');
                $event = json_decode(stripePaymentEvent($type, 'evt_recovery_001'), true);
                $amountKey = $type === 'checkout.session.completed' ? 'amount_total' : 'amount_received';
                if ($invalid === 'wrong amount') { $event['data']['object'][$amountKey] = 321; }
                if ($invalid === 'wrong currency') { $event['data']['object']['currency'] = 'eur'; }
                if ($invalid === 'missing amount') { unset($event['data']['object'][$amountKey]); }
                if ($invalid === 'missing currency') { unset($event['data']['object']['currency']); }
                $body = json_encode($event);
                response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 400, 10014, 'Amount or currency mismatch');
                noWrites('failed');
            };
        }
    }

    $tests['late Stripe failed attempt cannot downgrade a successful payment'] = function () {
        paidStripe('pending');
        $success = stripePaymentEvent('payment_intent.succeeded', 'evt_success_001');
        response(WebhookRouter::handle('stripe', $success, stripeHeaders($success)), 200, 0, 'ok');
        $failure = stripePaymentEvent('payment_intent.payment_failed', 'evt_failure_001');
        response(WebhookRouter::handle('stripe', $failure, stripeHeaders($failure)), 200, 0, 'Invalid status transition, ignored');
        same('paid', Fixture::$rows['S-001']['status'], 'Late failure leaves paid state intact');
        same(['paid'], array_column(Fixture::$callbacks, 'status'), 'Late failure does not send failed callback');
        same([['markPaid', [1, 'pi_test_001']]], Fixture::$orders, 'Late failure does not mark WooCommerce failed');
    };

    $tests['Stripe dispute won cannot reopen a failed payment'] = function () {
        paidStripe('failed');
        $body = stripeDisputeEvent('charge.dispute.closed', 'won');
        response(WebhookRouter::handle('stripe', $body, stripeHeaders($body)), 503, 10001, 'Payment confirmation pending; retry required');
        same('failed', Fixture::$rows['S-001']['status'], 'Dispute result cannot substitute for payment confirmation');
        same([], Fixture::$callbacks, 'No failed payment recovery callback from dispute');
        same([], Fixture::$orders, 'No payment side effects from dispute result');
    };

    $tests['Stripe retry recovery does not reopen failed PayPal payments'] = function () {
        global $wpdb;
        foreach (['paypal_js', 'paypal_rest'] as $method) {
            foreach ([false, true] as $alreadyRecorded) {
                paidPayPal($method, 'failed');
                if ($alreadyRecorded) { $wpdb->events[$method . ':WH-001'] = [$method, 'WH-001', 'S-001', 'paid', current_time('mysql', true)]; }
                $body = paypalEvent();
                response(WebhookRouter::handle('paypal', $body, paypalHeaders()), 200, $alreadyRecorded ? 10013 : 0,
                    $alreadyRecorded ? 'Event already processed (idempotent)' : 'Invalid status transition, ignored');
                same('failed', Fixture::$rows['S-001']['status'], 'Failed PayPal payment remains failed');
                same([], Fixture::$callbacks, 'No PayPal recovery callback');
                same([], Fixture::$orders, 'No PayPal recovery order effects');
            }
        }
    };

    $tests['unknown gateways and the payment factory keep existing behavior'] = function () {
        Fixture::reset();
        response(WebhookRouter::handle('unsupported', '{}', []), 404, 10016, 'Unsupported payment gateway');
        same(['stripe', 'paypal_js', 'paypal_rest', 'airwallex', 'antom'], GatewayFactory::supportedMethods(), 'Payment methods unchanged');
        same(false, GatewayFactory::isSupported('paypal'), 'Alias is webhook-only');
        same(0, Fixture::$decrypts, 'No config lookup');
        noWrites();
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
