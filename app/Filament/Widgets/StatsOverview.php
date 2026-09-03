<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    /**
     * 列数按角色区分：超级管理员有 5 张卡片（含"总商户数"）走 5 列；
     * 商户用户看不到"总商户数"，只剩 4 张卡片走 4 列，保证一行排齐。
     */
    protected function getColumns(): int|array|null
    {
        return auth()->user()?->is_super_admin ? 5 : 4;
    }

    protected function getStats(): array
    {
        $stats = app(DashboardService::class)->getAdminStats($this->resolveMerchantId());

        return [
            Stat::make(__('admin.dashboard.stats.total_orders'), number_format($stats['total_orders'])),
            Stat::make(__('admin.dashboard.stats.paid_orders'), number_format($stats['paid_orders'])),
            Stat::make(__('admin.dashboard.stats.total_amount_usd'), '$'.number_format($stats['total_amount_usd'], 2)),
            Stat::make(__('admin.dashboard.stats.total_merchants'), number_format($stats['total_merchants']))
                ->visible((bool) auth()->user()?->is_super_admin),
            Stat::make(__('admin.dashboard.stats.today_new_orders'), number_format($stats['today_new_orders'])),
        ];
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
