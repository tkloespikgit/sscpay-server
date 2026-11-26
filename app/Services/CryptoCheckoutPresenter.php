<?php

namespace App\Services;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoCheckout;
use App\Models\CryptoPaymentRequest;
use App\Models\Order;
use App\Support\CryptoAmount;
use App\Support\CryptoAssetRegistry;
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
        $paymentUri = $address ? $this->paymentUri($current, $address) : null;

        return [
            'state' => $state, 'message' => __('crypto.checkout.states.'.$state),
            'request_id' => $current?->id, 'can_requote' => $state === 'expired',
            'network' => $current?->network, 'asset' => $current?->asset,
            'due_amount' => $current ? $this->amount($current, $current->due_units) : null,
            'expires_at' => $current?->expires_at->toIso8601String(),
            'markup_percent' => $current?->quote_snapshot['markup_percent'] ?? null,
            'price_usd' => $current?->quote_snapshot['price_usd'] ?? null,
            'price_at' => $current?->quote_snapshot['price_at'] ?? null,
            'address' => $address,
            'payment_uri' => $paymentUri,
            'qr_type' => $address ? ($paymentUri !== $address ? 'amount' : 'address') : null,
            'qr' => $paymentUri ? Cache::remember('crypto-payment-qr:'.hash('sha256', $paymentUri), 86400, fn () => (new QRCode)->render($paymentUri)) : null,
            ...$amounts,
        ];
    }

    private function paymentUri(CryptoPaymentRequest $request, string $address): string
    {
        $amount = $this->amount($request, $request->due_units);
        if ($request->asset === 'BTC' && in_array($request->network, ['bitcoin-mainnet', 'bitcoin-testnet'], true)) {
            return 'bitcoin:'.$address.'?amount='.$amount;
        }
        if ($request->asset === 'LTC' && in_array($request->network, ['litecoin-mainnet', 'litecoin-testnet'], true)) {
            return 'litecoin:'.$address.'?amount='.$amount;
        }
        if (in_array($request->network, ['ethereum-mainnet', 'ethereum-sepolia'], true)) {
            $chainId = $request->network === 'ethereum-mainnet' ? '1' : '11155111';
            if ($request->asset === 'ETH') {
                return 'ethereum:'.$address.'@'.$chainId.'?value='.$request->due_units;
            }
            $contract = $request->quote_snapshot['payment_config_snapshot']['contract_address'] ?? null;
            if ($request->asset === 'USDT' && is_string($contract) && preg_match('/^0x[0-9a-fA-F]{40}$/D', $contract)) {
                return 'ethereum:'.$contract.'@'.$chainId.'/transfer?address='.$address.'&uint256='.$request->due_units;
            }
        }

        return $address;
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

        return ['received_amount' => $this->amount($request, $received),
            'confirmed_amount' => $this->amount($request, $confirmed),
            'remaining_amount' => $this->amount($request, $remaining)];
    }

    private function amount(CryptoPaymentRequest $request, string $units): string
    {
        return CryptoAmount::present($units, $request->decimals, app(CryptoAssetRegistry::class)->payableDecimals($request->network, $request->asset));
    }
}
