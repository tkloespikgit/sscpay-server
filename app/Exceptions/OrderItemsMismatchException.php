<?php

namespace App\Exceptions;

use Exception;

/**
 * 校验约束（3.8 节）：订单主表 subtotal 必须等于所有商品明细 total_price 之和。
 */
class OrderItemsMismatchException extends Exception
{
    public function __construct(
        public readonly string $expectedSubtotal,
        public readonly string $itemsSum,
    ) {
        parent::__construct("Order items subtotal mismatch: subtotal={$expectedSubtotal}, sum(items.total_price)={$itemsSum}");
    }

    public function errorCode(): string
    {
        return 'ITEMS_SUBTOTAL_MISMATCH';
    }
}
