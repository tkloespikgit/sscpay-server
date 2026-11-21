<?php

use PaymentGatewayAggregator\API\Controllers\ProductCatalogController;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', dirname(__DIR__) . '/');
define('PGA_VERSION', '1.15.0');
define('HOUR_IN_SECONDS', 3600);
define('WC_API_DEV_MODE', false);

final class WooCommerce {}

final class WP_REST_Request
{
    private $query;
    private $authorization;

    public function __construct(array $query = [], string $authorization = '')
    {
        $this->query = $query;
        $this->authorization = $authorization;
    }

    public function get_param(string $key)
    {
        return $this->query[$key] ?? null;
    }

    public function get_query_params(): array
    {
        return $this->query;
    }

    public function get_header(string $name): string
    {
        return $this->authorization;
    }
}

final class WP_REST_Response
{
    public $data;
    public $headers = [];

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
}

final class ProductCatalogDatabase
{
    public $prefix = 'fixture_';
    public $posts = 'fixture_posts';
    public $postmeta = 'fixture_postmeta';
    public $term_relationships = 'fixture_term_relationships';
    public $term_taxonomy = 'fixture_term_taxonomy';
    public $terms = 'fixture_terms';
    public $permissions = 'read';
    public $lastAccessWrites = 0;
    public $simpleProductName = 'Simple product';

    public function prepare(string $sql, ...$arguments): array
    {
        return [$sql, $arguments];
    }

    public function get_col(array $query): array
    {
        return ['101', '202'];
    }

    public function get_row(array $query)
    {
        return $query[1] === [wc_api_hash('ck_fixture')]
            ? (object) ['key_id' => 9, 'consumer_secret' => 'cs_fixture', 'user_id' => 2, 'permissions' => $this->permissions]
            : null;
    }

    public function update(string $table, array $values, array $where): void
    {
        $this->lastAccessWrites++;
    }

    public function get_results(array $query): array
    {
        [$sql, [$arguments]] = $query;
        if (strpos($sql, 'post_title') !== false) {
            return array_map(function (int $id): object {
                return (object) [
                    'ID' => $id, 'post_title' => $id === 101 ? $this->simpleProductName : 'Variable product',
                    'post_content' => '', 'post_excerpt' => '',
                ];
            }, array_slice($arguments, 0, -1));
        }
        if (strpos($sql, 'SELECT ID, post_parent') !== false) {
            return in_array(202, $arguments, true) ? [(object) ['ID' => 203, 'post_parent' => 202]] : [];
        }
        if (strpos($sql, 'meta_key IN') !== false) {
            return [(object) ['post_id' => $arguments[0], 'meta_key' => '_price', 'meta_value' => '12.34']];
        }

        return [];
    }
}

function add_action(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['catalog_hooks'][$hook] = $callback;
}

function register_rest_route(string $namespace, string $path, array $definition): void
{
    $GLOBALS['catalog_routes'][$namespace . $path][] = $definition;
}

function rest_ensure_response(array $data): WP_REST_Response
{
    $response = new WP_REST_Response();
    $response->data = $data;
    return $response;
}

function get_option(string $name, $default = false)
{
    return $GLOBALS['catalog_options'][$name] ?? $default;
}

function update_option(string $name, string $value, bool $autoload): void
{
    $GLOBALS['catalog_options'][$name] = $value;
}

function get_transient(string $key)
{
    return $GLOBALS['catalog_transients'][$key] ?? false;
}

function set_transient(string $key, array $value, int $expiration): void
{
    $GLOBALS['catalog_transients'][$key] = $value;
}

function wp_generate_uuid4(): string { return 'catalog-generation'; }
function is_ssl(): bool { return $GLOBALS['catalog_https']; }
function sanitize_text_field(string $value): string { return trim($value); }
function wc_api_hash(string $value): string { return hash('sha256', $value); }
function get_user_by(string $field, int $id): object
{
    return new class {
        public function has_cap(string $capability): bool { return $GLOBALS['catalog_manager'] && $capability === 'manage_woocommerce'; }
    };
}
function wp_set_current_user(int $id): void { $GLOBALS['catalog_user'] = $id; }
function current_time(string $type, bool $gmt): string { return '2026-11-20 00:00:00'; }
function absint($value): int { return abs((int) $value); }
function get_permalink(int $id): string { return 'https://store.example.test/?p=' . $id; }
function site_url(): string { return 'https://store.example.test'; }
function get_bloginfo(string $field): string { return 'test-wordpress'; }

function expectSame($expected, $actual, string $description): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($description);
    }
}

require dirname(__DIR__) . '/src/API/Controllers/ProductCatalogController.php';

