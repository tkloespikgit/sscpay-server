<?php

namespace App\Exceptions;

use Exception;

/**
 * 风控遍历完 group_key 下所有支付方式后，没有任何一个通过全部阈值检查。
 * 按最新业务决定（风控在下单时直接锁死单一支付方式，不返回候选列表），
 * 这种情况下整笔订单创建失败，不会产生 order 记录。
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
