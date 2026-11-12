<?php

namespace App\Services;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\SystemConfig;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoTronAddress;
use App\Support\CryptoVerificationPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** All-merchant infrastructure worker. Every write rechecks the permanent merchant/order/address binding. */
final class CryptoHistoryScanService
{
    public function __construct(private readonly CryptoBlockHistoryService $blocks, private readonly CryptoAssetRegistry $assets) {}

    /** One complete block shared by up to 500 requests at the same cursor; HTTP never holds financial locks. */
    public function scan(string $network, ?int $merchantId = null, ?int $requestId = null): bool
    {
        if (! config('services.tatum.history_enabled')) {
            throw new DomainException('Crypto history scanning is disabled.');
        }
        CryptoVerificationPolicy::version($network);
        if (($merchantId === null) !== ($requestId === null)) {
            throw new DomainException('Targeted history scanning requires both merchant and request.');
        }
        $lock = Cache::lock('crypto:history:'.$network, 600);
        if (! $lock->get()) {
            return false;
        }
        $requests = collect();
        try {
            $due = $this->due($network)->when($merchantId !== null, fn ($query) => $query->where('merchant_id', $merchantId)->whereKey($requestId));
            $seed = (clone $due)->with(['cryptoAddress' => fn ($query) => $query->withoutGlobalScopes()])->orderBy('history_next_attempt_at')->orderBy('id')->first();
            if (! $seed) {
                return false;
            }
            $height = $seed->history_to_block === null ? $seed->cryptoAddress->scan_from_block : $seed->history_to_block + 1;
            $requests = (clone $due)->with(['cryptoAddress' => fn ($query) => $query->withoutGlobalScopes()])
                ->where(function (Builder $query) use ($height): void {
                    $query->where('history_to_block', $height - 1)->orWhere(function (Builder $query) use ($height): void {
                        $query->whereNull('history_to_block')->whereHas('cryptoAddress', fn (Builder $address) => $address->withoutGlobalScopes()->where('scan_from_block', $height));
                    });
                })->orderBy('merchant_id')->orderBy('id')->limit(500)->get();
            $interval = $this->interval();
            $genesis = null;
            $identitySelected = false;
            $includeEth = false;
            foreach ($requests as $index => $request) {
                try {
                    $this->validate($request);
                } catch (DomainException) {
                    $this->fail($request, 'invalid_history_policy');
                    $requests->forget($index);

                    continue;
                }
                $pin = $request->quote_snapshot['verification_policy']['genesis_hash'] ?? null;
                if ($identitySelected && $pin !== $genesis) {
                    $requests->forget($index);

                    continue;
                }
                $genesis = $pin;
                $identitySelected = true;
                $includeEth = $includeEth || $request->asset === 'ETH';
            }
            if ($requests->isEmpty()) {
                return true;
            }
            $this->blocks->assertNetwork($network, $genesis);
            $head = $this->blocks->head($network);
            $maxAge = config('services.tatum.history_max_head_age_seconds');
            if ((! is_int($maxAge) && ! is_string($maxAge)) || ! preg_match('/^[1-9][0-9]{0,4}$/D', (string) $maxAge)
                || (int) $maxAge > 86400 || $head['time']->lt(now()->subSeconds((int) $maxAge))) {
                throw new DomainException('History chain head is stale or freshness is unconfigured.');
            }
            $previous = $height > 0 && $height - 1 <= $head['height'] ? $this->blocks->header($network, $height - 1) : null;
            $canonicalHeaders = [$head['height'] => $head];
            if ($previous !== null) {
                $canonicalHeaders[$previous['height']] = $previous;
            }
            foreach ($requests as $index => $request) {
                if ($request->history_to_block !== null && ($previous === null || $previous['hash'] !== $request->history_to_hash)) {
                    $this->rewind($request, $head['height'], $canonicalHeaders);
                    $requests->forget($index);
                }
            }
            if ($requests->isEmpty()) {
                return true;
            }
            if ($height > $head['height']) {
                foreach ($requests as $request) {
                    if ($request->history_to_block === $head['height'] && $request->history_to_hash === $head['hash']) {
                        $this->persist($request, null, [], true, $interval);
                    } else {
                        $this->fail($request, 'scan_start_ahead_of_head');
                    }
                }

                return true;
            }
            $block = $this->blocks->block($network, $height, $includeEth);
            if ($previous !== null && $block['header']['parent'] !== $previous['hash']) {
                throw new DomainException('History parent continuity failed.');
            }
            $matches = [];
            foreach ($block['hints'] as $hint) {
                $recipient = $this->recipient($network, $hint['recipient'], true);
                $matches[$hint['asset'].':'.$recipient.':'.($hint['contract'] ?? '')][$hint['hash']] = true;
            }
            $atHead = $height === $head['height'] && $block['header']['hash'] === $head['hash'];
            foreach ($requests as $request) {
                $contract = $request->quote_snapshot['payment_config_snapshot']['contract_address'] ?? '';
                if ($contract !== '') {
                    $contract = str_starts_with($network, 'tron-') ? CryptoTronAddress::hex($contract) : strtolower($contract);
                }
                $key = $request->asset.':'.$this->recipient($network, $request->cryptoAddress->address).':'.$contract;
                $this->persist($request, $block['header'], array_keys($matches[$key] ?? []), $atHead, $interval);
            }

            return true;
        } catch (\Throwable) {
            foreach ($requests as $request) {
                $this->fail($request, 'history_read_failed');
            }
            throw new DomainException('Crypto history scan failed; coverage has not advanced.');
        } finally {
            $lock->release();
        }
    }

