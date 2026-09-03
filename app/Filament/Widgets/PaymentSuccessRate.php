<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 支付成功率（口径 1，订单维度）：分母 = 所选时间窗内已到达终态的订单
 *（排除 pending），分子 = 其中支付成功过的订单。
 *
 * 受仪表盘筛选器联动：支付方式（$filters['payment_method']）与
 * 时间窗口（$filters['time_window']，单位天）。通过 InteractsWithPageFilters
 * 接收页面筛选状态（v5 中 $this->filters 是 $this->pageFilters 的兼容别名）。
 * 首次进入时筛选器可能尚未初始化，所以读取时都做了兜底。
 */
class PaymentSuccessRate extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    public function getHeading(): ?string
    {
        return __('admin.dashboard.success_rate.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.success_rate.description');
    }

    protected function getColumns(): int|array|null
    {
        return 3;
    }

    protected function getStats(): array
    {
        $days = (int) ($this->filters['time_window'] ?? 30);
        $paymentMethod = $this->filters['payment_method'] ?? null;

        $result = app(DashboardService::class)->getPaymentSuccessRate(
            auth()->user()?->merchant_id,
            $paymentMethod,
            $days,
        );

        $rate = $result['success_rate'];

        return [
            Stat::make(__('admin.dashboard.success_rate.rate'), $rate.'%')
                ->color($this->rateColor($rate)),
            Stat::make(__('admin.dashboard.success_rate.paid_orders'), number_format($result['paid_count'])),
            Stat::make(__('admin.dashboard.success_rate.terminal_orders'), number_format($result['terminal_count'])),
        ];
    }

    /**
     * 成功率配色：>= 90% 绿色，70% ~ 90% 黄色，< 70% 红色。
     */
    private function rateColor(float $rate): string
    {
        if ($rate >= 90) {
            return 'success';
        }

        return $rate >= 70 ? 'warning' : 'danger';
    }
}
