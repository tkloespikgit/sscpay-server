<?php
namespace Sscpay\OpenCart;

use RuntimeException;

final class Signature {
    public static function canonical(array $body): string {
        unset($body['sign']);
        return json_encode(self::sort($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sort($value) {
        if (!is_array($value)) {
            return $value;
        }
        $list = !$value || array_keys($value) === range(0, count($value) - 1);
        foreach ($value as $key => $item) {
            $value[$key] = self::sort($item);
        }
        if (!$list) {
            ksort($value);
        }
        return $value;
    }

    public static function sign(string $app, string $timestamp, string $nonce, array $body, string $secret): string {
        return hash_hmac('sha256', $app . "\n" . $timestamp . "\n" . $nonce . "\n" . self::canonical($body), $secret);
    }

    public static function verify(array $body, array $headers, string $app, string $secret, ?int $now = null): void {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = (string)($headers['timestamp'] ?? '');
        $nonce = (string)($headers['x-nonce'] ?? '');
        if ($app === '' || $secret === '' || !hash_equals($app, (string)($headers['app-id'] ?? ''))
            || !preg_match('/^\d{10}$/D', $timestamp) || abs(($now ?? time()) - (int)$timestamp) > 300
            || !preg_match('/^[a-zA-Z0-9_-]{8,128}$/D', $nonce)
            || !is_string($body['sign'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $body['sign'])
            || !hash_equals(self::sign($app, $timestamp, $nonce, $body, $secret), $body['sign'])) {
            throw new RuntimeException('Invalid notification signature.');
        }
    }
}
