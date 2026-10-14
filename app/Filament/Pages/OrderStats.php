<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OrderStatsBreakdownTable;
use App\Filament\Widgets\OrderStatsOverview;
use App\Filament\Widgets\OrderStatsTrendChart;
use App\Models\OrderStatsRun;
use App\Services\DashboardService;
use App\Services\OrderStatsAggregator;
use App\Services\OrderStatsQueryService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshStatsAction(),
        ];
    }

    /**
     * 「立即刷新」：在 Web 请求里同步重算当前统计周期对应日期区间的
     * order_daily_stats，跑完硬刷新页面让三张 widget 立刻反映新数据。
     *
     * 【为什么需要它】统计表由 order-stats:aggregate 定时重算（每 3 小时一次，
     * 覆盖今天+昨天），所以数字最多滞后约 3 小时。运营在对账时经常是"先看
     * 页面、后等调度"，这个入口让他们不用干等。
     *
     * 【刷新范围跟着周期走】用户在看「当月」时点刷新，就重算当月；看「上一月」
     * 就重算上一月。这样按钮的语义与页面正在展示的数据始终一致，不会出现
     * "刷了但看到的还是旧数"的困惑。
     *
     * 【为什么加锁】聚合是「整日删掉重插」（见 OrderStatsAggregator 类注释），
     * 两个人同时点刷新会让两个请求交替删除同一批行、结果互相覆盖。而本页对
     * 所有能看订单统计的账号开放（超管、商户级管理员、普通商户用户），并不是
     * 只有超管会点，所以并发是真实存在的。用 Cache::lock 兜住，第二个点击直接
     * 收到"正在刷新中"而不是把第一个的结果搅乱。
     *
     * 【为什么用运行记录判定成败】不解析 Artisan 的控制台输出文本——那是实现
     * 细节，不该成为 UI 分支依据；改看 order_stats_runs 的 completed_at 有没有
     * 前进，与 ExchangeRateTrends 的「立即同步」同一套判定思路。
     */
    private function refreshStatsAction(): Action
    {
        return Action::make('refreshOrderStats')
            ->label(__('admin.order_stats.actions.refresh_now'))
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalHeading(__('admin.order_stats.actions.refresh_now'))
            ->modalDescription(fn (): string => __('admin.order_stats.actions.refresh_now_desc', [
                'range' => $this->refreshRangeLabel(),
            ]))
            ->modalSubmitActionLabel(__('admin.order_stats.actions.refresh_now_submit'))
            ->action(function () {
                [$from, $to] = $this->refreshDateRange();

                // 手动刷新和定时任务可能撞车，加锁把并发的第二个请求挡在门外。
                // 锁的 TTL 取 120 秒：整月重算在数据量大时可能跑满一分钟，
                // 120 秒既留够了执行时间，又不至于进程崩溃后锁长期挂死。
                $lock = Cache::lock(self::REFRESH_LOCK_KEY, 120);

                if (! $lock->get()) {
                    Notification::make()
                        ->title(__('admin.order_stats.actions.refresh_busy'))
                        ->danger()
                        ->send();

                    return;
                }

                $latestBefore = OrderStatsRun::query()
                    ->orderByDesc('completed_at')
                    ->value('completed_at');

                try {
                    $written = app(OrderStatsAggregator::class)->aggregateDateRange($from, $to);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('admin.order_stats.actions.refresh_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                } finally {
                    $lock->release();
                }

                $latestAfter = OrderStatsRun::query()
                    ->orderByDesc('completed_at')
                    ->value('completed_at');

                $advanced = $latestAfter !== null
                    && ($latestBefore === null || Carbon::parse($latestAfter)->greaterThan(Carbon::parse($latestBefore)));

                if (! $advanced) {
                    Notification::make()
                        ->title(__('admin.order_stats.actions.refresh_failed'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.order_stats.actions.refresh_now_success', [
                        'rows' => number_format($written),
                    ]))
                    ->success()
                    ->send();

                // 硬跳转刷新整页：三张 widget 是独立的 Livewire 子组件，
                // 只刷新当前页面组件不会让它们重新查库（与汇率页同一处理）。
                $this->redirect(static::getUrl());
            });
    }

    /**
     * 并发锁键。同账号多标签页会互斥，不同账号各自放行。
     *
     * 公开是为了让并发测试能真实地"占住锁"再验证按钮行为——把常量藏成
     * private 会迫使测试靠字符串硬编码键名，那才是真正脆弱的写法。
     */
    public const REFRESH_LOCK_KEY = 'order-stats:refresh-lock';

    /**
     * 当前统计周期对应的重算区间。
     *
     * 复用 OrderStatsQueryService::periodRange()——看板读数与手动刷新必须用
     * 同一套周期换算，否则会出现"刷的范围和看的范围不是一回事"。
     * 非法/缺失的 period 在那边会回退到「当天」，这里保持同样的行为。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function refreshDateRange(): array
    {
        /** @var string|null $period */
        $period = $this->filters['period'] ?? null;

        return app(OrderStatsQueryService::class)->periodRange($period);
    }

    /** 确认弹窗里展示的重算范围，让用户点之前就知道会重算哪几天。 */
    private function refreshRangeLabel(): string
    {
        [$from, $to] = $this->refreshDateRange();

        $fromDate = Carbon::parse($from);
        $toDate = Carbon::parse($to);

        return $fromDate->isSameDay($toDate)
            ? $fromDate->toDateString()
            : $fromDate->toDateString().' ~ '.$toDate->toDateString();
    }
}
