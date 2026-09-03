<?php

namespace App\Exceptions;

use Exception;

/**
 * API 下单显式指定了 payment_method_key，但该商户名下找不到可用的对应支付方式
 * （method_code 不存在、不属于当前商户，或已被停用）。
 *
 * 指定渠道模式下不会回退到支付组路由（否则商户点名要用的渠道和实际收款的渠道
 * 就不一致了），因此直接拒单。API 层应捕获后返回业务错误码
 * PAYMENT_METHOD_NOT_AVAILABLE。
 */
class PaymentMethodNotAvailableException extends Exception
{
    public function __construct(public readonly string $paymentMethodKey)
    {
        parent::__construct("Specified payment method is not available: payment_method_key={$paymentMethodKey} does not exist, belongs to another merchant, or is disabled");
    }

    public function errorCode(): string
    {
        return 'PAYMENT_METHOD_NOT_AVAILABLE';
    }
}
