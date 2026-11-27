<?php
/** Real gateway signature, refund inbox, records and queue over an isolated in-memory SQL database. */
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('PGA_ENCRYPTION_KEY', base64_encode(random_bytes(32)));
function wp_json_encode($value) { return json_encode($value); }
function current_time($format, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wp_next_scheduled($hook) { return true; }
function spawn_cron() {}
function sanitize_text_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function get_option($key, $default = false) { return $key === 'pga_db_version' ? ($GLOBALS['refundSchemaVersion'] ?? '1.7.0') : $default; }

use PaymentGatewayAggregator\Core\ConfigEncryptor;
use PaymentGatewayAggregator\Core\RefundInbox;
use PaymentGatewayAggregator\Core\RefundRecords;
use PaymentGatewayAggregator\Core\WebhookRouter;
require dirname(__DIR__) . '/vendor/autoload.php';

/** Only the provider's upstream HTTP boundary is faked. Signatures, parsing, persistence and queues run normally. */
final class FixtureStripeGateway extends \PaymentGatewayAggregator\Gateways\StripeGateway
{
    public static $items = [];
    public static $queries = 0;
    public static $fail = false;
    protected function refundClient()
    {
        return (object) ['refunds' => new class {
            public function all(array $options, array $context)
            {
                FixtureStripeGateway::$queries++;
                if (FixtureStripeGateway::$fail) { throw new RuntimeException('Offline'); }
                $rows = array_values(array_filter(FixtureStripeGateway::$items, static function ($row) use ($options) {
                    $field = isset($options['charge']) ? 'charge' : 'payment_intent';
                    return $row[$field] === $options[$field];
                }));
                return new class($rows) {
                    private $rows;
                    public function __construct($rows) { $this->rows = $rows; }
                    public function toArray() { return ['data' => $this->rows, 'has_more' => false]; }
                };
            }
        }];
    }
    public function findCheckoutSessionForPaymentIntent($piId, ?string $account = null): ?string { return null; }
}
final class FixtureAntomGateway extends \PaymentGatewayAggregator\Gateways\AntomGateway
{
    public static $list = [];
    public static $queries = [];
    public function queryRefunds(array $transaction): array
    {
        self::$queries[] = $transaction['s_order_id'];
        return ['kind' => 'succeeded', 'refunds' => self::$list[$transaction['s_order_id']] ?? [], 'evidence' => []];
    }
}
$gateways = new ReflectionProperty(\PaymentGatewayAggregator\Core\GatewayFactory::class, 'map');
$gateways->setAccessible(true);
$gateways->setValue(null, array_merge($gateways->getValue(), ['stripe' => FixtureStripeGateway::class, 'antom' => FixtureAntomGateway::class]));

final class RefundTestDatabase
{
    public $prefix = 'test_';
    public $last_error = '';
    public $insert_id;
    public $pdo;
    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('UTC_TIMESTAMP', static function () { return gmdate('Y-m-d H:i:s'); });
        $this->pdo->sqliteCreateFunction('GET_LOCK', static function () { return 1; }, 2);
        $this->pdo->sqliteCreateFunction('RELEASE_LOCK', static function () { return 1; }, 1);
        foreach ([
            'payment_transactions' => 'id INTEGER PRIMARY KEY, s_order_id TEXT, payment_method TEXT, amount TEXT, currency TEXT, paid_at TEXT, status TEXT, payment_intent TEXT, transaction_id TEXT, gateway_config TEXT, updated_at TEXT',
            'payment_refund_inbox' => "id INTEGER PRIMARY KEY, receipt_key TEXT UNIQUE, failed_source TEXT, payment_method TEXT, config_source TEXT, config_id INTEGER, config_hash TEXT, s_order_id TEXT, verified_body TEXT, status TEXT, attempts INTEGER DEFAULT 0, lookup_cursor INTEGER DEFAULT 0, next_attempt_at TEXT, created_at TEXT,last_error TEXT DEFAULT ''",
            'payment_refunds' => 'id INTEGER PRIMARY KEY, refund_key TEXT UNIQUE, source_hash TEXT UNIQUE, amount NUMERIC, s_order_id TEXT, payload TEXT',
            'payment_callback_queue' => 'id INTEGER PRIMARY KEY, s_order_id TEXT, event_type TEXT, payload TEXT, retry_count INTEGER, max_retries INTEGER, next_retry_at TEXT, status TEXT, created_at TEXT, updated_at TEXT',
            'payment_gateway_configs' => 'id INTEGER PRIMARY KEY, payment_method TEXT, config_encrypted TEXT, status TEXT',
            'payment_session_references' => 'id INTEGER PRIMARY KEY, ref_hash TEXT',
            'payment_logs' => 'id INTEGER PRIMARY KEY, s_order_id TEXT, wp_order_id INTEGER, payment_method TEXT, level TEXT, message TEXT, request_data TEXT, response_data TEXT, callback_data TEXT, ip TEXT, user_agent TEXT, created_at TEXT'
        ] as $suffix => $columns) { $this->pdo->exec("CREATE TABLE test_{$suffix} ({$columns})"); }
    }
    public function prepare($sql, ...$args)
    {
        return preg_replace_callback('/%[sd]/', function () use (&$args) { return $this->pdo->quote((string) array_shift($args)); }, $sql);
    }
    public function query($sql)
    {
        if ($sql === 'START TRANSACTION') { return $this->pdo->beginTransaction(); }
        if ($sql === 'COMMIT') { return $this->pdo->commit(); }
        if ($sql === 'ROLLBACK') { return $this->pdo->rollBack(); }
        if (strpos($sql, 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)') !== false) {
            $sql = str_replace('ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', 'ON CONFLICT(receipt_key) DO UPDATE SET receipt_key=excluded.receipt_key RETURNING id', $sql);
            $this->insert_id = $this->pdo->query($sql)->fetchColumn();
            return 1;
        }
        return $this->pdo->exec(str_replace('DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)', "datetime('now','+10 minutes')", $sql));
    }
    public function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    public function get_row($sql, $format) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function get_results($sql, $format) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function get_var($sql)
    {
        $value = $this->pdo->query(str_replace(' FOR UPDATE', '', $sql))->fetchColumn();
        return $value === false ? null : $value;
    }
    public function insert($table, array $data, $format = null)
    {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($data));
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }
    public function update($table, $values, $where)
    {
        $pairs = static function ($keys, $separator) { return implode($separator, array_map(static function ($key) { return $key . '=?'; }, $keys)); };
        $query = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $pairs(array_keys($values), ',') . ' WHERE ' . $pairs(array_keys($where), ' AND '));
        $query->execute(array_merge(array_values($values), array_values($where)));
        return $query->rowCount();
    }
}
function sameRefund($wanted, $actual, $label)
{
    if ($wanted !== $actual) { throw new RuntimeException($label . ': ' . json_encode($actual)); }
}
function stripeRefundNotice($eventId, $refundId, $amount, $ref = 'pi_paid', $signed = true, $status = 'succeeded')
{
    $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'refund.updated', 'livemode' => false,
        'created' => 1780000000, 'data' => ['object' => ['object' => 'refund', 'id' => $refundId,
            'status' => $status, 'currency' => 'usd', 'payment_intent' => $ref, 'charge' => 'ch_order', 'amount' => $amount]]]);
    return signedRefundBody($body, $signed);
}
function signedRefundBody(string $body, bool $signed = true)
{
    $now = time();
    $signature = hash_hmac('sha256', $now . '.' . $body, $signed ? 'fixture_webhook' : 'wrong');
    return WebhookRouter::handle('stripe', $body, ['stripe-signature' => "t={$now},v1={$signature}"]);
}
$wpdb = new RefundTestDatabase();
$config = ConfigEncryptor::encrypt(['mode' => 'sandbox', 'webhook_secret' => 'fixture_webhook']);
$wpdb->insert('test_payment_transactions', ['s_order_id' => 'S1', 'payment_method' => 'stripe', 'currency' => 'USD',
    'amount' => '100.00', 'status' => 'paid', 'paid_at' => gmdate('Y-m-d H:i:s'),
    'payment_intent' => 'cs_paid', 'transaction_id' => 'pi_paid', 'gateway_config' => $config]);
