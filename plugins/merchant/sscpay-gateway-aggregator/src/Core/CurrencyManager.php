<?php
/**
 * 多币种管理：金额换算成/换回三方网关要求的"最小货币单位"。
 *
 * 评审里指出的问题：Stripe/PayPal/Airwallex这类网关，金额字段通常要求
 * 整数的最小货币单位（比如USD传"美分"整数），但JPY/KRW等货币没有小数位，
 * 不能直接 ×100，否则金额会变成100倍。
 *
 * 零小数位货币列表参考Stripe官方文档（这是目前业界最常引用、最稳定的一份列表）：
 * https://docs.stripe.com/currencies#zero-decimal
 * 如果某个网关（尤其Airwallex/Antom）对个别币种的处理和这份列表不一致，
 * 以该网关自己的文档为准，在对应Gateway类里单独处理，不要改这份公共列表。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class CurrencyManager
{
    /** @var string[] 零小数位货币（最小单位=1个货币单位，不是1分） */
    private static $zeroDecimalCurrencies = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function normalize($currency)
    {
        return strtoupper(trim((string) $currency));
    }

    public static function isValid($currency)
    {
        return Validator::isValidCurrency(self::normalize($currency));
    }

    public static function isZeroDecimal($currency)
    {
        return in_array(self::normalize($currency), self::$zeroDecimalCurrencies, true);
    }

    /**
     * 把十进制金额（99.99）换算成网关要求的最小单位整数（9999，或JPY的话就是99）。
     *
     * @param float|string $amount
     * @param string $currency
     * @return int
     */
    public static function toMinorUnit($amount, $currency)
    {
        $amount = (float) $amount;

        if (self::isZeroDecimal($currency)) {
            return (int) round($amount);
        }

        // 用round而不是直接×100再强转int，避免浮点误差导致99.99变成9998。
        return (int) round($amount * 100);
    }

    /**
     * 反向换算：网关返回的最小单位整数 -> 十进制金额，四舍五入到2位小数存库。
     *
     * @param int $minorAmount
     * @param string $currency
     * @return float
     */
    public static function fromMinorUnit($minorAmount, $currency)
    {
        if (self::isZeroDecimal($currency)) {
            return (float) $minorAmount;
        }

        return round(((int) $minorAmount) / 100, 2);
    }

    /**
     * 校验webhook/网关返回的金额是否与订单记录一致（对应F4b）。
     * 用最小单位整数比较，避免浮点数直接比较相等导致的精度问题。
     *
     * @param float $expectedAmount 订单记录里的十进制金额
     * @param string $expectedCurrency
     * @param int $actualMinorAmount 网关返回的最小单位整数金额
     * @param string $actualCurrency
     * @return bool
     */
    public static function amountsMatch($expectedAmount, $expectedCurrency, $actualMinorAmount, $actualCurrency)
    {
        if (self::normalize($expectedCurrency) !== self::normalize($actualCurrency)) {
            return false;
        }

        return self::toMinorUnit($expectedAmount, $expectedCurrency) === (int) $actualMinorAmount;
    }
}
