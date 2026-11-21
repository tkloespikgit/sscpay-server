<?php
/**
 * 敏感数据过滤器 —— 落日志之前，把密钥类字段替换成占位符。
 *
 * 对应设计文档 F9。任何要写进 wp_payment_logs 的 request_data / response_data /
 * callback_data，必须先过一遍这个类，不能把原始数组直接 json_encode 落库。
 */

namespace PaymentGatewayAggregator\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class SensitiveDataFilter
{
    /**
     * 字段名包含以下关键字（不区分大小写）的，值会被替换成 ***REDACTED***。
     * 用"包含"而不是"精确匹配"，是因为不同网关的字段命名差异很大
     * （secret_key / secretKey / client_secret / api_secret ...），
     * 精确匹配很容易漏掉新网关的字段名。
     */
    private static $sensitiveKeywords = [
        'secret',
        'password',
        'passwd',
        'api_key',
        'apikey',
        'private_key',
        'privatekey',
        'sign_key',
        'signkey',
        'webhook_secret',
        'token',
        'authorization',
        'access_token',
        'refresh_token',
        'client_secret',
        'card_number',
        'customer_ip',
        'cvv',
        'cvc',
    ];

    const REDACTED = '***REDACTED***';

    /**
     * 递归过滤，返回一份新数据（不修改传入的原始数据）。
     *
     * @param mixed $data
     * @return mixed
     */
    public static function filter($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $result[$key] = self::REDACTED;
                    continue;
                }
                $result[$key] = self::filter($value);
            }
            return $result;
        }

        if (is_object($data)) {
            // stdClass等：转数组过滤完再转回对象，保留可读性。
            $asArray = json_decode(wp_json_encode($data), true);
            $filtered = self::filter($asArray);
            return json_decode(wp_json_encode($filtered));
        }

        if (is_string($data) && strlen($data) > 4000) {
            // 防止个别异常大的字段把日志表撑爆，超长字符串截断并标注。
            return substr($data, 0, 4000) . '...[truncated]';
        }

        return $data;
    }

    /**
     * 过滤后编码为JSON字符串，供直接落库使用。
     *
     * @param mixed $data
     * @return string
     */
    public static function filterToJson($data)
    {
        return wp_json_encode(self::filter($data));
    }

    private static function isSensitiveKey($key)
    {
        $normalized = strtolower($key);
        foreach (self::$sensitiveKeywords as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
}
