<?php

namespace App\Services\ChargebackAlerts;

use RuntimeException;

final class ProviderRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $result,
        public readonly bool $retryAllowed = false,
        public readonly bool $uncertain = false,
    ) {
        parent::__construct('Chargeback provider request failed.');
    }
}
