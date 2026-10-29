<?php

namespace App\Services\LogisticsTracking\Drivers\SeventeenTrack;

/**
 * 17TRACK Webhook 验签：sign = sha256_hex(rawBody + "/" + secret)。
 * secret 由商户配置注入，不读系统级密钥。
 */
class SeventeenTrackWebhookVerifier
{
    /**
     * 比对请求头 sign 与本地计算值（兼容大小写）；secret 或签名缺失则失败。
     */
    public function verify(string $rawBody, ?string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $expected = hash('sha256', $rawBody.'/'.$secret);

        return hash_equals($expected, strtolower($signatureHeader))
            || hash_equals($expected, $signatureHeader);
    }
}
