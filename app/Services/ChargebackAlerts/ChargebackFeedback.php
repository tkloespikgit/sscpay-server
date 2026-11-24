<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackAlert;
use App\Models\ChargebackAlertFeedback;
use App\Models\ChargebackProviderAccount;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class ChargebackFeedback
{
    public function __construct(private ChargebackAlertDrivers $drivers, private ChargebackAlertPolicy $policy) {}

    /** @param array<string, mixed> $input */
    public function request(User $actor, int $alertId, array $input): ChargebackAlertFeedback
    {
        return DB::transaction(function () use ($actor, $alertId, $input): ChargebackAlertFeedback {
            $actor = User::query()->findOrFail($actor->id);
            $alert = $this->alerts()->lockForUpdate()->findOrFail($alertId);
            abort_unless($this->policy->feedback($actor, $alert), 403);
            $account = $alert->account;
            $driver = $this->drivers->find($account->provider);
            if (! $account->isServiceActive($alert->service)
                || ! $driver instanceof SendsChargebackFeedback || $alert->evidence_conflict
                || $alert->remote_feedback_at !== null || $alert->feedback()->whereIn('status', ['pending', 'sending', 'unknown', 'submitted'])->exists()) {
                throw ValidationException::withMessages(['outcome' => __('chargeback_alerts.feedback_unavailable')]);
            }

            $data = Validator::make($input, [
                'outcome' => ['required', 'string', 'max:64'],
                'refund_status' => ['required', 'string', 'max:32'],
                'refund_amount' => ['nullable', 'required_if:refund_status,refunded', 'regex:/\A\d{1,13}(?:\.\d{1,2})?\z/'],
                'refund_time' => ['nullable', 'required_if:refund_status,refunded', 'date_format:Y-m-d H:i:s', 'before_or_equal:now'],
                'evidence_reference' => ['nullable', 'required_if:refund_status,refunded', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f]/'],
                'comments' => ['nullable', 'string', 'max:250', 'not_regex:/(?:\d[ -]?){12,}/', 'not_regex:/&[A-Za-z][A-Za-z0-9_]*=/'],
            ])->validate();
            if (($alert->order_id === null && $data['outcome'] !== 'notfound')
                || ! isset($driver->refundStatuses($alert, $data['outcome'])[$data['refund_status']])) {
                throw ValidationException::withMessages(['refund_status' => __('chargeback_alerts.invalid_outcome')]);
            }

            $amount = isset($data['refund_amount']) ? bcadd($data['refund_amount'], '0', 2) : null;
            if ($amount !== null && (bccomp($amount, '0', 2) <= 0
                    || ($alert->case_amount !== null && bccomp($amount, $alert->case_amount, 2) > 0))) {
                throw ValidationException::withMessages(['refund_amount' => __('chargeback_alerts.refund_amount_invalid')]);
            }

            if ($data['refund_status'] === 'not_refunded' && ($amount !== null || isset($data['refund_time']))) {
                throw ValidationException::withMessages(['refund_amount' => __('chargeback_alerts.refund_amount_invalid')]);
            }

            $feedback = $alert->feedback()->create([
                'operator_id' => $actor->id, 'status' => 'pending', 'next_attempt_at' => now(),
                'evidence_reference' => $data['evidence_reference'] ?? null,
                'payload' => [
                    'outcome' => $data['outcome'], 'refund_status' => $data['refund_status'],
                    'refund_amount' => $amount, 'refund_time' => $data['refund_time'] ?? null, 'comments' => $data['comments'] ?? null,
                ],
            ]);
            $alert->events()->create([
                'operator_id' => $actor->id, 'kind' => 'feedback_requested', 'source' => 'operator',
                'details' => ['outcome' => $data['outcome'], 'refund_status' => $data['refund_status']],
            ]);

            return $feedback;
        }, 3);
    }

    public function send(int $id): void
    {
        $claimed = $this->claim($id);
        if ($claimed === null) {
            return;
        }
        [$feedback, $alert, $account, $driver] = $claimed;

        try {
            $driver->sendFeedback($account, $alert, $feedback->payload);
            $updates = ['status' => 'submitted', 'result_code' => 'ok'];
        } catch (ProviderRequestFailed $error) {
            $updates = match (true) {
                $error->retryAllowed && $feedback->attempts <= 3 => ['status' => 'pending',
                    'next_attempt_at' => now()->addMinutes([5, 30, 60][$feedback->attempts - 1])],
                $error->uncertain => ['status' => 'unknown'],
                default => ['status' => 'rejected'],
            };
            $updates['result_code'] = $error->result;
        } catch (Throwable $error) {
            Log::warning('Chargeback feedback result could not be recorded.', ['feedback_id' => $id, 'exception' => $error::class]);
            $updates = ['status' => 'unknown', 'result_code' => 'transport_unknown'];
        }

        DB::transaction(function () use ($id, $alert, $updates): void {
            $locked = $this->alerts()->lockForUpdate()->findOrFail($alert->id);
            $feedback = $locked->feedback()->lockForUpdate()->findOrFail($id);
            if ($feedback->status !== 'sending') {
                return;
            }

            $feedback->update($updates + ['next_attempt_at' => null, 'finished_at' => $updates['status'] === 'pending' ? null : now()]);
            $locked->events()->create([
                'kind' => 'feedback_result', 'source' => 'system',
                'details' => ['result' => $updates['status'], 'code' => $updates['result_code']],
            ]);
        }, 3);
    }

    public function recover(int $id): void
    {
        ChargebackAlertFeedback::query()->whereKey($id)->where('status', 'sending')
            ->where('started_at', '<=', now()->subMinutes(2))
            ->update(['status' => 'unknown', 'result_code' => 'delivery_interrupted', 'finished_at' => now()]);
    }

    /** @return array{ChargebackAlertFeedback, ChargebackAlert, ChargebackProviderAccount, SendsChargebackFeedback}|null */
    private function claim(int $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $feedback = ChargebackAlertFeedback::query()->find($id);
            $alert = $feedback ? $this->alerts()->find($feedback->chargeback_alert_id) : null;
            if (! $alert) {
                return null;
            }
            $account = ChargebackProviderAccount::query()->lockForUpdate()->find($alert->chargeback_provider_account_id);
            if (! $account?->isServiceActive($alert->service)) {
                return null;
            }

            $alert = $this->alerts()->lockForUpdate()->findOrFail($alert->id);
            $feedback = $alert->feedback()->lockForUpdate()->findOrFail($id);
            if ($feedback->status !== 'pending' || $feedback->next_attempt_at === null || $feedback->next_attempt_at->isFuture()) {
                return null;
            }

            $driver = $this->drivers->find($account->provider);
            if (! $driver instanceof SendsChargebackFeedback
                || $alert->evidence_conflict || $alert->remote_feedback_at !== null) {
                $feedback->update(['status' => 'unknown', 'result_code' => 'provider_feedback_conflict', 'next_attempt_at' => null, 'finished_at' => now()]);

                return null;
            }

            $operator = $feedback->operator_id === null ? null : User::query()->find($feedback->operator_id);
            if (! $operator || ! $this->policy->feedback($operator, $alert)) {
                $feedback->update(['status' => 'rejected', 'result_code' => 'operator_no_longer_authorized',
                    'next_attempt_at' => null, 'finished_at' => now()]);

                return null;
            }

            $feedback->update([
                'status' => 'sending', 'attempts' => $feedback->attempts + 1, 'started_at' => now(), 'next_attempt_at' => null,
            ]);

            return [$feedback, $alert, $account, $driver];
        }, 3);
    }

    private function alerts(): Builder
    {
        return ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class);
    }
}
