<?php
/**
 * Stripe webhook reliability regressions; isolated database and external effects.
 * Run: php tests/stripe-webhook-reliability.php
 * Production router, Stripe parser/signature verifier, transaction manager and CAS
 * state machine run unchanged. No WordPress bootstrap, credentials or HTTP calls.
 */

namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    function current_time($type, $gmt = false) { return '2026-11-10 00:00:00'; }
    function wp_json_encode($value) { return json_encode($value); }
}

namespace PaymentGatewayAggregator\Tests\StripeReliability {
    class Fixture
    {
        const SECRET = 'whsec_isolated_regression_only';
        public static $orders = [];
        public static $callbacks = [];
        public static $deferredDeliveries = [];
        public static $logs = [];
        public static $failOrder = false;
        public static $orderAttempts = 0;
        public static $decrypts = 0;
        public static $remoteDispute;
        public static $sessionLookup = [];
        public static $remoteCalls = [];
        public static $remoteFailure = false;
        public static $configSnapshots = [];
        public static $sessionResponses = [];
        public static $sessionFailures = [];
        public static $registryConfigs = [];
        public static $disputeFailures = [];

        public static function reset($status = 'pending')
        {
            global $wpdb;
            $wpdb = new MemoryWpdb();
            self::$orders = self::$callbacks = self::$logs = [];
            self::$deferredDeliveries = [];
            self::$failOrder = false;
            self::$orderAttempts = self::$decrypts = 0;
            self::$remoteDispute = null;
            self::$sessionLookup = self::$remoteCalls = [];
            self::$remoteFailure = false;
            self::$configSnapshots = self::$sessionResponses = self::$sessionFailures = [];
            self::$registryConfigs = self::$disputeFailures = [];
            $wpdb->transactions['S-001'] = [
                'id' => 1, 's_order_id' => 'S-001', 'wp_order_id' => 1,
                'payment_method' => 'stripe', 'status' => $status,
                'payment_intent' => 'cs_test_session', 'transaction_id' => null,
                'gateway_session' => json_encode([
                    'external_ref' => 'cs_test_session', '_attempt_key' => 'attempt-regression-001',
                    'session_id' => 'cs_test_session',
                ]),
                'gateway_session_expires_at' => null,
                'gateway_config' => 'encrypted-fixture',
                'amount' => '12.34', 'currency' => 'USD',
                'paid_at' => $status === 'pending' ? null : '2026-11-09 00:00:00',
                'updated_at' => '2026-11-09 00:00:00',
                'callback_url' => 'https://example.invalid/callback',
            ];
        }

        public static function row()
        {
            global $wpdb;
            return $wpdb->transactions['S-001'];
        }
    }

    class MemoryWpdb
    {
        public $prefix = 'isolated_';
        public $transactions = [];
        public $references = [];
        public $events = [];
        public $last_error = '';
        public $lookups = [];
        public $failEventInsert = false;
        public $failTransactionUpdate = false;
        public $failReadAfterUpdate = false;
        public $failNextTransactionRead = false;
        public $nonTransactionalTable = false;
        public $insert_id = 0;
        private $snapshot;

        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public function esc_like($value) { return addcslashes($value, '_%\\'); }

        public function get_var($prepared)
        {
            [$sql, $args] = is_array($prepared) ? $prepared : [$prepared, []];
            if (strpos($sql, 'GET_LOCK(') !== false || strpos($sql, 'RELEASE_LOCK(') !== false) { return '1'; }
            $row = $this->get_row([$sql, $args], ARRAY_A);
            return $row ? reset($row) : null;
        }