$refundSchemaVersion = '1.6.0';
sameRefund(503, stripeRefundNotice('evt_migrating', 're_one', 2000)['http_status'], 'Incomplete schema rejected before writes');
sameRefund([], $wpdb->get_col('SELECT id FROM test_payment_refund_inbox'), 'No receipt without transactional schema');
$refundSchemaVersion = '1.7.0';
sameRefund(400, stripeRefundNotice('evt_invalid', 're_one', 2000, 'pi_paid', false)['http_status'], 'Invalid signature');
sameRefund([], RefundRecords::forOrder('S1'), 'Rejected refund has no effect');
sameRefund(200, stripeRefundNotice('evt_one', 're_one', 2000)['http_status'], 'First partial refund accepted');
sameRefund(200, stripeRefundNotice('evt_one', 're_one', 2000)['http_status'], 'Delivery replay');
sameRefund(200, stripeRefundNotice('evt_two', 're_one', 2000)['http_status'], 'Distinct event, same refund');
$first = RefundRecords::forOrder('S1');
sameRefund(1, count($first), 'Exactly one refund');
sameRefund('20.00', $first[0]['amount'], 'Principal is actual PSP amount');
sameRefund('pi_paid', $first[0]['payment_reference'], 'Stable payment reference');
sameRefund('paid', $wpdb->get_var('SELECT status FROM test_payment_transactions'), 'Partial keeps paid status');
sameRefund(200, stripeRefundNotice('evt_failed', 're_failed', 1000, 'pi_paid', true, 'failed')['http_status'], 'Failed refund acknowledged');
sameRefund(1, count(RefundRecords::forOrder('S1')), 'Failed refund has no financial effect');
stripeRefundNotice('evt_conflicting', 're_one', 9999);
sameRefund('review', $wpdb->get_var('SELECT status FROM test_payment_refund_inbox ORDER BY id DESC LIMIT 1'), 'Conflicting fact held for review');
sameRefund(1, count($wpdb->get_col('SELECT * FROM test_payment_callback_queue')), 'Replay or conflict cannot enqueue again');
stripeRefundNotice('evt_second', 're_two', 8000);
sameRefund(['20.00','80.00'], array_column(RefundRecords::forOrder('S1'), 'amount'), 'Two distinct refund amounts');
sameRefund('refunded', $wpdb->get_var('SELECT status FROM test_payment_transactions'), 'Confirmed totals close order');
sameRefund(['payment_refund', 'payment_refund'], $wpdb->get_col('SELECT event_type FROM test_payment_callback_queue'), 'No legacy full deduction');
$wpdb->update('test_payment_transactions', ['paid_at' => null, 'status' => 'pending', 's_order_id' => 'S2'], ['s_order_id' => 'S1']);
stripeRefundNotice('evt_early', 're_early', 500);
sameRefund([], RefundRecords::forOrder('S2'), 'Early refund waits for payment');
$wpdb->update('test_payment_transactions', ['paid_at' => gmdate('Y-m-d H:i:s'), 'status' => 'paid'], ['s_order_id' => 'S2']);
$wpdb->pdo->exec("UPDATE test_payment_refund_inbox SET next_attempt_at='2000-01-01' WHERE status='pending'");
RefundInbox::retryDue();
sameRefund(['5.00'], array_column(RefundRecords::forOrder('S2'), 'amount'), 'Early refund survives retry');

