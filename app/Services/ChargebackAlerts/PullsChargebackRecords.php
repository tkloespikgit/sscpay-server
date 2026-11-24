<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackProviderAccount;
use Carbon\CarbonImmutable;

interface PullsChargebackRecords
{
    /** @return array<string, string> Stream => service identifier. */
    public function syncStreams(): array;

    public function lookbackDays(): int;

    /** @return array{events:list<ChargebackProviderEvent>, pages:int} */
    public function pull(ChargebackProviderAccount $account, string $stream, int $page, CarbonImmutable $start, CarbonImmutable $end): array;
}
