<?php

namespace App\Support;

/**
 * 商户自带 ESP 凭证的形状定义与完整性校验：不同驱动需要的字段不一样
 * （postmark 只要一个 token；ses 要 key/secret，region 可选默认 us-east-1；
 * smtp 要 host，port/username/password/encryption 均可选）。
 * 新增驱动只需要在 overridesFor() 里加一个 match 分支。
 *
 * Order::resolveMailSender()（判断 PaymentMethod/Application 某一级配置是否
 * 可用）和 SendPaymentLinkJob（构造要临时覆写进 mail.mailers.{driver} 的片段）
 * 共用同一份逻辑，避免两处各自判断"这算不算配置完整"而互相不一致。
 */
class MailerCredentials
{
    /**
     * @param  array<string, mixed>|null  $credentials
     * @return array<string, mixed>|null 完整则返回要合并进 mail.mailers.{driver}
     *                                    的片段；driver 未识别或必填项缺失则返回 null。
     */
    public static function overridesFor(?string $driver, ?array $credentials): ?array
    {
        $credentials ??= [];

        return match ($driver) {
            'postmark' => filled($credentials['token'] ?? null) ? [
                'token' => $credentials['token'],
            ] : null,
            'ses' => (filled($credentials['key'] ?? null) && filled($credentials['secret'] ?? null)) ? [
                'key' => $credentials['key'],
                'secret' => $credentials['secret'],
                'region' => ($credentials['region'] ?? null) ?: 'us-east-1',
            ] : null,
            'smtp' => filled($credentials['host'] ?? null) ? [
                'host' => $credentials['host'],
                'port' => ($credentials['port'] ?? null) ?: 587,
                'username' => $credentials['username'] ?? null,
                'password' => $credentials['password'] ?? null,
                // scheme 显式指定才覆盖；留空交给 EsmtpTransportFactory 按端口自动判断
                // （465 走 smtps 隐式加密，其余走 smtp + 机会性 STARTTLS）。
                'scheme' => match ($credentials['encryption'] ?? null) {
                    'ssl' => 'smtps',
                    'tls' => 'smtp',
                    default => null,
                },
            ] : null,
            default => null,
        };
    }
}