$transaction = \PaymentGatewayAggregator\Core\TransactionManager::findBySOrderId('S2');
$aggregate = json_encode(['id' => 'evt_charge', 'object' => 'event', 'type' => 'charge.refunded', 'created' => time(), 'livemode' => false,
    'data' => ['object' => ['id' => 'ch_order', 'object' => 'charge', 'payment_intent' => 'pi_paid', 'refunded' => true, 'amount_refunded' => 10000]]]);
sameRefund(200, signedRefundBody($aggregate)['http_status'], 'Aggregate with no individual proofs durably saved');
sameRefund(1, count(RefundRecords::forOrder('S2')), 'Aggregate amount never creates a refund');
FixtureStripeGateway::$items = [
    ['id' => 're_early', 'status' => 'succeeded', 'charge' => 'ch_order', 'payment_intent' => 'pi_paid',
        'currency' => 'usd', 'amount' => 500, 'balance_transaction' => ['created' => time()]],
    ['id' => 're_charge', 'status' => 'succeeded', 'charge' => 'ch_order', 'payment_intent' => 'pi_paid',
        'currency' => 'usd', 'amount' => 1000, 'balance_transaction' => ['created' => time()]],
];
$wpdb->pdo->exec("UPDATE test_payment_refund_inbox SET next_attempt_at='2000-01-01' WHERE status='pending'");
RefundInbox::retryDue();
sameRefund(['5.00', '10.00'], array_column(RefundRecords::forOrder('S2'), 'amount'), 'Charge fetch and refund notifications converge');
sameRefund('paid', $wpdb->get_var("SELECT status FROM test_payment_transactions WHERE s_order_id='S2'"), 'Aggregate full flag cannot override actual partials');
sameRefund(RefundRecords::forOrder('S2'), RefundRecords::reconcile($transaction, $complete), 'Query returns identical immutable payloads');
sameRefund(true, $complete, 'Complete authenticated query');
FixtureStripeGateway::$fail = true;
sameRefund(RefundRecords::forOrder('S2'), RefundRecords::reconcile($transaction, $complete), 'Provider failure preserves saved proofs');
sameRefund(false, $complete, 'Incomplete query explicitly identified');
$before = FixtureStripeGateway::$queries;
$unpaid = array_merge($transaction, ['s_order_id' => 'S-pending', 'paid_at' => null]);
sameRefund([], RefundRecords::reconcile($unpaid, $complete), 'Unpaid order remains queryable');
sameRefund($before, FixtureStripeGateway::$queries, 'Unpaid orders do not query refunds');
FixtureStripeGateway::$fail = false;

