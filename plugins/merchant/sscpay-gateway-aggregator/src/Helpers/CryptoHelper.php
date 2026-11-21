<?php

namespace PaymentGatewayAggregator\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class CryptoHelper
{
    const CIPHER = SiteCipher::CIPHER;

    public static function encrypt(string $plaintext): string
    {
        return SiteCipher::encrypt($plaintext);
    }

    public static function decrypt(string $encoded): string
    {
        return SiteCipher::decrypt($encoded);
    }

    public static function encryptArray(array $data): string
    {
        return self::encrypt(wp_json_encode($data));
    }

    public static function decryptArray(string $encoded): array
    {
        $data = json_decode(self::decrypt($encoded), true);
        if (!is_array($data)) {
            throw new \RuntimeException('CryptoHelper: 解密结果不是合法JSON对象');
        }

        return $data;
    }

    public static function fingerprint(array $data): string
    {
        ksort($data);

        return hash('sha256', wp_json_encode($data));
    }
}
