<?php

namespace App\Exceptions;

use Exception;

/**
 * 金额公式铁律校验失败：amount != subtotal + shipping_fee - discount + tax
 * （误差超过 Order::AMOUNT_TOLERANCE）。API 层应捕获后返回业务错误码 AMOUNT_MISMATCH。
 */
class AmountMismatchException extends Exception
{
    public function __construct(
        public readonly string $expectedAmount,
        public readonly string $actualAmount,
    ) {
        parent::__construct("Amount mismatch: expected {$expectedAmount}, got {$actualAmount}");
    }

    public function errorCode(): string
    {
        return 'AMOUNT_MISMATCH';
    }
}
