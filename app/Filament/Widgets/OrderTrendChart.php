<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

class OrderTrendChart extends ChartWidget
{
    public function getHeading(): ?string
    {
        return __('admin.dashboard.charts.order_trend');
    }

    protected function getData(): array
    {
        $trend = app(DashboardService::class)->getAdminStats($this->resolveMerchantIds())['trend_30d'];

        return [
            'datasets' => [
                [
                    'label' => __('admin.dashboard.charts.order_count'),
                    'data' => array_column($trend, 'order_count'),
                    'borderColor' => '#6366f1',
                ],
                [
                    'label' => __('admin.dashboard.charts.amount_usd'),
                    'data' => array_column($trend, 'amount_usd'),
                    'borderColor' => '#22c55e',
                ],
            ],
            'labels' => array_column($trend, 'date'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * 统计范围：超级管理员不限，商户级管理员是名下全部商户，普通商户用户是自己那一个。
     * 统一走 DashboardService::viewerMerchantIds()，见该方法注释。
     */
    private function resolveMerchantIds(): ?array
    {
        return DashboardService::viewerMerchantIds();
    }
}
