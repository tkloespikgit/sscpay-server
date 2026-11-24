<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackProviderAccount;

interface ChargebackAlertDriver
{
    public function code(): string;

    public function label(): string;

    /** @return array<string, string> Field name => label for write-only credential input. */
    public function signingFields(): array;

    public function accountIdentifierLabel(): string;

    /** @return array<string, string> */
    public function services(): array;

    /** @return list<string> Permitted transaction identifier types for this service. */
    public function referenceTypes(string $service): array;

    /** Untrusted lookup hint. The driver must authenticate it before using any business data. */
    public function identify(WebhookEnvelope $webhook): string;

    /** @return list<ChargebackProviderEvent> */
    public function authenticate(WebhookEnvelope $webhook, ChargebackProviderAccount $account): array;

    /** @return array{status:int, body:array<string, mixed>|string|null} */
    public function acknowledgement(?WebhookRejected $error = null): array;
}
