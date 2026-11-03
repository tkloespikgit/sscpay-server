<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AdminDashboard;
use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrderTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    public function boot(): void
    {
        abort_unless(static::canView(), 403);
    }

    public static function canView(): bool
    {
        return AdminDashboard::canAccess();
    }

    public function getHeading(): ?string
    {
        return __('admin.dashboard.charts.order_trend');
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);

        $trend = app(DashboardService::class)->trend(DashboardService::viewerMerchantIds(), $this->pageFilters ?? []);

        return [
            'datasets' => [
                [
                    'label' => __('admin.dashboard.charts.order_count'),
                    'data' => array_column($trend, 'order_count'),
                    'borderColor' => '#6366f1',
                ],
            ],
            'labels' => array_column($trend, 'date'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