        public function get_row($prepared, $format = ARRAY_A)
        {
            [$sql, $args] = $prepared;
            $this->last_error = '';
            $this->lookups[] = [$sql, $args];
            if (strpos($sql, 'isolated_payment_session_references') !== false) {
                if (strpos($sql, 'ref_hash =') !== false) { return $this->references[$args[0]] ?? null; }
                foreach ($this->references as $row) {
                    if (strpos($sql, 'session_data') !== false && strpos($row['session_data'], trim($args[0], '%')) !== false) { return $row; }
                }
                return null;
            }
            if (strpos($sql, 'isolated_payment_webhook_events') !== false) {
                foreach ($this->events as $row) {
                    if (in_array($row['event_id'], $args, true)
                        || (in_array($row['s_order_id'], $args, true) && strpos($sql, 'event_id =') === false)) { return $row; }
                }
                return null;
            }
            if (strpos($sql, 'isolated_payment_transactions') !== false) {
                if ($this->failNextTransactionRead) {
                    $this->failNextTransactionRead = false;
                    $this->last_error = 'Injected transaction read failure';
                    return null;
                }
                if (strpos($sql, 'payment_intent =') !== false && strpos($sql, ' OR transaction_id') !== false) {
                    foreach ($this->transactions as $row) {
                        if ($row['payment_intent'] === $args[0] || $row['transaction_id'] === $args[1]) { return $row; }
                    }
                    return null;
                }
                if (strpos($sql, 'gateway_session') !== false || strpos($sql, 'attempt') !== false) {
                    foreach ($this->transactions as $row) {
                        foreach ($args as $needle) {
                            if (is_string($needle) && strpos($needle, 'attempt-') !== false
                                && strpos($row['gateway_session'], trim(str_replace('\\', '', $needle), '%')) !== false) { return $row; }
                        }
                    }
                    return null;
                }
                return $this->transactions[$args[0]] ?? null;
            }
            throw new \RuntimeException('Unexpected lookup: ' . $sql);
        }

        public function get_results($prepared, $format = ARRAY_A)
        {
            [$sql, $args] = is_array($prepared) ? $prepared : [$prepared, []];
            $this->last_error = '';
            $this->lookups[] = [$sql, $args];
            if (strpos($sql, 'SHOW TABLE STATUS') === 0) {
                $rows = [];
                foreach (['payment_transactions', 'payment_session_references', 'payment_webhook_events', 'payment_callback_queue'] as $suffix) {
                    $rows[] = ['Name' => $this->prefix . $suffix,
                        'Engine' => $this->nonTransactionalTable && $suffix === 'payment_transactions' ? 'MyISAM' : 'InnoDB'];
                }
                return $rows;
            }
            if (strpos($sql, 'isolated_payment_gateway_configs') !== false) {
                return Fixture::$registryConfigs;
            }
            if (strpos($sql, 'isolated_payment_transactions') !== false) {
                if (strpos($sql, 'id >') !== false) {
                    $rows = array_values(array_filter($this->transactions, function ($row) use ($args) {
                        return $row['payment_method'] === 'stripe' && $row['id'] > $args[0];
                    }));
                    usort($rows, function ($left, $right) { return $left['id'] <=> $right['id']; });
                    return array_slice($rows, 0, $args[1]);
                }
                return array_values($this->transactions);
            }
            if (strpos($sql, 'isolated_payment_session_references') !== false) { return array_values($this->references); }
            if (strpos($sql, 'isolated_payment_webhook_events') !== false) { return array_values($this->events); }
            throw new \RuntimeException('Unexpected multi lookup: ' . $sql);
        }

