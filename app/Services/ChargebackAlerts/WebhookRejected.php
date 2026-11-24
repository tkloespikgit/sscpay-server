<?php

namespace App\Services\ChargebackAlerts;

use RuntimeException;

final class WebhookRejected extends RuntimeException
{
    public function __construct(public readonly int $httpStatus = 400)
    {
        parent::__construct('Chargeback webhook rejected.');
    }
}