$wpdb->pdo->exec("CREATE TRIGGER stop_outbox BEFORE INSERT ON test_payment_callback_queue BEGIN SELECT RAISE(ABORT, 'test outbox failure'); END");
sameRefund(200, stripeRefundNotice('evt_outbox', 're_outbox', 700)['http_status'], 'Durable receipt survives outbox failure');
sameRefund(2, count(RefundRecords::forOrder('S2')), 'Outbox failure rolls back refund proof');
$wpdb->pdo->exec('DROP TRIGGER stop_outbox');
$wpdb->pdo->exec("UPDATE test_payment_refund_inbox SET next_attempt_at='2000-01-01' WHERE status='pending'");
RefundInbox::retryDue();
sameRefund(['5.00', '10.00', '7.00'], array_column(RefundRecords::forOrder('S2'), 'amount'), 'Retry restores refund and queue together');
$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM test_payment_refund_inbox');
sameRefund(400, stripeRefundNotice('evt_unknown_bad', 're_unknown', 300, 'pi_unknown', false)['http_status'], 'Unmatched invalid signature rejected');
sameRefund($before, (int) $wpdb->get_var('SELECT COUNT(*) FROM test_payment_refund_inbox'), 'Unverified body never persisted');
sameRefund(200, stripeRefundNotice('evt_unknown', 're_unknown', 300, 'pi_unknown')['http_status'], 'Unmatched verified body retained');
$wpdb->insert('test_payment_transactions', ['s_order_id' => 'S3', 'payment_method' => 'stripe', 'currency' => 'USD', 'amount' => '100.00',
    'status' => 'paid', 'paid_at' => gmdate('Y-m-d H:i:s'), 'payment_intent' => 'cs_unknown', 'transaction_id' => 'pi_unknown', 'gateway_config' => $config]);
$wpdb->pdo->exec("UPDATE test_payment_refund_inbox SET next_attempt_at='2000-01-01' WHERE status='pending'");
RefundInbox::retryDue();
sameRefund(['3.00'], array_column(RefundRecords::forOrder('S3'), 'amount'), 'Unmatched refund recovered using verified original payment');

$key = openssl_pkey_new(['private_key_bits' => 2048]);
$publicKey = openssl_pkey_get_details($key)['key'];
$antomConfig = ConfigEncryptor::encrypt(['mode' => 'sandbox', 'client_id' => 'fixture', 'antom_public_key' => $publicKey]);
$wpdb->insert('test_payment_transactions', ['s_order_id' => 'A1', 'payment_method' => 'antom', 'currency' => 'USD', 'amount' => '100.00',
    'status' => 'paid', 'paid_at' => gmdate('Y-m-d H:i:s'), 'payment_intent' => 'request-A1', 'transaction_id' => 'pay-A1', 'gateway_config' => $antomConfig]);