$wpdb = new ProductCatalogDatabase();
$GLOBALS['catalog_https'] = $GLOBALS['catalog_manager'] = true;
$credentials = ['consumer_key' => 'ck_fixture', 'consumer_secret' => 'cs_fixture'];
ProductCatalogController::registerHooks();
$GLOBALS['catalog_hooks']['rest_api_init']();
expectSame(1, count($GLOBALS['catalog_routes']['wc/v3/products-with-variations']), 'Single product route');
expectSame(1, count($GLOBALS['catalog_routes']['wc/v3/ninox-status']), 'Single compatibility status route');
$route = $GLOBALS['catalog_routes']['wc/v3/products-with-variations'][0];
$statusRoute = $GLOBALS['catalog_routes']['wc/v3/ninox-status'][0];

foreach ([$route, $statusRoute] as $definition) {
    expectSame('GET', $definition['methods'], 'Routes are read-only');
    expectSame(false, $definition['permission_callback'](new WP_REST_Request()), 'Anonymous requests are denied');
}
$permissions = $route['permission_callback'];
expectSame(false, $permissions(new WP_REST_Request(['consumer_key' => ['ck_fixture']])), 'Malformed query credentials are denied');
expectSame(false, $permissions(new WP_REST_Request(array_replace($credentials, ['consumer_secret' => 'wrong']))), 'Invalid secret is denied');
$wpdb->permissions = 'write';
expectSame(false, $permissions(new WP_REST_Request($credentials)), 'Write-only key is denied');
$wpdb->permissions = 'read';
$GLOBALS['catalog_manager'] = false;
expectSame(false, $permissions(new WP_REST_Request($credentials)), 'Key owner must be a WooCommerce manager');
$GLOBALS['catalog_manager'] = true;
$GLOBALS['catalog_https'] = false;
expectSame(false, $permissions(new WP_REST_Request($credentials)), 'HTTPS is required');
$GLOBALS['catalog_https'] = true;
expectSame(0, $wpdb->lastAccessWrites, 'Rejected keys do not update last access');
expectSame(true, $permissions(new WP_REST_Request($credentials)), 'Query credentials accepted');
expectSame(true, $permissions(new WP_REST_Request([], 'ck_fixture:cs_fixture')), 'Legacy plain Authorization accepted');
expectSame(true, $permissions(new WP_REST_Request([], 'Basic ' . base64_encode('ck_fixture:cs_fixture'))), 'Basic authorization accepted');
expectSame(3, $wpdb->lastAccessWrites, 'Successful keys update last access');
expectSame(2, $GLOBALS['catalog_user'], 'Authenticated context uses the key owner');
echo "PASS WooCommerce credentials and permissions\n";

$fetch = $route['callback'];
$first = $fetch(new WP_REST_Request($credentials + ['per_page' => '1']));
expectSame([101], array_column($first->data, 'id'), 'Stable first page');
expectSame('12.34', $first->data[0]['price'], 'Price remains a string');
expectSame('2', $first->headers['X-WP-TotalPages'], 'Fresh response has pagination');
$cached = $fetch(new WP_REST_Request(['per_page' => '1', 'ignored_request_parameter' => 'ignored']));
expectSame('cache', $cached->headers['X-Data-Source'], 'Non-paging arguments do not change the cache key');
expectSame('2', $cached->headers['X-WP-TotalPages'], 'Cached response has pagination');
expectSame('private, no-store', $cached->headers['Cache-Control'], 'Authenticated catalog is not public-cacheable');
$second = $fetch(new WP_REST_Request(['page' => '2', 'per_page' => '1']));
expectSame([202], array_column($second->data, 'id'), 'Distinct second page');
expectSame([203], array_column($second->data[0]['variations'], 'id'), 'Variable product still includes variations');
expectSame([], $fetch(new WP_REST_Request(['page' => '3', 'per_page' => '1']))->data, 'Out-of-range page is an empty array');
$wpdb->simpleProductName = 'Refreshed product';
$fresh = $fetch(new WP_REST_Request(['per_page' => '1', 'force_refresh' => '1']));
expectSame('Refreshed product', $fresh->data[0]['name'], 'Laravel-style boolean bypasses cache');
$wpdb->simpleProductName = 'Updated product';
$GLOBALS['catalog_hooks']['woocommerce_update_product_variation']();
expectSame('Updated product', $fetch(new WP_REST_Request(['per_page' => '1']))->data[0]['name'], 'Product variation update invalidates cache');
echo "PASS Product and variation pagination, refresh and cache\n";

expectSame('sscpay-products-api', $statusRoute['callback']()->data['plugin'], 'Legacy status identifier is retained');
expectSame('2.0.0', $statusRoute['callback']()->data['plugin_version'], 'Legacy catalog protocol version is retained');
class_alias(WooCommerce::class, 'WCPVA_Product_Variations_API');
unset($GLOBALS['catalog_hooks']['rest_api_init']);
ProductCatalogController::registerHooks();
expectSame(false, isset($GLOBALS['catalog_hooks']['rest_api_init']), 'Legacy active plugin prevents overlapping route registration');
echo "PASS Legacy status and staged upgrade compatibility\n";
