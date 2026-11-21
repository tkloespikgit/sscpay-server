<?php
/**
 * S-system callback audit regression runner. Real logger/queue; isolated storage and HTTP.
 * Run: php tests/callback-logging.php
 */

namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
    function current_time($type, $gmt = false) { return '2026-10-29 00:00:00'; }
    function sanitize_text_field($value) { return trim(strip_tags($value)); }
    function wp_unslash($value) { return stripslashes($value); }
    function get_option($name, $default = false) {
        return $name === 'pga_outbound_shared_secret'
            ? \PaymentGatewayAggregator\Tests\CallbackLogging\Fixture::$secret : $default;
    }
}

namespace PaymentGatewayAggregator\Tests\CallbackLogging {
    class Fixture
    {
        public static $secret;
        public static $transaction;
        public static $requests;
        public static $response;
        public static $httpException;

        public static function reset()
        {
            global $wpdb;
            $wpdb = new MemoryWpdb();
            self::$secret = 'isolated-shared-secret-never-log';
            self::$transaction = [
                's_order_id' => 'S-001', 'wp_order_id' => 42, 'payment_method' => 'stripe',
                'payment_intent' => 'pi_test', 'transaction_id' => null,
                'amount' => '10.00', 'currency' => 'USD', 'status' => 'pending',
                'paid_at' => null, 'callback_url' => 'https://example.invalid/callback',
            ];
            self::$requests = [];
            self::$httpException = null;
            self::$response = ['ok' => true, 'status' => 200, 'body' => ['ok' => true], 'raw' => '{"ok":true}', 'error' => null];
        }

        public static function queue($raw, $eventType = 'payment_status')
        {
            global $wpdb;
            $wpdb->queue[1] = [
                'id' => 1, 's_order_id' => 'S-001', 'event_type' => $eventType,
                'payload' => $raw, 'retry_count' => 0, 'max_retries' => 5,
                'next_retry_at' => '2026-10-28 00:00:00', 'status' => 'pending', 'last_error' => null,
            ];
        }
    }

    class MemoryWpdb
    {
        public $prefix = 'isolated_';
        public $logs = [];
        public $queue = [];
        public $insert_id = 0;
        public $last_error = '';

        public function prepare($query, ...$args) { return [$query, $args]; }
        public function insert($table, array $row, $formats = null)
        {
            if ($table !== 'isolated_payment_logs') { throw new \RuntimeException('Unexpected insert: ' . $table); }
            $row['id'] = count($this->logs) + 1;
            $this->logs[] = $row;
            $this->insert_id = $row['id'];
            return 1;
        }
        public function update($table, array $fields, array $where)
        {
            if ($table !== 'isolated_payment_callback_queue') { throw new \RuntimeException('Unexpected update: ' . $table); }
            $this->queue[$where['id']] = array_merge($this->queue[$where['id']], $fields);
            return 1;
        }
        public function get_row($query, $format)
        {
            [$sql, $args] = $query;
            if (strpos($sql, 'SELECT * FROM isolated_payment_callback_queue') === 0) {
                return $this->queue[$args[0]] ?? null;
            }
            throw new \RuntimeException('Unexpected lookup');
        }
        public function get_results($query, $format)
        {
            [$sql, $args] = $query;
            if (strpos($sql, 'SELECT * FROM isolated_payment_callback_queue') === 0) {
                return array_slice(array_values(array_filter($this->queue, function ($row) use ($args) {
                    return $row['status'] === 'pending' && $row['next_retry_at'] <= $args[0];
                })), 0, $args[1]);
            }
            throw new \RuntimeException('Unexpected batch lookup');
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\CallbackLogging\Fixture;
    class TransactionManager
    {
        public static function findBySOrderId($id) { return Fixture::$transaction; }
    }
}

namespace PaymentGatewayAggregator\Helpers {
    use PaymentGatewayAggregator\Tests\CallbackLogging\Fixture;

    class HttpClient
    {
        public static function postRawJson($url, $body, array $headers)
        {
            Fixture::$requests[] = compact('url', 'body', 'headers');
            if (Fixture::$httpException) { throw Fixture::$httpException; }
            return Fixture::$response;
        }
    }
}

namespace {
    use PaymentGatewayAggregator\Core\LogManager;
    use PaymentGatewayAggregator\Core\OutboundSigner;
    use PaymentGatewayAggregator\Core\QueueManager;
    use PaymentGatewayAggregator\Tests\CallbackLogging\Fixture;

