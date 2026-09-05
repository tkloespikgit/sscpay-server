<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExchangeRateTrendChart;
use App\Models\ExchangeRate;
use App\Models\ExchangeRateHistory;
use App\Models\SystemConfig;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Artisan;

/**
 * 汇率趋势页（超级管理员专属）。
 *
 * 基准币种固定 USD——全系统记账口径就是 USD（orders.converted_amount、商户余额、
 * 风控阈值），页面展示的是 exchange.supported_currencies 里配置的各币种兑美元的
 * 历史走势，窗口可切 7 / 30 / 90 天。
 *
 * 三块内容：
 *  1. 同步状态：最近一次抓取时间、已配置币种、快照总量与保留期；
 *  2. 当前汇率：exchange_rates 里正在被下单链路使用的值（含汇损前的原始汇率）；
 *  3. 趋势图：ExchangeRateTrendChart，数据来自 exchange_rate_histories 快照表。
 *
 * 【为什么历史数据是从上线才开始累积】exchange_rates 有 (base, target) 唯一约束
 * 且每次抓取原地覆盖，本身没有历史维度；第三方免费版接口也不提供历史序列，
 * 订单表里的 original_exchange_rate 只是"下单那一刻用过的值"（样本稀疏、口径带汇损），
 * 拿它回填会画出失真曲线。所以 exchange_rate_histories 是纯追加式的，
 * 曲线从 exchange:fetch 第一次成功写入快照那一刻起按小时累积。
 */
class ExchangeRateTrends extends Page
{
    /**
     * 记账/展示基准币种，与 ExchangeRate::updateBatchRates() 写入时用的基准一致。
     */
    private const BASE_CURRENCY = 'USD';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static string|\UnitEnum|null $navigationGroup = null;

