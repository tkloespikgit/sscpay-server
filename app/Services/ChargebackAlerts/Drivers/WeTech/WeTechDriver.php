<?php

namespace App\Services\ChargebackAlerts\Drivers\WeTech;

use App\Models\ChargebackAlert;
use App\Models\ChargebackChannelBinding;
use App\Models\ChargebackProviderAccount;
use App\Services\ChargebackAlerts\ChargebackAlertDriver;
use App\Services\ChargebackAlerts\ProviderRequestFailed;
use App\Services\ChargebackAlerts\PullsChargebackRecords;
use App\Services\ChargebackAlerts\SendsChargebackFeedback;
use App\Services\ChargebackAlerts\WebhookEnvelope;
use App\Services\ChargebackAlerts\WebhookRejected;
use Carbon\CarbonImmutable;
use JsonException;

final readonly class WeTechDriver implements ChargebackAlertDriver, PullsChargebackRecords, SendsChargebackFeedback
{
    public function __construct(
        private WeTechSignature $signature,
        private WeTechPayload $payload,
        private WeTechClient $client,
    ) {}

    public function code(): string
    {
        return 'wetech';
    }

    public function label(): string
    {
        return 'WeTech';
    }

    public function signingFields(): array
    {
        return ['secret_key' => 'Secret Key'];
    }

    public function accountIdentifierLabel(): string
    {
        return 'Secret ID';
    }

    public function services(): array
    {
        return [WeTechPayload::ETHOCA => 'Ethoca', WeTechPayload::RDR => 'RDR'];
    }

    public function referenceTypes(string $service): array
    {
        return match ($service) {
            WeTechPayload::ETHOCA => [ChargebackChannelBinding::ARN, ChargebackChannelBinding::PSP_TRANSACTION],
            WeTechPayload::RDR => [ChargebackChannelBinding::ARN, ChargebackChannelBinding::GATEWAY_REFERENCE, ChargebackChannelBinding::INVOICE],
            default => [],
        };
    }

    public function identify(WebhookEnvelope $webhook): string
    {
        $body = $this->signature->decode($webhook->body);
        $id = $body['secret_id'] ?? null;

        return is_string($id) && preg_match('/\A[A-Za-z0-9_.-]{1,120}\z/', $id) ? $id : throw new WebhookRejected(401);
    }

    public function authenticate(WebhookEnvelope $webhook, ChargebackProviderAccount $account): array
    {
        $body = $this->signature->decode($webhook->body);
        $id = $body['secret_id'] ?? null;
        $provided = $body['sign'] ?? null;
        $timestamp = $body['timestamp'] ?? null;
        $key = $account->signing_config['secret_key'] ?? null;
        if (! is_string($id) || ! hash_equals($account->external_id, $id)
            || ! is_string($provided) || strlen($provided) !== 28 || ! is_string($key) || $key === ''
            || (! is_int($timestamp) && ! is_string($timestamp)) || ! preg_match('/\A\d{13}\z/', (string) $timestamp)) {
            throw new WebhookRejected(401);
        }

        $now = now();
        if ((int) $timestamp < $now->copy()->subDays(7)->getTimestampMs() || (int) $timestamp > $now->copy()->addMinutes(5)->getTimestampMs()) {
            throw new WebhookRejected(401);
        }

        try {
            $expected = $this->signature->sign($body, $key);
        } catch (JsonException) {
            throw new WebhookRejected;
        }
        if (! hash_equals($expected, $provided)) {
            throw new WebhookRejected(401);
        }

        return $this->payload->webhook($account, $body, CarbonImmutable::createFromTimestampMs($timestamp, config('app.timezone')));
    }

    public function acknowledgement(?WebhookRejected $error = null): array
    {
        $status = $error?->httpStatus ?? 200;

        return ['status' => $status, 'body' => match ($status) {
            200 => ['code' => 0, 'msg' => 'OK'],
            401 => ['code' => 4001, 'msg' => 'Invalid signature'],
            500 => ['code' => 5001, 'msg' => 'Temporary processing error'],
            default => ['code' => 4001, 'msg' => 'Invalid request'],
        }];
    }

    public function syncStreams(): array
    {
        return [
            'ethoca_alerts' => WeTechPayload::ETHOCA, 'rdr_alerts' => WeTechPayload::RDR,
            'ethoca_settings' => WeTechPayload::ETHOCA, 'rdr_settings' => WeTechPayload::RDR,
        ];
    }

    public function lookbackDays(): int
    {
        return 7;
    }

    public function pull(ChargebackProviderAccount $account, string $stream, int $page, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$service, $path, $datePrefix] = match ($stream) {
            'ethoca_alerts' => [WeTechPayload::ETHOCA, 'eth_oca/list', 'alert'],
            'rdr_alerts' => [WeTechPayload::RDR, 'rdr/list', 'receive'],
            'ethoca_settings' => [WeTechPayload::ETHOCA, 'eth_oca/descriptor/list', null],
            'rdr_settings' => [WeTechPayload::RDR, 'rdr/setting/list', null],
            default => throw new ProviderRequestFailed('invalid_stream'),
        };

        $parameters = ['page' => $page, 'size' => 100];
        if ($datePrefix !== null) {
            $parameters[$datePrefix.'_start_time'] = $start->setTimezone($account->timezone)->toDateTimeString();
            $parameters[$datePrefix.'_end_time'] = $end->setTimezone($account->timezone)->toDateTimeString();
        }

        $observed = CarbonImmutable::now();
        $result = $this->client->request($account, 'GET', '/api/open/rw/'.$path, $parameters)['data'] ?? null;
        $records = is_array($result) ? ($result['records'] ?? null) : null;
        $pages = is_array($result) ? filter_var($result['pages'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if (! is_array($records) || ! array_is_list($records) || count($records) > 100 || $pages === false) {
            throw new ProviderRequestFailed('invalid_page');
        }

        $events = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new ProviderRequestFailed('invalid_page');
            }
            try {
                array_push($events, ...($datePrefix === null
                    ? [$this->payload->setting($account, $service, $record, $observed)]
                    : $this->payload->notice($account, $service, $record, $observed)));
            } catch (WebhookRejected) {
                throw new ProviderRequestFailed('invalid_record');
            }
        }

        return ['events' => $events, 'pages' => $pages];
    }

    public function outcomes(ChargebackAlert $alert): array
    {
        if ($alert->service !== WeTechPayload::ETHOCA) {
            return [];
        }

        $outcomes = match (strtolower((string) $alert->alert_type)) {
            'fraud' => ['stopped', 'partially_stopped', 'previously_cancelled', 'missed', 'notfound', 'shipper_contacted', 'account_suspended', 'in_progress', 'other'],
            'dispute' => ['resolved', 'previously_refunded', 'unresolved_dispute', 'notfound', 'other'],
            default => [],
        };

        return $this->labels($outcomes, 'outcomes');
    }

    public function refundStatuses(ChargebackAlert $alert, string $outcome): array
    {
        if (! isset($this->outcomes($alert)[$outcome])) {
            return [];
        }

        $statuses = match ($outcome) {
            'stopped', 'previously_cancelled', 'previously_refunded' => ['refunded', 'not_settled'],
            'missed' => ['refunded', 'not_refunded'],
            'notfound', 'unresolved_dispute' => ['not_refunded'],
            default => ['refunded', 'not_settled', 'not_refunded'],
        };

        return $this->labels($statuses, 'refund_statuses');
    }

    public function sendFeedback(ChargebackProviderAccount $account, ChargebackAlert $alert, array $payload): void
    {
        $this->client->request($account, 'POST', '/api/open/rw/eth_oca/feedback', [
            'rw_id' => $alert->external_id,
            'refund_status' => $payload['refund_status'],
            'outcome' => $payload['outcome'],
            'refund_amount' => isset($payload['refund_amount']) ? (float) $payload['refund_amount'] : null,
            'refund_time' => isset($payload['refund_time'])
                ? CarbonImmutable::parse($payload['refund_time'])->setTimezone($account->timezone)->toDateTimeString()
                : null,
            'comments' => $payload['comments'] ?? null,
        ]);
    }

    /** @param list<string> $values
     * @return array<string, string>
     */
    private function labels(array $values, string $group): array
    {
        return array_combine($values, array_map(fn (string $value): string => __("chargeback_alerts.{$group}.{$value}"), $values));
    }
}
