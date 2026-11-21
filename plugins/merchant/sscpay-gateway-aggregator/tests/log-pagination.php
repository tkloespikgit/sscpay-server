<?php
/** Log pagination storage regressions; isolated rows, no WordPress bootstrap/database. */
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/vendor/autoload.php';

use PaymentGatewayAggregator\Admin\LogViewer;
use PaymentGatewayAggregator\Core\LogManager;

function current_user_can($capability) { return $capability === 'manage_options' && $GLOBALS['can_manage_logs']; }
function check_admin_referer($action) { return ($_POST['_wpnonce'] ?? '') === $action; }
function esc_html__($message, $domain) { return $message; }
function wp_die($message, $title, $args) { throw new RuntimeException($message, $args['response']); }
function admin_url($path) { return 'https://wordpress.example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { throw new RuntimeException($url, 302); }

class LogPageDb
{
    public $prefix = 'isolated_';
    public $rows = [];
    public $canDelete = true;
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    private function matching($prepared)
    {
        [$sql, $args] = is_array($prepared) ? $prepared : [$prepared, []];
        $filters = [];
        preg_match_all('/(level|s_order_id) = %s/', $sql, $matches);
        foreach ($matches[1] as $i => $field) { $filters[$field] = $args[$i]; }
        return array_values(array_filter($this->rows, function ($row) use ($filters) {
            foreach ($filters as $field => $value) { if ($row[$field] !== $value) { return false; } }
            return true;
        }));
    }
    public function get_var($query) { return count($this->matching($query)); }
    public function get_results($query, $format)
    {
        [$sql, $args] = $query;
        if (strpos($sql, 'ORDER BY created_at DESC, id DESC') === false) {
            throw new RuntimeException('Pagination requires stable ordering when timestamps match');
        }
        $rows = $this->matching($query);
        usort($rows, function ($a, $b) { return [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]; });
        return array_slice($rows, $args[count($args) - 1], $args[count($args) - 2]);
    }

    public function query($sql)
    {
        same('DELETE FROM isolated_payment_logs', $sql, 'Only delete site logs, without resetting IDs');
        if (!$this->canDelete) {
            return false;
        }
        $count = count($this->rows);
        $this->rows = [];

        return $count;
    }
}
function same($expected, $actual, $label)
{
    if ($expected !== $actual) { throw new RuntimeException($label . ': ' . var_export($actual, true)); }
}
function seedLogs($count)
{
    global $wpdb;
    $wpdb = new LogPageDb();
    for ($id = 1; $id <= $count; $id++) {
        $wpdb->rows[] = ['id' => $id, 's_order_id' => 'S-001',
            'level' => $id % 2 ? 'ERROR' : 'INFO', 'created_at' => '2026-11-10 00:00:00'];
    }
}

function clearLogsThroughAdmin($method, $canManage, array $post): RuntimeException
{
    $GLOBALS['can_manage_logs'] = $canManage;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    try {
        LogViewer::handleClear();
    } catch (RuntimeException $result) {
        return $result;
    }

    throw new LogicException('Expected an admin error or redirect.');
}
$tests = [
    'fifty-one equal-timestamp records span two nonoverlapping pages' => function () {
        seedLogs(51);
        $first = LogManager::paginate();
        $second = LogManager::paginate(null, 2);
        same(51, $first['total'], 'Total count');
        same(2, $first['total_pages'], 'Page count');
        same(range(51, 2), array_column($first['items'], 'id'), 'First page stable IDs');
        same([1], array_column($second['items'], 'id'), 'Second page IDs');
    },
    'order search reaches older records beyond the former five-hundred cap' => function () {
        seedLogs(501);
        $last = LogManager::paginate(null, 11, 50, 'S-001');
        same(501, $last['total'], 'Uncapped order total');
        same([1], array_column($last['items'], 'id'), 'Oldest order record reachable');
    },
    'order and level filters apply together to count and rows' => function () {
        global $wpdb;
        seedLogs(51);
        $wpdb->rows[] = ['id' => 52, 's_order_id' => 'S-002', 'level' => 'ERROR', 'created_at' => '2026-11-10 00:00:00'];
        $page = LogManager::paginate('ERROR', 2, 20, 'S-001');
        same(26, $page['total'], 'Combined filter total');
        same([11, 9, 7, 5, 3, 1], array_column($page['items'], 'id'), 'Combined filter page');
    },
    'out-of-range pages and page sizes are normalized without losing results' => function () {
        seedLogs(51);
        $last = LogManager::paginate(null, PHP_INT_MAX, 50);
        same(2, $last['page'], 'Oversized page clamped');
        same([1], array_column($last['items'], 'id'), 'Clamped page remains populated');
        same(1, LogManager::paginate(null, -10)['page'], 'Negative page clamped');
        same(1, LogManager::paginate(null, 1, 0)['per_page'], 'Minimum page size');
        same(200, LogManager::paginate(null, 1, 10000)['per_page'], 'Maximum page size');
        $empty = LogManager::paginate(null, 90, 50, 'not-found');
        same([[], 0, 1, 1], [$empty['items'], $empty['total'], $empty['page'], $empty['total_pages']], 'Empty result bounds');
    },
    'clearing requires a confirmed POST from an administrator with a valid nonce' => function () {
        seedLogs(51);
        $confirmed = ['_wpnonce' => 'pga_clear_logs', 'pga_confirm_clear_logs' => '1'];
        same(403, clearLogsThroughAdmin('GET', true, $confirmed)->getCode(), 'GET denied');
        same(403, clearLogsThroughAdmin('POST', false, $confirmed)->getCode(), 'Unauthorized user denied');
        same(403, clearLogsThroughAdmin('POST', true, ['pga_confirm_clear_logs' => '1'])->getCode(), 'Missing nonce denied');
        same(400, clearLogsThroughAdmin('POST', true, ['_wpnonce' => 'pga_clear_logs'])->getCode(), 'Missing confirmation denied');
        same(51, LogManager::paginate()['total'], 'Denied requests preserve logs');

        $result = clearLogsThroughAdmin('POST', true, $confirmed + ['level' => 'ERROR']);
        same('https://wordpress.example.test/wp-admin/admin.php?page=pga-logs&pga_log_clear=success', $result->getMessage(), 'Clear redirects to unfiltered log page');
        same(0, LogManager::paginate()['total'], 'Confirmation clears all levels');
        same(302, clearLogsThroughAdmin('POST', true, $confirmed)->getCode(), 'Empty log clear still succeeds');
    },
    'failed log deletion redirects with an error and retains logs' => function () {
        global $wpdb;
        seedLogs(1);
        $wpdb->canDelete = false;
        $result = clearLogsThroughAdmin('POST', true, ['_wpnonce' => 'pga_clear_logs', 'pga_confirm_clear_logs' => '1']);
        same('https://wordpress.example.test/wp-admin/admin.php?page=pga-logs&pga_log_clear=error', $result->getMessage(), 'Deletion error');
        same(1, LogManager::paginate()['total'], 'Logs remain');
    },
];
$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS {$name}\n"; }
    catch (Throwable $e) { $failures++; fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n"); }
}
echo count($tests) . " tests, {$failures} failures\n";
exit($failures ? 1 : 0);
