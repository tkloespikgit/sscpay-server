<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AdminDashboard;
use App\Services\DashboardService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
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

    protected function getColumns(): int|array|null
    {
        return auth()->user()?->is_super_admin ? 5 : 4;
    }

    protected function getStats(): array
    {
        abort_unless(static::canView(), 403);

        $stats = app(DashboardService::class)->summary(DashboardService::viewerMerchantIds(), $this->pageFilters ?? []);

        $cards = [
            Stat::make(__('admin.dashboard.stats.total_orders'), number_format($stats['total_orders'])),
            Stat::make(__('admin.dashboard.stats.paid_orders'), number_format($stats['paid_orders'])),
            Stat::make(__('admin.dashboard.stats.total_amount_usd'), '$'.number_format($stats['total_amount_usd'], 2)),
            Stat::make(__('admin.dashboard.stats.total_merchants'), number_format($stats['total_merchants']))
                ->visible((bool) auth()->user()?->is_super_admin),
            Stat::make(__('admin.dashboard.stats.failed_orders'), number_format($stats['failed_orders'])),
        ];

        if (! ($stats['is_complete'] ?? true)) {
            $cards[0]->description(__('admin.dashboard.stats.incomplete'))->descriptionColor('warning');
        }

        return $cards;
    }
}
