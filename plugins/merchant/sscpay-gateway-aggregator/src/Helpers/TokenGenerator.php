<?php
/**
 * 随机token生成，用于支付页面访问令牌(pay_token)、幂等键等场景。
 */

namespace PaymentGatewayAggregator\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class TokenGenerator
{
    /**
     * @param int $bytes 随机字节数，默认32字节（对应64个hex字符）
     * @return string urlsafe的十六进制字符串
     */
    public static function generate($bytes = 32)
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * 支付页面访问令牌——和 wp_payment_transactions.pay_token 对应，
     * 长度选64字符（32字节hex），碰撞概率可忽略不计。
     */
    public static function generatePayToken()
    {
        return self::generate(32);
    }

    /**
     * 请求幂等键，转发给三方网关时用（如PayPal的PayPal-Request-Id、
     * Airwallex的request_id），避免网络重试导致三方那边重复创建订单。
     */
    public static function generateIdempotencyKey()
    {
        return self::generate(16);
    }

    /**
     * 常数时间比较，校验token时用这个而不是 ===，防止时序攻击侧信道。
     *
     * @param string $known 服务端存储的已知值
     * @param string $given 用户传入的值
     * @return bool
     */
    public static function isEqual($known, $given)
    {
        if (!is_string($known) || !is_string($given)) {
            return false;
        }
        return hash_equals($known, $given);
    }
}
