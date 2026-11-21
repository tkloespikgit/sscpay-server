<?php

namespace PaymentGatewayAggregator\Helpers;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteCipher
{
    public const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v2:';
    private const LEGACY_OPTION = 'pga_wrapped_legacy_gateway_key';

    public static function protectCallbackSecret(string $secret): array
    {
        return ['sealed' => self::seal($secret, self::siteKey('callback-secret'))];
    }

    public static function readCallbackSecret($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value) || !is_string($value['sealed'] ?? null)) {
            throw new RuntimeException('回调密钥格式无效，请在插件设置中重新保存。');
        }

        return self::open($value['sealed'], self::siteKey('callback-secret'));
    }

    public static function encrypt(string $plaintext): string
    {
        return self::PREFIX . self::seal($plaintext, self::currentKey());
    }

    public static function decrypt(string $encoded): string
    {
        if (strncmp($encoded, self::PREFIX, strlen(self::PREFIX)) === 0) {
            return self::open(substr($encoded, strlen(self::PREFIX)), self::currentKey());
        }

        $key = self::configuredKey();
        if ($key === null) {
            $wrapped = get_option(self::LEGACY_OPTION, false);
            if ($wrapped !== false) {
                $key = self::open((string) $wrapped, self::siteKey('legacy-wrap'));
            } elseif (defined('AUTH_KEY') && AUTH_KEY && defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY) {
                $key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
            }
        }
        if ($key === null || strlen($key) !== 32) {
            throw new RuntimeException('缺少旧版网关凭证密钥，请从升级前备份恢复后运行本站初始化包。');
        }

        return self::open($encoded, $key);
    }

    public static function preserveLegacyKey(): void
    {
        $key = self::configuredKey();
        if ($key === null) {
            return;
        }

        $wrappingKey = self::siteKey('legacy-wrap');
        if (get_option(self::LEGACY_OPTION, false) === false) {
            add_option(self::LEGACY_OPTION, self::seal($key, $wrappingKey), '', false);
        }
        $wrapped = get_option(self::LEGACY_OPTION, false);
        if (!is_string($wrapped) || !hash_equals($key, self::open($wrapped, $wrappingKey))) {
            throw new RuntimeException('旧版网关凭证密钥未能安全保存，请勿升级。');
        }
    }

    private static function currentKey(): string
    {
        return self::configuredKey() ?? self::siteKey('gateway-config');
    }

    private static function configuredKey(): ?string
    {
        if (!defined('PGA_ENCRYPTION_KEY') || PGA_ENCRYPTION_KEY === '') {
            return null;
        }
        $key = is_string(PGA_ENCRYPTION_KEY) ? base64_decode(PGA_ENCRYPTION_KEY, true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('PGA_ENCRYPTION_KEY 必须为 Base64 编码的 32 字节密钥。');
        }

        return $key;
    }

    private static function siteKey(string $purpose): string
    {
        return hash_hmac('sha256', 'sscpay/' . $purpose . '/' . get_current_blog_id(), wp_salt('auth'), true);
    }

    private static function seal(string $plaintext, string $key): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $body = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($body === false) {
            throw new RuntimeException('网关凭证加密失败。');
        }

        return base64_encode($iv . $tag . $body);
    }

    private static function open(string $encoded, string $key): string
    {
        $raw = base64_decode($encoded, true);
        $ivSize = openssl_cipher_iv_length(self::CIPHER);
        if ($raw === false || strlen($raw) < $ivSize + 16) {
            throw new RuntimeException('网关凭证密文格式无效。');
        }
        $plaintext = openssl_decrypt(substr($raw, $ivSize + 16), self::CIPHER, $key, OPENSSL_RAW_DATA,
            substr($raw, 0, $ivSize), substr($raw, $ivSize, 16));
        if ($plaintext === false) {
            throw new RuntimeException('网关凭证解密失败，请核对站点安全盐及旧版密钥备份。');
        }

        return $plaintext;
    }
}
