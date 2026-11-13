<?php

namespace App\Services;

use App\Models\CombinedOrder;
use App\Models\Order;

class CombinedPaymentStatusService
{
    /** 调用方已锁定商户和子单，父单更新与首次付款事实处于同一事务。 */
    public function apply(Order $order, bool $firstPayment): ?CombinedOrder
    {
        if (! $order->combined_order_id) {
            return null;
        }
        $parent = CombinedOrder::query()->forMerchant($order->merchant_id)->withTrashed()
            ->lockForUpdate()->findOrFail($order->combined_order_id);
        if ($firstPayment) {
            $paid = bcadd($parent->paid_amount, (string) $order->amount, 2);
            $converted = bcadd($parent->paid_converted_amount, (string) $order->converted_amount, 2);
            $remaining = bcsub($parent->amount, $paid, 2);
            $remainingConverted = bcsub($parent->converted_amount, $converted, 2);
            $review = $parent->status === 'review' || $parent->trashed()
                || bccomp($remaining, '0', 2) < 0 || bccomp($remainingConverted, '0', 2) < 0
                || $parent->current_order_id !== $order->id;
            $complete = ! $review && bccomp($remaining, '0', 2) === 0 && bccomp($remainingConverted, '0', 2) === 0;
            $parent->fill([
                'paid_amount' => $paid, 'paid_converted_amount' => $converted,
                'remaining_amount' => bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining,
                'remaining_converted_amount' => bccomp($remainingConverted, '0', 2) < 0 ? '0.00' : $remainingConverted,
                'status' => $review ? 'review' : ($complete ? 'paid' : ($parent->expires_at->isPast() ? 'expired' : 'partially_paid')),
                'current_quote' => null, 'first_paid_at' => $parent->first_paid_at ?? $order->paid_at,
                'paid_at' => $complete ? ($parent->paid_at ?? $order->paid_at) : $parent->paid_at,
                'version' => $parent->version + 1, 'last_error_code' => $review ? 'COMBINE_REVIEW_REQUIRED' : null,
            ]);
            if ($parent->current_order_id === $order->id) {
                $parent->current_order_id = null;
            }
            $parent->save();
        } elseif (in_array($order->status, ['cancelled', 'expired', 'refunded', 'partially_refunded', 'chargeback', 'disputing', 'dispute_review'], true)) {
            $parent->update(['status' => 'review', 'current_quote' => null, 'last_error_code' => 'COMBINE_REVIEW_REQUIRED']);
        }

        return $parent;
    }

    public function rejectAmount(Order $order): void
    {
        CombinedOrder::query()->forMerchant($order->merchant_id)->whereKey($order->combined_order_id)
            ->update(['status' => 'review', 'current_quote' => null, 'last_error_code' => 'PAYMENT_AMOUNT_MISMATCH']);
    }

    public function amountMatches(Order $order, array $payload): bool
    {
        $amount = $payload['amount'] ?? null;

        return (is_string($amount) || is_int($amount) || is_float($amount))
            && preg_match('/^\d+(\.\d{1,8})?$/D', (string) $amount) === 1
            && bccomp((string) $amount, (string) $order->amount, 8) === 0
            && strtoupper((string) ($payload['currency'] ?? '')) === strtoupper((string) $order->currency);
    }
}
