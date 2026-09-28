<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * 国际化手机号校验（收款链接落地页用）。
 *
 * 为什么不用正则：各国号码长度、前缀、运营商段位规则差异极大，正则要么
 * 松到形同虚设（放进一堆假号），要么紧到误杀真实号码（客户下不了单）。
 * libphonenumber 是 Google 维护的号码规则库，按国家逐一校验。
 *
 * 要求必须是 E.164 格式（+ 开头带国家码，如 +4915112345678）——落地页上
 * 由国家区号选择器拼好再提交。不带国家码的话无从判断按哪个国家的规则校验，
 * 同一串数字在不同国家可能一个有效一个无效。
 */
class InternationalPhone implements ValidationRule
{
    public function __construct(private readonly ?array $allowedCountries = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = trim((string) $value);

        if (! str_starts_with($number, '+')) {
            $fail(__('checkout.validation.phone_needs_country_code'));

            return;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            // 第二个参数传 null：号码已经是 E.164，国家由 + 后面的国家码决定，
            // 不需要（也不应该）再给一个默认地区去猜。
            $parsed = $util->parse($number, null);
        } catch (NumberParseException) {
            $fail(__('checkout.validation.phone_invalid'));

            return;
        }

        if (! $util->isValidNumber($parsed)) {
            $fail(__('checkout.validation.phone_invalid'));

            return;
        }

        if ($this->allowedCountries && ! in_array($util->getRegionCodeForNumber($parsed), $this->allowedCountries, true)) {
            $fail(__('checkout.validation.phone_country_not_supported'));
        }
    }

    /**
     * 归一化成 E.164 落库。客户可能填成 "+49 151 1234 5678" 这种带空格/横杠的
     * 写法，直接存会让后续按手机号查订单、给客户发短信全部对不上。
     * 校验已通过的号码才调这个方法。
     */
    public static function normalize(string $value): string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            return $util->format($util->parse(trim($value), null), PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            return trim($value);
        }
    }
}
