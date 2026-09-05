<?php

namespace App\Exceptions;

use Exception;

/**
 * 订单折算 USD 金额扣完支付方式的百分比+固定手续费后会变负（低于最小可收款金额）。
 * API 层应捕获后返回业务错误码 MINIMUM_AMOUNT_NOT_MET。
 */
class MinimumAmountNotMetException extends Exception
{
    public function __construct(
        public readonly ?string $minAmount,
        public readonly string $actualAmount,
    ) {
        $hint = $minAmount === null
            ? 'the payment method fee configuration has no valid minimum amount'
            : "minimum {$minAmount} USD required";

        parent::__construct("Order amount {$actualAmount} USD is below the minimum transaction amount for this payment method ({$hint}).");
    }

    public function errorCode(): string
    {
        return 'MINIMUM_AMOUNT_NOT_MET';
    }
}