    require dirname(__DIR__) . '/vendor/autoload.php';
    set_error_handler(function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    function same($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
    function auditRows($raw)
    {
        global $wpdb;
        return array_values(array_filter($wpdb->logs, function ($row) use ($raw) {
            $data = json_decode($row['callback_data'] ?? '', true);
            return is_array($data) && ($data['body_sha256'] ?? null) === hash('sha256', $raw);
        }));
    }
    function audit($raw)
    {
        $rows = auditRows($raw);
        same(true, count($rows) > 0, 'Raw callback has an audit record');
        $row = end($rows);
        $data = json_decode($row['callback_data'], true);
        same($raw, $data['raw_body'], 'Exact request bytes retained');
        same(strlen($raw), $data['body_bytes'], 'Byte count retained');
        return [$row, $data];
    }

    $tests = [];
    $tests['raw JSON over 4000 bytes survives logging and order-log API decoding'] = function () {
        global $wpdb;
        $raw = "{\n \"note\": \"" . str_repeat('x', 5000) . '\\u4e2d\\u6587", "amount": "10.0000", "url":"https:\\/\\/example.invalid\\/x"' . "\n}\n";
        json_decode($raw);
        same(JSON_ERROR_NONE, json_last_error(), 'Fixture is JSON');
        LogManager::callbackExchange('outbound', 'S-001', 'Raw S callback', $raw,
            ['X-PGA-Signature' => 'abc', 'authorization' => 'Bearer forbidden-header'], [
                'level' => 'WARNING', 'payment_method' => 'stripe', 'wp_order_id' => 42,
                'request_data' => ['api_key' => 'forbidden-request-key'],
                'response_data' => ['status' => 401, 'client_secret' => 'forbidden-response-secret'],
                'callback_data' => ['event_id' => 'evt_test', 'private_key' => 'forbidden-callback-key'],
            ]);
        [$row, $data] = audit($raw);
        same('WARNING', $row['level'], 'Explicit level retained');
        same('stripe', $row['payment_method'], 'Gateway retained');
        same(42, $row['wp_order_id'], 'Woo order retained');
        same('outbound', $data['direction'], 'Direction retained');
        same(['x-pga-signature' => 'abc'], $data['signature_headers'], 'Only signature headers retained');
        same('evt_test', $data['event_id'], 'Callback metadata retained');
        foreach (['forbidden-header', 'forbidden-request-key', 'forbidden-response-secret', 'forbidden-callback-key', Fixture::$secret] as $secret) {
            same(false, strpos(json_encode($row), $secret) !== false, 'Secret omitted: ' . $secret);
        }
        $formatter = new \ReflectionMethod(\PaymentGatewayAggregator\API\Controllers\OrderLogController::class, 'formatRow');
        $formatter->setAccessible(true);
        $apiLog = $formatter->invoke(null, $row);
        same($raw, $apiLog['callback_data']['raw_body'], 'Order-log API retains exact body');
        same(401, $apiLog['response_data']['status'], 'Response status retained');
    };
    $tests['only the outbound signature is retained and its header name is normalized'] = function () {
        LogManager::callbackExchange('outbound', 'S-001', 'Headers', '{}', [
            'X_PGA_SIGNATURE' => ['hmac'], 'cookie' => 'do-not-log-cookie',
            'authorization' => 'do-not-log-auth', 'stripe-signature' => 'not-an-outbound-header',
        ]);
        [, $data] = audit('{}');
        same(['x-pga-signature' => 'hmac'], $data['signature_headers'], 'Only normalized outbound signature retained');
    };
    $tests['missing signature is visible and invalid UTF-8 is recoverable without changing bytes'] = function () {
        $raw = "{\"invalid\":\"\xFF\"}";
        LogManager::callbackExchange('outbound', 'S-001', 'Invalid JSON', $raw, []);
        $rows = auditRows($raw);
        same(1, count($rows), 'Invalid UTF-8 audit persisted');
        $data = json_decode($rows[0]['callback_data'], true);
        same(null, $data['raw_body'], 'Invalid UTF-8 not silently repaired');
        same($raw, base64_decode($data['raw_body_base64'], true), 'Base64 recovers exact original bytes');
        same([], $data['signature_headers'], 'Missing signature recorded explicitly');
        same(strlen($raw), $data['body_bytes'], 'Invalid body byte count retained');
    };

    foreach (['payment_status', 'tracking_sync'] as $eventType) {
        $tests['outbound ' . $eventType . ' logs the exact signed request and 401 response on every retry'] = function () use ($eventType) {
            global $wpdb;
            $raw = "{\n \"event\": \"" . $eventType . "\", \"status\": \"paid\", \"amount\": \"10.0000\"\n}\n";
            Fixture::queue($raw, $eventType);
            Fixture::$response = ['ok' => false, 'status' => 401, 'body' => ['message' => 'signature invalid'], 'raw' => '{"message":"signature invalid"}', 'error' => null];
            QueueManager::deliverNow(1);
            same(1, count(Fixture::$requests), 'One request sent');
            same($raw, Fixture::$requests[0]['body'], 'Exact body sent');
            $expected = hash_hmac('sha256', $raw, Fixture::$secret);
            same($expected, Fixture::$requests[0]['headers']['X-PGA-Signature'], 'Expected HMAC sent');
            [$row, $data] = audit($raw);
            same($expected, $data['signature_headers']['x-pga-signature'], 'Actual outgoing HMAC audited');
            $request = json_decode($row['request_data'], true);
            same(1, $request['queue_id'], 'Queue identity retained');
            same($eventType, $request['event_type'], 'Callback event retained');
            same(1, $request['attempt'], 'Initial attempt numbered');
            same(true, strpos($row['response_data'], '401') !== false, 'HTTP status audited');
            same(true, strpos($row['response_data'], 'signature invalid') !== false, 'S response body audited');
            same(false, strpos(json_encode($wpdb->logs), Fixture::$secret) !== false, 'Signing key never audited');
            same('pending', $wpdb->queue[1]['status'], 'Failure still schedules retry');
            $firstCount = count(auditRows($raw));
            $wpdb->queue[1]['next_retry_at'] = '2026-10-28 00:00:00';
            Fixture::$response = ['ok' => true, 'status' => 200, 'body' => ['ok' => true], 'raw' => '{"ok":true}', 'error' => null];
            QueueManager::processDueBatch();
            same(2, count(Fixture::$requests), 'Cron sends retry');
            same(true, count(auditRows($raw)) > $firstCount, 'Retry has its own request audit');
            same('success', $wpdb->queue[1]['status'], 'Successful retry marked complete');
            [$row, $data] = audit($raw);
            same($expected, $data['signature_headers']['x-pga-signature'], 'Retry signature retained');
            same(2, json_decode($row['request_data'], true)['attempt'], 'Retry attempt numbered independently');
            same(true, strpos($row['response_data'], '200') !== false, 'Successful response audited');
        };
    }
    $tests['outbound transport errors retain the exact attempted signed request'] = function () {
        $raw = '{"event":"payment_status","status":"paid"}';
        Fixture::queue($raw);
        Fixture::$response = ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'Synthetic connection timeout'];
        QueueManager::deliverNow(1);
        [$row, $data] = audit($raw);
        same(hash_hmac('sha256', $raw, Fixture::$secret), $data['signature_headers']['x-pga-signature'], 'Attempted signature retained');
        same(true, strpos($row['response_data'], 'Synthetic connection timeout') !== false, 'Transport failure audited');
    };
    $tests['missing outbound key audits unsent JSON without claiming a signature was sent'] = function () {
        $raw = '{"event":"payment_status","status":"paid"}';
        Fixture::queue($raw);
        Fixture::$secret = '';
        QueueManager::deliverNow(1);
        same([], Fixture::$requests, 'No unsigned request sent');
        [, $data] = audit($raw);
        same([], $data['signature_headers'], 'No invented signature');
    };
    $tests['HTTP exceptions retain request audit and propagate the original exception'] = function () {
        $raw = '{"event":"payment_status","status":"paid"}';
        Fixture::queue($raw);
        Fixture::$httpException = new \RuntimeException('Synthetic HTTP hook exception');
        $caught = null;
        try { QueueManager::deliverNow(1); }
        catch (\Throwable $error) { $caught = $error; }
        same(Fixture::$httpException, $caught, 'Original transport exception propagated');
        [$row, $data] = audit($raw);
        same(hash_hmac('sha256', $raw, Fixture::$secret), $data['signature_headers']['x-pga-signature'], 'Signature retained even when HTTP throws');
        same(true, strpos($row['response_data'], 'Synthetic HTTP hook exception') !== false, 'HTTP exception message audited');
    };
    $tests['last failed attempt retains signed JSON and response after retries are exhausted'] = function () {
        global $wpdb;
        $raw = '{"event":"payment_status","status":"paid"}';
        Fixture::queue($raw);
        $wpdb->queue[1]['retry_count'] = 4;
        Fixture::$response = ['ok' => false, 'status' => 401, 'body' => 'Signature rejected', 'raw' => 'Signature rejected', 'error' => null];
        QueueManager::deliverNow(1);
        same('failed', $wpdb->queue[1]['status'], 'Final failure remains terminal');
        [$row, $data] = audit($raw);
        same(hash_hmac('sha256', $raw, Fixture::$secret), $data['signature_headers']['x-pga-signature'], 'Final attempt HMAC retained');
        same(5, json_decode($row['request_data'], true)['attempt'], 'Final attempt identity retained');
        same(true, strpos($row['response_data'], 'Signature rejected') !== false, 'Final S error body retained');
    };

    $tests['site default is used only until an editable per-site key is saved'] = function () {
        define('PGA_DEFAULT_OUTBOUND_SHARED_SECRET', 'isolated-default-for-test');
        Fixture::$secret = '';
        $body = '{"event":"payment_status"}';
        same('isolated-default-for-test', OutboundSigner::configuredSecret(), 'Empty option uses the site default');
        same(hash_hmac('sha256', $body, 'isolated-default-for-test'), OutboundSigner::sign($body), 'Default signs the raw body');
        Fixture::$secret = 'isolated-edited-key-for-test';
        same(hash_hmac('sha256', $body, Fixture::$secret), OutboundSigner::sign($body), 'Saved key overrides the default');
    };

    $failed = 0;
    foreach ($tests as $name => $test) {
        Fixture::reset();
        try { $test(); echo "PASS {$name}\n"; }
        catch (\Throwable $error) { ++$failed; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
    }
    echo count($tests) . " tests, {$failed} failures\n";
    exit($failed ? 1 : 0);
}
