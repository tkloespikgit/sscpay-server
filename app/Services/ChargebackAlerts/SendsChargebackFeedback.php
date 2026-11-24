<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackAlert;
use App\Models\ChargebackProviderAccount;

interface SendsChargebackFeedback
{
    /** @return array<string, string> Outcome code => label. */
    public function outcomes(ChargebackAlert $alert): array;

    /** @return array<string, string> Refund status code => label. */
    public function refundStatuses(ChargebackAlert $alert, string $outcome): array;

    /** @param array<string, string|null> $payload */
    public function sendFeedback(ChargebackProviderAccount $account, ChargebackAlert $alert, array $payload): void;
}
