<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MerchantSalesRankingChart;
use App\Filament\Widgets\OrderTrendChart;
use App\Filament\Widgets\PaymentMethodBreakdownChart;
use App\Filament\Widgets\PaymentSuccessRate;
use App\Filament\Widgets\StatsOverview;
use App\Services\DashboardService;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * 仪表盘页面。不再提供商户筛选器：所有 Widget 统一读取当前登录用户所属
 * 商户的数据（见各 Widget 的 resolveMerchantId()）。超级管理员没有归属商户，
 * 看到的是全平台汇总数据。
 *
 * 页面级筛选器（HasFiltersForm）现在只服务于「支付成功率」Widget：
 * time_window（统计时间窗）与 payment_method（支付方式）。筛选状态通过
 * pageFilters 下发给各 Widget，只有 PaymentSuccessRate 读取。
 */
class AdminDashboard extends BaseDashboard
{
    use HasFiltersForm;

    /**
     * 强制沿用内置 Dashboard 页面的 slug（"dashboard"），而不是让 Filament
     * 按类名 "AdminDashboard" 自动生成 "admin-dashboard"。
     *
     * 原因：Filament 面板内部很多地方（登录成功后的默认跳转、导航栏"返回
     * 仪表盘"链接等）都是按约定引用 filament.{panel}.pages.dashboard 这个
     * 固定路由名，如果这里的 slug 跟着类名走，会变成
     * filament.{panel}.pages.admin-dashboard，导致那些地方路由解析失败
     * （RouteNotFoundException）。这个自定义 Dashboard 是要"替换"内置的那个，
     * 而不是新增一个平行的页面，所以必须占用同一个 slug。
     */
    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'dashboard';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('time_window')
                ->label(__('admin.dashboard.filters.time_window'))
                ->options([
                    '7' => __('admin.dashboard.filters.days.7'),
                    '30' => __('admin.dashboard.filters.days.30'),
                    '90' => __('admin.dashboard.filters.days.90'),
                ])
                ->default('30')
                ->selectablePlaceholder(false),
            Select::make('payment_method')
                ->label(__('admin.dashboard.filters.payment_method'))
                ->placeholder(__('admin.dashboard.filters.all_payment_methods'))
                ->options(fn () => collect(
                    app(DashboardService::class)->getPaymentMethodOptions(auth()->user()?->merchant_id)
                )->mapWithKeys(fn (string $method) => [$method => $method]))
                ->searchable(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            StatsOverview::class,
            PaymentSuccessRate::class,
            OrderTrendChart::class,
            MerchantSalesRankingChart::class,
            PaymentMethodBreakdownChart::class,
        ];
    }
}