$phantom = ['notifyType' => 'REFUND_RESULT', 'refundId' => 'AR1', 'refundStatus' => 'SUCCESS',
    'refundTime' => '2026-01-01T01:02:03Z', 'refundAmount' => ['currency' => 'USD', 'value' => '1200']];
$raw = json_encode($phantom);
$path = '/wp-json/sscpay/v1/webhook/antom';
$time = gmdate('c');
openssl_sign("POST {$path}\nfixture.{$time}.{$raw}", $sig, $key, OPENSSL_ALGO_SHA256);
$headers = ['x-pga-http-method' => 'POST', 'x-pga-http-path' => $path, 'request-time' => $time,
    'client-id' => 'fixture', 'signature' => 'algorithm=RSA256,keyVersion=1,signature=' . rawurlencode(base64_encode($sig))];
sameRefund(200, WebhookRouter::handle('antom', $raw, $headers)['http_status'], 'Antom orphan retained');
sameRefund([], FixtureAntomGateway::$queries, 'Orphan received before querying any candidate payment');
sameRefund([], RefundRecords::forOrder('A1'), 'Order not guessed from same account or amount');
FixtureAntomGateway::$list['A1'] = [['refund_id' => 'AR1', 'amount' => '12.00', 'currency' => 'USD',
    'refunded_at' => $phantom['refundTime'], 'payment_refs' => ['pay-A1']]];
$wpdb->pdo->exec("UPDATE test_payment_refund_inbox SET next_attempt_at='2000-01-01' WHERE status='pending'");
RefundInbox::retryDue();
sameRefund(['A1'], FixtureAntomGateway::$queries, 'Only payment from same verified account queried');
sameRefund(['12.00'], array_column(RefundRecords::forOrder('A1'), 'amount'), 'Orphan matched by exact provider refund number on original payment');
sameRefund(null, $wpdb->get_var("SELECT verified_body FROM test_payment_refund_inbox WHERE payment_method='antom'"), 'Verified body removed after completion');

FixtureStripeGateway::$items[1]['status'] = 'failed';
$remaining = RefundRecords::reconcile($transaction, $complete);
sameRefund(false, in_array('re_charge', array_column($remaining, 'refund_id'), true), 'An authenticated query of a failed refund suppresses prior success');
sameRefund(false, $complete, 'Failed-after-success conflict remains outstanding');

stripeRefundNotice('evt_success_then_failed', 're_unknown', 300, 'pi_unknown', true, 'failed');
sameRefund([], RefundRecords::forOrder('S3'), 'A later failure suppresses disputed success in order queries');
sameRefund(true, RefundRecords::hasFailedNotice('S3', 're_unknown'), 'Outstanding success deliveries held for finance review');
sameRefund('review', $wpdb->get_var("SELECT status FROM test_payment_refund_inbox WHERE failed_source IS NOT NULL ORDER BY id DESC LIMIT 1"), 'Success then failed flagged for review');
stripeRefundNotice('evt_revived', 're_failed', 1000, 'pi_paid');
sameRefund(false, in_array('re_failed', array_column(RefundRecords::forOrder('S2'), 'refund_id'), true), 'Late success cannot override an earlier verified failure');

$old = \PaymentGatewayAggregator\Core\TransactionManager::findBySOrderId('A1');
$wpdb->update('test_payment_transactions', ['transaction_id' => 'AR1'], ['s_order_id' => 'A1']);
$proof = FixtureAntomGateway::$list['A1'][0];
$proof['payment_refs'][] = $old['payment_intent'];
$proof['refund_id'] = 'AR2';
try {
    RefundRecords::record($old, ConfigEncryptor::decrypt($antomConfig), [$proof]);
    throw new RuntimeException('An unverified local payment_reference should be rejected');
} catch (UnexpectedValueException $expected) {
    sameRefund(['AR1'], array_column(RefundRecords::forOrder('A1'), 'refund_id'), 'Historic refund ID cannot be used as original payment');
}
echo "PASS partial and cumulative refunds, signed deliveries, atomic outbox, replay, conflicts, incomplete queries and orphan recovery\n";