        public function query($prepared)
        {
            [$sql, $args] = is_array($prepared) ? $prepared : [$prepared, []];
            $this->last_error = '';
            if (preg_match('/^(START TRANSACTION|BEGIN)/i', $sql)) {
                $this->snapshot = [$this->transactions, $this->references, $this->events];
                return 1;
            }
            if (strtoupper($sql) === 'COMMIT') { $this->snapshot = null; return 1; }
            if (strtoupper($sql) === 'ROLLBACK') {
                if ($this->snapshot) { [$this->transactions, $this->references, $this->events] = $this->snapshot; }
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
            if (strpos($sql, 'INSERT') === 0 && strpos($sql, 'isolated_payment_webhook_events') !== false) {
                if ($this->failEventInsert) { $this->last_error = 'Injected event-table failure'; return false; }
                $row = array_combine(['payment_method', 'event_id', 's_order_id', 'event_type', 'processed_at'], $args);
                $key = $row['payment_method'] . ':' . $row['event_id'];
                if (isset($this->events[$key])) { return 0; }
                $this->events[$key] = $row;
                return 1;
            }
            if (strpos($sql, 'DELETE FROM isolated_payment_webhook_events') === 0) {
                $removed = 0;
                foreach ($this->events as $key => $row) {
                    if (in_array($row['event_id'], $args, true)) { unset($this->events[$key]); $removed++; }
                }
                return $removed;
            }
            throw new \RuntimeException('Unexpected write: ' . $sql);
        }

        public function update($table, array $fields, array $where)
        {
            $this->last_error = '';
            if ($table === 'isolated_payment_transactions') {
                if ($this->failTransactionUpdate) { $this->last_error = 'Injected transaction failure'; return false; }
                $rows =& $this->transactions;
            } elseif ($table === 'isolated_payment_session_references') {
                $rows =& $this->references;
            } elseif ($table === 'isolated_payment_webhook_events') {
                $rows =& $this->events;
            } else { throw new \RuntimeException('Unexpected table update: ' . $table); }
            $changed = 0;
            foreach ($rows as &$row) {
                foreach ($where as $field => $value) {
                    if (($row[$field] ?? null) !== $value) { continue 2; }
                }
                $next = array_merge($row, $fields);
                if ($next !== $row) { $row = $next; $changed++; }
            }
            if ($table === 'isolated_payment_transactions' && $this->failReadAfterUpdate && $changed) {
                $this->failReadAfterUpdate = false;
                $this->failNextTransactionRead = true;
            }
            return $changed;
        }

        public function insert($table, $data)
        {
            if ($table !== 'isolated_payment_webhook_events') { throw new \RuntimeException('Unexpected insert: ' . $table); }
            if ($this->failEventInsert) { $this->last_error = 'Injected event-table failure'; return false; }
            $key = $data['payment_method'] . ':' . $data['event_id'];
            if (isset($this->events[$key])) { return false; }
            $this->events[$key] = $data;
            $this->insert_id++;
            return 1;
        }
        public function delete($table, $where)
        {
            if ($table !== 'isolated_payment_webhook_events') { throw new \RuntimeException('Unexpected deletion'); }
            $count = 0;
            foreach ($this->events as $key => $row) {
                foreach ($where as $field => $value) { if (($row[$field] ?? null) !== $value) { continue 2; } }
                unset($this->events[$key]); $count++;
            }
            return $count;
        }
    }

