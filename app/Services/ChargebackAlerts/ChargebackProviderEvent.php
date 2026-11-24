<?php

namespace App\Services\ChargebackAlerts;

use Carbon\CarbonImmutable;

final readonly class ChargebackProviderEvent
{
    public const NOTICE = 'notice';

    public const FEEDBACK = 'feedback';

    public const SETTING = 'setting';

    /** @param array<string, mixed> $data Normalized allowlisted data, without raw card data or credentials. */
    public function __construct(
        public string $kind,
        public string $service,
        public string $externalId,
        public CarbonImmutable $observedAt,
        public array $data,
        public ?int $feedbackHours = null,
    ) {}
}
