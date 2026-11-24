<?php

namespace App\Console\Commands;

use App\Models\ChargebackProviderAccount;
use App\Services\ChargebackAlerts\ChargebackAlertSync;
use Illuminate\Console\Command;

class SyncChargebackAlerts extends Command
{
    protected $signature = 'chargeback-alerts:sync {--account= : Process a specific enabled provider account}';

    protected $description = 'Reconcile provider chargeback alerts and registration status';

    public function handle(ChargebackAlertSync $sync): int
    {
        $account = $this->option('account');
        if ($account !== null && (! ctype_digit((string) $account) || ! ChargebackProviderAccount::query()->whereKey($account)->where('enabled', true)->exists())) {
            $this->error('The selected provider account is invalid or disabled.');

            return self::FAILURE;
        }

        $ids = ChargebackProviderAccount::query()->where('enabled', true)
            ->when($account === null, fn ($query) => $query->where(fn ($due) => $due->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now())),
                fn ($query) => $query->whereKey($account))
            ->orderBy('next_sync_at')->orderBy('id')->limit(10)->pluck('id');

        $ok = true;
        foreach ($ids as $id) {
            $ok = $sync->run((int) $id, $account !== null) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
