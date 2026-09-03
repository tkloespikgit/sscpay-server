<?php

namespace App\Support;

/**
 * 系统对外签名的唯一实现：入站请求鉴权（ApiAuthentication 中间件，商户调用
 * order/create、order/query、order/ship）和出站 webhook 通知（OrderNotificationService，
 * 系统推给商户 notify_url）共用同一套算法，方向不同但计算方式完全一致——
 * 商户端只需要实现一次签名/验签函数，两个方向都能直接复用。
 *
 * StringToSign = App-ID + "\n" + Timestamp + "\n" + Nonce + "\n" + 规范化请求体（不含 sign 字段）
 * Signature    = HMAC-SHA256(StringToSign, api_key)
 *
 * 规范化规则：关联数组（对象）按 key 递归 ksort；数字索引数组（列表）保持原顺序；
 * json_encode 时不转义 Unicode、不转义斜杠。
 */
class SignatureCanonicalizer
{
    public static function sign(string $appId, string $timestamp, string $nonce, array $body, string $apiKey): string
    {
        $stringToSign = $appId."\n".$timestamp."\n".$nonce."\n".self::canonicalize($body);

        return hash_hmac('sha256', $stringToSign, $apiKey);
    }

    /**
     * 移除 sign 字段后规范化编码：关联数组递归按 key 排序，数字索引列表保持原顺序。
     */
    public static function canonicalize(array $body): string
    {
        unset($body['sign']);

        return json_encode(self::ksortRecursive($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function ksortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::ksortRecursive($item);
        }

        if (! $isList) {
            ksort($result);
        }

        return $result;
    }
}
