<?php

namespace App\Console\Commands;

use App\Models\OrderShipping;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 定时任务：扫描候选运单，委托 SyncService::recalculateAnomaly 重算异常标记。
 *
 * 扫描订单快照开启的供应商异常、可能已超阈值及已有异常记录，并用 Cache 游标轮转。
 * 已有异常仅本地重算，支持调大阈值后恢复正常，不重复主动查询或提醒。
 */
class ReflagLogisticsTrackingAnomaliesCommand extends Command
{
    public const CURSOR_CACHE_KEY = 'logistics_tracking:reflag_after_id';

    protected $signature = 'logistics-tracking:reflag-anomalies
                            {--limit=5000 : 单轮最多处理条数}
                            {--after-id= : 覆盖游标起点（不含）；省略则用缓存游标}
                            {--reset-cursor : 清空缓存游标后从 id=0 开始}';

    protected $description = '按供应商状态和系统配置阈值重算物流异常，首次异常可主动查轨迹并提醒';

    public function handle(LogisticsTrackingSyncService $sync): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if ((bool) $this->option('reset-cursor')) {
            Cache::forget(self::CURSOR_CACHE_KEY);
        }

        $afterId = $this->option('after-id');
        $afterId = $afterId !== null && $afterId !== ''
            ? max(0, (int) $afterId)
            : max(0, (int) Cache::get(self::CURSOR_CACHE_KEY, 0));

        $noDeliveryBefore = now()->subHours($sync::noDeliveryDays() * 24);
        $staleBefore = now()->subHours($sync::staleDays() * 24);

        $candidates = OrderShipping::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('id', '>', $afterId)
            ->where(function ($candidate) use ($noDeliveryBefore, $staleBefore): void {
                $candidate->where('logistics_anomaly', true)
                    ->orWhereIn('tracking_status', array_keys(LogisticsTrackingSyncService::PROVIDER_ANOMALIES))
                    ->orWhere(function ($undelivered) use ($noDeliveryBefore, $staleBefore): void {
                        $undelivered->whereNull('tracking_delivered_at')
                            ->where(fn ($status) => $status->whereNull('tracking_status')->orWhere('tracking_status', '!=', 'Delivered'))
                            ->where(function ($overdue) use ($noDeliveryBefore, $staleBefore): void {
                                $overdue->where('shipped_at', '<=', $noDeliveryBefore)
                                    ->orWhere('tracking_last_event_at', '<=', $staleBefore)
                                    ->orWhere(fn ($stale) => $stale->whereNull('tracking_last_event_at')->where('shipped_at', '<=', $staleBefore));
                            });
                    });
            })
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()
                ->where('logistics_tracking_enabled', true)
                ->whereIn('status', LogisticsTrackingSyncService::TRACKING_ORDER_STATUSES))
            ->with('order');

        $rows = (clone $candidates)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            Cache::forever(self::CURSOR_CACHE_KEY, 0);
            $this->info('Reflagged 0 shipping record(s); cursor reset.');

            return self::SUCCESS;
        }

        $scanned = 0;

        $rows->each(function (OrderShipping $shipping) use ($sync, &$scanned): void {
            $sync->recalculateAnomaly($shipping);
            $scanned++;
        });

        $lastId = (int) $rows->last()->id;
        $moreRemain = (clone $candidates)
            ->where('id', '>', $lastId)
            ->exists();

        Cache::forever(self::CURSOR_CACHE_KEY, $moreRemain ? $lastId : 0);

        $this->info("Reflagged {$scanned} shipping record(s); cursor=".Cache::get(self::CURSOR_CACHE_KEY, 0).'.');

        return self::SUCCESS;
    }
}
