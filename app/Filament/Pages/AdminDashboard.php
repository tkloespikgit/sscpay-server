<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MerchantBalanceOverview;
use App\Filament\Widgets\MerchantSalesRankingChart;
use App\Filament\Widgets\OrderStatsBreakdownTable;
use App\Filament\Widgets\OrderTrendChart;
use App\Filament\Widgets\PaymentMethodBreakdownChart;
use App\Filament\Widgets\PaymentSuccessRate;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TransactionAmountTrendChart;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\OrderStatsAggregator;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Panel;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * 订单指标共用已确认的统计筛选，余额卡片始终展示当前余额。
 * 商户范围由 DashboardService::viewerMerchantIds() 按登录用户确定。
 */
class AdminDashboard extends BaseDashboard
{
    public const QUICK_PERIODS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month'];

    private const REPORTS_ANCHOR = 'data-reports';

    private const OVERVIEW_REPORT = 'overview';

    /** @var array{period: string, date_from: ?string, date_to: ?string, owner_id?: ?int, merchant_id?: ?int, application_id?: ?int, payment_method_id?: ?int} */
    #[Locked]
    public array $filters = ['period' => 'today', 'date_from' => null, 'date_to' => null];

    /** @var array<string, mixed> */
    public array $dimensionFilters = [];

    public string $activeReport = self::OVERVIEW_REPORT;

    #[Locked]
    public int $statsRefreshVersion = 0;

