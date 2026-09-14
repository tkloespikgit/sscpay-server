<?php

namespace App\Console\Commands;

use App\Models\AdConversionAttempt;
use App\Services\AdConversionService;
use Illuminate\Console\Command;

/**
 * 扫描"已失败且到了重试时间"的广告转化通知记录，逐个生成下一次尝试并发送。
 * 与 order-notifications:process-due 同样的调度频率（每分钟一次）。
 *
 *   $schedule->command('ad-conversions:process-due')->everyMinute();
 */
class ProcessDueAdConversions extends Command
{
    protected $signature = 'ad-conversions:process-due';

    protected $description = '扫描到期的广告转化通知重试记录并发起下一次尝试';

    public function handle(AdConversionService $service): int
    {
        $dueAttempts = AdConversionAttempt::query()
            ->withoutGlobalScopes()
            ->dueForRetry()
            ->get();

        if ($dueAttempts->isEmpty()) {
            $this->info('No due ad conversion retries.');

            return self::SUCCESS;
        }

        foreach ($dueAttempts as $attempt) {
            $service->dispatchRetry($attempt);
        }

        $this->info("Dispatched {$dueAttempts->count()} retry attempt(s).");

        return self::SUCCESS;
    }
}
