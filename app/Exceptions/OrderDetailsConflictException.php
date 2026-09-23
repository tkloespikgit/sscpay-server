<?php

namespace App\Exceptions;

use Exception;

/** 同一商户订单号只允许重复请求相同的币种和应付金额。 */
class OrderDetailsConflictException extends Exception
{
    public function __construct()
    {
        parent::__construct('Merchant order number already exists with a different currency or amount.');
    }

    public function errorCode(): string
    {
        return 'ORDER_DETAILS_CONFLICT';
    }
}
