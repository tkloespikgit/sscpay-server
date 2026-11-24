<?php

namespace App\Services\ChargebackAlerts\Drivers\WeTech;

use App\Models\ChargebackProviderAccount;
use App\Services\ChargebackAlerts\ChargebackProviderEvent;
use App\Services\ChargebackAlerts\WebhookRejected;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;

final class WeTechPayload
{
    public const ETHOCA = 'ethoca';

    public const RDR = 'rdr';

    /** @param array<string, mixed> $body
     * @return list<ChargebackProviderEvent>
     */
    public function webhook(ChargebackProviderAccount $account, array $body, CarbonImmutable $observed): array
    {
        if (array_key_exists('data', $body)) {
            $data = $body['data'];
            if (array_diff_key($body, array_flip(['secret_id', 'timestamp', 'sign', 'data'])) !== []) {
                throw new WebhookRejected;
            }

            if ($data instanceof stdClass) {
                return [$this->setting($account, self::ETHOCA, get_object_vars($data), $observed)];
            }

            if (! is_array($data) || $data === [] || ! $data[0] instanceof stdClass || count($data) > 50) {
                throw new WebhookRejected;
            }

            $isNotice = property_exists($data[0], 'rw_id');
            if ($isNotice && count($data) > 10) {
                throw new WebhookRejected;
            }

            $events = [];
            foreach ($data as $row) {
                if (! $row instanceof stdClass || property_exists($row, 'rw_id') !== $isNotice) {
                    throw new WebhookRejected;
                }

                $events[] = $isNotice
                    ? $this->notice($account, self::RDR, get_object_vars($row), $observed)[0]
                    : $this->setting($account, self::ETHOCA, get_object_vars($row), $observed);
            }

            return $events;
        }

        if (isset($body['feedback_time'], $body['rw_id'])) {
            return [$this->feedback($account, $body, $observed)];
        }
        if (isset($body['rw_id'], $body['alert_timestamp'])) {
            return $this->notice($account, self::ETHOCA, $body, $observed);
        }
        if (isset($body['id']) && ! isset($body['rw_id'])) {
            return [$this->setting($account, self::RDR, $body, $observed)];
        }

        throw new WebhookRejected;
    }

    /** @param array<string, mixed> $row
     * @return list<ChargebackProviderEvent>
     */
    public function notice(ChargebackProviderAccount $account, string $service, array $row, CarbonImmutable $observed): array
    {
        $ethoca = $service === self::ETHOCA;
        $id = $this->identifier($row['rw_id'] ?? null) ?? throw new WebhookRejected;
        $occurred = $this->date($row[$ethoca ? 'alert_timestamp' : 'receive_time'] ?? null, $account) ?? throw new WebhookRejected;
        $descriptor = $this->text($row['descriptor'] ?? null, 255);
        $registeredDescriptor = $this->text($row['source_descriptor'] ?? null, 255);
        [$tradeAmount, $tradeCurrency] = $this->money($row['trade_amount'] ?? null, $row['trade_currency'] ?? null);
        [$caseAmount, $caseCurrency] = $this->money($row['amount'] ?? null, $row['currency'] ?? null);
        $events = [new ChargebackProviderEvent(ChargebackProviderEvent::NOTICE, $service, $id, $observed, [
            'scope_key' => $ethoca ? $registeredDescriptor ?? $descriptor : $this->text($row['mid'] ?? null, 255),
            'scope_is_explicit' => ! $ethoca || $registeredDescriptor !== null,
            'descriptor' => $descriptor,
            'arn' => $this->identifier($row['arn'] ?? null, 100),
            'provider_transaction_id' => $this->identifier($row['transaction_id'] ?? null, 100),
            'provider_order_reference' => $this->identifier($row['mch_order_id'] ?? null),
            'alert_type' => $this->text($row['type'] ?? null, 32),
            'reason_code' => $this->text($row['charge_back_reason_code'] ?? $row['reason_code'] ?? null, 50),
            'occurred_at' => $occurred,
            'trade_at' => $this->date($row['trade_time'] ?? null, $account, dateOnlyAllowed: ! $ethoca),
            'trade_amount' => $tradeAmount,
            'trade_currency' => $tradeCurrency,
            'case_amount' => $caseAmount,
            'case_currency' => $caseCurrency,
            'remote_status' => $this->text($row['status'] ?? null, 64),
            'charge_level' => $this->text($row['charge_level'] ?? null, 32),
        ], feedbackHours: $ethoca ? 24 : null)];

        if ($ethoca && isset($row['feedback_time'], $row['outcome'], $row['refund_status'])) {
            $events[] = $this->feedback($account, $row, $observed);
        }

        return $events;
    }

