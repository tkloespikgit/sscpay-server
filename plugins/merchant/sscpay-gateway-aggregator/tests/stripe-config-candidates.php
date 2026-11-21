<?php
/** Transaction storage regressions; synthetic encrypted strings, no database or keys. */
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/vendor/autoload.php';

use PaymentGatewayAggregator\Core\TransactionManager;

class CandidateDb
{
    public $prefix = 'isolated_';
    public $last_error = '';
    public $rows = [];
    public $registered = [];
    public $queries = [];
    public $failPageAt;
    public $failSingle = false;
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_results($prepared, $format)
    {
        [$sql, $args] = $prepared;
        if (strpos($sql, 'payment_gateway_configs') !== false) { return $this->registered; }
        $this->queries[] = $args;
        $this->last_error = '';
        if (strpos($sql, 'DISTINCT') !== false || strpos($sql, 'ORDER BY id ASC') === false) {
            throw new RuntimeException('Credentials must be streamed by primary key');
        }
        if ($this->failPageAt !== null && $args[0] >= $this->failPageAt) {
            $this->last_error = 'Synthetic database page failure'; return null;
        }
        return array_slice(array_values(array_filter($this->rows, function ($row) use ($args) {
            return $row['id'] > $args[0] && $row['payment_method'] === 'stripe';
        })), 0, $args[1]);
    }
    public function get_row($prepared, $format)
    {
        $this->last_error = $this->failSingle ? 'Synthetic read failure' : '';
        if ($this->failSingle) { return null; }
        foreach ($this->rows as $row) { if ($row['s_order_id'] === $prepared[1][0]) { return $row; } }
        return null;
    }
}
function same($expected, $actual, $message)
{
    if ($expected !== $actual) { throw new RuntimeException($message . ': ' . var_export($actual, true)); }
}
function readAll() { return iterator_to_array(TransactionManager::stripeWebhookConfigs(), false); }
$tests = [];
$tests['registered keys are available before the first transaction using a rotated key'] = function () {
    global $wpdb;
    $wpdb->registered = [['gateway_config' => 'synthetic-new-registered-key']];
    same($wpdb->registered, readAll(), 'Registered credentials do not require a historical order');
};
$tests['all historical credential rows remain reachable beyond the first hundred'] = function () {
    global $wpdb;
    for ($id = 1; $id <= 251; $id++) {
        $wpdb->rows[] = ['id' => $id, 'payment_method' => 'stripe', 's_order_id' => 'S-' . $id,
            'gateway_config' => 'synthetic-ciphertext-' . $id];
    }
    // Unrelated gateway rows can create primary-key gaps without ending the scan.
    $wpdb->rows[54]['payment_method'] = 'paypal_rest';
    $rows = readAll();
    same(250, count($rows), 'Every Stripe history row is yielded');
    same('synthetic-ciphertext-251', end($rows)['gateway_config'], 'Late candidate reached');
    same([[0, 100], [101, 100], [201, 100]], $wpdb->queries, 'Cursor uses last primary key, not offset');
};
$tests['candidate generator is lazy and stops reading when a verified key is found'] = function () {
    global $wpdb;
    for ($id = 1; $id <= 200; $id++) {
        $wpdb->rows[] = ['id' => $id, 'payment_method' => 'stripe', 'gateway_config' => 'fixture-' . $id];
    }
    $candidates = TransactionManager::stripeWebhookConfigs();
    same([], $wpdb->queries, 'No query before iteration');
    foreach ($candidates as $candidate) { same(1, $candidate['id'], 'First candidate'); break; }
    same([[0, 100]], $wpdb->queries, 'No unnecessary page loaded');
};
$tests['later-page database failure is reported instead of an incomplete successful scan'] = function () {
    global $wpdb;
    for ($id = 1; $id <= 101; $id++) {
        $wpdb->rows[] = ['id' => $id, 'payment_method' => 'stripe', 'gateway_config' => 'fixture-' . $id];
    }
    $wpdb->failPageAt = 100;
    try { readAll(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Missing database page error');
};
$tests['single-order database errors are distinct from a genuinely absent order'] = function () {
    global $wpdb;
    same(null, TransactionManager::findBySOrderId('absent'), 'Missing order is nullable');
    $wpdb->failSingle = true;
    try { TransactionManager::findBySOrderId('S-001'); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Missing order-read error');
};
$failures = 0;
foreach ($tests as $name => $test) {
    $wpdb = new CandidateDb();
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $e) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL); }
}
echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
