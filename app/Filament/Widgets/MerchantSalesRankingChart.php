<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AdminDashboard;
use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * 各商户销售额排行榜（4.7 节）。只在超管视角有意义——排行榜天然是跨商户的
 * 数据，商户管理员看不到别人的排名，canView() 直接把这个 Widget 隐藏掉。
 */
class MerchantSalesRankingChart extends ChartWidget
{
    use InteractsWithPageFilters;

    public function boot(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function getHeading(): ?string
    {
        return __('admin.dashboard.charts.merchant_ranking');
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);

        $ranking = app(DashboardService::class)->merchantSalesRanking(DashboardService::viewerMerchantIds(), $this->pageFilters ?? []);

        return [
            'datasets' => [
                [
                    'label' => __('admin.dashboard.charts.sales_usd'),
                    'data' => array_column($ranking, 'amount_usd'),
                    'backgroundColor' => '#6366f1',
                ],
            ],
            'labels' => array_column($ranking, 'merchant_name'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public static function canView(): bool
    {
        return AdminDashboard::canAccess() && (bool) auth()->user()?->is_super_admin;
    }
}
