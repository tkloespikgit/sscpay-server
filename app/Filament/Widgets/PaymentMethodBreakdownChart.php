<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

class PaymentMethodBreakdownChart extends ChartWidget
{
    public function getHeading(): ?string
    {
        return __('admin.dashboard.charts.payment_method_breakdown');
    }

    protected function getData(): array
    {
        $breakdown = app(DashboardService::class)->getAdminStats($this->resolveMerchantId())['payment_method_breakdown'];

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

    /**
     * 统一取当前登录用户所属商户；超级管理员没有归属商户（merchant_id 为 NULL），
     * 看到的是全平台汇总数据。
     */
    private function resolveMerchantId(): ?int
    {
        return auth()->user()?->merchant_id;
    }
}
