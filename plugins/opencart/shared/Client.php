<?php
namespace Sscpay\OpenCart;

use RuntimeException;

final class Client {
    private $transport;
    private string $url;
    private string $app;
    private string $secret;

    public function __construct(string $url, string $app, string $secret, ?callable $transport = null) {
        self::https($url);
        if ($app === '' || $secret === '') {
            throw new RuntimeException('Missing SSCPay credentials.');
        }
        $this->url = rtrim($url, '/');
        $this->app = $app;
        $this->secret = $secret;
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    public static function https(string $url): string {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query'])) {
            throw new RuntimeException('An HTTPS URL without credentials or query is required.');
        }
        return $url;
    }

    public function request(string $action, array $body): array {
        if (!in_array($action, ['create', 'query', 'ship'], true)) {
            throw new RuntimeException('Unsupported API operation.');
        }
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $body['sign'] = Signature::sign($this->app, $timestamp, $nonce, $body, $this->secret);
        $headers = ['App-ID: ' . $this->app, 'Timestamp: ' . $timestamp, 'X-Nonce: ' . $nonce, 'Content-Type: application/json', 'Accept: application/json'];
        $result = call_user_func($this->transport, $this->url . '/api/order/' . $action, $headers,
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $json = json_decode((string)($result['body'] ?? ''), true);
        if (($result['status'] ?? 0) !== 200 || !is_array($json) || ($json['code'] ?? null) !== 0) {
            $code = is_array($json) && is_int($json['code'] ?? null) ? $json['code'] : 0;
            throw new RuntimeException('SSCPay request failed (HTTP ' . (int)($result['status'] ?? 0) . ', code ' . $code . ').');
        }
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    public static function curl(string $url, array $headers, string $body): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($response === false || strlen($response) > 2097152) {
            throw new RuntimeException('SSCPay connection failed. Please retry the same order.');
        }
        return ['status' => $status, 'body' => $response];
    }
}
