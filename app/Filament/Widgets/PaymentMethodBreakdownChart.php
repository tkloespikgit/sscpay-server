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
        $breakdown = app(DashboardService::class)->getAdminStats($this->resolveMerchantIds())['payment_method_breakdown'];

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
     * 统计范围：超级管理员不限，商户级管理员是名下全部商户，普通商户用户是自己那一个。
     * 统一走 DashboardService::viewerMerchantIds()，见该方法注释。
     */
    private function resolveMerchantIds(): ?array
    {
        return DashboardService::viewerMerchantIds();
    }
}
