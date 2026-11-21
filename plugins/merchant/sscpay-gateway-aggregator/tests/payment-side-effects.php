<?php
/** Real Woo bridge and callback outbox regressions, with isolated boundaries. */
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
    function wp_json_encode($value) { return json_encode($value); }
    function wc_create_order() {}
    function wc_get_order($id) { global $fixtureOrder; return $fixtureOrder; }
    class WC_Order {
        public $result = true;
        public $notes = [];
        public function payment_complete($id) { return $this->result; }
        public function update_status($status, $note) { return $this->result; }
        public function add_order_note($note) { $this->notes[] = $note; }
    }
    class MemoryOutbox {
        public $prefix = 'isolated_';
        public $insert_id = 0;
        public $rows = [];
        public $fail = false;
        public function insert($table, $row) {
            if ($this->fail) { return false; }
            $this->rows[++$this->insert_id] = $row;
            return 1;
        }
        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public function get_row($query, $format) { throw new \RuntimeException('Deferred callback must not perform HTTP delivery'); }
    }
}
namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    use PaymentGatewayAggregator\Core\OrderManager;
    use PaymentGatewayAggregator\Core\CallbackHandler;
    function expectException($callable) {
        try { $callable(); } catch (\RuntimeException $e) { return; }
        throw new \RuntimeException('Failure was silently accepted');
    }
    $tests = [
        'missing Woo orders cannot silently consume a payment event' => function () {
            global $fixtureOrder; $fixtureOrder = null;
            expectException(function () { OrderManager::markPaid(1, 'pi_fixture'); });
            expectException(function () { OrderManager::markFailed(1); });
            expectException(function () { OrderManager::markCancelled(1); });
        },
        'Woo boolean failures stay retryable and create no success note' => function () {
            global $fixtureOrder; $fixtureOrder = new WC_Order(); $fixtureOrder->result = false;
            expectException(function () { OrderManager::markPaid(1, 'pi_fixture'); });
            expectException(function () { OrderManager::markFailed(1); });
            expectException(function () { OrderManager::markCancelled(1); });
            if ($fixtureOrder->notes) { throw new \RuntimeException('False success note'); }
        },
        'deferred Stripe callback writes the real queue without delivering before commit' => function () {
            global $wpdb; $wpdb = new MemoryOutbox();
            CallbackHandler::forwardPaymentStatus([
                's_order_id' => 'S-001', 'wp_order_id' => 1, 'payment_method' => 'stripe', 'status' => 'paid',
                'transaction_id' => 'pi_fixture', 'amount' => '12.34', 'currency' => 'USD',
                'paid_at' => '2026-11-10 00:00:00', 'updated_at' => '2026-11-10 00:00:00',
            ], false, true);
            if (count($wpdb->rows) !== 1 || $wpdb->rows[1]['status'] !== 'pending'
                || json_decode($wpdb->rows[1]['payload'], true)['status'] !== 'paid') {
                throw new \RuntimeException('Missing durable callback');
            }
        },
        'outbox insert failure propagates back to the webhook transaction' => function () {
            global $wpdb; $wpdb->fail = true;
            expectException(function () {
                CallbackHandler::forwardPaymentStatus([
                    's_order_id' => 'S-001', 'wp_order_id' => 1, 'payment_method' => 'stripe', 'status' => 'paid',
                    'transaction_id' => 'pi_fixture', 'amount' => '12.34', 'currency' => 'USD',
                    'paid_at' => '2026-11-10 00:00:00', 'updated_at' => '2026-11-10 00:00:00',
                ], false, true);
            });
        },
    ];
    $failures = 0;
    foreach ($tests as $label => $test) {
        try { $test(); echo "PASS {$label}\n"; }
        catch (\Throwable $e) { $failures++; echo "FAIL {$label}: {$e->getMessage()}\n"; }
    }
    echo count($tests) . " tests, {$failures} failures\n";
    exit($failures ? 1 : 0);
}
