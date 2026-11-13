<?php

namespace App\Exceptions;

use RuntimeException;

class CombinedPaymentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 409)
    {
        parent::__construct(__('combined.errors.'.$errorCode));
    }
}