    /** 面板根路径交由 Filament 按可访问导航跳转，客服直接进入订单查询。 */
    protected static string $routePath = '/dashboard';

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! $this->canViewReport($this->activeReport)) {
            $this->activeReport = self::OVERVIEW_REPORT;
        }
    }

    public function updatingActiveReport(mixed $report): void
    {
        abort_unless(is_string($report) && $this->canViewReport($report), 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status && ! $user->isCustomerServiceOnly();
    }

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
    public static function getSlug(?Panel $panel = null): string
    {
        return 'dashboard';
    }

    public function selectPeriod(string $period): void
    {
        abort_unless(static::canAccess(), 403);

        Validator::make(['period' => $period], [
            'period' => ['required', Rule::in(self::QUICK_PERIODS)],
        ])->validate();

        $this->filters = [...$this->filters, 'period' => $period, 'date_from' => null, 'date_to' => null];
    }

    public function customDateRangeAction(): Action
    {
        return Action::make('customDateRange')
            ->label(__('admin.dashboard.periods.custom'))
            ->authorize(fn (): bool => static::canAccess())
            ->modalHeading(__('admin.dashboard.filters.custom_heading'))
            ->modalSubmitActionLabel(__('admin.dashboard.filters.apply'))
            ->fillForm(function (): array {
                abort_unless(static::canAccess(), 403);

                [$from, $to] = app(DashboardService::class)->dateRange($this->filters);

                return ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];
            })
            ->schema([
                DatePicker::make('date_from')
                    ->label(__('admin.dashboard.filters.date_from'))
                    ->format('Y-m-d')
                    ->required()
                    ->maxDate(fn () => today())
                    ->rules(['date_format:Y-m-d']),
                DatePicker::make('date_to')
                    ->label(__('admin.dashboard.filters.date_to'))
                    ->format('Y-m-d')
                    ->required()
                    ->afterOrEqual('date_from')
                    ->maxDate(fn () => today())
                    ->rules(['date_format:Y-m-d']),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);

                $dates = Validator::make($data, [
                    'date_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:date_to'],
                    'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:'.today()->toDateString()],
                ])->validate();

                $this->filters = [...$this->filters, 'period' => 'custom', ...$dates];
            });
    }

    public function refreshTodayStatsAction(): Action
    {
        return Action::make('refreshTodayStats')
            ->label(__('admin.dashboard.stats.refresh_today'))
            ->icon(Heroicon::ArrowPath)
            ->authorize(fn (): bool => $this->todayStatsRefreshDate() !== null)
            ->action(function (OrderStatsAggregator $aggregator): void {
                $date = $this->todayStatsRefreshDate();
                abort_unless($date !== null, 403);

                try {
                    $aggregator->aggregateDate($date);
                } catch (LockTimeoutException) {
                    Notification::make()->title(__('admin.order_stats.actions.refresh_busy'))->warning()->send();

                    return;
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('admin.order_stats.actions.refresh_failed'))->danger()->send();

                    return;
                }

                $this->statsRefreshVersion++;

                Notification::make()->title(__('admin.dashboard.stats.refresh_today_success'))->success()->send();
            });
    }

    private function todayStatsRefreshDate(): ?Carbon
    {
        if (! static::canAccess() || ! OrderStats::canAccess()) {
            return null;
        }

        [$from, $to] = app(DashboardService::class)->dateRange($this->filters);
        $today = Carbon::today(config('app.timezone'));

        return $from->isSameDay($today) && $to->isSameDay($today) ? $today : null;
    }

    /** @return array<string, string> */
    public function getPeriodOptions(): array
    {
        return collect(self::QUICK_PERIODS)
            ->mapWithKeys(fn (string $period): array => [$period => __('admin.dashboard.periods.'.$period)])
            ->all();
    }

    public function getDateRangeLabel(): string
    {
        abort_unless(static::canAccess(), 403);

        [$from, $to] = app(DashboardService::class)->dateRange($this->filters);

        return $from->toDateString().' — '.$to->toDateString();
    }

    public function dimensionFiltersForm(Schema $schema): Schema
    {
        $user = auth()->user();

        return $schema
            ->components([
                $this->dimensionFilter('owner_id', __('admin.dashboard.filters.owner'), ['merchant_id', 'application_id', 'payment_method_id'])
                    ->visible((bool) $user?->is_super_admin),
                $this->dimensionFilter('merchant_id', __('admin.order_stats.filters.merchant'), ['application_id', 'payment_method_id'])
                    ->visible((bool) $user?->isPlatformStaff()),
                $this->dimensionFilter('application_id', __('admin.order_stats.filters.application'), ['payment_method_id']),
                $this->dimensionFilter('payment_method_id', __('admin.order_stats.filters.payment_method')),
            ])
            ->columns(['sm' => 2, 'xl' => $user?->is_super_admin ? 4 : ($user?->isPlatformStaff() ? 3 : 2)])
            ->statePath('dimensionFilters');
    }

    /** @param array<string> $dependentFields */
    private function dimensionFilter(string $field, string $label, array $dependentFields = []): Select
    {
        return Select::make($field)
            ->label($label)
            ->placeholder(__('admin.order_stats.filters.all'))
            ->options(fn (): array => app(DashboardService::class)->filterOptions($field, $this->dimensionFilters))
            ->searchable()
            ->live()
            ->afterStateUpdated(function (Set $set) use ($dependentFields): void {
                abort_unless(static::canAccess(), 403);

                foreach ($dependentFields as $dependentField) {
                    $set($dependentField, null);
                }

                $dimensions = app(DashboardService::class)->validateDimensionFilters($this->dimensionFilters, 'dimensionFilters.');
                $this->filters = [...$this->filters, ...$dimensions];
            });
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.dashboard-period-filter'),
            Tabs::make(__('admin.dashboard.overview'))
                ->id(self::REPORTS_ANCHOR)
                ->key(fn (): string => self::REPORTS_ANCHOR.'-'.$this->statsRefreshVersion)
                ->extraAttributes(['style' => 'scroll-margin-top: 5rem'])
                ->livewireProperty('activeReport')
                ->tabs(fn (): array => $this->getReportTabs()),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MerchantBalanceOverview::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            StatsOverview::class,
            PaymentSuccessRate::class,
            OrderTrendChart::class,
            TransactionAmountTrendChart::class,
            MerchantSalesRankingChart::class,
            PaymentMethodBreakdownChart::class,
        ];
    }

    /** @return array<string, Tab> */
    private function getReportTabs(): array
    {
        $tabs = [
            self::OVERVIEW_REPORT => Tab::make(__('admin.dashboard.overview'))
                ->schema([$this->getWidgetsContentComponent()]),
        ];

        foreach ($this->reportDimensions() as $report => $dimension) {
            $tabs[$report] = Tab::make(__('admin.dashboard.breakdowns.'.$report))
                ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                    OrderStatsBreakdownTable::make(['fixedDimension' => $dimension]),
                ]));
        }

        return $tabs;
    }

    private function canViewReport(string $report): bool
    {
        return $report === self::OVERVIEW_REPORT || isset($this->reportDimensions()[$report]);
    }

    /** @return array<string, string> */
    private function reportDimensions(): array
    {
        if (! OrderStats::canAccess()) {
            return [];
        }

        $user = auth()->user();

        return [
            ...($user?->is_super_admin ? ['owner' => 'owner_id'] : []),
            ...($user?->isPlatformStaff() ? ['merchant' => 'merchant_id'] : []),
            'application' => 'application_id',
            'payment_method' => 'payment_method_id',
        ];
    }
}
