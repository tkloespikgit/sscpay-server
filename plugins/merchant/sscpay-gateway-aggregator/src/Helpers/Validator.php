<?php
/**
 * 轻量参数校验工具。不追求大而全的校验框架，只覆盖设计文档里明确列出的
 * 必填字段/格式要求，够用即可，复杂的网关专属校验放在各Gateway::validateConfig()里。
 */

namespace PaymentGatewayAggregator\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class Validator
{
    /**
     * 支持点号路径，比如 'customer.email'。
     *
     * @param array $data
     * @param array $requiredFields
     * @return array 缺失的字段名列表，空数组表示全部通过
     */
    public static function requireFields(array $data, array $requiredFields)
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!self::hasPath($data, $field)) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    public static function hasPath(array $data, $path)
    {
        $segments = explode('.', $path);
        $cursor = $data;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor !== null && $cursor !== '';
    }

    public static function isValidEmail($email)
    {
        return is_string($email) && is_email($email) !== false;
    }

    public static function isValidUrl($url)
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public static function isValidCurrency($currency)
    {
        return is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }

    public static function isPositiveDecimal($value)
    {
        return is_numeric($value) && (float) $value > 0;
    }

    public static function isNonNegativeDecimal($value)
    {
        return is_numeric($value) && (float) $value >= 0;
    }

    /**
     * s_order_id 之类的业务单号，限制长度和字符集，防止注入奇怪字符进URL/表单。
     */
    public static function isSafeIdentifier($value, $maxLength = 64)
    {
        return is_string($value)
            && strlen($value) > 0
            && strlen($value) <= $maxLength
            && preg_match('/^[A-Za-z0-9_\-]+$/', $value) === 1;
    }

    /**
     * 枚举字段校验。调用方如果允许大小写不敏感，先自己 strtoupper/trim 归一化再传进来，
     * 这里只做严格比对（in_array 第三参数 true，避免 '0' == false 之类的松散比较坑）。
     *
     * @param mixed $value
     * @param array $allowed
     * @return bool
     */
    public static function isOneOf($value, array $allowed)
    {
        return is_string($value) && in_array($value, $allowed, true);
    }

    /**
     * 账单号（invoice_number）。会原样写进 PayPal 的 purchase_units[].invoice_id
     * （上限127字符，会展示在买家交易历史和邮件通知里）以及 Stripe/Airwallex
     * 的 metadata，所以卡死长度和字符集，不让空格/引号/控制字符进三方系统。
     *
     * @param mixed $value
     * @return bool
     */
    public static function isInvoiceNumber($value)
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9_\-.\/#]{1,127}$/', $value) === 1;
    }

    /**
     * 订单主题（subject）。VIRTUAL 模式下会被当作商品名传给通道（PayPal 商品名
     * 上限127，组装时会再截断一次）。这里允许中文、空格等正常文案字符，
     * 只挡控制字符和过长。
     *
     * 注：控制字符正则不加 u 修饰符，按字节匹配——UTF-8 多字节字符的每个字节
     * 都 >= 0x80，不会被误伤，同时遇到非法 UTF-8 也不会像加 u 那样直接报错返回 false。
     *
     * @param mixed $value
     * @return bool
     */
    public static function isSubject($value)
    {
        return is_string($value)
            && trim($value) !== ''
            && mb_strlen($value) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $value) === 0;
    }
}
