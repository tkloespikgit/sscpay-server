<?php

namespace App\Console\Commands;

use App\Models\CryptoAddressMonitor;
use App\Services\CryptoAddressMonitorService;
use Illuminate\Console\Command;
use Throwable;

class MaintainCryptoSubscriptions extends Command
{
    protected $signature = 'crypto:maintain-subscriptions {--limit=5 : Maximum monitors processed per invocation}';

    protected $description = '同步待创建或到期的 Tatum 地址订阅；缺少历史接管时保留即时订阅';

    public function handle(CryptoAddressMonitorService $service): int
    {
        if (! config('services.tatum.subscriptions_enabled')) {
            $this->info('Tatum subscriptions are disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }
        $monitors = CryptoAddressMonitor::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($query) => $query->whereIn('status', ['preparing', 'creating', 'error', 'cancelling'])
                ->orWhere(fn ($active) => $active->where('status', 'active')->where('expires_at', '<=', now())))
            ->orderBy('id')->limit($limit)->get(['id', 'merchant_id']);
        $failed = 0;
        foreach ($monitors as $monitor) {
            try {
                if (! $service->synchronize((int) $monitor->merchant_id, (int) $monitor->id)) {
                    $failed++;
                }
            } catch (Throwable) {
                $failed++;
                $this->error('Subscription maintenance failed for monitor '.$monitor->id.'.');
            }
        }
        $this->info('Processed '.$monitors->count().' monitor(s); '.$failed.' failed or require safety checks.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
