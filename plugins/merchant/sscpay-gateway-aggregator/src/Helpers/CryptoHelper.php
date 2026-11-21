<?php
/**
 * AES-256-GCM 加解密封装。
 *
 * 密钥来源（按优先级）：
 * 1. PGA_ENCRYPTION_KEY 常量：默认值已内置在插件主文件 sscpay-gateway-aggregator.php 里
 *    （固定写死，不需要再改 wp-config.php）；站点如需覆盖，可在 wp-config.php 里定义，优先生效。
 *    注意：这个密钥一旦用于加密过数据，之后就不能再换，换了旧数据就解不出来了。
 * 2. 如果该常量被人为清空/未定义，退化使用 wp-config.php 里的 AUTH_KEY + SECURE_AUTH_KEY 派生一个密钥，
 *    并在后台显著位置提示管理员尽快设置专用密钥——因为 AUTH_KEY 这类salt理论上可能被更新
 *    （比如站点迁移、重置密钥），一旦变化会导致历史加密数据无法解密，只是应急兜底，不建议长期依赖。
 */

namespace PaymentGatewayAggregator\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class CryptoHelper
{
    const CIPHER = 'aes-256-gcm';

    /**
     * @param string $plaintext
     * @return string base64编码的 iv + tag + ciphertext，可直接存入LONGTEXT字段
     * @throws \RuntimeException
     */
    public static function encrypt($plaintext)
    {
        $key = self::resolveKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16 // tag length in bytes
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('CryptoHelper: 加密失败');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * @param string $encoded self::encrypt() 的输出
     * @return string 明文
     * @throws \RuntimeException 解密失败（密钥不对/数据被篡改/格式损坏）
     */
    public static function decrypt($encoded)
    {
        $key = self::resolveKey();
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new \RuntimeException('CryptoHelper: 输入不是合法的base64数据');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;

        if (strlen($raw) < $ivLength + $tagLength) {
            throw new \RuntimeException('CryptoHelper: 密文长度异常，无法解密');
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, $tagLength);
        $ciphertext = substr($raw, $ivLength + $tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // GCM是认证加密，openssl_decrypt在tag校验不通过时就会返回false，
            // 这通常意味着密钥不对或者数据被篡改，不要把这类错误吞掉。
            throw new \RuntimeException('CryptoHelper: 解密失败（密钥错误或数据已被篡改）');
        }

        return $plaintext;
    }

    /**
     * 加密一个数组/对象（先json_encode再加密），配置类数据用这个更方便。
     *
     * @param array $data
     * @return string
     */
    public static function encryptArray(array $data)
    {
        return self::encrypt(wp_json_encode($data));
    }

    /**
     * @param string $encoded
     * @return array
     * @throws \RuntimeException
     */
    public static function decryptArray($encoded)
    {
        $json = self::decrypt($encoded);
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('CryptoHelper: 解密结果不是合法JSON');
        }
        return $data;
    }

    /**
     * 生成一个内容哈希指纹，用于审计/变更检测——单向，不可逆，不能用来恢复原文。
     *
     * @param array $data
     * @return string
     */
    public static function fingerprint(array $data)
    {
        ksort($data);
        return hash('sha256', wp_json_encode($data));
    }

    /**
     * @return string 32字节的原始密钥
     * @throws \RuntimeException
     */
    private static function resolveKey()
    {
        if (defined('PGA_ENCRYPTION_KEY') && PGA_ENCRYPTION_KEY) {
            $key = base64_decode(PGA_ENCRYPTION_KEY, true);
            if ($key !== false && strlen($key) === 32) {
                return $key;
            }
            // 常量存在但格式不对，不要静默降级到派生密钥，直接报错，避免管理员以为
            // 设置生效了但其实插件在用别的密钥。
            throw new \RuntimeException(
                'CryptoHelper: PGA_ENCRYPTION_KEY 格式不正确，需要是 base64_encode(random_bytes(32)) 的结果'
            );
        }

        if (defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') && AUTH_KEY && SECURE_AUTH_KEY) {
            // 应急兜底：不建议长期依赖，见文件头注释。
            return hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
        }

        throw new \RuntimeException(
            'CryptoHelper: 未配置加密密钥，请检查插件主文件中的 PGA_ENCRYPTION_KEY 常量是否被意外移除/清空'
        );
    }
}
