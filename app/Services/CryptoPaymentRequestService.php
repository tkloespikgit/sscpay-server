<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressAllocation;
use App\Models\CryptoCheckout;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoCheckoutGuard;
use App\Support\CryptoVerificationPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Persists prepared requests only. Address derivation and monitor readiness belong to the chain integration. */
final class CryptoPaymentRequestService
{
    public function __construct(
        private readonly CryptoQuoteService $quotes,
        private readonly CryptoAssetRegistry $assets,
        private readonly CryptoAddressAllocationService $allocations,
        private readonly CryptoMarketPriceService $prices,
        private readonly CryptoPaymentConfigurationService $configuration,
    ) {}

    public function prepareAllocated(int $merchantId, int $orderId, int $methodId, string $allocationKey, int $scanFromBlock, ?int $replacesRequestId = null): CryptoPaymentRequest
    {
        $order = Order::query()->forMerchant($merchantId)->findOrFail($orderId);
        if ($order->payment_type !== 'crypto' || (int) $order->payment_method_id !== $methodId) {
            throw new DomainException('Order does not match the crypto payment method.');
        }
        $existing = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $orderId)
            ->where('payment_method_id', $methodId)->where('replaces_request_id', $replacesRequestId)->first();
        if ($existing) {
            if ($existing->expires_at->isPast()) {
                throw new DomainException('Expired payment requires an explicit requote workflow.');
            }

            return $existing;
        }
        $allocation = $this->allocations->allocate($merchantId, $orderId, $methodId, $allocationKey);
        $price = $this->prices->usdPrice($allocation->network.':'.$allocation->asset);

