<?php

namespace App\Console\Commands;

use App\Models\SystemConfig;
use App\Services\OrderEventSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 建议调度：每分钟跑一次（真正的同步间隔由 order_event.sync_interval 配置动态控制，
 * 命令内部自己判断"距离上次同步是否已经超过配置的间隔"，没到点就直接跳过——
 * 这是在 Laravel 原生 cron 调度粒度（分钟级）之上实现"可在后台动态调整间隔"的
 * 最简单方式，不需要每次改配置都重新部署 crontab）。
 *
 *   $schedule->command('order-events:sync')->everyMinute()->withoutOverlapping();
 */
class SyncOrderEvents extends Command
{
    protected $signature = 'order-events:sync';

    protected $description = '按订单逐笔从插件 /order-logs 接口拉取订单日志';

    private const LAST_RUN_CONFIG_KEY = 'order_event._last_run_at';

    public function handle(OrderEventSyncService $service): int
    {
        $intervalMinutes = (int) SystemConfig::get('order_event.sync_interval', 10);
        $lastRun = SystemConfig::get(self::LAST_RUN_CONFIG_KEY);

        if ($lastRun && now()->diffInMinutes(Carbon::parse($lastRun)) < $intervalMinutes) {
            $this->info('Not due yet, skipping this run.');

            return self::SUCCESS;
        }

        $result = $service->sync();

        SystemConfig::set(self::LAST_RUN_CONFIG_KEY, now()->toIso8601String());

        $this->info('Sync result: '.json_encode($result));

        return ($result['success'] ?? true) ? self::SUCCESS : self::FAILURE;
    }
}