    /** @param array<string, mixed> $row */
    public function setting(ChargebackProviderAccount $account, string $service, array $row, CarbonImmutable $observed): ChargebackProviderEvent
    {
        $descriptors = $row['descriptors'] ?? [];
        if (! is_array($descriptors)) {
            throw new WebhookRejected;
        }
        $descriptors = array_values(array_filter(array_map(fn ($value) => $this->text($value, 255), array_slice($descriptors, 0, 100))));
        $effect = $row['effect_time'] ?? null;
        $effectiveAt = $this->date($effect, $account);
        if ((is_string($effect) || is_int($effect)) && preg_match('/\A\d{13}\z/', (string) $effect)) {
            $effectiveAt = CarbonImmutable::createFromTimestampMs((string) $effect, config('app.timezone'))->toDateTimeString();
        }

        return new ChargebackProviderEvent(ChargebackProviderEvent::SETTING, $service,
            $this->identifier($row['id'] ?? null) ?? throw new WebhookRejected, $observed, [
                'scope_key' => $this->text($row[$service === self::ETHOCA ? 'descriptor' : 'mid'] ?? null, 255),
                'remote_status' => $this->text($row['status'] ?? $row['stauts'] ?? null, 32),
                'details' => array_filter([
                    'group_id' => $this->identifier($row['group_id'] ?? null, 50),
                    'effective_at' => $effectiveAt,
                    'acquirer_bin' => $this->identifier($row['acquirer_bin'] ?? null, 32),
                    'descriptors' => $descriptors ?: null,
                    'auto_refund_platform' => $this->text($row['auto_refund_platform'] ?? null, 32),
                ], fn ($value): bool => $value !== null),
            ]);
    }

    /** @param array<string, mixed> $row */
    private function feedback(ChargebackProviderAccount $account, array $row, CarbonImmutable $observed): ChargebackProviderEvent
    {
        return new ChargebackProviderEvent(ChargebackProviderEvent::FEEDBACK, self::ETHOCA,
            $this->identifier($row['rw_id'] ?? null) ?? throw new WebhookRejected, $observed, [
                'feedback_at' => $this->date($row['feedback_time'] ?? null, $account) ?? throw new WebhookRejected,
                'outcome' => $this->text($row['outcome'] ?? null, 64) ?? throw new WebhookRejected,
                'refund_status' => $this->text($row['refund_status'] ?? null, 32) ?? throw new WebhookRejected,
                'refund_amount' => $this->money($row['refund_amount'] ?? null, null)[0],
                'refund_time' => $this->date($row['refund_time'] ?? null, $account),
            ]);
    }

    public function identifier(mixed $value, int $length = 120): ?string
    {
        $value = $this->text($value, $length);

        return $value !== null && preg_match('/\A[^&=\x00-\x20\x7f]+\z/u', $value) ? $value : null;
    }

    private function text(mixed $value, int $length): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && mb_strlen($value) <= $length && ! preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : null;
    }

    /** @return array{?string, ?string} */
    private function money(mixed $value, mixed $currency): array
    {
        $currency = is_string($currency) && preg_match('/\A[A-Za-z]{3}\z/', $currency) ? strtoupper($currency) : null;
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || (is_float($value) && ! is_finite($value))) {
            return [null, $currency];
        }

        $number = is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR) : trim((string) $value);
        if (! preg_match('/\A(\d{1,13}(?:\.\d{1,2})?)(?:\s*([A-Za-z]{3}))?\z/', $number, $matches)) {
            return [null, $currency];
        }
        $suffix = isset($matches[2]) ? strtoupper($matches[2]) : null;
        if ($suffix !== null && $currency !== null && $suffix !== $currency) {
            return [null, null];
        }

        return [bcadd($matches[1], '0', 2), $suffix ?? $currency];
    }

    private function date(mixed $value, ChargebackProviderAccount $account, bool $dateOnlyAllowed = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $format = $dateOnlyAllowed && strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
        try {
            $parsed = CarbonImmutable::createFromFormat('!'.$format, $value, $account->timezone);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $parsed && $parsed->format($format) === $value ? $parsed->setTimezone(config('app.timezone'))->toDateTimeString() : null;
    }
}