        return $this->prepare($merchantId, $orderId, $methodId, $allocation->address, $allocation->publicAccount->fingerprint,
            $allocation->derivation_index, $scanFromBlock, $price['value'], $price['at'], $allocation->id, $price['source'], $replacesRequestId);
    }

    public function prepare(int $merchantId, int $orderId, int $methodId, string $verifiedAddress, string $sourceFingerprint, int $derivationIndex, int $scanFromBlock, string $priceUsd, CarbonImmutable $priceAt, ?int $allocationId = null, string $priceSource = 'provided', ?int $replacesRequestId = null): CryptoPaymentRequest
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $sourceFingerprint) || $derivationIndex < 0 || $derivationIndex > 2147483647 || $scanFromBlock < 0) {
            throw new DomainException('Invalid public derivation reference.');
        }

        return DB::transaction(function () use ($merchantId, $orderId, $methodId, $verifiedAddress, $sourceFingerprint, $derivationIndex, $scanFromBlock, $priceUsd, $priceAt, $allocationId, $priceSource, $replacesRequestId): CryptoPaymentRequest {
            $merchant = Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId);
            $method = PaymentMethod::query()->forMerchant($merchantId)->where('is_active', true)->findOrFail($methodId);

            if ($order->payment_type !== 'crypto' || $method->payment_type !== 'crypto' || $method->configMap?->payment_type !== 'crypto'
                || ! $method->configMap?->is_active || (int) $order->payment_method_id !== $methodId || $order->paid_at !== null
                || $order->status !== 'pending') {
                throw new DomainException('Order or payment method is not eligible for crypto payment.');
            }

            $existing = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $orderId)->where('replaces_request_id', $replacesRequestId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->expires_at->isPast()) {
                    throw new DomainException('Expired payment requires an explicit requote workflow.');
                }

                return $existing;
            }

            if (CryptoLedgerEntry::query()->forMerchant($merchantId)->where('order_id', $orderId)->exists()) {
                throw new DomainException('Order has already been credited.');
            }

            if ($replacesRequestId !== null) {
                CryptoCheckoutGuard::assertRequote($order, $replacesRequestId);
            }

            $config = $method->config ?? [];
            if ($allocationId !== null) {
                $allocation = CryptoAddressAllocation::query()->forMerchant($merchantId)->where('order_id', $orderId)
                    ->where('payment_method_id', $methodId)->lockForUpdate()->findOrFail($allocationId);
                $config = $this->configuration->validateConfig($config);
                $snapshot = $allocation->public_config_snapshot;
                $currentConfig = $config;
                ksort($snapshot);
                ksort($currentConfig);
                if ($allocation->status !== 'derived' || $snapshot !== $currentConfig
                    || $allocation->address !== $verifiedAddress || $allocation->derivation_index !== $derivationIndex
                    || $allocation->publicAccount->fingerprint !== $sourceFingerprint) {
                    throw new DomainException('Payment configuration changed during address preparation.');
                }
            }
            $asset = $this->assets->get(($config['network'] ?? '').':'.($config['asset'] ?? ''));
            if ($replacesRequestId !== null) {
                $previous = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $orderId)->findOrFail($replacesRequestId);
                if ($previous->network !== $asset['network'] || $previous->asset !== $asset['asset']) {
                    throw new DomainException('Requote cannot change the locked payment asset or network.');
                }
            }
            $ttl = $this->positiveSetting('crypto.quote_ttl_seconds');
            $maxAge = $this->positiveSetting('crypto.max_market_price_age_seconds');
            if ($priceAt->getTimestampMs() > now()->getTimestampMs() || $priceAt->getTimestampMs() < now()->getTimestampMs() - $maxAge * 1000) {
                throw new DomainException('Market price is stale or has a future timestamp.');
            }
            $markup = SystemConfig::get('crypto.quote_markup_percent');
            if (! is_string($markup)) {
                throw new DomainException('Crypto quote markup has not been configured.');
            }
            $checkout = CryptoCheckout::query()->forMerchant($merchantId)->where('order_id', $orderId)->lockForUpdate()->first();
            $settings = $checkout?->fee_snapshot ?? $method->configurationForMerchant($merchant);
            $quote = $this->quotes->calculate(
                $asset['network'].':'.$asset['asset'], (string) $order->amount, $order->currency,
                $order->currency === 'USD' ? '1' : (string) $order->original_exchange_rate,
                $priceUsd, $markup, (string) $settings['fee_percent'], (string) $settings['fee_fixed'],
            );
            $address = CryptoAddress::query()->create([
                'crypto_address_allocation_id' => $allocationId,
                'merchant_id' => $merchantId, 'order_id' => $orderId, 'payment_method_id' => $methodId,
                'network' => $asset['network'], 'address' => $verifiedAddress,
                'address_key' => $this->assets->addressKey($asset['network'], $verifiedAddress),
                'source_fingerprint' => $sourceFingerprint, 'derivation_index' => $derivationIndex,
                'scan_from_block' => $scanFromBlock,
            ]);

            $request = CryptoPaymentRequest::query()->create([
                'replaces_request_id' => $replacesRequestId,
                'merchant_id' => $merchantId, 'order_id' => $orderId, 'payment_method_id' => $methodId,
                'crypto_address_id' => $address->id, ...$asset, 'status' => 'preparing',
                'due_units' => $quote['due_units'], 'fee_units' => $quote['fee_units'], 'net_units' => $quote['net_units'],
                'quote_snapshot' => [...$quote, 'price_at' => $priceAt->toIso8601String(), 'price_source' => $priceSource,
                    'payment_config_snapshot' => $config,
                    'verification_policy' => CryptoVerificationPolicy::snapshot($asset['network']),
                ],
                'expires_at' => now()->addSeconds($ttl),
            ]);
            $checkout?->update(['current_request_id' => $request->id, 'action' => 'open', 'phase' => 'backfill',
                'handoff_at' => null, 'ready_at' => null, 'next_attempt_at' => now()]);

            return $request;
        }, 3);
    }

    private function positiveSetting(string $key): int
    {
        $value = SystemConfig::get($key);
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,6}$/D', $value)) {
            throw new DomainException('Crypto quote duration settings are missing or invalid.');
        }

        return (int) $value;
    }
}
