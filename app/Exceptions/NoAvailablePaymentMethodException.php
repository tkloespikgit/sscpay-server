<?php

namespace App\Exceptions;

use Exception;

/**
 * 支付组不可用，或没有商户可用且通过风控的支付方式。
 * 首次下单不会创建本地订单；已有订单的重试保留原记录，不请求不可用渠道。
 */
class NoAvailablePaymentMethodException extends Exception
{
    public function __construct(public readonly string $groupKey)
    {
        parent::__construct("No available payment method passed risk control for group_key={$groupKey}");
    }

    public function errorCode(): string
    {
        return 'NO_AVAILABLE_PAYMENT_METHOD';
    }
}
