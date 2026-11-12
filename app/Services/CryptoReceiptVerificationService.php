<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\Order;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoVerificationPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Durable receipt consumer; never mutates order payment facts or balances. */
final class CryptoReceiptVerificationService
{
    public function __construct(
        private readonly CryptoEvmVerificationService $evm,
        private readonly CryptoUtxoVerificationService $utxo,
        private readonly CryptoTronVerificationService $tron,
        private readonly CryptoAssetRegistry $assets,
    ) {}

    public function process(int $merchantId, int $receiptId): bool
    {
        if (! config('services.tatum.verification_enabled')) {
            throw new DomainException('Crypto verification is disabled.');
        }
        $receipt = CryptoWebhookReceipt::query()->forMerchant($merchantId)->findOrFail($receiptId);
        $lock = Cache::lock('crypto:verify-request:'.$receipt->crypto_payment_request_id, 600);
        if (! $lock->get()) {
            return false;
        }
        try {
            $receipt = CryptoWebhookReceipt::query()->forMerchant($merchantId)->findOrFail($receiptId);
            if (in_array($receipt->status, ['ignored', 'review'], true) || $receipt->next_attempt_at?->isFuture()) {
                return true;
            }
            try {
                $request = CryptoPaymentRequest::query()->forMerchant($merchantId)->findOrFail($receipt->crypto_payment_request_id);
                $address = CryptoAddress::query()->forMerchant($merchantId)->where('order_id', $request->order_id)
                    ->where('payment_method_id', $request->payment_method_id)->where('network', $request->network)->findOrFail($request->crypto_address_id);
                $monitor = CryptoAddressMonitor::query()->forMerchant($merchantId)
                    ->where('crypto_payment_request_id', $request->id)->where('crypto_address_id', $address->id)->findOrFail($receipt->crypto_address_monitor_id);
                if ($monitor->network !== $request->network
                    || $monitor->environment !== (str_ends_with($request->network, '-mainnet') ? 'mainnet' : 'testnet')) {
                    throw new DomainException('Unsupported receipt network.');
                }
                $policy = $request->quote_snapshot['verification_policy'] ?? [];
                $config = $request->quote_snapshot['payment_config_snapshot'] ?? [];
                $minimum = $policy['minimum_confirmations'] ?? null;
                $version = CryptoVerificationPolicy::version($request->network);
                $asset = $this->assets->get($request->network.':'.$request->asset);
                if (($policy['version'] ?? null) !== $version
                    || (! is_string($minimum) && ! is_int($minimum)) || ! preg_match('/^[1-9][0-9]{0,5}$/D', (string) $minimum)
                    || (int) $minimum > 100000 || ($config['network'] ?? null) !== $request->network || ($config['asset'] ?? null) !== $request->asset
                    || $request->decimals !== $asset['decimals']) {
                    throw new DomainException('Frozen verification policy is missing or invalid.');
                }
                $genesis = $policy['genesis_hash'] ?? '';
                if ($version !== 'evm-finalized-v1' && (! is_string($genesis) || ! preg_match('/^[a-fA-F0-9]{64}$/D', $genesis))) {
                    throw new DomainException('Frozen genesis hash is missing.');
                }
                $observation = match ($version) {
                    'evm-finalized-v1' => $this->evm->observe($request->network, $request->asset, $address->address,
                        $receipt->transaction_hash, $config['contract_address'] ?? null, (int) $minimum, ($policy['internal_transfers'] ?? false) === true),
                    'utxo-canonical-v1' => $this->utxo->observe($request->network, $request->asset, $address->address,
                        $receipt->transaction_hash, $genesis, (int) $minimum),
                    'tron-solidified-v1' => $this->tron->observe($request->network, $address->address,
                        $receipt->transaction_hash, $config['contract_address'] ?? '', $genesis, (int) $minimum),
                };
                $this->persist($receipt, $request, $observation);

                return true;
            } catch (\Throwable) {
                $receipt->refresh()->update([
                    'status' => $receipt->status === 'verified' ? 'confirming' : $receipt->status,
                    'processed_at' => null,
                    'verification_attempts' => min(4294967295, $receipt->verification_attempts + 1),
                    'last_checked_at' => now(), 'last_error_code' => 'verification_failed',
                    'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min(7, $receipt->verification_attempts)))),
                ]);

                return false;
            }
        } finally {
            $lock->release();
        }
    }

