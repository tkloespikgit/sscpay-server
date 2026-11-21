<?php
/**
 * 网关配置的加解密封装——薄封装 CryptoHelper，语义上专门针对
 * "网关凭证JSON"这一种数据，方便调用方不用关心底层用的是AES-256-GCM
 * 还是别的算法（以后要换算法，只用改这一个文件）。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Helpers\CryptoHelper;

if (!defined('ABSPATH')) {
    exit;
}

class ConfigEncryptor
{
    /**
     * @param array $config 明文网关配置，如 ['secret_key'=>'sk_live_xxx', ...]
     * @return string 可直接存入 gateway_config / config_encrypted 字段的密文
     */
    public static function encrypt(array $config)
    {
        return CryptoHelper::encryptArray($config);
    }

    /**
     * @param string $encrypted
     * @return array 明文配置
     * @throws \RuntimeException 解密失败
     */
    public static function decrypt($encrypted)
    {
        return CryptoHelper::decryptArray($encrypted);
    }

    /**
     * 用于 wp_payment_gateway_configs.config_fingerprint：
     * 判断"新提交的配置内容"和"已保存的是否相同"，不需要解密旧值来比较。
     *
     * @param array $config
     * @return string
     */
    public static function fingerprint(array $config)
    {
        return CryptoHelper::fingerprint($config);
    }
}
