<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressMonitor;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\Order;
use App\Support\CryptoAssetRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TatumWebhookService
{
    public function __construct(private readonly CryptoAssetRegistry $assets) {}

    /** @param array<string, mixed> $payload */
    public function receive(CryptoAddressMonitor $monitor, array $payload, string $raw, string $signatureMode): ?CryptoWebhookReceipt
    {
        $hint = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        if (($hint['subscriptionType'] ?? null) !== 'ADDRESS_EVENT') {
            return null;
        }
        $txid = $hint['txId'] ?? null;
        if (! is_string($txid) || ! preg_match('/^(?:0x)?[a-fA-F0-9]{64}$/D', $txid)
            || (isset($hint['chain']) && $hint['chain'] !== $monitor->network)
            || ($monitor->subscription_id !== null && isset($hint['subscriptionId']) && $hint['subscriptionId'] !== $monitor->subscription_id)) {
            throw ValidationException::withMessages(['notification' => 'Notification does not match the monitored chain or subscription.']);
        }
        $address = CryptoAddress::query()->forMerchant($monitor->merchant_id)->where('network', $monitor->network)->findOrFail($monitor->crypto_address_id);
        $monitoredKey = $this->assets->addressKey($monitor->network, $address->address);
        $containsAddress = false;
        foreach (['address', 'from', 'to', 'counterAddress'] as $field) {
            if (! is_string($hint[$field] ?? null)) {
                continue;
            }
            try {
                $containsAddress = $containsAddress || $this->assets->addressKey($monitor->network, $hint[$field]) === $monitoredKey;
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        if (! $containsAddress) {
            throw ValidationException::withMessages(['notification' => 'Notification does not mention the monitored address.']);
        }
        $safeHint = Arr::only($hint, ['txId', 'chain', 'subscriptionType', 'subscriptionId', 'address', 'from', 'to', 'counterAddress',
            'asset', 'currency', 'type', 'amount', 'value', 'contractAddress', 'blockHash', 'blockNumber', 'blockTimestamp', 'mempool', 'logIndex']);
        foreach ($safeHint as $field => $value) {
            if ((! is_scalar($value) && $value !== null) || is_float($value)) {
                unset($safeHint[$field]);
            }
        }

        return DB::transaction(function () use ($monitor, $address, $raw, $txid, $safeHint, $signatureMode): CryptoWebhookReceipt {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($monitor->merchant_id);
            Order::query()->withoutGlobalScopes()->where('merchant_id', $monitor->merchant_id)->lockForUpdate()->findOrFail($address->order_id);
            CryptoPaymentRequest::query()->forMerchant($monitor->merchant_id)->where('order_id', $address->order_id)
                ->where('crypto_address_id', $address->id)->where('network', $monitor->network)
                ->lockForUpdate()->findOrFail($monitor->crypto_payment_request_id);

            return CryptoWebhookReceipt::query()->forMerchant($monitor->merchant_id)->firstOrCreate([
                'merchant_id' => $monitor->merchant_id, 'crypto_address_monitor_id' => $monitor->id, 'payload_hash' => hash('sha256', $raw),
            ], [
                'crypto_payment_request_id' => $monitor->crypto_payment_request_id,
                'transaction_hash' => strtolower(preg_replace('/^0x/', '', $txid)), 'hint_payload' => $safeHint,
                'signature_mode' => $signatureMode, 'status' => 'pending', 'received_at' => now(),
            ]);
        }, 3);
    }
}
