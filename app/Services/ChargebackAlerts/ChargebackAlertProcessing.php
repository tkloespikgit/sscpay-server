<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackAlert;
use App\Models\Order;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Services\TelegramNotificationRouter;
use App\Support\TelegramNotificationKind;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class ChargebackAlertProcessing
{
    public function __construct(private TelegramNotificationRouter $telegram, private ChargebackAlertPolicy $policy) {}

    public function confirmReview(User $actor, int $alertId, string $note): void
    {
        DB::transaction(function () use ($actor, $alertId, $note): void {
            $actor = User::query()->findOrFail($actor->id);
            $alert = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->lockForUpdate()->findOrFail($alertId);
            abort_unless($this->policy->process($actor, $alert) || $this->policy->matchOrder($actor, $alert), 403);
            Validator::make(['note' => $note], ['note' => ['required', 'string', 'max:250', 'not_regex:/(?:\d[ -]?){12,}/']])->validate();
            if ($alert->reviewed_at !== null) {
                return;
            }

            $alert->update(['reviewed_at' => now()]);
            $alert->events()->create(['operator_id' => $actor->id, 'kind' => 'reviewed', 'source' => 'operator', 'details' => ['reason' => $note]]);
        }, 3);
    }

    public function notify(int $alertId): void
    {
        Cache::lock('chargeback-alert-notice:'.$alertId, 60)->get(function () use ($alertId): void {
            $alert = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->findOrFail($alertId);
            if ($alert->next_notice_at === null || $alert->next_notice_at->isFuture() || $alert->merchant_id === null || $alert->order_id === null
                || ! $alert->account->isServiceActive($alert->service)) {
                return;
            }

            $order = Order::query()->withoutGlobalScope(MerchantScope::class)->withTrashed()->forMerchant($alert->merchant_id)->find($alert->order_id);
            if (! $order) {
                $alert->update(['next_notice_at' => null]);

                return;
            }

            $deadline = $alert->notified_at !== null;
            $needsFeedback = $alert->feedback_due_at?->isFuture() && $alert->remote_feedback_at === null
                && ! $alert->feedback()->where('status', 'submitted')->exists();
            if ($deadline && (! $needsFeedback || $alert->deadline_notified_at !== null)) {
                $alert->update(['next_notice_at' => null]);

                return;
            }

            $kind = $deadline ? TelegramNotificationKind::ChargebackAlertDueSoon : TelegramNotificationKind::ChargebackAlertReceived;
            $delivery = $this->telegram->sendNotification($kind, [
                'alert_id' => $alert->external_id,
                'service' => strtoupper($alert->service),
                'order_no' => $order->order_no,
                'due_at' => $alert->feedback_due_at?->toDateTimeString() ?? '—',
            ], (int) $order->merchant_id);

            if (! $delivery->sent && $delivery->anyUsable && ! $delivery->suppressed && $alert->notice_attempts < 3) {
                $alert->update(['next_notice_at' => now()->addMinutes(5), 'notice_attempts' => $alert->notice_attempts + 1]);

                return;
            }

            $alert->update([
                $deadline ? 'deadline_notified_at' : 'notified_at' => now(),
                'next_notice_at' => ! $deadline && $needsFeedback ? max(now(), $alert->feedback_due_at->copy()->subHours(2)) : null,
                'notice_attempts' => 0,
            ]);
        });
    }
}
