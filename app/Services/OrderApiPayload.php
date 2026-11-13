<?php

namespace App\Services;

use App\Models\CombinedOrder;
use App\Models\Order;

class OrderApiPayload
{
    /** @return array<string, mixed> */
    public function parent(CombinedOrder $parent): array
    {
        $children = $parent->children()->forMerchant($parent->merchant_id)->withTrashed()->with(['settlement', 'shipping'])->get();
        $shipped = $children->filter(fn (Order $order): bool => $order->shipping !== null)->count();
        $paid = $children->filter(fn (Order $order): bool => $order->paid_at !== null)->count();

        $status = in_array($parent->status, ['pending', 'partially_paid'], true) && $parent->expires_at->isPast()
            ? 'expired' : $parent->status;

        return [
            'order_type' => 'combine', 'order_no' => $parent->order_no, 'merchant_order_no' => $parent->merchant_order_no,
            'status' => $status === 'partially_paid' ? 'pending' : $status,
            'order_status' => $status, 'platform' => $parent->platform, 'currency' => $parent->currency,
            'amount' => $parent->amount, 'converted_currency' => 'USD', 'converted_amount' => $parent->converted_amount,
            'exchange_rate' => $parent->exchange_rate, 'paid_amount' => $parent->paid_amount,
            'paid_converted_amount' => $parent->paid_converted_amount,
            'remaining_amount' => $parent->remaining_amount, 'remaining_converted_amount' => $parent->remaining_converted_amount,
            'payment_method' => null, 'settlement_status' => null,
            'shipping_status' => $shipped === 0 ? 'unshipped' : ($parent->status === 'paid' && $shipped === $paid ? 'shipped' : 'partially_shipped'),
            'current_order_no' => $children->firstWhere('id', $parent->current_order_id)?->order_no,
            'version' => $parent->version, 'children' => $children->map(fn (Order $order): array => $this->child($order))->all(),
            'created_at' => $parent->created_at?->toIso8601String(), 'paid_at' => $parent->paid_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function status(Order $order): array
    {
        $order->loadMissing(['settlement', 'shipping']);

        return [
            'status' => $order->status === 'pending_settlement' ? 'paid' : $order->status,
            'order_status' => $order->status,
            'settlement_status' => $order->settlement?->status,
            'shipping_status' => $order->shipping ? 'shipped' : 'unshipped',
        ];
    }

    /** @return array<string, mixed> */
    public function child(Order $order): array
    {
        $status = $this->status($order);
        $shipping = $order->shipping;

        return [
            'sequence' => $order->combined_sequence, 'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no, ...$status,
            'payment_status' => $order->paid_at !== null ? 'paid' : $order->status,
            'currency' => $order->currency, 'amount' => (string) $order->amount,
            'converted_currency' => $order->converted_currency, 'converted_amount' => (string) $order->converted_amount,
            'payment_method' => $order->payment_method, 'paid_at' => $order->paid_at?->toIso8601String(),
            'logistics' => $shipping ? [
                'logistics_company' => $shipping->logistics_company, 'tracking_number' => $shipping->tracking_number,
                'tracking_url' => $shipping->tracking_url, 'shipped_at' => $shipping->shipped_at?->toIso8601String(),
                'sync_status' => $shipping->sync_status,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function identity(Order $order): array
    {
        $parent = $order->combined_order_id
            ? CombinedOrder::query()->forMerchant($order->merchant_id)->withTrashed()->find($order->combined_order_id) : null;

        return [
            'order_type' => $order->combined_order_id ? 'combine' : 'single',
            'parent_order_no' => $parent?->order_no,
            'parent_merchant_order_no' => $parent?->merchant_order_no,
        ];
    }
}
