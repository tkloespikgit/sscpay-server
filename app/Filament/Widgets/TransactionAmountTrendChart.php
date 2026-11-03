<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AdminDashboard;
use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class TransactionAmountTrendChart extends ChartWidget
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
        return __('admin.dashboard.charts.transaction_amount_trend');
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);

        $trend = app(DashboardService::class)->trend(DashboardService::viewerMerchantIds(), $this->pageFilters ?? []);

        return [
            'datasets' => [[
                'label' => __('admin.dashboard.charts.amount_usd'),
                'data' => array_column($trend, 'amount_usd'),
                'borderColor' => '#22c55e',
            ]],
            'labels' => array_column($trend, 'date'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
