<?php

use PaymentGatewayAggregator\Core\UpdateChecker;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', dirname(__DIR__) . '/');
define('WP_PLUGIN_DIR', dirname(__DIR__, 2));
define('WPMU_PLUGIN_DIR', WP_PLUGIN_DIR . '/mu-plugins');
define('WP_DEBUG', false);
require dirname(__DIR__) . '/vendor/autoload.php';

$hooks = [];
$siteOptions = ['external_updates-sscpay-gateway-aggregator' => 'stale metadata'];
$clearedTransients = [];
function add_filter($name, $callback, $priority = 10, $accepted = 1) { $GLOBALS['hooks'][$name][$priority][] = [$callback, $accepted]; }
function add_action($name, $callback, $priority = 10, $accepted = 1) { add_filter($name, $callback, $priority, $accepted); }
function remove_filter($name, $callback, $priority = 10)
{
    $GLOBALS['hooks'][$name][$priority] = array_filter($GLOBALS['hooks'][$name][$priority] ?? [], static function ($entry) use ($callback) {
        return $entry[0] !== $callback;
    });
}
function apply_filters($name, $value, ...$args)
{
    $priorities = $GLOBALS['hooks'][$name] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice([$value, ...$args], 0, $accepted));
        }
    }

    return $value;
}
function did_action($name) { return 0; }
function is_admin() { return false; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function plugin_basename($path) { return basename(dirname($path)) . '/' . basename($path); }
function wp_next_scheduled($name) { return 1; }
function register_deactivation_hook($plugin, $callback) {}
function get_site_option($name, $default = false) { return $GLOBALS['siteOptions'][$name] ?? $default; }
function update_site_option($name, $value) { $GLOBALS['siteOptions'][$name] = $value; }
function delete_site_option($name) { unset($GLOBALS['siteOptions'][$name]); }
function delete_site_transient($name) { $GLOBALS['clearedTransients'][] = $name; }

function expect(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

$pluginFile = ABSPATH . 'sscpay-gateway-aggregator.php';
expect(UpdateChecker::sourceUrl() === 'https://www.ssecurepay.com/plugins/gateway/updates/v1', 'Default update server.');
UpdateChecker::init($pluginFile);
expect(UpdateChecker::instance() !== null, 'The default feed starts without credentials.');
expect(!get_site_option('external_updates-sscpay-gateway-aggregator'), 'Old updater cache is cleared.');
expect($clearedTransients === ['update_plugins'], 'WordPress clears the old update offer.');

define('PGA_UPDATE_REPO_URL', 'https://GitHub.com/example-org/gateway-releases/');
expect(UpdateChecker::sourceUrl() === 'https://github.com/example-org/gateway-releases', 'Explicit legacy GitHub override.');

update_site_option(UpdateChecker::BASE_OPTION, 'https://Updates.Example.test:443/');
expect(UpdateChecker::sourceUrl() === 'https://updates.example.test/plugins/gateway/updates/v1', 'Dashboard override precedes the legacy repository.');
define('PGA_UPDATE_BASE_URL', 'https://override.example.test');
expect(UpdateChecker::sourceUrl() === 'https://override.example.test/plugins/gateway/updates/v1', 'Explicit server override takes precedence.');
$source = 'https://www.ssecurepay.com/plugins/gateway/updates/v1';
$package = $source . '/1.16.0/download';
$checker = UpdateChecker::instance();
expect(!apply_filters('http_request_host_is_external', false, 'www.ssecurepay.com'), 'The update host does not bypass WordPress safe URL validation.');

$headers = ['headers' => ['Accept' => 'application/json'], 'method' => 'GET'];
foreach ([$source . '?installed_version=1.15.1', $package] as $url) {
    $request = apply_filters('http_request_args', $headers, $url);
    expect($request['headers'] === $headers['headers'], 'No credentials are added to the public update requests.');
    expect($request['sslverify'] === true && $request['redirection'] === 0 && $request['reject_unsafe_urls'] === true, 'Requests require TLS without redirects.');
}

foreach (['http://updates.example.test/plugins/gateway/updates/v1', 'https://other.example.test/plugins/gateway/updates/v1', $source . '/unrelated', $package . '?extra=1'] as $url) {
    expect(apply_filters('http_request_args', $headers, $url) === $headers, 'Unrelated requests are unchanged.');
}

$releaseFilter = $checker->getUniqueName('request_info_result');
$release = (object) ['version' => '1.16.0', 'download_url' => $package];
expect(apply_filters($releaseFilter, $release, []) === $release, 'Accept a version-specific package on the update host.');
$release->download_url = 'https://other.example.test/gateway.zip';
expect(apply_filters($releaseFilter, $release, []) === null, 'Reject a package on a different host.');
echo "PASS configurable public gateway updates without credentials\n";