    class StripeDisputes
    {
        private $key;
        public function __construct($key) { $this->key = $key; }
        public function retrieve($id)
        {
            Fixture::$remoteCalls[] = ['dispute', $id, $this->key];
            if (Fixture::$remoteFailure || !empty(Fixture::$disputeFailures[$this->key])) {
                throw new \RuntimeException('Injected Stripe API failure');
            }
            if (!Fixture::$remoteDispute) { throw new \RuntimeException('Unconfigured dispute response'); }
            return (object) Fixture::$remoteDispute;
        }
    }
    class StripeSessions
    {
        private $key;
        public function __construct($key) { $this->key = $key; }
        public function all($params)
        {
            Fixture::$remoteCalls[] = ['sessions', $params, $this->key];
            if (Fixture::$remoteFailure || !empty(Fixture::$sessionFailures[$this->key])) {
                throw new \RuntimeException('Injected Stripe API failure');
            }
            return (object) ['data' => Fixture::$sessionResponses[$this->key] ?? Fixture::$sessionLookup];
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
            $this->checkout = (object) ['sessions' => new \PaymentGatewayAggregator\Tests\StripeReliability\StripeSessions($key)];
            $this->disputes = new \PaymentGatewayAggregator\Tests\StripeReliability\StripeDisputes($key);
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\StripeReliability\Fixture;
    class ConfigEncryptor
    {
        public static function decrypt($value)
        {
            Fixture::$decrypts++;
            if (array_key_exists($value, Fixture::$configSnapshots)) {
                if (Fixture::$configSnapshots[$value] instanceof \Throwable) { throw Fixture::$configSnapshots[$value]; }
                return Fixture::$configSnapshots[$value];
            }
            return ['webhook_secret' => Fixture::SECRET, 'secret_key' => 'sk_test_isolated'];
        }
    }
    class LogManager
    {
        const SYSTEM_ORDER_ID = '__system__';
        public static function __callStatic($method, $args) { Fixture::$logs[] = [$method, $args]; }
    }
    class OrderManager
    {
        public static function __callStatic($method, $args)
        {
            Fixture::$orderAttempts++;
            if (Fixture::$failOrder) { throw new \RuntimeException('Injected Woo order failure'); }
            Fixture::$orders[] = [$method, $args];
        }
    }
    class CallbackHandler
    {
        public static function forwardPaymentStatus(array $transaction, $accountForbidden = false, $deferDelivery = false)
        {
            Fixture::$callbacks[] = $transaction;
            Fixture::$deferredDeliveries[] = $deferDelivery;
        }
    }
}

namespace {
    use PaymentGatewayAggregator\Core\WebhookRouter;
    use PaymentGatewayAggregator\Core\PaymentSessionReferences;
    use PaymentGatewayAggregator\Tests\StripeReliability\Fixture;
    require dirname(__DIR__) . '/vendor/autoload.php';

    set_error_handler(function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    function same($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }
    function event($type, array $object = [], $id = 'evt_regression')
    {
        if (strpos($type, 'checkout.session.') === 0) {
            $defaults = ['id' => 'cs_test_session', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'payment_intent' => 'pi_regression', 'amount_total' => 1234, 'currency' => 'usd'];
        } elseif (strpos($type, 'charge.dispute.') === 0) {
            $defaults = ['id' => 'dp_regression', 'object' => 'dispute', 'payment_intent' => 'pi_regression',
                'amount' => 1234, 'currency' => 'usd', 'status' => 'needs_response'];
        } elseif (strpos($type, 'charge.') === 0) {
            $defaults = ['id' => 'ch_regression', 'object' => 'charge', 'payment_intent' => 'pi_regression',
                'amount' => 1234, 'amount_refunded' => 1234, 'refunded' => true, 'currency' => 'usd'];
        } else {
            $defaults = ['id' => 'pi_regression', 'object' => 'payment_intent', 'amount' => 1234,
                'amount_received' => 1234, 'currency' => 'usd'];
        }
        return json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => 1794268800,
            'api_version' => '2020-08-27', 'data' => ['object' => array_replace_recursive($defaults, $object)]]);
    }
    function send($body, $secret = Fixture::SECRET)
    {
        $time = time();
        return WebhookRouter::handle('stripe', $body, [
            'stripe-signature' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret),
        ]);
    }
    function paidEvent($id = 'evt_regression') { return event('checkout.session.completed', [], $id); }
    function noEffects()
    {
        same([], Fixture::$orders, 'No Woo effects');
        same([], Fixture::$callbacks, 'No S callbacks');
    }
    function paidOnce()
    {
        same('paid', Fixture::row()['status'], 'Stored payment state');
        same(1, count(Fixture::$orders), 'One Woo effect');
        same(1, count(Fixture::$callbacks), 'One S callback');
        same([true], Fixture::$deferredDeliveries, 'Stripe queues S delivery until after webhook commit');
    }
    function seedHistoricalConfig($id, $snapshot)
    {
        global $wpdb;
        $row = Fixture::row();
        $row['id'] = $id;
        $row['s_order_id'] = 'HISTORY-' . $id;
        $row['payment_intent'] = 'cs_history_' . $id;
        $row['transaction_id'] = null;
        $row['gateway_session'] = '{}';
        $row['gateway_config'] = $snapshot;
        $wpdb->transactions[$row['s_order_id']] = $row;
    }
    function configForApiKey($key)
    {
        return ['webhook_secret' => Fixture::SECRET, 'secret_key' => $key];
    }

