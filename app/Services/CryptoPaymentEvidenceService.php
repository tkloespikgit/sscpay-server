<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Models\CryptoChainEvent;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Order;
use App\Support\CryptoAmount;
use App\Support\CryptoCheckoutGuard;

final class CryptoPaymentEvidenceService
{
    /**
     * Fingerprint economic facts, not scan timestamps or mutable workflow labels.
     * No HTTP here: a fresh, complete historical scan is required before human reconciliation.
     *
     * @return array{hash: string, snapshot: array, gross: string, request: CryptoPaymentRequest, last_event: ?CryptoChainEvent, healthy: bool}
     */
    public function capture(Order $order, bool $requireHealthy = false): array
    {
        $requests = CryptoCheckoutGuard::requests($order);
        if ($order->payment_type !== 'crypto' || $requests->isEmpty() || $requests->count() > 50) {
            throw new BalanceOperationException(__('crypto.review.errors.evidence'));
        }
        $income = CryptoLedgerEntry::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)->first();
        $requests->load(['chainEvents' => fn ($q) => $q->forMerchant($order->merchant_id)->orderBy('id'), 'cryptoAddress']);
        $root = $income ? $requests->firstWhere('id', $income->crypto_payment_request_id)
            : ($requests->first(fn ($r) => $r->chainEvents->contains(fn ($e) => $e->orphaned_at === null && $e->confirmed_at !== null)) ?? $requests->first());
        if (! $root) {
            throw new BalanceOperationException(__('crypto.review.errors.evidence'));
        }
        $snapshot = [];
        $healthy = true;
        $gross = '0';
        $last = null;
        foreach ($requests as $request) {
            $address = $request->cryptoAddress;
            $healthy = $healthy && CryptoCheckoutGuard::covered($request)
                && $request->network === $root->network && $request->asset === $root->asset
                && $address && (int) $address->merchant_id === (int) $order->merchant_id
                && (int) $address->order_id === (int) $order->id && $address->network === $request->network;
            $events = [];
            foreach ($request->chainEvents as $event) {
                $events[] = [$event->id, $event->transaction_hash, $event->event_position, $event->network, $event->asset,
                    $event->amount_units, $event->block_height, $event->block_hash, $event->block_time?->toIso8601String(),
                    $event->verified_at !== null, $event->confirmed_at !== null, $event->orphaned_at !== null];
                if ($event->orphaned_at !== null) {
                    continue;
                }
                $healthy = $healthy && $event->verified_at !== null && $event->confirmed_at !== null
                    && $event->network === $root->network && $event->asset === $root->asset
                    && $event->block_time !== null && $event->block_time->gte($request->created_at)
                    && $event->block_height >= ($address?->scan_from_block ?? PHP_INT_MAX)
                    && $event->block_height <= ($request->history_to_block ?? -1);
                $gross = CryptoAmount::units(bcadd($gross, CryptoAmount::units($event->amount_units), 0));
                if ($request->id === $root->id && ($last === null || $event->block_time?->gt($last->block_time))) {
                    $last = $event;
                }
            }
            $receipts = CryptoWebhookReceipt::query()->forMerchant($order->merchant_id)
                ->where('crypto_payment_request_id', $request->id)->orderBy('id')->get();
            foreach ($receipts as $receipt) {
                if ($receipt->last_error_code === 'credited_transaction_unavailable'
                    && ! $request->chainEvents->contains(fn ($e) => $e->transaction_hash === $receipt->transaction_hash && $e->orphaned_at !== null)) {
                    $healthy = false;
                }
                $healthy = $healthy && (in_array($receipt->status, ['verified', 'ignored'], true)
                    || ($receipt->status === 'review' && in_array($receipt->last_error_code, ['manual_review_required', 'canonical_block_changed', 'credited_transaction_unavailable'], true)));
            }
            $snapshot[] = ['request_id' => $request->id, 'address_id' => $request->crypto_address_id,
                'network' => $request->network, 'asset' => $request->asset, 'quote' => $request->quote_snapshot,
                'events' => $events, 'receipts' => $receipts->map(fn ($r) => [$r->id, $r->transaction_hash, $r->status, $r->last_error_code])->all()];
        }
        if ($requireHealthy && ! $healthy) {
            throw new BalanceOperationException(__('crypto.review.errors.evidence'));
        }

        $economic = array_map(function (array $item): array {
            $receipts = array_unique(array_map(fn (array $r): string => json_encode(array_slice($r, 1), JSON_THROW_ON_ERROR), $item['receipts']));
            sort($receipts);
            $item['receipts'] = array_values($receipts);

            return $item;
        }, $snapshot);

        return ['hash' => hash('sha256', json_encode($economic, JSON_THROW_ON_ERROR)), 'snapshot' => $snapshot,
            'gross' => $gross, 'request' => $root, 'last_event' => $last, 'healthy' => $healthy];
    }
}
