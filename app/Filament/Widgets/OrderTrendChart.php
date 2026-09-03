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
        $trend = app(DashboardService::class)->getAdminStats($this->resolveMerchantId())['trend_30d'];

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
     * 统一取当前登录用户所属商户；超级管理员没有归属商户（merchant_id 为 NULL），
     * 看到的是全平台汇总数据。
     */
    private function resolveMerchantId(): ?int
    {
        return auth()->user()?->merchant_id;
    }
}
