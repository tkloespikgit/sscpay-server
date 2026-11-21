<?php

use PaymentGatewayAggregator\Admin\SettingsPage;
use PaymentGatewayAggregator\Core\OutboundSigner;
use PaymentGatewayAggregator\Core\UpdateChecker;
use PaymentGatewayAggregator\Helpers\SiteCipher;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', dirname(__DIR__) . '/');
require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$siteOptions = [];
$canUpdate = true;
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function wp_salt($scheme) { return 'admin-settings-test-salt'; }
function get_current_blog_id() { return 1; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; }
function add_option($name, $value, $deprecated, $autoload) { update_option($name, $value, $autoload); return true; }
function get_site_option($name, $default = false) { return $GLOBALS['siteOptions'][$name] ?? $default; }
function update_site_option($name, $value) { $GLOBALS['siteOptions'][$name] = $value; }
function delete_site_option($name) { unset($GLOBALS['siteOptions'][$name]); }
function current_user_can($capability) { return $capability === 'manage_options' || ($capability === 'update_plugins' && $GLOBALS['canUpdate']); }
function check_admin_referer($action) { return ($_POST['_wpnonce'] ?? '') === $action; }
function wp_unslash($value) { return stripslashes($value); }
function __($message, $domain) { return $message; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html__($message, $domain) { return esc_html(__($message, $domain)); }
function esc_html_e($message, $domain) { echo esc_html__($message, $domain); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function admin_url($path) { return 'https://wordpress.example.test/wp-admin/' . $path; }
function rest_url($path) { return 'https://wordpress.example.test/wp-json/' . ltrim($path, '/'); }
function wp_nonce_url($url, $action) { return $url; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($action) . '">'; }
function settings_fields($group) {}
function selected($current, $value) {}
function submit_button($text = '', $type = 'primary') {}
function add_query_arg($args, $valueOrUrl, $url = null)
{
    $target = $url ?? $valueOrUrl;

    return $target . (strpos($target, '?') === false ? '?' : '&')
        . http_build_query(is_array($args) ? $args : [$args => $valueOrUrl]);
}
function wp_die($message, $title, $args) { throw new RuntimeException($message, $args['response']); }
function wp_safe_redirect($url) { throw new RuntimeException($url, 302); }

function expect(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function captureSettings(): string
{
    ob_start();
    try {
        SettingsPage::render();

        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

$default = 'fake-private-bootstrap-default';
expect(OutboundSigner::configuredSecret() === '', 'A public install has no shared signing credential.');
update_option(OutboundSigner::DEFAULT_OPTION, SiteCipher::protectCallbackSecret($default), false);
$html = captureSettings();
expect(OutboundSigner::configuredSecret() === $default, 'Default is active without a stored option.');
expect(strpos($html, $default) === false, 'Default secret leaked into settings markup.');
expect(strpos($html, 'name="pga_outbound_shared_secret"') !== false, 'Replacement input is missing.');
expect(strpos($html, 'API认证方式') === false, 'Removed authentication instructions still render.');

$_SERVER['REQUEST_METHOD'] = 'POST';
$replacement = 'fake-replacement-for-testing';
$_POST = ['_wpnonce' => 'pga_save_secret', 'pga_outbound_shared_secret' => $replacement];
try {
    SettingsPage::handleSaveSecret();
} catch (RuntimeException $result) {
    expect($result->getCode() === 302 && strpos($result->getMessage(), 'pga_secret_saved=custom') !== false, 'Secret save did not redirect with success.');
}

expect(OutboundSigner::configuredSecret() === $replacement, 'Site override did not take precedence over the default.');
expect(strpos(json_encode($options), $default) === false && strpos(json_encode($options), $replacement) === false, 'Neither provisioned nor edited credential may be persisted in plaintext.');
expect(strpos(captureSettings(), $replacement) === false, 'Saved secret leaked into settings markup.');
$_POST = ['_wpnonce' => 'pga_save_update_source', UpdateChecker::BASE_OPTION => 'https://UPDATES.example.test/'];
function submitSource(int $expected): void
{
    try {
        SettingsPage::handleSaveUpdateSource();
    } catch (RuntimeException $result) {
        expect($result->getCode() === $expected, 'Unexpected update settings response.');
        return;
    }
    throw new RuntimeException('Missing update settings response.');
}
$canUpdate = false;
submitSource(403);
expect(strpos(captureSettings(), 'name="pga_update_base_url"') === false, 'Site administrators without update rights cannot edit the source.');
$canUpdate = true;
$_POST['_wpnonce'] = 'invalid';
submitSource(403);
$_POST['_wpnonce'] = 'pga_save_update_source';
submitSource(302);
expect(UpdateChecker::sourceUrl() === 'https://updates.example.test/plugins/gateway/updates/v1', 'Saved HTTPS origin is active.');
foreach (['http://example.test', 'https://user:pass@example.test', 'https://example.test/path', 'https://example.test/?token=x', ['bad']] as $invalid) {
    $_POST[UpdateChecker::BASE_OPTION] = $invalid;
    submitSource(400);
    expect(get_site_option(UpdateChecker::BASE_OPTION) === 'https://updates.example.test', 'Invalid origin must not replace the current setting.');
}
$_POST[UpdateChecker::BASE_OPTION] = '';
submitSource(302);
expect(UpdateChecker::sourceUrl() === 'https://www.ssecurepay.com/plugins/gateway/updates/v1', 'Blank restores the public default.');
echo "PASS callback secret defaults, replacement and redaction\n";
