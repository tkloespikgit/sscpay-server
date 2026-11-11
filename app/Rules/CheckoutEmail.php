<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 与支付插件 WordPress is_email() 相同的邮箱格式。
 *
 * 插件在创建支付链接时用这套规则，不查域名有没有邮件服务器。收款页在浏览器里
 * 用同一套判断拦住客户；这里在写入订单前再查一次，避免本地订单已经留下、
 * 支付站点再以 customer.email 格式不合法拒绝。站点上其他插件挂的 is_email
 * 过滤器不在这套规则里。
 */
class CheckoutEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::accepts($value)) {
            $fail(__('checkout.validation.email_invalid'));
        }
    }

    public static function accepts(mixed $email): bool
    {
        if (! is_string($email) || strlen($email) < 6 || strpos($email, '@', 1) === false) {
            return false;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (preg_match('/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.\-]+$/', $local) !== 1) {
            return false;
        }

        if (preg_match('/\.{2,}/', $domain) === 1 || trim($domain, " \t\n\r\0\x0B.") !== $domain) {
            return false;
        }

        $labels = explode('.', $domain);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (trim($label, " \t\n\r\0\x0B-") !== $label || preg_match('/^[a-z0-9-]+$/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
