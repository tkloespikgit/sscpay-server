<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressAllocation;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPublicAccount;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoPublicKey;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CryptoAddressAllocationService
{
    public function __construct(private readonly CryptoPaymentConfigurationService $configuration, private readonly TatumReadClient $tatum, private readonly CryptoAssetRegistry $assets) {}

    /** Reserves before HTTP. Failures consume no new index on retry and never release an existing reservation. */
    public function allocate(int $merchantId, int $orderId, int $methodId, string $allocationKey): CryptoAddressAllocation
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $allocationKey)) {
            throw new DomainException('Invalid address allocation idempotency key.');
        }
        $allocation = $this->reserve($merchantId, $orderId, $methodId, $allocationKey);
        if ($allocation->status === 'derived') {
            return $allocation;
        }
        $lockSeconds = max(30, 6 * (int) config('services.tatum.timeout', 10) + 15);

        return Cache::lock('crypto-derive:'.$allocation->id, $lockSeconds)->block(5, function () use ($merchantId, $allocation): CryptoAddressAllocation {
            $fresh = CryptoAddressAllocation::query()->forMerchant($merchantId)->findOrFail($allocation->id);
            if ($fresh->status === 'derived') {
                return $fresh;
            }
            $snapshot = $fresh->public_config_snapshot;
            foreach ([0, 1] as $index) {
                $recovered = $this->tatum->deriveAddress($fresh->network, $snapshot['extended_public_key'], $index);
                if ($this->assets->addressKey($fresh->network, $recovered) !== $this->assets->addressKey($fresh->network, $snapshot['recovery_address_'.$index])) {
                    throw new DomainException('Public derivation does not match the locally recovered addresses.');
                }
            }
            $address = $this->tatum->deriveAddress($fresh->network, $snapshot['extended_public_key'], $fresh->derivation_index);
            $key = $this->assets->addressKey($fresh->network, $address);

            return DB::transaction(function () use ($merchantId, $fresh, $address, $key): CryptoAddressAllocation {
                $locked = CryptoAddressAllocation::query()->forMerchant($merchantId)->lockForUpdate()->findOrFail($fresh->id);
                if ($locked->status === 'derived') {
                    return $locked;
                }
                if (CryptoAddress::query()->withoutGlobalScopes()->where('address_key', $key)->exists()) {
                    throw new DomainException('Derived address is already bound to a payment request.');
                }
                $locked->update(['status' => 'derived', 'address' => $address, 'address_key' => $key, 'recovery_checked_at' => now()]);

                return $locked;
            }, 3);
        });
    }

    private function reserve(int $merchantId, int $orderId, int $methodId, string $key): CryptoAddressAllocation
    {
        return DB::transaction(function () use ($merchantId, $orderId, $methodId, $key): CryptoAddressAllocation {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId);
            $method = PaymentMethod::query()->forMerchant($merchantId)->where('is_active', true)->lockForUpdate()->findOrFail($methodId);
            if ($order->payment_type !== 'crypto' || $order->status !== 'pending' || $order->paid_at !== null
                || (int) $order->payment_method_id !== $methodId || $method->payment_type !== 'crypto' || ! $method->configMap?->is_active
                || CryptoLedgerEntry::query()->forMerchant($merchantId)->where('order_id', $orderId)->exists()) {
                throw new DomainException('Order is not eligible for address allocation.');
            }
            $existing = CryptoAddressAllocation::query()->forMerchant($merchantId)->where('allocation_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->order_id !== $orderId || (int) $existing->payment_method_id !== $methodId) {
                    throw new DomainException('Address allocation key belongs to another payment.');
                }

                return $existing;
            }
            $data = $this->configuration->validate($method->only(['payment_type', 'config_map_id', 'config']), $method);
            $config = $data['config'];
            $family = explode('-', $config['network'], 2)[0];
            $fingerprint = CryptoPublicKey::fingerprint($family, $config['extended_public_key']);
            $source = CryptoPublicAccount::query()->firstOrCreate(['fingerprint' => $fingerprint], [
                'family' => $family, 'extended_public_key' => $config['extended_public_key'],
                'derivation_profile' => $config['derivation_profile'], 'next_index' => 2,
            ]);
            $source = CryptoPublicAccount::query()->lockForUpdate()->findOrFail($source->id);
            if ($source->next_index > 2147483647) {
                throw new DomainException('Public derivation source is exhausted.');
            }
            $index = $source->next_index;
            $source->update(['next_index' => $index + 1]);

            return CryptoAddressAllocation::query()->create([
                'merchant_id' => $merchantId, 'order_id' => $orderId, 'payment_method_id' => $methodId,
                'crypto_public_account_id' => $source->id, 'allocation_key' => $key, 'derivation_index' => $index,
                'network' => $config['network'], 'asset' => $config['asset'], 'status' => 'reserved',
                'public_config_snapshot' => $config,
            ]);
        }, 3);
    }
}
