<?php

namespace App\Console\Commands;

use App\Models\OrderShipping;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 定时任务：扫描候选运单，委托 SyncService::recalculateAnomaly 重算异常标记。
 *
 * 默认只扫「订单快照开启 ∧ 尚未 anomaly ∧ 未签收 ∧ 可能已超阈值」的行，并用 Cache 游标
 * 轮转，避免固定 orderBy id + limit 饿死高 id。已 anomaly 的清除依赖 Webhook / 手动 poll。
 */
class ReflagLogisticsTrackingAnomaliesCommand extends Command
{
    public const CURSOR_CACHE_KEY = 'logistics_tracking:reflag_after_id';

    protected $signature = 'logistics-tracking:reflag-anomalies
                            {--limit=5000 : 单轮最多处理条数}
                            {--after-id= : 覆盖游标起点（不含）；省略则用缓存游标}
                            {--reset-cursor : 清空缓存游标后从 id=0 开始}';

    protected $description = '按发货 15 天未签收 / 轨迹 5 天未更新重算物流异常标记（仅未 anomaly 候选）';

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

        $noDeliveryBefore = now()->subHours(LogisticsTrackingSyncService::NO_DELIVERY_HOURS);
        $staleBefore = now()->subHours(LogisticsTrackingSyncService::STALE_EVENT_HOURS);

        $rows = OrderShipping::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('id', '>', $afterId)
            ->where('logistics_anomaly', false)
            ->whereNull('tracking_delivered_at')
            ->where(function ($status): void {
                $status->whereNull('tracking_status')
                    ->orWhere('tracking_status', '!=', 'Delivered');
            })
            ->where(function ($overdue) use ($noDeliveryBefore, $staleBefore): void {
                $overdue->where('shipped_at', '<=', $noDeliveryBefore)
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->whereNotNull('tracking_last_event_at')
                            ->where('tracking_last_event_at', '<=', $staleBefore);
                    })
                    ->orWhere(function ($staleFromShip) use ($staleBefore): void {
                        // 无事件时以 shipped_at 作为 stale 锚点（与 predictAnomaly 一致）
                        $staleFromShip->whereNull('tracking_last_event_at')
                            ->where('shipped_at', '<=', $staleBefore);
                    });
            })
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()
                ->where('logistics_tracking_enabled', true)
                ->whereIn('status', LogisticsTrackingSyncService::TRACKING_ORDER_STATUSES))
            ->with('order')
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
        $moreRemain = OrderShipping::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('id', '>', $lastId)
            ->where('logistics_anomaly', false)
            ->whereNull('tracking_delivered_at')
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()
                ->where('logistics_tracking_enabled', true))
            ->exists();

        Cache::forever(self::CURSOR_CACHE_KEY, $moreRemain ? $lastId : 0);

        $this->info("Reflagged {$scanned} shipping record(s); cursor=".Cache::get(self::CURSOR_CACHE_KEY, 0).'.');

        return self::SUCCESS;
    }
}
