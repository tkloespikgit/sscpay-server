<?php
/**
 * Run: php tests/proxy-config.php.
 * Requires ext-curl; local handshake checks also require pcntl and permission to bind loopback TCP.
 * Synthetic proxy servers terminate every request locally and never contact payment gateways.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "This test requires ext-curl.\n");
    exit(1);
}

define('ABSPATH', dirname(__DIR__) . '/');
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_get($url, $args)
{
    $GLOBALS['proxy_test_direct_calls'][] = compact('url', 'args');
    return ['response' => ['code' => 200], 'body' => '{"direct":true}'];
}
function wp_remote_post($url, $args) { return wp_remote_get($url, $args); }

require dirname(__DIR__) . '/vendor/autoload.php';

use PaymentGatewayAggregator\Gateways\StripeGateway;
use PaymentGatewayAggregator\Helpers\HttpClient;
use PaymentGatewayAggregator\Helpers\ProxyConfig;

set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

function same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function stripeProxyClient($proxy)
{
    $configure = new ReflectionMethod(StripeGateway::class, 'configureStripeProxy');
    $configure->setAccessible(true);
    $configure->invoke(null, $proxy);
    return \Stripe\ApiRequestor::httpClient();
}
function readBytes($stream, $length)
{
    $bytes = '';
    while (strlen($bytes) < $length) {
        $chunk = fread($stream, $length - strlen($bytes));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Synthetic proxy received incomplete handshake');
        }
        $bytes .= $chunk;
    }
    return $bytes;
}
function readHeaders($stream)
{
    $headers = '';
    while (strpos($headers, "\r\n\r\n") === false) {
        $headers .= readBytes($stream, 1);
        check(strlen($headers) < 32768, 'Synthetic request headers exceeded limit');
    }
    return $headers;
}
function withSyntheticProxy($protocol, $callback)
{
    // Do not fork with a live SDK curl handle: libcurl resolver threads cannot survive fork.
    stripeProxyClient(null);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    check($listener !== false, 'Cannot bind synthetic proxy: ' . $error);
    $address = stream_socket_get_name($listener, false);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    check($pair !== false, 'Cannot create proxy observation channel');
    $pid = pcntl_fork();
    check($pid >= 0, 'Cannot fork synthetic proxy');
    if ($pid === 0) {
        fclose($pair[0]);
        $observation = [];
        try {
            $socket = stream_socket_accept($listener, 5);
            check($socket !== false, 'No connection reached synthetic proxy');
            stream_set_timeout($socket, 5);
            if ($protocol === 'http') {
                $observation['connect'] = readHeaders($socket);
                fwrite($socket, "HTTP/1.1 200 Connection established\r\n\r\n");
            } else {
                $greeting = readBytes($socket, 2);
                same(5, ord($greeting[0]), 'SOCKS version');
                $methods = readBytes($socket, ord($greeting[1]));
                check(strpos($methods, "\x02") !== false, 'SOCKS username/password authentication offered');
                fwrite($socket, "\x05\x02");
                $auth = readBytes($socket, 2);
                same(1, ord($auth[0]), 'SOCKS authentication version');
                $observation['username'] = readBytes($socket, ord($auth[1]));
                $passwordLength = ord(readBytes($socket, 1));
                $observation['password'] = readBytes($socket, $passwordLength);
                fwrite($socket, "\x01\x00");
                $request = readBytes($socket, 4);
                same("\x05\x01\x00", substr($request, 0, 3), 'SOCKS CONNECT request');
                $observation['address_type'] = ord($request[3]);
                if ($observation['address_type'] === 3) {
                    $observation['target'] = readBytes($socket, ord(readBytes($socket, 1)));
                } elseif ($observation['address_type'] === 1) {
                    $observation['target'] = inet_ntop(readBytes($socket, 4));
                } else {
                    throw new RuntimeException('Unexpected SOCKS address type');
                }
                $observation['port'] = unpack('nport', readBytes($socket, 2))['port'];
                fwrite($socket, "\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x50");
            }
            $observation['request'] = readHeaders($socket);
            $body = '{"via_proxy":true}';
            fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
            fclose($socket);
        } catch (Throwable $failure) {
            $observation['error'] = $failure->getMessage();
        }
        fwrite($pair[1], json_encode($observation));
        fclose($pair[1]);
        fclose($listener);
        exit(0);
    }

    fclose($pair[1]);
    fclose($listener);
    $failure = null;
    $result = null;
    try {
        $result = $callback($port);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        pcntl_waitpid($pid, $status);
        $observation = json_decode(stream_get_contents($pair[0]), true);
        fclose($pair[0]);
    }
    check(is_array($observation), 'Synthetic proxy returned an observation');
    check(!isset($observation['error']), 'Synthetic proxy: ' . ($observation['error'] ?? ''));
    if ($failure !== null) {
        throw $failure;
    }
    return [$result, $observation];
}

$tests = [];
$tests['empty proxy preserves direct WordPress transport'] = function () {
    $GLOBALS['proxy_test_direct_calls'] = [];
    foreach ([null, ''] as $proxy) {
        same([], ProxyConfig::curlOptions($proxy), 'No proxy options');
        same(true, HttpClient::getJson('https://example.invalid/direct', [], 2, $proxy)['body']['direct'], 'WordPress transport used');
    }
    same(2, count($GLOBALS['proxy_test_direct_calls']), 'Direct requests recorded');
};
$tests['HTTP SOCKS5 and SOCKS5H map to the correct proxy protocol and credentials'] = function () {
    foreach (['http' => CURLPROXY_HTTP, 'socks5' => CURLPROXY_SOCKS5, 'socks5h' => CURLPROXY_SOCKS5_HOSTNAME] as $scheme => $type) {
        $options = ProxyConfig::curlOptions($scheme . '://account%40test:pass%3Aword%2F%2B@127.0.0.1:1080');
        same('127.0.0.1', $options[CURLOPT_PROXY], 'Proxy host');
        same(1080, $options[CURLOPT_PROXYPORT], 'Proxy port');
        same($type, $options[CURLOPT_PROXYTYPE], 'Proxy protocol');
        same($scheme === 'http', $options[CURLOPT_HTTPPROXYTUNNEL], 'HTTP CONNECT applies only to HTTP proxy');
        same('account@test:pass:word/+', $options[CURLOPT_PROXYUSERPWD], 'URL encoded credentials decoded');
        same('', $options[CURLOPT_NOPROXY], 'Explicit proxy overrides environment bypass');
    }
};
$tests['HTTP remains compatible with schemeless authority and optional credentials'] = function () {
    $options = ProxyConfig::curlOptions('//localhost:8080');
    same(CURLPROXY_HTTP, $options[CURLOPT_PROXYTYPE], 'Legacy authority uses HTTP');
    same(true, $options[CURLOPT_HTTPPROXYTUNNEL], 'Legacy CONNECT retained');
    check(!isset($options[CURLOPT_PROXYUSERPWD]), 'No invented authentication');
    same('account:', ProxyConfig::curlOptions('socks5://account@localhost:1080')[CURLOPT_PROXYUSERPWD], 'Empty password supported');
};
$tests['invalid proxies fail without exposing the URL username or password'] = function () {
    foreach ([
        'https://audit-user:audit-password@localhost:1080',
        'socks4://audit-user:audit-password@localhost:1080',
        'socks5://audit-user:audit-password@localhost',
        'socks5://audit-user:audit-password@localhost:0',
        'socks5://audit-user:audit-password@localhost:65536',
        'socks5://audit-user:audit-password@localhost:not-a-port',
        'socks5://audit-user:audit-password@localhost:1080/not-a-proxy-path',
        'socks5://audit-user:audit-password@localhost:1080?ignored=true',
        'socks5://audit-user:audit-password@localhost:1080#ignored',
        'not a proxy',
        '0',
        0,
        [],
    ] as $proxy) {
        try {
            ProxyConfig::curlOptions($proxy);
            throw new RuntimeException('Invalid proxy was accepted');
        } catch (InvalidArgumentException $error) {
            $secrets = ['audit-user', 'audit-password'];
            if (is_string($proxy)) {
                $secrets[] = $proxy;
            }
            foreach ($secrets as $secret) {
                check(strpos($error->getMessage(), $secret) === false, 'Error exposes proxy information');
            }
        }
    }
};
$tests['invalid HttpClient proxy fails closed without direct fallback or credential leakage'] = function () {
    $GLOBALS['proxy_test_direct_calls'] = [];
    $requests = [
        function ($proxy) { return HttpClient::getJson('https://example.invalid/test', [], 2, $proxy); },
        function ($proxy) { return HttpClient::postJson('https://example.invalid/test', [], [], 2, $proxy); },
        function ($proxy) { return HttpClient::postForm('https://example.invalid/test', [], [], 2, $proxy); },
        function ($proxy) { return HttpClient::postRawJson('https://example.invalid/test', '{}', [], 2, $proxy); },
    ];
    foreach ($requests as $request) {
        foreach (['0', 'socks4://audit-user:audit-password@localhost:1080'] as $proxy) {
            $result = $request($proxy);
            same(false, $result['ok'], 'Invalid proxy fails');
            same(0, $result['status'], 'No upstream HTTP response');
            check(strpos($result['error'], 'audit-user') === false && strpos($result['error'], 'audit-password') === false, 'Returned error hides credentials');
        }
    }
    same([], $GLOBALS['proxy_test_direct_calls'], 'No direct fallback');
};
$tests['Stripe uses shared options and resets previous proxy configuration'] = function () {
    foreach (['http', 'socks5', 'socks5h'] as $scheme) {
        $proxy = $scheme . '://account:password@localhost:1080';
        same(ProxyConfig::curlOptions($proxy), stripeProxyClient($proxy)->getDefaultOptions(), 'Stripe transport options');
    }
    same([], stripeProxyClient(null)->getDefaultOptions(), 'Stripe direct configuration resets proxy');
};
$tests['Stripe preserves RuntimeException error handling without exposing proxy credentials'] = function () {
    try {
        stripeProxyClient('socks4://audit-user:audit-password@localhost:1080');
        throw new LogicException('Invalid Stripe proxy was accepted');
    } catch (RuntimeException $error) {
        check($error->getPrevious() instanceof InvalidArgumentException, 'Original helper exception retained');
        check(strpos($error->getMessage(), 'audit-user') === false && strpos($error->getMessage(), 'audit-password') === false, 'Stripe error hides credentials');
    }
};

if (extension_loaded('pcntl')) {
    foreach (['http', 'socks5', 'socks5h'] as $scheme) {
        foreach (['HttpClient', 'Stripe'] as $transport) {
            $tests[$transport . ' completes authenticated ' . $scheme . ' handshake with real libcurl'] = function () use ($scheme, $transport) {
                // An explicit proxy must still be used when the host environment bypasses every proxy.
                $oldNoProxy = getenv('no_proxy');
                $oldUpperNoProxy = getenv('NO_PROXY');
                putenv('no_proxy=*');
                putenv('NO_PROXY=*');
                try {
                    list($result, $seen) = withSyntheticProxy($scheme, function ($port) use ($scheme, $transport) {
                        $proxy = $scheme . '://account%40test:pass%3Aword@127.0.0.1:' . $port;
                        $host = $scheme === 'socks5' ? '127.0.0.1' : 'example.invalid';
                        $url = 'http://' . $host . '/synthetic';
                        if ($transport === 'HttpClient') {
                            $response = HttpClient::getJson($url, [], 3, $proxy);
                            same(true, $response['ok'], 'Proxied HttpClient response');
                            return $response['body'];
                        }
                        $client = stripeProxyClient($proxy);
                        list($body, $status) = $client->request('get', $url, [], [], false);
                        same(200, $status, 'Proxied Stripe response');
                        return json_decode($body, true);
                    });
                    same(['via_proxy' => true], $result, 'Local proxy supplied response');
                    check(strpos($seen['request'], 'GET /synthetic HTTP/') === 0, 'Origin request sent through tunnel');
                    if ($scheme === 'http') {
                        check(strpos($seen['connect'], 'CONNECT example.invalid:80 HTTP/') === 0, 'HTTP CONNECT handshake');
                        check(stripos($seen['connect'], 'Proxy-Authorization: Basic ' . base64_encode('account@test:pass:word')) !== false, 'HTTP proxy credentials');
                    } else {
                        same('account@test', $seen['username'], 'SOCKS username');
                        same('pass:word', $seen['password'], 'SOCKS password');
                        same($scheme === 'socks5' ? 1 : 3, $seen['address_type'], 'SOCKS DNS resolution location');
                        same($scheme === 'socks5' ? '127.0.0.1' : 'example.invalid', $seen['target'], 'SOCKS target');
                        same(80, $seen['port'], 'SOCKS target port');
                    }
                } finally {
                    putenv($oldNoProxy === false ? 'no_proxy' : 'no_proxy=' . $oldNoProxy);
                    putenv($oldUpperNoProxy === false ? 'NO_PROXY' : 'NO_PROXY=' . $oldUpperNoProxy);
                }
            };
        }
    }
} else {
    echo "SKIP local handshake checks: pcntl is unavailable.\n";
}

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}
echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
