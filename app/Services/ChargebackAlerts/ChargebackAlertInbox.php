<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackAlert;
use App\Models\ChargebackProviderAccount;
use App\Models\ChargebackProviderSetting;
use App\Models\Scopes\MerchantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ChargebackAlertInbox
{
    private const EVIDENCE = [
        'arn', 'provider_transaction_id', 'provider_order_reference', 'trade_amount', 'trade_currency', 'case_amount', 'case_currency',
    ];

    public function receive(ChargebackAlertDriver $driver, WebhookEnvelope $webhook): void
    {
        $hint = $driver->identify($webhook);
        DB::transaction(function () use ($driver, $webhook, $hint): void {
            $account = ChargebackProviderAccount::query()->where('provider', $driver->code())->where('external_id', $hint)
                ->where('enabled', true)->lockForUpdate()->first();
            if (! $account || ! hash_equals($account->external_id, $hint)) {
                throw new WebhookRejected(401);
            }

            foreach ($driver->authenticate($webhook, $account) as $event) {
                if (! array_key_exists($event->service, $driver->services())) {
                    throw new WebhookRejected;
                }
                $this->record($account, $event, 'webhook');
            }
        }, 3);
    }

    /** Caller holds the provider account lock and has authenticated the event or queried the provider. */
    public function record(ChargebackProviderAccount $account, ChargebackProviderEvent $event, string $source): void
    {
        if (! $account->isServiceActive($event->service)) {
            return;
        }
        if ($event->kind === ChargebackProviderEvent::SETTING) {
            $this->storeSetting($account, $event);

            return;
        }

        $alert = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->firstOrCreate([
            'chargeback_provider_account_id' => $account->id,
            'service' => $event->service,
            'external_id' => $event->externalId,
        ]);
        $alert = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->lockForUpdate()->findOrFail($alert->id);
        $fingerprint = hash('sha256', $event->kind."\0".json_encode($event->data, JSON_THROW_ON_ERROR));
        $newEvent = ! $alert->events()->where('fingerprint', $fingerprint)->exists();
        if ($newEvent) {
            $alert->events()->create(['kind' => $event->kind, 'source' => $source, 'fingerprint' => $fingerprint, 'details' => $event->data]);
        }

        if ($event->kind === ChargebackProviderEvent::FEEDBACK) {
            $this->applyFeedback($alert, $event, $source);

            return;
        }

        if ($source === 'webhook' && ! $newEvent) {
            return;
        }
        $this->applyNotice($alert, $event);
    }

    private function storeSetting(ChargebackProviderAccount $account, ChargebackProviderEvent $event): void
    {
        $setting = ChargebackProviderSetting::query()->firstOrNew([
            'chargeback_provider_account_id' => $account->id, 'service' => $event->service, 'external_id' => $event->externalId,
        ]);

        if ($setting->observed_ms !== null && $event->observedAt->getTimestampMs() < $setting->observed_ms) {
            return;
        }

        $setting->fill(array_filter(Arr::only($event->data, ['scope_key', 'remote_status']), fn ($value): bool => $value !== null));
        $setting->fill([
            'details' => array_replace($setting->details ?? [], $event->data['details'] ?? []),
            'observed_at' => $event->observedAt,
            'observed_ms' => $event->observedAt->getTimestampMs(),
        ]);
        $setting->save();
    }

    private function applyNotice(ChargebackAlert $alert, ChargebackProviderEvent $event): void
    {
        $data = array_filter($event->data, fn ($value): bool => $value !== null);
        foreach (Arr::only($data, self::EVIDENCE) as $field => $value) {
            if ($alert->{$field} === null) {
                $alert->{$field} = $value;
            } elseif ((string) $alert->{$field} !== (string) $value) {
                $alert->evidence_conflict = true;
            }
        }

        if (isset($data['scope_key'])) {
            if ($alert->scope_key === null || ($alert->scope_key !== $data['scope_key']
                    && ! $alert->scope_is_explicit && ($data['scope_is_explicit'] ?? false) && $alert->order_id === null)) {
                $alert->scope_key = $data['scope_key'];
                $alert->scope_is_explicit = $data['scope_is_explicit'] ?? false;
            } elseif ($alert->scope_key === $data['scope_key']) {
                $alert->scope_is_explicit = $alert->scope_is_explicit || ($data['scope_is_explicit'] ?? false);
            } elseif ($data['scope_is_explicit'] ?? false) {
                $alert->evidence_conflict = true;
            }
        }

        foreach (Arr::only($data, ['descriptor', 'trade_at', 'occurred_at', 'alert_type', 'reason_code']) as $field => $value) {
            if ($alert->{$field} === null) {
                $alert->{$field} = $value;
            }
        }

        if ($alert->notice_observed_ms === null || $event->observedAt->getTimestampMs() >= $alert->notice_observed_ms) {
            $alert->fill(Arr::only($data, ['remote_status', 'charge_level']));
            $alert->notice_observed_ms = $event->observedAt->getTimestampMs();
        }
        if ($event->feedbackHours !== null && $alert->occurred_at !== null && $alert->feedback_due_at === null) {
            $alert->feedback_due_at = $alert->occurred_at->copy()->addSeconds($event->feedbackHours * 3600);
        }
        if ($alert->order_id === null && $alert->isDirty([...self::EVIDENCE, 'scope_key', 'occurred_at', 'evidence_conflict'])) {
            $alert->next_match_at = now();
        }
        $alert->save();
    }

    private function applyFeedback(ChargebackAlert $alert, ChargebackProviderEvent $event, string $source): void
    {
        $time = CarbonImmutable::parse($event->data['feedback_at']);
        if ($alert->remote_feedback_at !== null && ($time->lt($alert->remote_feedback_at)
                || ($time->equalTo($alert->remote_feedback_at) && $source === 'webhook'))) {
            return;
        }

        $alert->update(['remote_feedback_at' => $time, 'remote_feedback' => $event->data]);
        $feedback = $alert->feedback()->whereIn('status', ['pending', 'sending', 'unknown'])->latest('id')->lockForUpdate()->first();
        if (! $feedback) {
            return;
        }

        foreach (['outcome', 'refund_status', 'refund_amount', 'refund_time'] as $key) {
            if (($feedback->payload[$key] ?? null) !== ($event->data[$key] ?? null)) {
                if ($feedback->status === 'pending') {
                    $feedback->update(['status' => 'unknown', 'result_code' => 'provider_feedback_conflict', 'next_attempt_at' => null]);
                }

                return;
            }
        }

        $feedback->update(['status' => 'submitted', 'result_code' => 'confirmed_by_provider', 'finished_at' => now(), 'next_attempt_at' => null]);
    }
}
