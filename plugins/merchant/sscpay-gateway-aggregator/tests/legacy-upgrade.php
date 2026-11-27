<?php

use PaymentGatewayAggregator\Core\OutboundSigner;
use PaymentGatewayAggregator\Helpers\CryptoHelper;
use PaymentGatewayAggregator\Helpers\SiteCipher;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', dirname(__DIR__) . '/');
define('WP_CLI', true);
const TEST_DEFAULT = 'synthetic-default-for-upgrade-test';
$options = [];
$salt = 'isolated-legacy-upgrade-test';
function wp_salt($scheme) { return $GLOBALS['salt']; }
function get_current_blog_id() { return 1; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; }
function add_option($name, $value, $deprecated, $autoload) {
    if (array_key_exists($name, $GLOBALS['options'])) { return false; }
    update_option($name, $value, $autoload);
    return true;
}
function expect(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
class WP_CLI
{
    public static function success(string $message): void
    {
        expect(strpos($message, TEST_DEFAULT) === false, 'CLI disclosed the callback default.');
        if (defined('PGA_ENCRYPTION_KEY')) {
            expect(strpos($message, PGA_ENCRYPTION_KEY) === false, 'CLI disclosed the gateway encryption key.');
        }
    }

    public static function error(string $message): void
    {
        throw new RuntimeException($message);
    }
}

$prepare = ABSPATH . 'src/prepare-legacy-upgrade.php';
if (($argv[1] ?? '') === 'prepare') {
    define('PGA_VERSION', '1.15.1');
    $key = random_bytes(32);
    define('PGA_ENCRYPTION_KEY', base64_encode($key));
    define('PGA_DEFAULT_OUTBOUND_SHARED_SECRET', TEST_DEFAULT);
    $iv = random_bytes(12);
    $tag = '';
    $body = openssl_encrypt('old transaction credentials', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    $options['transaction'] = base64_encode($iv . $tag . $body);
    $options['pga_outbound_shared_secret'] = 'synthetic-site-override';

    require $prepare;
    $firstRun = $options;
    require $prepare;
    expect($options === $firstRun, 'A repeated preparation must leave existing values intact.');
    $serialized = json_encode($options, JSON_THROW_ON_ERROR);
    expect(strpos($serialized, PGA_ENCRYPTION_KEY) === false && strpos($serialized, TEST_DEFAULT) === false, 'Migration stored an unencrypted credential.');
    $options['pga_default_outbound_shared_secret'] = SiteCipher::protectCallbackSecret('earlier-provisioned-value');
    require $prepare;
    expect(SiteCipher::readCallbackSecret($options['pga_default_outbound_shared_secret']) === 'earlier-provisioned-value', 'Existing provisioned default was overwritten.');
    echo $serialized;
    exit(0);
}

try {
    require $prepare;
    throw new LogicException('An inactive legacy plugin must block preparation.');
} catch (RuntimeException $expected) {
    expect($options === [], 'Preparing an inactive plugin modified site options.');
}
define('PGA_VERSION', '1.15.1');
require $prepare;
expect($options === [], 'An older site without embedded keys must not acquire a signing default.');

// Simulate replacing a plugin whose credential constants disappear between requests.
$process = proc_open([PHP_BINARY, __FILE__, 'prepare'], [1 => ['pipe', 'w']], $pipes);
expect(is_resource($process), 'Could not start the legacy site.');
$state = stream_get_contents($pipes[1]);
fclose($pipes[1]);
expect(proc_close($process) === 0, 'Legacy site preparation failed.');
$options = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
require ABSPATH . 'vendor/autoload.php';

expect(OutboundSigner::configuredSecret() === 'synthetic-site-override', 'The site override must take precedence.');
unset($options[OutboundSigner::SECRET_OPTION]);
expect(OutboundSigner::sign('test event') === hash_hmac('sha256', 'test event', TEST_DEFAULT), 'The preserved default did not sign the event.');
expect(CryptoHelper::decrypt($options['transaction']) === 'old transaction credentials', 'The upgraded site lost its legacy transaction credentials.');
$ciphertext = CryptoHelper::encrypt('new credentials');
unset($options['pga_wrapped_legacy_gateway_key']);
expect(CryptoHelper::decrypt($ciphertext) === 'new credentials', 'New transactions must use the site key.');
$salt = 'a-different-site';
try {
    CryptoHelper::decrypt($ciphertext);
} catch (RuntimeException $expected) {
    echo "PASS legacy site preparation and single-package upgrade\n";
    exit(0);
}
throw new RuntimeException('A different site must not decrypt the new credentials.');
