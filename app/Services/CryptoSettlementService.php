<?php

namespace App\Services;

use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoPaymentResolution;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\SystemConfig;
use App\Support\CryptoAmount;
use App\Support\CryptoCheckoutGuard;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Re-runnable consumer of persisted chain evidence; balance and payment facts commit together. */
final class CryptoSettlementService
{
    public function __construct(private readonly CryptoBalanceService $balances, private readonly OrderPaymentStatusService $payments) {}

    public function settle(int $merchantId, int $requestId): string
    {
        if (! config('services.tatum.settlement_enabled') || ! config('services.tatum.verification_enabled') || ! config('services.tatum.history_enabled')) {
            throw new DomainException('Automatic crypto settlement is disabled.');
        }
        $snapshot = CryptoPaymentRequest::query()->forMerchant($merchantId)->findOrFail($requestId);

        return DB::transaction(function () use ($merchantId, $snapshot): string {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($snapshot->order_id);
            $request = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $order->id)->lockForUpdate()->findOrFail($snapshot->id);
            if ($order->payment_type !== 'crypto') {
                throw new DomainException('Cannot apply crypto evidence to a fiat order.');
            }
            $request->update(['settlement_next_attempt_at' => now()->addMinute()]);
            if ($request->credited_at !== null && $order->paid_at !== null) {
                $this->payments->recoverCryptoNotification($order);
            }
            $resolution = CryptoPaymentResolution::query()->forMerchant($merchantId)
                ->where('order_id', $order->id)->whereIn('action', ['accept', 'adjust'])->latest('id')->first();
            if ($resolution && $request->review_reason === null && $request->status !== 'review') {
                if (app(CryptoPaymentEvidenceService::class)->capture($order)['hash'] === $resolution->evidence_hash) {
                    return 'credited';
                }

                return $this->review($request, 'resolved_evidence_changed');
            }
            if ($request->status === 'review' || $request->review_reason !== null) {
                return 'review';
            }
            $receipts = CryptoWebhookReceipt::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $request->id);
            if ((clone $receipts)->where('status', 'review')->exists()) {
                return $this->review($request, 'chain_evidence_review');
            }
            $events = CryptoChainEvent::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $request->id)
                ->whereNull('orphaned_at')->orderBy('block_time')->orderBy('id')->lockForUpdate()->get();
            $gross = '0';
            $unconfirmed = false;
            foreach ($events as $event) {
                if ($event->network !== $request->network || $event->asset !== $request->asset
                    || $event->block_time->lt($request->created_at) || $event->block_time->gt($request->expires_at)) {
                    return $this->review($request, 'late_or_mismatched_transfer');
                }
                if ($event->verified_at === null || $event->confirmed_at === null) {
                    $unconfirmed = true;
                }
                $gross = CryptoAmount::units(bcadd($gross, $event->amount_units, 0));
            }
            if (bccomp($gross, $request->due_units, 0) > 0) {
                return $this->review($request, $request->credited_at ? 'additional_payment' : 'overpayment');
            }
            if ($request->credited_at !== null) {
                if ($order->paid_at === null) {
                    return $this->review($request, 'credit_without_payment_fact');
                }
                if ($unconfirmed || $gross !== $request->due_units) {
                    return $this->review($request, 'credited_evidence_changed');
                }

                return 'credited';
            }
            if ($order->status !== 'pending' || $order->paid_at !== null) {
                return $this->review($request, 'order_not_payable');
            }
            $interval = SystemConfig::get('crypto.history_scan_interval_seconds');
            if (! is_string($interval) || ! preg_match('/^[1-9][0-9]{0,6}$/D', $interval)) {
                throw new DomainException('Missing history health interval.');
            }
            $maxAge = config('services.tatum.history_max_head_age_seconds');
            if ((! is_int($maxAge) && ! is_string($maxAge)) || ! preg_match('/^[1-9][0-9]{0,4}$/D', (string) $maxAge) || (int) $maxAge > 86400) {
                throw new DomainException('Missing chain head freshness policy.');
            }
            $healthy = $request->history_error_code === null && $request->history_to_block !== null && $request->history_to_hash !== null
                && $request->history_checked_at !== null && ! $request->history_checked_at->isFuture()
                && $request->history_checked_at->gte(now()->subSeconds((int) $interval)) && $request->history_to_time !== null
                && $request->history_to_time->gte(now()->subSeconds((int) $maxAge));
            if (! $healthy || $events->contains(fn (CryptoChainEvent $event): bool => $event->block_height > $request->history_to_block)
                || (clone $receipts)->whereIn('status', ['pending', 'confirming'])->exists() || $unconfirmed) {
                if ($gross !== '0') {
                    $request->update(['status' => 'confirming']);
                }

                return 'waiting';
            }
            if ($gross !== $request->due_units) {
                if ($gross !== '0' && $request->history_to_time->gt($request->expires_at)) {
                    return $this->review($request, 'expired_underpayment');
                }
                $request->update(['status' => $gross !== '0' ? 'partial' : ($request->expires_at->isPast() ? 'expired' : $request->status)]);

                return $gross === '0' ? 'waiting' : 'partial';
            }
            $request->update(['status' => 'confirmed']);
            foreach (CryptoCheckoutGuard::requests($order) as $other) {
                if ($other->id === $request->id) {
                    continue;
                }
                if (CryptoCheckoutGuard::hasEvidence($other)) {
                    return $this->review($request, 'multiple_requests_received');
                }
                if (! CryptoCheckoutGuard::covered($other)) {
                    return 'waiting';
                }
            }
            $ledger = $this->balances->creditConfirmedRequest($merchantId, $request->id);
            $this->payments->confirmCryptoPayment($order, $request->refresh(), $ledger, $events->last());

            return 'credited';
        }, 3);
    }

    private function review(CryptoPaymentRequest $request, string $reason): string
    {
        $request->update(['status' => 'review', 'review_reason' => $reason, 'settlement_next_attempt_at' => null]);

        return 'review';
    }
}