    $tests = [];
    $tests['unrelated discounts mandates and PI-created events never look up orders'] = function () {
        global $wpdb;
        foreach (['customer.discount.created' => 'di_fixture', 'mandate.updated' => 'mandate_fixture',
            'payment_intent.created' => 'pi_fixture'] as $type => $id) {
            Fixture::reset();
            $result = send(event($type, ['id' => $id]));
            same(200, $result['http_status'], $type . ' acknowledgement');
            same([], $wpdb->lookups, $type . ' does not read transaction storage');
            same(0, Fixture::$decrypts, 'Ignored event does not select credentials');
            noEffects();
        }
    };
    $tests['known PaymentIntent wins over an unrelated merchant order reference'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->transactions['S-001']['transaction_id'] = 'pi_regression';
        $result = send(event('payment_intent.succeeded', ['payment_details' => ['order_reference' => 'INV-001']]));
        same(200, $result['http_status'], 'Payment acknowledgment'); paidOnce();
    };
    $tests['Session reference remains a fallback when a PI alias is not saved yet'] = function () {
        Fixture::reset();
        $result = send(event('payment_intent.succeeded', ['payment_details' => ['order_reference' => 'cs_test_session']]));
        same(200, $result['http_status'], 'Session fallback acknowledgment'); paidOnce();
    };
    $tests['opaque attempt metadata locates a delayed-created PaymentIntent'] = function () {
        Fixture::reset();
        PaymentSessionReferences::remember(Fixture::row());
        $result = send(event('payment_intent.succeeded', ['metadata' => ['pga_attempt_key' => 'attempt-regression-001']]));
        same(200, $result['http_status'], 'Metadata acknowledgment'); paidOnce();
    };
    $tests['unpaid completed Session preserves PI linkage without marking payment'] = function () {
        Fixture::reset();
        $result = send(event('checkout.session.completed', ['payment_status' => 'unpaid']));
        same(200, $result['http_status'], 'Processing acknowledgment');
        same('pending', Fixture::row()['status'], 'Unpaid remains pending'); noEffects();
        $reference = PaymentSessionReferences::findReference('pi_regression');
        same('cs_test_session', $reference['session_ref'] ?? null, 'PI maps to completed Session');
        same(200, send(event('payment_intent.succeeded', [], 'evt_later_paid'))['http_status'], 'Later PI event');
        paidOnce();
    };
    $tests['asynchronous Session success and failure update the matching order'] = function () {
        foreach (['checkout.session.async_payment_succeeded' => 'paid', 'checkout.session.async_payment_failed' => 'failed'] as $type => $status) {
            Fixture::reset();
            $result = send(event($type));
            same(200, $result['http_status'], $type . ' acknowledgment');
            same($status, Fixture::row()['status'], $type . ' stored state');
            same(1, count(Fixture::$callbacks), $type . ' callback');
        }
    };
    $tests['event storage failure stays retryable and does not consume the payment'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->failEventInsert = true;
        same(500, send(paidEvent())['http_status'], 'Event-table failure response');
        $wpdb->failEventInsert = false;
        same(200, send(paidEvent())['http_status'], 'Retry acknowledgment'); paidOnce();
        same(200, send(paidEvent())['http_status'], 'Duplicate acknowledgment'); paidOnce();
    };
    $tests['failed transaction write can replay the same event after storage recovers'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->failTransactionUpdate = true;
        same(500, send(paidEvent())['http_status'], 'Failed transaction response');
        same('pending', Fixture::row()['status'], 'Failed write leaves pending'); noEffects();
        $wpdb->failTransactionUpdate = false;
        same(200, send(paidEvent())['http_status'], 'Replay acknowledgment'); paidOnce();
    };
    $tests['Woo failure remains retryable after the transaction already became paid'] = function () {
        Fixture::reset(); Fixture::$failOrder = true;
        same(500, send(paidEvent())['http_status'], 'Woo failure response');
        same([], Fixture::$callbacks, 'S callback waits for order update');
        Fixture::$failOrder = false;
        same(200, send(paidEvent())['http_status'], 'Woo recovery replay'); paidOnce();
        same(200, send(paidEvent())['http_status'], 'Post recovery duplicate'); paidOnce();
    };
    $tests['transaction reread failure rolls back the event and state before retry'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->failReadAfterUpdate = true;
        same(500, send(paidEvent())['http_status'], 'Post-write reread failure response');
        same('pending', Fixture::row()['status'], 'Failed reread rolls back payment');
        same([], $wpdb->events, 'Failed reread rolls back event receipt'); noEffects();
        same(200, send(paidEvent())['http_status'], 'Reread recovery acknowledgment'); paidOnce();
    };
    $tests['nontransactional payment storage rejects processing before any writes'] = function () {
        global $wpdb;
        Fixture::reset(); $wpdb->nonTransactionalTable = true;
        same(500, send(paidEvent())['http_status'], 'Nontransactional storage response');
        same('pending', Fixture::row()['status'], 'Unsafe storage does not change payment');
        same([], $wpdb->events, 'Unsafe storage does not consume event');
        same([], $wpdb->references, 'Unsafe storage does not write aliases'); noEffects();
    };
    $tests['invalid signatures cannot establish PI aliases or consume events'] = function () {
        global $wpdb;
        Fixture::reset();
        same(400, send(paidEvent(), 'wrong-secret')['http_status'], 'Invalid signature response');
        same([], $wpdb->references, 'No unsigned references'); same([], $wpdb->events, 'No unsigned events'); noEffects();
    };
    $tests['missing malformed and stale signatures are rejected before database lookup'] = function () {
        global $wpdb;
        foreach ([[], ['stripe-signature' => 'invalid-header'],
            ['stripe-signature' => 't=' . time() . ',v1=not-a-signature'],
            ['stripe-signature' => 't=' . (time() - 1000) . ',v1=' . str_repeat('0', 64)]] as $headers) {
            Fixture::reset();
            same(400, WebhookRouter::handle('stripe', paidEvent(), $headers)['http_status'], 'Malformed signature rejection');
            same([], $wpdb->lookups, 'Invalid header does not read credential or order storage');
            same([], Fixture::$remoteCalls, 'Invalid header does not call Stripe'); noEffects();
        }
    };
    // Per-refund amount, replay, aggregate charge and out-of-order cases run in refund-delivery.php.
    $tests['legacy PI resolves through verified Stripe Session association'] = function () {
        Fixture::reset();
        Fixture::$sessionLookup = [(object) ['id' => 'cs_test_session', 'payment_intent' => 'pi_regression']];
        same(200, send(event('payment_intent.succeeded'))['http_status'], 'Legacy PI acknowledgment');
        same(1, count(Fixture::$remoteCalls), 'One Session query'); paidOnce();
    };
    $tests['invalid signatures never query Stripe APIs to resolve unknown PI'] = function () {
        Fixture::reset();
        send(event('payment_intent.succeeded'), 'wrong-secret');
        same([], Fixture::$remoteCalls, 'No unauthenticated API calls'); noEffects();
    };
    $tests['legacy PI continues past a revoked API key sharing the endpoint signing secret'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->transactions['S-001']['gateway_config'] = 'old-key-snapshot';
        seedHistoricalConfig(2, 'new-key-snapshot');
        Fixture::$configSnapshots = [
            'old-key-snapshot' => configForApiKey('sk_test_old_revoked'),
            'new-key-snapshot' => configForApiKey('sk_test_new_valid'),
        ];
        Fixture::$sessionFailures['sk_test_old_revoked'] = true;
        Fixture::$sessionResponses['sk_test_new_valid'] = [(object) ['id' => 'cs_test_session', 'payment_intent' => 'pi_regression']];
        same(200, send(event('payment_intent.succeeded'))['http_status'], 'Rotated API-key recovery');
        same(['sk_test_old_revoked', 'sk_test_new_valid'], array_column(Fixture::$remoteCalls, 2), 'Both authenticated API credentials are attempted');
        paidOnce();
    };
    $tests['corrupt historical credential snapshots cannot hide a later valid account'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->transactions['S-001']['id'] = 2;
        seedHistoricalConfig(1, 'corrupt-snapshot');
        Fixture::$configSnapshots['corrupt-snapshot'] = new \RuntimeException('Injected decryption failure');
        Fixture::$sessionLookup = [(object) ['id' => 'cs_test_session', 'payment_intent' => 'pi_regression']];
        same(200, send(event('payment_intent.succeeded'))['http_status'], 'Corrupt snapshot bypass');
        same(1, count(Fixture::$remoteCalls), 'Only valid credentials reach Stripe');
        paidOnce();
    };
    $tests['different encrypted snapshots of identical credentials make one legacy Session query'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->transactions['S-001']['gateway_config'] = 'nonce-one';
        seedHistoricalConfig(2, 'nonce-two');
        Fixture::$configSnapshots = [
            'nonce-one' => configForApiKey('sk_test_same_plaintext'),
            'nonce-two' => configForApiKey('sk_test_same_plaintext'),
        ];
        same(400, send(event('payment_intent.succeeded'))['http_status'], 'Unknown PI remains unprocessed');
        same(1, count(Fixture::$remoteCalls), 'Identical plaintext credentials are deduplicated');
        same([], $wpdb->events, 'Unresolved PI has no processed receipt'); noEffects();
    };
    $tests['all authenticated legacy API lookup failures preserve replay after recovery'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->transactions['S-001']['gateway_config'] = 'key-one-snapshot';
        seedHistoricalConfig(2, 'key-two-snapshot');
        Fixture::$configSnapshots = [
            'key-one-snapshot' => configForApiKey('sk_test_one'),
            'key-two-snapshot' => configForApiKey('sk_test_two'),
        ];
        Fixture::$sessionFailures = ['sk_test_one' => true, 'sk_test_two' => true];
        $body = event('payment_intent.succeeded');
        same(500, send($body)['http_status'], 'API outage remains retryable');
        same(2, count(Fixture::$remoteCalls), 'All authenticated alternatives are tried');
        same('pending', Fixture::row()['status'], 'API outage preserves pending state');
        same([], $wpdb->events, 'API outage does not consume payment'); noEffects();
        Fixture::$sessionFailures = [];
        Fixture::$sessionResponses['sk_test_one'] = [(object) ['id' => 'cs_test_session', 'payment_intent' => 'pi_regression']];
        same(200, send($body)['http_status'], 'Same event replays after API recovery');
        paidOnce();
    };
    $tests['newly registered credentials can resolve legacy PI without a matching historical order snapshot'] = function () {
        global $wpdb;
        Fixture::reset();
        $wpdb->transactions['S-001']['gateway_config'] = 'old-key-snapshot';
        Fixture::$registryConfigs = [['gateway_config' => 'registered-new-key']];
        Fixture::$configSnapshots = [
            'old-key-snapshot' => configForApiKey('sk_test_old_revoked'),
            'registered-new-key' => configForApiKey('sk_test_registered_new'),
        ];
        Fixture::$sessionFailures['sk_test_old_revoked'] = true;
        Fixture::$sessionResponses['sk_test_registered_new'] = [(object) ['id' => 'cs_test_session', 'payment_intent' => 'pi_regression']];
        same(200, send(event('payment_intent.succeeded'))['http_status'], 'Registered key resolves legacy payment');
        same(['sk_test_registered_new'], array_column(Fixture::$remoteCalls, 2), 'Registered credentials are preferred without needing a new payment');
        paidOnce();
    };
    $tests['known PI dispute refresh falls back from revoked API key to newly registered credentials'] = function () {
        global $wpdb;
        Fixture::reset('disputing');
        $wpdb->transactions['S-001']['transaction_id'] = 'pi_regression';
        $wpdb->transactions['S-001']['gateway_config'] = 'old-key-snapshot';
        Fixture::$registryConfigs = [['gateway_config' => 'registered-new-key']];
        Fixture::$configSnapshots = [
            'old-key-snapshot' => configForApiKey('sk_test_old_revoked'),
            'registered-new-key' => configForApiKey('sk_test_registered_new'),
        ];
        Fixture::$disputeFailures['sk_test_old_revoked'] = true;
        Fixture::$remoteDispute = ['id' => 'dp_regression', 'payment_intent' => 'pi_regression',
            'amount' => 1234, 'currency' => 'usd', 'status' => 'won'];
        same(200, send(event('charge.dispute.closed', ['status' => 'won']))['http_status'], 'Rotated key refreshes known dispute');
        same(['sk_test_old_revoked', 'sk_test_registered_new'], array_column(Fixture::$remoteCalls, 2), 'Refresh retries with a signed registered key');
        same('paid', Fixture::row()['status'], 'Dispute closure is applied');
        same(1, count(Fixture::$callbacks), 'One dispute closure callback');
    };
    $tests['closed disputes cannot be reopened by an older created snapshot'] = function () {
        global $wpdb;
        Fixture::reset('disputing'); $wpdb->transactions['S-001']['transaction_id'] = 'pi_regression';
        Fixture::$remoteDispute = ['id' => 'dp_regression', 'payment_intent' => 'pi_regression',
            'amount' => 1234, 'currency' => 'usd', 'status' => 'won'];
        same(200, send(event('charge.dispute.closed', ['status' => 'won'], 'evt_dispute_closed'))['http_status'], 'Dispute close acknowledgment');
        same('paid', Fixture::row()['status'], 'Closed dispute restores paid');
        same(200, send(event('charge.dispute.created', [], 'evt_dispute_old_created'))['http_status'], 'Old snapshot acknowledgment');
        same('paid', Fixture::row()['status'], 'Old creation cannot reopen dispute');
        same(1, count(Fixture::$callbacks), 'One closure callback');
    };
    $tests['dispute query failure preserves event retry and stored financial state'] = function () {
        global $wpdb;
        Fixture::reset('paid'); $wpdb->transactions['S-001']['transaction_id'] = 'pi_regression';
        Fixture::$remoteFailure = true;
        same(500, send(event('charge.dispute.created'))['http_status'], 'Remote query failure response');
        same('paid', Fixture::row()['status'], 'Payment preserved during Stripe API outage');
        same([], $wpdb->events, 'Failed query does not consume event'); noEffects();
    };
    $tests['old succeeded payment cannot downgrade an active dispute'] = function () {
        global $wpdb;
        Fixture::reset('disputing'); $wpdb->transactions['S-001']['transaction_id'] = 'pi_regression';
        same(200, send(event('payment_intent.succeeded'))['http_status'], 'Delayed payment acknowledgment');
        same('disputing', Fixture::row()['status'], 'Active dispute preserved'); noEffects();
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        try { $test(); echo 'PASS ' . $name . PHP_EOL; }
        catch (\Throwable $error) { $failures++; echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL; }
    }
    echo count($tests) . ' tests, ' . $failures . ' failures' . PHP_EOL;
    exit($failures ? 1 : 0);
}