    /**
     * 排序放在「平台管理」分组靠后位置：系统配置、日志查看之后。
     */
    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.exchange_rate.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.exchange_rate.title');
    }

    /**
     * 页面上要渲染的 Widget。
     *
     * 不覆盖 getHeaderWidgets() / getFooterWidgets()：那两个钩子的渲染位置由布局
     * 固定死（内容区之前 / 之后），而这里要把图表排在「同步状态」「当前汇率」
     * 两个 Section 之后，所以统一在 content() 里按自己的顺序渲染。
     *
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            ExchangeRateTrendChart::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->syncStatusSection(),
            $this->currentRatesSection(),

            // 单列网格：趋势图需要占满内容区宽度，两个 Widget 并排会把折线挤扁。
            Grid::make(1)
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
        ]);
    }

    /**
     * 同步状态区块。「最近一次汇率同步」的入口就在这里：右上角 headerActions
     * 的「立即同步」按钮 + 本区块展示的最后同步时间，两者一起构成完整的入口。
     */
    private function syncStatusSection(): Section
    {
        return Section::make(__('admin.exchange_rate.sync.section'))
            ->description(__('admin.exchange_rate.sync.section_desc'))
            ->schema([
                Grid::make(4)->schema([
                    TextEntry::make('last_sync_at')
                        ->label(__('admin.exchange_rate.sync.last_sync_at'))
                        ->state(fn () => ExchangeRateHistory::lastSyncAt(self::BASE_CURRENCY)?->toDateTimeString()
                            ?? __('admin.exchange_rate.sync.never'))
                        ->placeholder(__('admin.exchange_rate.sync.never')),
                    TextEntry::make('last_sync_ago')
                        ->label(__('admin.exchange_rate.sync.last_sync_ago'))
                        ->state(fn () => ExchangeRateHistory::lastSyncAt(self::BASE_CURRENCY)?->diffForHumans()
                            ?? __('admin.exchange_rate.sync.never')),
                    TextEntry::make('supported_currencies')
                        ->label(__('admin.exchange_rate.sync.supported_currencies'))
                        ->state(fn () => $this->supportedCurrenciesLabel())
                        ->helperText(__('admin.exchange_rate.sync.supported_currencies_help'))
                        ->columnSpan(2),
                    TextEntry::make('snapshot_count')
                        ->label(__('admin.exchange_rate.sync.snapshot_count'))
                        ->state(fn () => number_format(
                            ExchangeRateHistory::query()->where('base_currency', self::BASE_CURRENCY)->count()
                        )),
                    TextEntry::make('retention_days')
                        ->label(__('admin.exchange_rate.sync.retention_days'))
                        ->state(fn () => __('admin.exchange_rate.sync.retention_days_value', [
                            'days' => (int) SystemConfig::get(
                                'exchange.history_retention_days',
                                ExchangeRateHistory::DEFAULT_RETENTION_DAYS
                            ),
                        ])),
                    TextEntry::make('schedule')
                        ->label(__('admin.exchange_rate.sync.schedule'))
                        ->state(fn () => __('admin.exchange_rate.sync.schedule_value'))
                        ->helperText(__('admin.exchange_rate.sync.schedule_help'))
                        ->columnSpan(2),
                ]),
            ]);
    }

    /**
     * 当前汇率区块：展示 exchange_rates 里正在被下单链路读取的值
     * （getRateWithSurcharge() 就是读这张表，汇损在这之上另算）。
     *
     * 用 exchange_rates 而不是快照表最新一行：前者才是真正影响订单金额的
     * 权威数据源，两者理论上同值，但万一快照写入失败（见 FetchExchangeRates
     * 的 try/catch），这里必须显示下单实际会用的那个值。
     */
    private function currentRatesSection(): Section
    {
        return Section::make(__('admin.exchange_rate.current.section'))
            ->description(__('admin.exchange_rate.current.section_desc', ['base' => self::BASE_CURRENCY]))
            ->schema(fn (): array => $this->currentRateEntries())
            ->columns(4);
    }

    /**
     * @return array<TextEntry>
     */
    private function currentRateEntries(): array
    {
        $rates = ExchangeRate::query()
            ->where('base_currency', self::BASE_CURRENCY)
            ->orderBy('target_currency')
            ->get();

        if ($rates->isEmpty()) {
            return [
                TextEntry::make('no_rates')
                    ->hiddenLabel()
                    ->state(__('admin.exchange_rate.current.empty'))
                    ->columnSpanFull(),
            ];
        }

        return $rates
            ->map(fn (ExchangeRate $rate) => TextEntry::make('current_rate_'.$rate->target_currency)
                ->label(__('admin.exchange_rate.current.rate_label', [
                    'currency' => $rate->target_currency,
                    'base' => self::BASE_CURRENCY,
                ]))
                // decimal:6 的 cast 会给出字符串，直接展示会带一堆无意义的尾零，
                // 统一格式化成 6 位小数（与库里 rate 字段的精度一致）。
                ->state(number_format((float) $rate->rate, 6))
                ->helperText($rate->retrieved_at
                    ? __('admin.exchange_rate.current.retrieved_at', [
                        'time' => $rate->retrieved_at->toDateTimeString(),
                    ])
                    : null))
            ->all();
    }

    private function supportedCurrenciesLabel(): string
    {
        $currencies = SystemConfig::getArray('exchange.supported_currencies', []);

        return empty($currencies)
            ? __('admin.exchange_rate.sync.not_configured')
            : implode('、', $currencies);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->syncNowAction(),
        ];
    }

    /**
     * 「立即同步」：在 Web 请求里同步执行 exchange:fetch，跑完硬刷新页面，
     * 让趋势图和最后同步时间立刻反映新数据。
     *
     * 同步执行而不是丢队列：抓取本身只是一次第三方 HTTP 调用（命令里
     * timeout 10s + retry 3），最坏情况约 30 秒，用户点完就能看到结果，
     * 不需要为了这个再引入队列 worker 依赖和"任务已提交请稍后刷新"的
     * 二段式体验。
     *
     * 结果判定用「快照表的最后同步时间有没有前进」，而不是匹配命令的控制台
     * 输出文本：命令在 supported_currencies 为空时也会返回 SUCCESS，
     * 在第三方接口失败时返回 FAILURE，只看退出码分不清"配置没配"和"抓取失败"，
     * 而输出文案属于实现细节，不该成为 UI 分支的依据。
     */
    private function syncNowAction(): Action
    {
        return Action::make('syncExchangeRatesNow')
            ->label(__('admin.exchange_rate.actions.sync_now'))
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalHeading(__('admin.exchange_rate.actions.sync_now'))
            ->modalDescription(__('admin.exchange_rate.actions.sync_now_desc'))
            ->modalSubmitActionLabel(__('admin.exchange_rate.actions.sync_now_submit'))
            ->action(function () {
                $before = ExchangeRateHistory::lastSyncAt(self::BASE_CURRENCY);

                try {
                    $exitCode = Artisan::call('exchange:fetch');
                    $output = trim(Artisan::output());
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('admin.exchange_rate.actions.sync_now_exception', ['error' => $e->getMessage()]))
                        ->danger()
                        ->send();

                    return;
                }

                $after = ExchangeRateHistory::lastSyncAt(self::BASE_CURRENCY);
                $advanced = $after !== null && ($before === null || $after->greaterThan($before));

                if ($exitCode !== ConsoleCommand::SUCCESS || ! $advanced) {
                    Notification::make()
                        ->title(__('admin.exchange_rate.actions.sync_now_failed'))
                        ->body($output !== '' ? $output : __('admin.exchange_rate.actions.sync_now_failed_hint'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.exchange_rate.actions.sync_now_success', [
                        'time' => $after->toDateTimeString(),
                    ]))
                    ->success()
                    ->send();

                // 硬跳转刷新整页：趋势图是独立的 Livewire 子组件，只刷新当前页面
                // 组件不会让它重新查库；「最后同步时间」「当前汇率」也要重新取值。
                $this->redirect(static::getUrl());
            });
    }

    /**
     * 汇率是平台级配置，抓取失败会直接影响所有商户的订单折算金额，
     * 排查属于平台运维职责——商户用户既看不到也不该看到，与
     * SystemConfigResource / FilamentLogViewer 的门禁口径保持一致。
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return static::shouldRegisterNavigation();
    }
}
