<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use App\Services\OrderStatsQueryService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * 选定周期内按日的成交额折线。当天/昨天只有一个点，主要服务于当月/上一月。
 */
class OrderStatsTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $maxHeight = '320px';

    public function getHeading(): ?string
    {
        return __('admin.order_stats.charts.trend');
    }

    public function getDescription(): ?string
    {
        return __('admin.order_stats.timezone_notice');
    }

    protected function getData(): array
    {
        $trend = app(OrderStatsQueryService::class)->dailyTrend(
            DashboardService::viewerMerchantIds(),
            $this->filters['period'] ?? null,
            $this->filters ?? [],
        );

        if ($trend === []) {
            return [];
        }

        return [
            'datasets' => [
                [
                    'label' => __('admin.order_stats.metrics.paid'),
                    'data' => array_column($trend, 'paid_amount'),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            // 只保留日（月份在周期选择里已经表达过了），横轴不至于太挤
            'labels' => array_map(
                fn (array $row) => substr($row['date'], 5),
                $trend,
            ),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
