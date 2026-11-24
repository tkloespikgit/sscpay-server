<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackProviderAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ChargebackAlertSync
{
    public function __construct(private ChargebackAlertDrivers $drivers, private ChargebackAlertInbox $inbox) {}

    public function run(int $accountId, bool $force = false): bool
    {
        $lock = Cache::lock('chargeback-alert-sync:'.$accountId, 120);
        if (! $lock->get()) {
            return true;
        }

        try {
            return $this->syncAccount($accountId, $force);
        } finally {
            $lock->release();
        }
    }

    private function syncAccount(int $accountId, bool $force): bool
    {
        $account = ChargebackProviderAccount::query()->findOrFail($accountId);
        $driver = $this->drivers->find($account->provider);
        if (! $account->enabled || ! $driver instanceof PullsChargebackRecords || (! $force && $account->next_sync_at?->isFuture())) {
            return true;
        }

        $streams = array_keys(array_intersect($driver->syncStreams(), $account->services));
        $state = array_intersect_key($account->sync_cursor ?? [], array_flip($streams));
        $visited = [];
        $budget = 4;
        $ok = true;

        while ($budget > 0) {
            $worked = false;
            foreach ($streams as $stream) {
                if ($budget <= 0) {
                    break;
                }

                $cursor = $state[$stream] ?? [];
                if (isset($cursor['next_at']) && CarbonImmutable::parse($cursor['next_at'])->isFuture()
                    && (! $force || isset($visited[$stream]))) {
                    continue;
                }

                $worked = true;
                $visited[$stream] = true;
                $budget--;
                $oldest = CarbonImmutable::now()->subSeconds($driver->lookbackDays() * 86400);
                if (! isset($cursor['start']) || CarbonImmutable::parse($cursor['start'])->lte($oldest)) {
                    $cursor = [
                        'start' => $oldest->addHour()->toDateTimeString(), 'end' => now()->toDateTimeString(), 'page' => 1,
                    ];
                }

                $complete = false;
                $events = [];
                try {
                    $result = $driver->pull($account, $stream, $cursor['page'],
                        CarbonImmutable::parse($cursor['start']), CarbonImmutable::parse($cursor['end']));
                    $events = $result['events'];
                    $complete = $cursor['page'] >= $result['pages'];
                    $state[$stream] = $complete ? ['next_at' => now()->addMinutes(30)->toDateTimeString()]
                        : array_replace($cursor, ['page' => $cursor['page'] + 1, 'next_at' => now()->toDateTimeString(), 'error' => null]);
                } catch (ProviderRequestFailed $error) {
                    $state[$stream] = array_replace($cursor, ['next_at' => now()->addMinutes(5)->toDateTimeString(), 'error' => $error->result]);
                    $ok = false;
                    Log::warning('Chargeback provider sync failed.', ['account_id' => $account->id, 'stream' => $stream, 'result' => $error->result]);
                }

                try {
                    if (! $this->persist($account, $streams, $state, $events, $complete)) {
                        return $ok;
                    }
                } catch (Throwable $error) {
                    Log::error('Chargeback sync page could not be stored.', ['account_id' => $account->id, 'stream' => $stream, 'exception' => $error::class]);

                    return false;
                }
            }

            if (! $worked) {
                break;
            }
        }

        return $ok;
    }

    /**
     * @param  list<string>  $streams
     * @param  array<string, array{start?:string, end?:string, page?:int, next_at:string, error?:string|null}>  $state
     * @param  list<ChargebackProviderEvent>  $events
     */
    private function persist(ChargebackProviderAccount $account, array $streams, array $state, array $events, bool $complete): bool
    {
        return DB::transaction(function () use ($account, $streams, $state, $events, $complete): bool {
            $fresh = ChargebackProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (! $fresh->enabled || $fresh->services !== $account->services
                || $fresh->getRawOriginal('signing_config') !== $account->getRawOriginal('signing_config')) {
                return false;
            }

            foreach ($events as $event) {
                $this->inbox->record($fresh, $event, 'query');
            }
            $fresh->update([
                'sync_cursor' => $state,
                'next_sync_at' => min(array_map(fn (string $stream): string => $state[$stream]['next_at'] ?? now()->toDateTimeString(), $streams)),
                'last_sync_at' => $complete ? now() : $fresh->last_sync_at,
                'sync_error' => collect($state)->pluck('error')->filter()->first(),
            ]);

            return true;
        }, 3);
    }
}
