<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 收款页地址按 PayPal REST 的 admin_area_1 / admin_area_2 语义组织。
 * 省州表来源与更新注意事项见 doc/checkout-link.md。
 */
final class CheckoutAddress
{
    public static function stateRules(): array
    {
        static $rules;

        return $rules ??= json_decode(file_get_contents(resource_path('data/paypal-address-states.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function normalizeState(string $country, ?string $state): ?string
    {
        $state = trim($state ?? '');
        $rules = self::stateRules()[strtoupper($country)] ?? null;
        if ($rules === null) {
            return $state === '' ? null : $state;
        }

        $needle = self::normalizeName($state);
        foreach ($rules as $value => $names) {
            foreach ([$value, ...$names] as $name) {
                if ($needle === self::normalizeName($name)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private static function normalizeName(string $name): string
    {
        // 仅折叠拉丁字符的重音；整串 ASCII 转写会丢失中文并造成省份误匹配。
        return Str::lower(preg_replace_callback('/[\p{Latin}\p{M}]+/u',
            fn (array $match) => Str::ascii($match[0]), trim($name)));
    }

    public static function validatedState(string $country, ?string $state): ?string
    {
        $normalized = self::normalizeState($country, $state);
        if (isset(self::stateRules()[strtoupper($country)]) && $normalized === null) {
            throw ValidationException::withMessages(['state' => __('checkout.validation.state_invalid')]);
        }

        return $normalized;
    }
}
