<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use App\Services\OrderStatsQueryService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 订单统计看板顶部的四张卡：支付成功 / 失败 / 退款 / 拒付。
 * 主数字是金额（USD），笔数放描述行。
 *
 * 「支付成功」口径是"曾经支付成功过"（Order::scopePaidEver()），不看订单终态，
 * 与仪表盘「总成交额」、订单列表「其中已支付」三处完全一致，可直接对账。
 */
class OrderStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected function getColumns(): int|array|null
    {
        return 4;
    }

    protected function getStats(): array
    {
        $stats = app(OrderStatsQueryService::class);

        $totals = $stats->totals(
            DashboardService::viewerMerchantIds(),
            $this->filters['period'] ?? null,
            $this->filters ?? [],
        );

        $cards = [
            $this->card('paid', $totals['paid_amount'], $totals['paid_orders'], 'success'),
            $this->card('failed', $totals['failed_amount'], $totals['failed_orders'], 'gray'),
            $this->card('refunded', $totals['refunded_amount'], $totals['refunded_orders'], 'warning'),
            $this->card('chargeback', $totals['chargeback_amount'], $totals['chargeback_orders'], 'danger'),
        ];

        // 选定周期里有日期从没统计过时给个明确提示——零交易的日子会留下
        // rows_written=0 的执行记录，和"任务没跑"区分得开，不会误报。
        $missing = $stats->missingDates($this->filters['period'] ?? null);

        if ($missing !== []) {
            $cards[0] = $cards[0]->description(__('admin.order_stats.missing_days', [
                'count' => count($missing),
                'dates' => implode(', ', array_slice($missing, 0, 3)).(count($missing) > 3 ? '…' : ''),
            ]))->descriptionIcon('heroicon-m-exclamation-triangle')->descriptionColor('danger');
        }

        return $cards;
    }

    private function card(string $key, float $amount, int $orders, string $color): Stat
    {
        return Stat::make(__('admin.order_stats.metrics.'.$key), '$'.number_format($amount, 2))
            ->description(__('admin.order_stats.orders_count', ['count' => number_format($orders)]))
            ->color($color);
    }
}
