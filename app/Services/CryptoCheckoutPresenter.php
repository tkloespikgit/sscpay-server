<?php

namespace App\Services;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoCheckout;
use App\Models\CryptoPaymentRequest;
use App\Models\Order;
use App\Support\CryptoAmount;
use App\Support\CryptoCheckoutGuard;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Cache;

final class CryptoCheckoutPresenter
{
    /** Only public fields. Never serialize a quote's public account, configuration or provider credentials. */
    public function data(Order $order): array
    {
        $checkout = CryptoCheckout::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)->first();
        $requests = CryptoCheckoutGuard::requests($order);
        $current = $requests->firstWhere('id', $checkout?->current_request_id);
        $state = 'checking';
        if ($order->paid_at !== null) {
            $state = 'paid';
        } elseif (! CryptoCheckoutGuard::available($order) || ! $checkout) {
            $state = 'unavailable';
        } elseif ($requests->contains(fn ($request) => $request->status === 'review' || $request->review_reason !== null)) {
            $state = 'review';
        } elseif ($requests->contains(fn ($request) => CryptoCheckoutGuard::hasEvidence($request))) {
            $state = $current?->status === 'partial' ? 'partial' : 'confirming';
        } elseif ($current && $checkout->status === 'expired' && ! $current->expires_at->isFuture()) {
            $state = 'expired';
        } elseif ($current && $checkout->status === 'ready' && $checkout->ready_at !== null && $checkout->handoff_at !== null
            && $current->status === 'ready' && $current->expires_at->isFuture()) {
            $monitor = CryptoAddressMonitor::query()->forMerchant($order->merchant_id)->where('crypto_payment_request_id', $current->id)
                ->where('crypto_address_id', $current->crypto_address_id)->find($current->current_monitor_id);
            if ($monitor?->status === 'active' && $monitor->expires_at?->isFuture()
                && ! $requests->contains(fn ($request) => ! CryptoCheckoutGuard::covered($request, $checkout->handoff_at))) {
                $state = 'ready';
            }
        }
        $amounts = $current ? $this->amounts($current) : [];
        $address = $state === 'ready' ? $current->cryptoAddress?->address : null;

        return [
            'state' => $state, 'message' => __('crypto.checkout.states.'.$state),
            'request_id' => $current?->id, 'can_requote' => $state === 'expired',
            'network' => $current?->network, 'asset' => $current?->asset,
            'due_amount' => $current ? CryptoAmount::format($current->due_units, $current->decimals) : null,
            'expires_at' => $current?->expires_at->toIso8601String(),
            'markup_percent' => $current?->quote_snapshot['markup_percent'] ?? null,
            'price_usd' => $current?->quote_snapshot['price_usd'] ?? null,
            'price_at' => $current?->quote_snapshot['price_at'] ?? null,
            'address' => $address,
            'qr' => $address ? Cache::remember('crypto-address-qr:'.hash('sha256', $address), 86400, fn () => (new QRCode)->render($address)) : null,
            ...$amounts,
        ];
    }

    private function amounts(CryptoPaymentRequest $request): array
    {
        $received = $confirmed = '0';
        foreach (CryptoChainEvent::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)
            ->whereNull('orphaned_at')->get(['amount_units', 'verified_at', 'confirmed_at']) as $event) {
            $received = bcadd($received, $event->amount_units, 0);
            if ($event->confirmed_at !== null && $event->verified_at !== null) {
                $confirmed = bcadd($confirmed, $event->amount_units, 0);
            }
        }
        $remaining = bccomp($received, $request->due_units, 0) >= 0 ? '0' : bcsub($request->due_units, $received, 0);

        return ['received_amount' => CryptoAmount::format($received, $request->decimals),
            'confirmed_amount' => CryptoAmount::format($confirmed, $request->decimals),
            'remaining_amount' => CryptoAmount::format($remaining, $request->decimals)];
    }
}