    private function due(string $network): Builder
    {
        return CryptoPaymentRequest::query()->withoutGlobalScopes()->where('network', $network)->whereNotNull('current_monitor_id')
            ->where(fn (Builder $query) => $query->whereNull('history_next_attempt_at')->orWhere('history_next_attempt_at', '<=', now()));
    }

    private function validate(CryptoPaymentRequest $request): void
    {
        $address = $request->cryptoAddress;
        $asset = $this->assets->get($request->network.':'.$request->asset);
        $policy = $request->quote_snapshot['verification_policy'] ?? [];
        $config = $request->quote_snapshot['payment_config_snapshot'] ?? [];
        $minimum = $policy['minimum_confirmations'] ?? null;
        if (! $address || $address->merchant_id !== $request->merchant_id || $address->order_id !== $request->order_id
            || $address->payment_method_id !== $request->payment_method_id || $address->network !== $request->network
            || ($config['network'] ?? null) !== $request->network || ($config['asset'] ?? null) !== $request->asset
            || $request->decimals !== $asset['decimals'] || ($policy['version'] ?? null) !== CryptoVerificationPolicy::version($request->network)
            || (! is_int($minimum) && ! is_string($minimum)) || ! preg_match('/^[1-9][0-9]{0,5}$/D', (string) $minimum) || (int) $minimum > 100000
            || ($request->asset === 'ETH' && ($policy['internal_transfers'] ?? null) !== true)) {
            throw new DomainException('Invalid history binding or frozen verification policy.');
        }
        if ($request->asset === 'USDT' && ! is_string($config['contract_address'] ?? null)) {
            throw new DomainException('Missing frozen token contract.');
        }
    }

