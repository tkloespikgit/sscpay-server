<?php
/** Run: php tests/stripe-failed-callback.php. Real queue; isolated storage, signing and HTTP. */

namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    function current_time($type, $gmt = false) { return '2026-10-29 00:00:00'; }
}

namespace PaymentGatewayAggregator\Tests\StripeFailedCallback {
    class Fixture
    {
        public static $transaction;
        public static $requests = [];
        public static $signatures = [];
        public static $logs = [];
        public static $response;

        public static function reset($status = 'paid', $method = 'stripe', $event = 'payment_status', $callbackStatus = 'failed')
        {
            global $wpdb;
            $wpdb = new MemoryWpdb();
            self::$transaction = [
                's_order_id' => 'S-001', 'payment_method' => $method, 'status' => $status,
                'paid_at' => null, 'callback_url' => 'https://example.invalid/callback',
            ];
            $wpdb->rows[1] = [
                'id' => 1, 's_order_id' => 'S-001', 'event_type' => $event,
                'payload' => json_encode(['event' => $event, 'status' => $callbackStatus]),
                'status' => 'pending', 'retry_count' => 2, 'max_retries' => 5,
                'next_retry_at' => '2026-10-28 00:00:00', 'last_error' => 'Previous transport failure',
            ];
            self::$requests = self::$signatures = self::$logs = [];
            self::$response = ['ok' => true, 'status' => 200, 'error' => null];
        }
    }

    class MemoryWpdb
    {
        public $prefix = 'isolated_';
        public $rows = [];

        public function prepare($sql, ...$args) { return [$sql, $args]; }
        public function get_row($query, $format)
        {
            [$sql, $args] = $query;
            if (strpos($sql, 'SELECT * FROM isolated_payment_callback_queue WHERE id = %d') !== 0) {
                throw new \RuntimeException('Unexpected queue lookup');
            }
            return $this->rows[$args[0]] ?? null;
        }
        public function get_results($query, $format)
        {
            [$sql, $args] = $query;
            if (strpos($sql, 'SELECT * FROM isolated_payment_callback_queue') !== 0) {
                throw new \RuntimeException('Unexpected queue batch');
            }
            return array_slice(array_values(array_filter($this->rows, function ($row) use ($args) {
                return $row['status'] === 'pending' && $row['next_retry_at'] <= $args[0];
            })), 0, $args[1]);
        }
        public function update($table, array $fields, array $where)
        {
            if ($table !== 'isolated_payment_callback_queue' || !isset($this->rows[$where['id']])) {
                throw new \RuntimeException('Unexpected write outside queue');
            }
            $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $fields);
            return 1;
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\StripeFailedCallback\Fixture;

    class TransactionManager
    {
        public static function findBySOrderId($orderId) { return Fixture::$transaction; }
    }
    class OutboundSigner
    {
        const HEADER_NAME = 'X-Test-Signature';
        public static function sign($payload)
        {
            Fixture::$signatures[] = $payload;
            return 'synthetic-signature';
        }
    }
    class LogManager
    {
        const LEVEL_INFO = 'INFO';
        const LEVEL_WARNING = 'WARNING';
        const LEVEL_ERROR = 'ERROR';

        public static function __callStatic($level, $args) { Fixture::$logs[] = [$level, $args]; }
    }
}

namespace PaymentGatewayAggregator\Helpers {
    use PaymentGatewayAggregator\Tests\StripeFailedCallback\Fixture;

    class HttpClient
    {
        public static function postRawJson($url, $body, array $headers)
        {
            Fixture::$requests[] = compact('url', 'body', 'headers');
            return Fixture::$response;
        }
    }
}

namespace {
    use PaymentGatewayAggregator\Core\QueueManager;
    use PaymentGatewayAggregator\Tests\StripeFailedCallback\Fixture;

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
    function skipped($originalTransaction)
    {
        global $wpdb;
        same([], Fixture::$requests, 'No obsolete failure sent');
        same([], Fixture::$signatures, 'Obsolete failure never signed');
        same('success', $wpdb->rows[1]['status'], 'Obsolete callback is handled permanently');
        same('Stripe订单已确认收款，跳过旧的支付失败回调', $wpdb->rows[1]['last_error'], 'Skip reason retained');
        same(2, $wpdb->rows[1]['retry_count'], 'No retry consumed');
        same($originalTransaction, Fixture::$transaction, 'Transaction remains unchanged');
        same('info', Fixture::$logs[0][0], 'Skip is audited');
        same('failed', Fixture::$logs[0][1][2]['callback_data']['status'], 'Audit retains original callback');
    }
    function sent()
    {
        global $wpdb;
        same(1, count(Fixture::$requests), 'Callback sent once');
        same([$wpdb->rows[1]['payload']], Fixture::$signatures, 'Original payload signed');
        same($wpdb->rows[1]['payload'], Fixture::$requests[0]['body'], 'Original payload sent');
        same('success', $wpdb->rows[1]['status'], 'Delivery succeeded');
    }

    $tests = [];
    foreach (['paid', 'refunded', 'disputing', 'confused'] as $status) {
        foreach (['immediate', 'retry batch'] as $path) {
            $tests['Stripe stale failed callback skipped for ' . $status . ' via ' . $path] = function () use ($status, $path) {
                Fixture::reset($status);
                $originalTransaction = Fixture::$transaction;
                if ($path === 'immediate') { QueueManager::deliverNow(1); }
                else { QueueManager::processDueBatch(); }
                skipped($originalTransaction);
                QueueManager::processDueBatch();
                same([], Fixture::$requests, 'Handled callback cannot retry later');
            };
        }
    }
    $tests['Stripe paid timestamp prevents old failure even after a later state change'] = function () {
        Fixture::reset('failed');
        Fixture::$transaction['paid_at'] = '2026-10-28 12:00:00';
        $originalTransaction = Fixture::$transaction;
        QueueManager::deliverNow(1);
        skipped($originalTransaction);
    };
    foreach ([
        ['failed', 'stripe', 'payment_status', 'failed'],
        ['pending', 'stripe', 'payment_status', 'failed'],
        ['paid', 'paypal_rest', 'payment_status', 'failed'],
        ['paid', 'stripe', 'payment_status', 'paid'],
        ['paid', 'stripe', 'tracking_sync', 'failed'],
    ] as $case) {
        $tests['Current callback is delivered for ' . implode('/', $case)] = function () use ($case) {
            Fixture::reset(...$case);
            $originalTransaction = Fixture::$transaction;
            QueueManager::deliverNow(1);
            sent();
            same($originalTransaction, Fixture::$transaction, 'Delivery does not alter transaction');
        };
    }
    $tests['Current Stripe failure callback still retries transport errors'] = function () {
        global $wpdb;
        Fixture::reset('failed');
        Fixture::$response = ['ok' => false, 'status' => 503, 'error' => 'Synthetic unavailable'];
        QueueManager::deliverNow(1);
        same(1, count(Fixture::$requests), 'Current failure was sent');
        same('pending', $wpdb->rows[1]['status'], 'Retry stays pending');
        same(3, $wpdb->rows[1]['retry_count'], 'Retry count advances');
        same('Synthetic unavailable', $wpdb->rows[1]['last_error'], 'Transport error retained');
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        try { $test(); echo 'PASS ' . $name . PHP_EOL; }
        catch (\Throwable $error) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
    }
    echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}
