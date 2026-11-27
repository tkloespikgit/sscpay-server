<?php
/** Queue ownership/recovery regression runner; no WordPress database or network requests. */
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_next_scheduled($hook) {
        return \PaymentGatewayAggregator\Tests\QueueRecovery\Fixture::$scheduled[$hook] ?? false;
    }
    function wp_schedule_single_event($timestamp, $hook) {
        $fixture = \PaymentGatewayAggregator\Tests\QueueRecovery\Fixture::class;
        $fixture::$scheduleCalls++;
        if ($fixture::$scheduleFailure) { return false; }
        $fixture::$scheduled[$hook] = $timestamp;
        return true;
    }
    function is_wp_error($value) { return false; }
    function spawn_cron() {
        $fixture = \PaymentGatewayAggregator\Tests\QueueRecovery\Fixture::class;
        $fixture::$spawnCalls++;
        if ($fixture::$spawnFailure) { throw new \RuntimeException('Synthetic loopback failure'); }
        return true;
    }

}

namespace PaymentGatewayAggregator\Tests\QueueRecovery {
    class Fixture
    {
        public static $requests;
        public static $exception;
        public static $ack;
        public static $refunds = [];
        public static $httpHook;
        public static $logs;
        public static $scheduled;
        public static $scheduleCalls;
        public static $spawnCalls;
        public static $scheduleFailure;
        public static $spawnFailure;
        public static function reset()
        {
            global $wpdb;
            $wpdb = new MemoryWpdb();
            self::$requests = self::$logs = self::$refunds = [];
            self::$ack = self::$exception = self::$httpHook = null;
            self::$scheduled = [];
            self::$scheduleCalls = self::$spawnCalls = 0;
            self::$scheduleFailure = self::$spawnFailure = false;
        }
        public static function seed($status = 'pending', $age = 0)
        {
            global $wpdb;
            $wpdb->rows[1] = [
                'id' => 1, 's_order_id' => 'S-001', 'event_type' => 'payment_status',
                'payload' => '{"status":"paid"}', 'status' => $status,
                'retry_count' => 0, 'max_retries' => 5,
                'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 10),
                'updated_at' => gmdate('Y-m-d H:i:s', time() - $age),
            ];
        }
    }
    class MemoryWpdb
    {
        public $prefix = 'isolated_';
        public $rows = [];
        public $insert_id = 0;
        public $insertFailure = false;
        public $claimFailure = false;
        public $beforeClaim;
        public $beforeRecovery;
        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public function insert($table, array $row)
        {
            if ($this->insertFailure) { return false; }
            $this->insert_id = count($this->rows) + 1;
            $this->rows[$this->insert_id] = array_merge($row, ['id' => $this->insert_id]);
            return 1;
        }
        public function update($table, array $fields, array $where)
        {
            if ($fields['status'] === 'processing' && $where['status'] === 'pending') {
                if ($this->claimFailure) { return false; }
                if ($this->beforeClaim) {
                    $hook = $this->beforeClaim; $this->beforeClaim = null; $hook($this);
                }
            }
            if ($fields['status'] === 'pending' && $where['status'] === 'processing' && $this->beforeRecovery) {
                $hook = $this->beforeRecovery; $this->beforeRecovery = null; $hook($this);
            }
            if (!isset($this->rows[$where['id']])) { return 0; }
            foreach ($where as $key => $value) {
                if (($this->rows[$where['id']][$key] ?? null) !== $value) { return 0; }
            }
            $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $fields);
            return 1;
        }
        public function get_row($query, $format) { return $this->rows[$query[1][0]] ?? null; }
        public function get_results($query, $format)
        {
            [$sql, $args] = $query;
            if (strpos($sql, "status = 'processing' AND updated_at < UTC_TIMESTAMP()") === false) {
                throw new \RuntimeException('Batch must select abandoned processing leases');
            }
            return array_slice(array_values(array_filter($this->rows, function ($row) use ($args) {
                return ($row['status'] === 'pending' && $row['next_retry_at'] <= $args[0])
                    || ($row['status'] === 'processing'
                        && strtotime($row['updated_at'] . ' UTC') < time() - 300);
            })), 0, $args[1]);
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\QueueRecovery\Fixture;
    class TransactionManager
    {
        public static function findBySOrderId($id)
        {
            return ['s_order_id' => $id, 'payment_method' => 'stripe', 'status' => 'paid',
                'paid_at' => '2026-01-01 00:00:00', 'callback_url' => 'https://example.invalid/callback'];
        }
    }
    class RefundRecords
    {
        public static function hasFailedNotice($orderId, $id) { return false; }
        public static function reconcile($transaction, &$complete)
        {
            $complete = (bool) Fixture::$refunds;
            return Fixture::$refunds;
        }
    }
    class OutboundSigner
    {
        const HEADER_NAME = 'X-Test-Signature';
        public static function sign($raw) { return 'fixture-signature'; }
    }
    class LogManager
    {
        const SYSTEM_ORDER_ID = '__system__';
        const LEVEL_INFO = 'INFO';
        const LEVEL_WARNING = 'WARNING';
        const LEVEL_ERROR = 'ERROR';
        public static function __callStatic($method, $args) { Fixture::$logs[] = [$method, $args]; }
    }
}
namespace PaymentGatewayAggregator\Helpers {
    use PaymentGatewayAggregator\Tests\QueueRecovery\Fixture;
    class HttpClient
    {
        public static function postRawJson($url, $raw, array $headers)
        {
            Fixture::$requests[] = compact('url', 'raw', 'headers');
            if (Fixture::$httpHook) { $hook = Fixture::$httpHook; Fixture::$httpHook = null; $hook(); }
            if (Fixture::$exception) { throw Fixture::$exception; }
            return ['ok' => true, 'status' => 200, 'body' => Fixture::$ack ?? '{}', 'error' => null];
        }
    }
}

namespace {
    use PaymentGatewayAggregator\Core\QueueManager;
    use PaymentGatewayAggregator\Tests\QueueRecovery\Fixture;
    require dirname(__DIR__) . '/vendor/autoload.php';
    set_error_handler(function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    function same($expected, $actual, $message)
    {
        if ($expected !== $actual) { throw new \RuntimeException($message . ': ' . var_export($actual, true)); }
    }
    function throws($callable, $message)
    {
        try { $callable(); } catch (\RuntimeException $e) { return $e; }
        throw new \RuntimeException($message);
    }
    $tests = [];
    $tests['enqueue reports database failure without using a stale insert ID'] = function () {
        global $wpdb;
        $wpdb->insertFailure = true; $wpdb->insert_id = 99;
        throws(function () { QueueManager::enqueue('S-001', 'payment_status', ['status' => 'paid']); }, 'Missing enqueue error');
        same([], $wpdb->rows, 'Failed enqueue leaves no task');
    };
    $tests['enqueue rejects unserializable payload and persists valid payload'] = function () {
        global $wpdb;
        throws(function () { QueueManager::enqueue('S-001', 'payment_status', ['amount' => NAN]); }, 'Missing serialization error');
        same(1, QueueManager::enqueue('S-001', 'payment_status', ['status' => 'paid']), 'New queue ID');
        same('{"status":"paid"}', $wpdb->rows[1]['payload'], 'Exact payload persisted');
    };
    $tests['pending legacy full refund status only reconciles individual refunds'] = function () {
        global $wpdb;
        Fixture::seed();
        $wpdb->rows[1]['payload'] = '{"status":"refunded"}';
        $wpdb->rows[1]['retry_count'] = 5;
        QueueManager::deliverNow(1);
        same([], Fixture::$requests, 'No legacy refund callback');
        same('pending', $wpdb->rows[1]['status'], 'Legacy detail lookup retries');
        Fixture::$refunds = [['refund_id' => 're_ok']];
        QueueManager::deliverNow(1);
        same('success', $wpdb->rows[1]['status'], 'Superseded after confirmed detailed query');
        same([], Fixture::$requests, 'Aggregate status never redelivered');
    };
    $tests['refund delivery requires acknowledgement of the exact refund and survives older receivers'] = function () {
        global $wpdb;
        Fixture::seed();
        $wpdb->rows[1]['event_type'] = 'payment_refund';
        $wpdb->rows[1]['payload'] = '{"refund_id":"re_01"}';
        $wpdb->rows[1]['retry_count'] = 5;
        foreach ([['code' => 0, 'message' => 'ignored'], ['code' => 0, 'received' => true, 'refund_id' => 're_other']] as $ack) {
            Fixture::$ack = $ack;
            $wpdb->rows[1]['next_retry_at'] = gmdate('Y-m-d H:i:s', time() - 1);
            QueueManager::processDueBatch();
            same('pending', $wpdb->rows[1]['status'], 'Unconfirmed refund remains retryable');
        }
        Fixture::$ack = ['code' => 0, 'received' => true, 'refund_id' => 're_01'];
        $wpdb->rows[1]['next_retry_at'] = gmdate('Y-m-d H:i:s', time() - 1);
        QueueManager::processDueBatch();
        same('success', $wpdb->rows[1]['status'], 'Matching receipt closes delivery');
        same(3, count(Fixture::$requests), 'Original refund resent');
    };
    $tests['nested workers and repeated immediate delivery send the callback once'] = function () {
        global $wpdb;
        Fixture::seed();
        Fixture::$httpHook = function () { QueueManager::deliverNow(1); };
        QueueManager::deliverNow(1);
        QueueManager::deliverNow(1);
        same(1, count(Fixture::$requests), 'Only claim winner sends');
        same('success', $wpdb->rows[1]['status'], 'Delivery completed');
    };
    $tests['worker losing the pending CAS never sends'] = function () {
        global $wpdb;
        Fixture::seed();
        $wpdb->beforeClaim = function ($db) { $db->rows[1]['status'] = 'processing'; };
        QueueManager::deliverNow(1);
        same([], Fixture::$requests, 'CAS loser sends nothing');
    };
    $tests['stale batch snapshot cannot reclaim an attempt that already scheduled retry'] = function () {
        global $wpdb;
        Fixture::seed();
        $wpdb->beforeClaim = function ($db) {
            $db->rows[1]['retry_count'] = 1;
            $db->rows[1]['next_retry_at'] = gmdate('Y-m-d H:i:s', time() + 60);
        };
        QueueManager::deliverNow(1);
        same([], Fixture::$requests, 'Old pending snapshot cannot bypass retry deadline');
        same(1, $wpdb->rows[1]['retry_count'], 'New retry count retained');
    };
    $tests['claim database error remains pending and is reported'] = function () {
        global $wpdb;
        Fixture::seed(); $wpdb->claimFailure = true;
        throws(function () { QueueManager::deliverNow(1); }, 'Missing claim failure');
        same('pending', $wpdb->rows[1]['status'], 'Task remains retryable');
        same([], Fixture::$requests, 'No request without claim');
    };
    $tests['HTTP exception schedules retry before propagating and retry can succeed'] = function () {
        global $wpdb;
        Fixture::seed(); Fixture::$exception = new \RuntimeException('Synthetic transport exception');
        same(Fixture::$exception, throws(function () { QueueManager::deliverNow(1); }, 'Missing HTTP exception'), 'Original exception retained');
        same('pending', $wpdb->rows[1]['status'], 'Exception releases processing status');
        same(1, $wpdb->rows[1]['retry_count'], 'Retry recorded');
        same('Synthetic transport exception', $wpdb->rows[1]['last_error'], 'Exception retained');
        Fixture::$exception = null; $wpdb->rows[1]['next_retry_at'] = gmdate('Y-m-d H:i:s', time() - 1);
        QueueManager::processDueBatch();
        same(2, count(Fixture::$requests), 'Cron retries failed attempt');
        same('success', $wpdb->rows[1]['status'], 'Retry completes');
    };
    $tests['cron recovers an abandoned processing lease but leaves an active worker alone'] = function () {
        global $wpdb;
        Fixture::seed('processing', 301); QueueManager::processDueBatch();
        same(1, count(Fixture::$requests), 'Abandoned task retried');
        same('success', $wpdb->rows[1]['status'], 'Recovered task completed');
        Fixture::reset(); Fixture::seed('processing', 30); QueueManager::processDueBatch();
        same([], Fixture::$requests, 'Active task not stolen');
        same('processing', $wpdb->rows[1]['status'], 'Active lease retained');
    };
    $tests['lease recovery CAS cannot steal a lease another worker renewed'] = function () {
        global $wpdb;
        Fixture::seed('processing', 301);
        $wpdb->beforeRecovery = function ($db) { $db->rows[1]['updated_at'] = current_time('mysql', true); };
        QueueManager::processDueBatch();
        same([], Fixture::$requests, 'Renewed lease not stolen');
        same('processing', $wpdb->rows[1]['status'], 'Other worker remains owner');
    };
    $tests['old owner cannot overwrite a newer lease after its HTTP request completes'] = function () {
        global $wpdb;
        Fixture::seed();
        Fixture::$httpHook = function () {
            global $wpdb;
            $wpdb->rows[1]['updated_at'] = gmdate('Y-m-d H:i:s', time() + 301);
        };
        QueueManager::deliverNow(1);
        same('processing', $wpdb->rows[1]['status'], 'Old owner cannot complete another lease');
    };
    $tests['post-commit wake schedules one immediate worker and spawns without sending S HTTP'] = function () {
        QueueManager::wakeWorker();
        QueueManager::wakeWorker();
        same(1, Fixture::$scheduleCalls, 'Immediate cron event deduplicated');
        same(2, Fixture::$spawnCalls, 'Explicit spawn works with traffic-driven cron disabled');
        same(true, isset(Fixture::$scheduled[QueueManager::CRON_HOOK_IMMEDIATE]), 'Separate immediate hook scheduled');
        same([], Fixture::$requests, 'Wake never waits for S callback HTTP');
    };
    $tests['immediate scheduling failure keeps durable queue for the regular worker'] = function () {
        global $wpdb;
        Fixture::seed(); Fixture::$scheduleFailure = true;
        QueueManager::wakeWorker();
        same('pending', $wpdb->rows[1]['status'], 'Failed wake preserves queue');
        same(0, Fixture::$spawnCalls, 'No spawn without a scheduled event');
        same(1, count(Fixture::$logs), 'Wake failure audited');
        QueueManager::processDueBatch();
        same('success', $wpdb->rows[1]['status'], 'Regular worker still drains queue');
    };
    $tests['loopback exceptions cannot turn a committed webhook into an error'] = function () {
        global $wpdb;
        Fixture::seed(); Fixture::$spawnFailure = true;
        QueueManager::wakeWorker();
        same('pending', $wpdb->rows[1]['status'], 'Loopback failure preserves queue');
        same(1, count(Fixture::$logs), 'Loopback failure audited');
        same([], Fixture::$requests, 'No synchronous S callback');
    };
    $failures = 0;
    foreach ($tests as $name => $test) {
        Fixture::reset();
        try { $test(); echo 'PASS ' . $name . PHP_EOL; }
        catch (\Throwable $e) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL); }
    }
    echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}
