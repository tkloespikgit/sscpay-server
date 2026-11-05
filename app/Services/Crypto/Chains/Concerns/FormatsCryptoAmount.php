<?php

namespace App\Services\Crypto\Chains\Concerns;

/**
 * 深链用金额格式：人类可读串 ↔ 链上最小单位（base units）。
 * 仅供 ChainAdapter 生成 TronLink / EIP-681；订单应付额截断仍在 CryptoPaymentService。
 */
trait FormatsCryptoAmount
{
    /** 去掉尾随 0，供深链 amount 字段；非法或 ≤0 返回 null。 */
    protected function humanAmount(string $amount): ?string
    {
        $amount = trim($amount);
        if ($amount === '' || ! is_numeric($amount) || bccomp($amount, '0', 18) <= 0) {
            return null;
        }

        if (str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }

        return $amount === '' ? null : $amount;
    }

    /**
     * 人类金额 × 10^decimals → 整数字符串。
     * 若换算后仍有非零小数位（超出代币精度），返回 null，避免深链金额错误。
     */
    protected function toBaseUnits(string $humanAmount, int $decimals): ?string
    {
        if ($decimals < 0) {
            return null;
        }

        $factor = bcpow('10', (string) $decimals, 0);
        $raw = bcmul($humanAmount, $factor, $decimals);

        if (! str_contains($raw, '.')) {
            return $raw;
        }

        [$int, $frac] = explode('.', $raw, 2);
        if (ltrim($frac, '0') !== '') {
            return null;
        }

        return $int === '' ? '0' : $int;
    }
}
