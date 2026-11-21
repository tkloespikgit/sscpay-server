<?php
/**
 * 插件转发给S系统的出站回调（payment_status/tracking_sync事件）签名。
 * S系统据此验证"这个回调真的来自插件"，防止被伪造（评审第5点）。
 *
 * 签名方案：HMAC-SHA256(shared_secret, raw_json_body)，放入请求头
 * X-PGA-Signature，S系统拿到原始请求体字节后用同样的算法和共享密钥重新计算，
 * 与header比对（应使用常量时间比较，S系统那边实现时需注意）。
 *
 * 共享密钥在后台保存，未保存时可使用站点的 PGA_DEFAULT_OUTBOUND_SHARED_SECRET。
 * 需要和S系统配置同一份密钥。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class OutboundSigner
{
    const HEADER_NAME = 'X-PGA-Signature';

    public const SECRET_OPTION = 'pga_outbound_shared_secret';

    public static function configuredSecret(): string
    {
        $configured = get_option(self::SECRET_OPTION, '');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $default = defined('PGA_DEFAULT_OUTBOUND_SHARED_SECRET') ? PGA_DEFAULT_OUTBOUND_SHARED_SECRET : '';

        return is_string($default) ? $default : '';
    }

    /**
     * @param string $rawJsonBody 已经序列化好的JSON字符串（不是数组）
     * @return string hex编码的HMAC-SHA256签名
     * @throws \RuntimeException 未配置共享密钥
     */
    public static function sign($rawJsonBody)
    {
        $secret = self::secret();
        return hash_hmac('sha256', $rawJsonBody, $secret);
    }

    /**
     * 供插件自身的单测/调试使用，正常流程里S系统会自己验证，插件不需要调用这个。
     */
    public static function verify($rawJsonBody, $signature)
    {
        return hash_equals(self::sign($rawJsonBody), $signature);
    }

    private static function secret()
    {
        $secret = self::configuredSecret();
        if ($secret === '') {
            throw new \RuntimeException('未配置出站回调签名密钥（pga_outbound_shared_secret），请先在设置页粘贴/生成');
        }
        return $secret;
    }
}
