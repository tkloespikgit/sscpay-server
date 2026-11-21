<?php

use PaymentGatewayAggregator\Core\OutboundSigner;
use PaymentGatewayAggregator\Helpers\CryptoHelper;

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    exit(1);
}

define('ABSPATH', $argv[2] . '/');
$state = ABSPATH . 'site-state.json';
$options = is_file($state) ? json_decode(file_get_contents($state), true) : [];
$salt = 'isolated-wordpress-salt-for-tests';

function wp_salt(string $scheme): string { return $GLOBALS['salt']; }
function get_current_blog_id(): int { return 1; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; }
function add_option($name, $value, $deprecated, $autoload) {
    if (array_key_exists($name, $GLOBALS['options'])) { return false; }
    update_option($name, $value, $autoload);
    return true;
}
function wp_die($message) { throw new RuntimeException($message); }
function register_activation_hook($file, $callback) { $GLOBALS['activate'] = $callback; }
function add_filter($name, $callback) { $GLOBALS['filters'][$name] = $callback; }
function expect(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

if ($argv[1] === 'legacy') {
    define('PGA_VERSION', '1.15.0');
    $key = random_bytes(32);
    define('PGA_ENCRYPTION_KEY', base64_encode($key));
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt('previous credentials', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    $options['transaction'] = base64_encode($iv . $tag . $ciphertext);
    require ABSPATH . 'sscpay-gateway-site-setup/site-setup.php';
    $prepare = $GLOBALS['filters']['puc_request_info_query_args-sscpay-gateway-aggregator'];
    expect($prepare([]) === [], 'A legacy site must not opt in before initialization.');
    $activate();
    expect($prepare([]) === ['site_prepared' => '1'], 'Legacy updater must only send a non-secret readiness flag.');
    $backup = $options['pga_wrapped_legacy_gateway_key'];
    $activate();
    expect($backup === $options['pga_wrapped_legacy_gateway_key'], 'A second activation must preserve the existing backup.');
    expect(strpos(json_encode($options), base64_encode($key)) === false, 'Legacy key must not be saved in plaintext.');
    expect(strpos(json_encode($options), 'synthetic-private-default-for-setup-test') === false, 'The default must not be exposed in site options.');
    file_put_contents($state, json_encode($options));
    exit(0);
}

require ABSPATH . 'sscpay-gateway-aggregator/vendor/autoload.php';
expect(OutboundSigner::configuredSecret() === 'synthetic-private-default-for-setup-test', 'The private default survives removal of the initializer.');
update_option(OutboundSigner::SECRET_OPTION, 'replacement');
expect(OutboundSigner::configuredSecret() === 'replacement', 'Site override wins after public upgrade.');
expect(CryptoHelper::decrypt($options['transaction']) === 'previous credentials', 'Old transaction is readable without the old plugin or constant.');
$ciphertext = CryptoHelper::encrypt('new credentials');
unset($options['pga_wrapped_legacy_gateway_key']);
expect(CryptoHelper::decrypt($ciphertext) === 'new credentials', 'New data does not use the legacy key.');
$salt = 'a-different-site';
try {
    CryptoHelper::decrypt($ciphertext);
} catch (RuntimeException $expected) {
    exit(0);
}
throw new RuntimeException('Another site must not decrypt the new credentials.');
