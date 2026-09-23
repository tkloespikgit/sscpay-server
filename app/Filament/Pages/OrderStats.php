<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OrderStatsBreakdownTable;
use App\Filament\Widgets\OrderStatsOverview;
use App\Filament\Widgets\OrderStatsTrendChart;
use App\Services\DashboardService;
use App\Services\OrderStatsQueryService;
use App\Support\Permissions;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;

/**
 * 订单统计看板：读 order_daily_stats（由 order-stats:aggregate 定时重算），
 * 支持 当天 / 昨天 / 当月 / 上一月 四个周期，按 商户 / 应用 / 支付方式 筛选。
 *
 * 【数据范围】统一走 DashboardService::viewerMerchantIds()：
 * 超级管理员不限，商户级管理员是名下全部商户，普通商户用户是自己那一个。
 * 商户用户只有一个商户，「商户」筛选器对他们没有意义，直接不显示。
 *
 * 【为什么不用 $view】与 ExchangeRateTrends 同款做法：用 content(Schema) 自己
 * 排版，getHeaderWidgets()/getFooterWidgets() 的渲染位置是布局写死的，
 * 这里要按"卡片 → 趋势 → 明细"的顺序排。
 */
class OrderStats extends Page
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = null;

    /** 排在「订单管理」分组里订单、争议之后。 */
    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.order_stats.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.order_stats.title');
    }

    /**
     * 统计是订单数据的汇总视图，能看订单就能看它的汇总，复用 orders.view 而不是
     * 另开一个权限——新权限要连带改 Permissions、两个 RoleProvisioning 服务，
     * 还得写一次性 rollout 命令给存量商户补发，否则除超管外全员不可见。
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->can(Permissions::ORDERS_VIEW);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function filtersForm(Schema $schema): Schema
    {
        $merchantIds = DashboardService::viewerMerchantIds();
        $stats = app(OrderStatsQueryService::class);

        return $schema->components([
            Select::make('period')
                ->label(__('admin.order_stats.filters.period'))
                ->options(collect(OrderStatsQueryService::PERIODS)
                    ->mapWithKeys(fn (string $p) => [$p => __('admin.order_stats.periods.'.$p)])
                    ->all())
                ->default(OrderStatsQueryService::PERIOD_TODAY)
                ->selectablePlaceholder(false),

            // 商户用户只有一个商户，这个筛选器对他们没意义
            Select::make('merchant_id')
                ->label(__('admin.order_stats.filters.merchant'))
                ->placeholder(__('admin.order_stats.filters.all'))
                ->visible(fn () => (bool) auth()->user()?->isPlatformStaff())
                ->options(fn () => $stats->merchantOptions($merchantIds))
                ->searchable(),

            Select::make('application_id')
                ->label(__('admin.order_stats.filters.application'))
                ->placeholder(__('admin.order_stats.filters.all'))
                ->options(fn () => $stats->applicationOptions($merchantIds))
                ->searchable(),

            Select::make('payment_method_id')
                ->label(__('admin.order_stats.filters.payment_method'))
                ->placeholder(__('admin.order_stats.filters.all'))
                ->options(fn () => $stats->paymentMethodOptions($merchantIds))
                ->searchable(),

            // 下方明细表按哪个维度分组。商户维度对只有一个商户的用户没意义。
            Select::make('dimension')
                ->label(__('admin.order_stats.filters.dimension'))
                ->options(fn () => collect([
                    'payment_method_id' => __('admin.order_stats.filters.payment_method'),
                    'application_id' => __('admin.order_stats.filters.application'),
                    'merchant_id' => __('admin.order_stats.filters.merchant'),
                ])->reject(fn ($label, $key) => $key === 'merchant_id' && ! auth()->user()?->isPlatformStaff())
                    ->all())
                ->default('payment_method_id')
                ->selectablePlaceholder(false),
        ])->columns(4);
    }

    /** @return array<class-string<Widget>> */
    public function getWidgets(): array
    {
        return [
            OrderStatsOverview::class,
            OrderStatsTrendChart::class,
            OrderStatsBreakdownTable::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // 筛选表单在普通 Page 上不会自动渲染（那是 Dashboard 基类干的），
            // 要自己用 EmbeddedSchema 显式排进来，见 Filament\Pages\Dashboard::content()。
            EmbeddedSchema::make('filtersForm'),

            // 单列：趋势图和明细表都要占满宽度，并排会被挤扁
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
        ]);
    }
}
