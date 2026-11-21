<?php
/** Log pagination storage regressions; isolated rows, no WordPress bootstrap/database. */
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/vendor/autoload.php';

use PaymentGatewayAggregator\Core\LogManager;

class LogPageDb
{
    public $prefix = 'isolated_';
    public $rows = [];
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
];
$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS {$name}\n"; }
    catch (Throwable $e) { $failures++; fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n"); }
}
echo count($tests) . " tests, {$failures} failures\n";
exit($failures ? 1 : 0);
