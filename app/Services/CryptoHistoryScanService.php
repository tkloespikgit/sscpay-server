<?php

namespace App\Services;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\Order;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoRuntimeSettings;
use App\Support\CryptoVerificationPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Resumable, explicitly requested address history pages. Never walks the network's blocks. */
final class CryptoHistoryScanService
{
    public function __construct(
        private readonly CryptoBlockHistoryService $blocks,
        private readonly CryptoAssetRegistry $assets,
        private readonly TatumAddressHistoryClient $history,
    ) {}

    public function scan(string $network, ?int $merchantId = null, ?int $requestId = null): bool
    {
        if (! CryptoRuntimeSettings::get('history_enabled')) {
            throw new DomainException('Crypto address history is disabled.');
        }
        if ($merchantId === null || $requestId === null) {
            throw new DomainException('Address queries require an explicit merchant and payment request.');
        }
        $lock = Cache::lock('crypto:address-history:'.$requestId, 120);
        if (! $lock->get()) {
            return false;
        }
        $request = null;
        try {
            $request = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('network', $network)
                ->with(['cryptoAddress' => fn ($query) => $query->withoutGlobalScopes()])->findOrFail($requestId);
            $this->validate($request);
            if ($request->history_query_state === null) {
                $this->begin($request);
                $request->refresh();
            }
            $state = $request->history_query_state;
            $page = $this->history->page($request, $state);
            $finished = $page['next'] === null && ($request->asset !== 'ETH' || $state['stage'] === 1);
            if ($finished && $this->blocks->header($network, $state['to'])['hash'] !== $state['to_hash']) {
                $request->update(['history_query_state' => null]);
                throw new DomainException('Address query chain anchor changed.');
            }
            DB::transaction(function () use ($request, $state, $page, $finished): void {
                $fresh = $this->locked($request);
                if ($fresh->history_query_state !== $state) {
                    throw new DomainException('Address query progress changed concurrently.');
                }
                $this->receipts($fresh, $state['id'], $page['hashes']);
                $next = $state;
                if ($page['next'] === null) {
                    $next['stage']++;
                    $next['cursor'] = 0;
                } else {
                    $next['cursor'] = $page['next'];
                }
                $changes = ['history_error_code' => null, 'history_next_attempt_at' => null,
                    'history_query_state' => $finished ? null : $next, 'settlement_next_attempt_at' => now()];
                if ($finished) {
                    $changes += ['history_to_block' => $state['to'], 'history_to_hash' => $state['to_hash'],
                        'history_to_time' => CarbonImmutable::createFromTimestamp($state['to_time'], config('app.timezone')),
                        'history_checked_at' => CarbonImmutable::parse($state['started_at']), 'history_recent_blocks' => []];
                }
                $fresh->update($changes);
                if ($finished) {
                    CryptoAddressMonitor::query()->forMerchant($fresh->merchant_id)->where('crypto_payment_request_id', $fresh->id)
                        ->update(['backfill_to_block' => $state['to'], 'backfill_to_hash' => $state['to_hash'],
                            'backfill_completed_at' => CarbonImmutable::parse($state['started_at']),
                            'cold_scan_to_block' => null, 'cold_scan_to_hash' => null, 'cold_scan_checked_at' => null]);
                }
            }, 3);

            return true;
        } catch (\Throwable $exception) {
            if ($request !== null) {
                $request->update(['history_checked_at' => null, 'history_error_code' => 'address_history_failed', 'history_next_attempt_at' => null]);
            }
            throw new DomainException('Crypto address query failed; incomplete coverage was not accepted.', previous: $exception);
        } finally {
            $lock->release();
        }
    }

    private function begin(CryptoPaymentRequest $request): void
    {
        $policy = $request->quote_snapshot['verification_policy'];
        $this->blocks->assertNetwork($request->network, $policy['genesis_hash'] ?? null);
        $startedAt = now()->toImmutable()->startOfSecond();
        $head = $this->blocks->head($request->network);
        $maxAge = CryptoRuntimeSettings::get('history_max_head_age_seconds');
        if ((! is_int($maxAge) && ! is_string($maxAge)) || ! preg_match('/^[1-9][0-9]{0,4}$/D', (string) $maxAge)
            || (int) $maxAge > 86400 || $head['time']->lt(now()->subSeconds((int) $maxAge))) {
            throw new DomainException('Address query head is stale.');
        }
        $from = $request->cryptoAddress->scan_from_block;
        $reorg = false;
        if ($request->history_to_block !== null && $request->history_to_hash !== null) {
            $reorg = $request->history_to_block > $head['height']
                || $this->blocks->header($request->network, $request->history_to_block)['hash'] !== $request->history_to_hash;
        }
        if ($from > $head['height']) {
            throw new DomainException('Address history starts ahead of the network.');
        }
        $first = $this->blocks->header($request->network, $from);
        $state = ['id' => (string) Str::uuid(), 'from' => $from, 'to' => $head['height'], 'to_hash' => $head['hash'],
            'from_time' => $first['time']->getTimestamp(), 'to_time' => $head['time']->getTimestamp(),
            'stage' => 0, 'cursor' => 0, 'started_at' => $startedAt->toIso8601String()];
        DB::transaction(function () use ($request, $state, $reorg): void {
            $fresh = $this->locked($request);
            if ($fresh->history_query_state !== null) {
                throw new DomainException('Another address query has started.');
            }
            $changes = ['history_query_state' => $state, 'history_checked_at' => null, 'history_error_code' => null];
            if ($reorg && $fresh->credited_at !== null) {
                $changes += ['status' => 'review', 'review_reason' => 'credited_chain_reorg'];
            }
            $fresh->update($changes);
            $hashes = CryptoChainEvent::query()->forMerchant($fresh->merchant_id)->where('crypto_payment_request_id', $fresh->id)
                ->distinct()->pluck('transaction_hash')->all();
            $this->receipts($fresh, $state['id'], $hashes);
        }, 3);
    }

    /** @param list<string> $hashes */
    private function receipts(CryptoPaymentRequest $request, string $queryId, array $hashes): void
    {
        foreach ($hashes as $hash) {
            CryptoWebhookReceipt::query()->forMerchant($request->merchant_id)->firstOrCreate([
                'crypto_payment_request_id' => $request->id,
                'payload_hash' => hash('sha256', 'address:v1:'.$request->id.':'.$queryId.':'.$hash),
            ], [
                'merchant_id' => $request->merchant_id, 'crypto_address_monitor_id' => $request->current_monitor_id,
                'transaction_hash' => $hash, 'signature_mode' => 'address_history', 'hint_payload' => ['query_id' => $queryId],
                'status' => 'pending', 'received_at' => now(),
            ]);
        }
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
            || ($request->asset === 'ETH' && ($policy['internal_transfers'] ?? null) !== true)
            || ($request->asset === 'USDT' && ! is_string($config['contract_address'] ?? null))) {
            throw new DomainException('Invalid address history binding or frozen verification policy.');
        }
    }

    private function locked(CryptoPaymentRequest $snapshot): CryptoPaymentRequest
    {
        Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($snapshot->merchant_id);
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $snapshot->merchant_id)->lockForUpdate()->findOrFail($snapshot->order_id);
        if ($order->payment_type !== 'crypto') {
            throw new DomainException('Address query belongs to a fiat order.');
        }

        return CryptoPaymentRequest::query()->forMerchant($snapshot->merchant_id)->where('order_id', $order->id)->lockForUpdate()->findOrFail($snapshot->id);
    }
}
