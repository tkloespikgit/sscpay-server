<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AdminDashboard;
use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class PaymentMethodBreakdownChart extends ChartWidget
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
        return __('admin.dashboard.charts.payment_method_breakdown');
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);

        $breakdown = app(DashboardService::class)->paymentMethodBreakdown(DashboardService::viewerMerchantIds(), $this->pageFilters ?? []);

        return [
            'datasets' => [
                [
                    'data' => array_column($breakdown, 'order_count'),
                    'backgroundColor' => ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7'],
                ],
            ],
            'labels' => array_column($breakdown, 'payment_method'),
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }
}