    /** @param array{state: string, confirmed: bool, block?: array{hash: string, height: int, time: CarbonImmutable}, transfers: list<array{position: string, units: string}>} $observation */
    private function persist(CryptoWebhookReceipt $receipt, CryptoPaymentRequest $request, array $observation): void
    {
        if (isset($observation['block'])) {
            $observation['block']['time'] = $observation['block']['time']->setTimezone(config('app.timezone'));
        }
        DB::transaction(function () use ($receipt, $request, $observation): void {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($receipt->merchant_id);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $receipt->merchant_id)->lockForUpdate()->findOrFail($request->order_id);
            $request = CryptoPaymentRequest::query()->forMerchant($receipt->merchant_id)->where('order_id', $order->id)->lockForUpdate()->findOrFail($request->id);
            $receipt = CryptoWebhookReceipt::query()->forMerchant($receipt->merchant_id)->where('crypto_payment_request_id', $request->id)->lockForUpdate()->findOrFail($receipt->id);
            if ($order->payment_type !== 'crypto') {
                throw new DomainException('Receipt is not bound to a crypto order.');
            }
            $request->update(['settlement_next_attempt_at' => now()]);
            $existing = CryptoChainEvent::query()->forMerchant($receipt->merchant_id)->where('crypto_payment_request_id', $request->id)
                ->where('transaction_hash', $receipt->transaction_hash)->orderBy('id')->lockForUpdate()->get();
            $changes = ['verification_attempts' => min(4294967295, $receipt->verification_attempts + 1), 'last_checked_at' => now(),
                'last_error_code' => null, 'processed_at' => null, 'next_attempt_at' => now()->addSeconds(60)];
            if ($observation['state'] === 'pending') {
                foreach ($existing as $event) {
                    $event->update(['confirmed_at' => null]);
                }
                $receipt->update([...$changes, 'status' => $request->credited_at ? 'review' : 'pending',
                    'last_error_code' => $request->credited_at ? 'credited_transaction_unavailable' : null]);

                return;
            }
            $positions = array_column($observation['transfers'], 'position');
            foreach ($existing as $event) {
                if (! in_array($event->event_position, $positions, true)) {
                    $event->update(['orphaned_at' => $event->orphaned_at ?? now(), 'confirmed_at' => null]);
                }
            }
            if ($observation['state'] === 'reorg') {
                $receipt->update([...$changes, 'status' => $request->credited_at ? 'review' : 'pending', 'last_error_code' => 'canonical_block_changed']);

                return;
            }
            $review = false;
            $reviewCode = 'manual_review_required';
            $address = CryptoAddress::query()->forMerchant($receipt->merchant_id)->findOrFail($request->crypto_address_id);
            foreach ($observation['transfers'] as $transfer) {
                $event = CryptoChainEvent::query()->forMerchant($receipt->merchant_id)->firstOrCreate([
                    'network' => $request->network, 'transaction_hash' => $receipt->transaction_hash, 'event_position' => $transfer['position'],
                ], [
                    'merchant_id' => $receipt->merchant_id, 'crypto_payment_request_id' => $request->id, 'asset' => $request->asset,
                    'amount_units' => $transfer['units'], 'block_hash' => $observation['block']['hash'], 'block_height' => $observation['block']['height'],
                    'block_time' => $observation['block']['time'],
                ]);
                if ((int) $event->crypto_payment_request_id !== (int) $request->id || $event->asset !== $request->asset) {
                    throw new DomainException('Economic event is already bound to a different payment.');
                }
                if ($event->amount_units !== $transfer['units']) {
                    $event->update(['orphaned_at' => $event->orphaned_at ?? now(), 'confirmed_at' => null]);
                    $review = true;
                    $reviewCode = 'economic_event_changed';

                    continue;
                }
                $event->update(['block_hash' => $observation['block']['hash'], 'block_height' => $observation['block']['height'],
                    'block_time' => $observation['block']['time'], 'verified_at' => now(), 'orphaned_at' => null,
                    'confirmed_at' => $observation['confirmed'] ? ($event->confirmed_at ?? now()) : null,
                ]);
                if ($observation['block']['time']->lt($request->created_at) || $observation['block']['time']->gt($request->expires_at)
                    || $observation['block']['height'] < $address->scan_from_block) {
                    $review = true;
                }
            }
            if ($existing->contains(fn (CryptoChainEvent $event): bool => $event->orphaned_at !== null) && $request->credited_at) {
                $review = true;
            }
            $status = $review ? 'review' : ($positions === [] ? 'ignored' : ($observation['confirmed'] ? 'verified' : 'confirming'));
            $receipt->update([...$changes, 'status' => $status, 'processed_at' => in_array($status, ['ignored', 'verified', 'review'], true) ? now() : null,
                'next_attempt_at' => in_array($status, ['ignored', 'verified', 'review'], true) ? null
                    : now()->addSeconds(min(300, 30 * (2 ** min(4, $receipt->verification_attempts)))),
                'last_error_code' => $review ? $reviewCode : null,
            ]);
        }, 3);
    }
}
