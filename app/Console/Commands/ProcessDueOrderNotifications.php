<?php

namespace App\Console\Commands;

use App\Jobs\SendOrderNotificationJob;
use App\Models\OrderNotificationAttempt;
use App\Models\Scopes\MerchantScope;
use App\Services\OrderNotificationService;
use Illuminate\Console\Command;

/**
 * 扫描"已失败且到了重试时间"的通知记录，逐个生成下一次尝试并发送。
 *
 * 建议调度频率：每分钟一次（重试间隔最短是 30 秒，每分钟扫描足够及时，
 * 且避免过于频繁的空转查询）。
 *
 *   $schedule->command('order-notifications:process-due')->everyMinute();
 */
class ProcessDueOrderNotifications extends Command
{
    protected $signature = 'order-notifications:process-due';

    protected $description = '扫描到期的商户通知重试记录并发起下一次尝试';

    public function handle(OrderNotificationService $service): int
    {
        OrderNotificationAttempt::query()->withoutGlobalScope(MerchantScope::class)
            ->where('status', 'processing')->where('attempted_at', '<=', now()->subMinutes(2))
            ->update(['status' => 'pending']);
        OrderNotificationAttempt::query()->withoutGlobalScope(MerchantScope::class)
            ->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(2))
            ->chunkById(100, function ($attempts): void {
                foreach ($attempts as $attempt) {
                    SendOrderNotificationJob::dispatch($attempt->id);
                }
            });

        $dueAttempts = OrderNotificationAttempt::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->dueForRetry()
            ->get();

        if ($dueAttempts->isEmpty()) {
            $this->info('No due notification retries.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($dueAttempts as $attempt) {
            if ($service->dispatchRetry($attempt) !== null) {
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} retry attempt(s).");

        return self::SUCCESS;
    }
}
