<?php

namespace App\Console\Commands;

use App\Models\OrderShipping;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use Illuminate\Console\Command;

/**
 * 定时任务：扫描候选运单，委托 SyncService::recalculateAnomaly 重算异常标记。
 * 仅在 logistics_anomaly false→true 且启用门闩通过时主动向 Provider 查一次；其余只写本地字段。
 */
class ReflagLogisticsTrackingAnomaliesCommand extends Command
{
    protected $signature = 'logistics-tracking:reflag-anomalies {--limit=5000 : 单轮最多处理条数}';

    protected $description = '按发货 15 天未签收 / 轨迹 5 天未更新重算物流异常标记';

    public function handle(LogisticsTrackingSyncService $sync): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $scanned = 0;

        OrderShipping::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()
                ->whereIn('status', LogisticsTrackingSyncService::TRACKING_ORDER_STATUSES))
            ->where(function ($query): void {
                $query->where('logistics_anomaly', true)
                    ->orWhere(function ($undelivered): void {
                        $undelivered->whereNull('tracking_delivered_at')
                            ->where(function ($status): void {
                                $status->whereNull('tracking_status')
                                    ->orWhere('tracking_status', '!=', 'Delivered');
                            });
                    });
            })
            ->with('order')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (OrderShipping $shipping) use ($sync, &$scanned): void {
                $sync->recalculateAnomaly($shipping);
                $scanned++;
            });

        $this->info("Reflagged {$scanned} shipping record(s).");

        return self::SUCCESS;
    }
}