    /** @param ?array{height: int, hash: string, parent: string, time: CarbonImmutable} $header
     * @param  list<string>  $hashes
     */
    private function persist(CryptoPaymentRequest $snapshot, ?array $header, array $hashes, bool $atHead, int $interval): void
    {
        if ($header !== null) {
            $header['time'] = $header['time']->setTimezone(config('app.timezone'));
        }
        DB::transaction(function () use ($snapshot, $header, $hashes, $atHead, $interval): void {
            $request = $this->locked($snapshot);
            if ($request->history_to_block !== $snapshot->history_to_block || $request->history_to_hash !== $snapshot->history_to_hash) {
                throw new DomainException('History cursor changed concurrently.');
            }
            $monitor = CryptoAddressMonitor::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)
                ->where('crypto_address_id', $request->crypto_address_id)->where('network', $request->network)->findOrFail($request->current_monitor_id);
            foreach ($hashes as $hash) {
                CryptoWebhookReceipt::query()->forMerchant($request->merchant_id)->firstOrCreate([
                    'crypto_address_monitor_id' => $monitor->id,
                    'payload_hash' => hash('sha256', 'history:v1:'.$request->id.':'.$hash.':'.$header['hash']),
                ], [
                    'merchant_id' => $request->merchant_id, 'crypto_payment_request_id' => $request->id,
                    'transaction_hash' => $hash, 'signature_mode' => 'history_rpc', 'hint_payload' => [
                        'chain' => $request->network, 'blockNumber' => $header['height'], 'blockHash' => $header['hash'],
                    ], 'status' => 'pending', 'received_at' => now(),
                ]);
            }
            $changes = ['history_error_code' => null, 'history_checked_at' => $atHead ? now() : null,
                'history_next_attempt_at' => $atHead ? now()->addSeconds($interval) : now(), 'settlement_next_attempt_at' => now()];
            if ($header !== null) {
                $recent = $request->history_recent_blocks ?? [];
                $recent[] = ['height' => $header['height'], 'hash' => $header['hash'], 'time' => $header['time']->toIso8601String()];
                $changes += ['history_to_block' => $header['height'], 'history_to_hash' => $header['hash'],
                    'history_to_time' => $header['time'], 'history_recent_blocks' => array_slice($recent, -64)];
            }
            $request->update($changes);
            $proof = ['cold_scan_checked_at' => null];
            if ($atHead) {
                $proof = ['backfill_to_block' => $request->history_to_block, 'backfill_to_hash' => $request->history_to_hash,
                    'backfill_completed_at' => now(), 'cold_scan_to_block' => $request->history_to_block,
                    'cold_scan_to_hash' => $request->history_to_hash, 'cold_scan_checked_at' => now()];
            }
            CryptoAddressMonitor::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)->update($proof);
        }, 3);
    }

    /** Binary search the retained contiguous hash window; a deeper fork restarts discovery and requires review. */
    private function rewind(CryptoPaymentRequest $snapshot, int $head, array &$canonicalHeaders): void
    {
        $recent = $snapshot->history_recent_blocks ?? [];
        $low = 0;
        $high = count($recent) - 1;
        $ancestor = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $saved = $recent[$middle];
            if ($saved['height'] <= $head) {
                $canonicalHeaders[$saved['height']] ??= $this->blocks->header($snapshot->network, $saved['height']);
            }
            if ($saved['height'] <= $head && $canonicalHeaders[$saved['height']]['hash'] === $saved['hash']) {
                $ancestor = $saved;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        DB::transaction(function () use ($snapshot, $recent, $ancestor): void {
            $request = $this->locked($snapshot);
            if ($request->history_to_block !== $snapshot->history_to_block || $request->history_to_hash !== $snapshot->history_to_hash) {
                throw new DomainException('Reorg cursor changed concurrently.');
            }
            $cutoff = $ancestor['height'] ?? -1;
            $affected = CryptoChainEvent::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)->where('block_height', '>', $cutoff);
            $hashes = (clone $affected)->pluck('transaction_hash');
            $affected->update(['orphaned_at' => now(), 'confirmed_at' => null]);
            CryptoWebhookReceipt::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)
                ->whereIn('transaction_hash', $hashes)->where('status', '!=', 'review')
                ->update(['status' => 'pending', 'processed_at' => null, 'next_attempt_at' => now(), 'last_error_code' => 'canonical_block_changed']);
            $review = $request->credited_at !== null || $ancestor === null;
            $request->update(['history_to_block' => $ancestor['height'] ?? null, 'history_to_hash' => $ancestor['hash'] ?? null,
                'history_to_time' => $ancestor['time'] ?? null, 'history_recent_blocks' => array_values(array_filter($recent, fn (array $row): bool => $row['height'] <= $cutoff)),
                'history_checked_at' => null, 'history_next_attempt_at' => now(), 'history_error_code' => 'canonical_block_changed',
                'status' => $review ? 'review' : $request->status, 'review_reason' => $review ? ($request->credited_at ? 'credited_chain_reorg' : 'deep_chain_reorg') : $request->review_reason]);
            CryptoAddressMonitor::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)
                ->update(['backfill_completed_at' => null, 'cold_scan_checked_at' => null]);
        }, 3);
    }

    private function locked(CryptoPaymentRequest $snapshot): CryptoPaymentRequest
    {
        Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($snapshot->merchant_id);
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $snapshot->merchant_id)->lockForUpdate()->findOrFail($snapshot->order_id);
        if ($order->payment_type !== 'crypto') {
            throw new DomainException('History request belongs to a non-crypto order.');
        }

        return CryptoPaymentRequest::query()->forMerchant($snapshot->merchant_id)->where('order_id', $order->id)->lockForUpdate()->findOrFail($snapshot->id);
    }

    private function fail(CryptoPaymentRequest $snapshot, string $code): void
    {
        DB::transaction(function () use ($snapshot, $code): void {
            $request = $this->locked($snapshot);
            $request->update(['history_checked_at' => null, 'history_next_attempt_at' => now()->addMinute(), 'history_error_code' => $code]);
            CryptoAddressMonitor::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)->update(['cold_scan_checked_at' => null]);
        }, 3);
    }

    private function recipient(string $network, string $address, bool $fromNode = false): string
    {
        if (str_starts_with($network, 'tron-')) {
            return $fromNode ? CryptoTronAddress::logAddress($address) : CryptoTronAddress::hex($address);
        }

        return $this->assets->addressKey($network, $address);
    }

    private function interval(): int
    {
        $value = SystemConfig::get('crypto.history_scan_interval_seconds');
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,6}$/D', $value)) {
            throw new DomainException('History interval must be explicitly configured.');
        }

        return (int) $value;
    }
}
