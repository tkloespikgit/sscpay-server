<?php

namespace App\Services\ChargebackAlerts;

final readonly class WebhookEnvelope
{
    /** @param array<string, list<string>> $headers Lowercase header names. */
    public function __construct(public string $body, public array $headers) {}
}
