<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AdminDashboard;
use App\Services\DashboardService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PaymentSuccessRate extends StatsOverviewWidget
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
        abort_unless(static::canView(), 403);

        $result = app(DashboardService::class)->getPaymentSuccessRate(
            DashboardService::viewerMerchantIds(),
            $this->pageFilters ?? [],
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
