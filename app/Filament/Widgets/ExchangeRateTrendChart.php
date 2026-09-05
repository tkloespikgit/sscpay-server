<?php

namespace App\Filament\Widgets;

use App\Models\ExchangeRateHistory;
use Filament\Widgets\ChartWidget;

/**
 * 汇率趋势折线图：基准币种固定 USD，展示 exchange.supported_currencies
 * 里配置的各币种兑美元的历史汇率变化，窗口可切 7 / 30 / 90 天。
 *
 * 数据源是 exchange_rate_histories 追加式快照表（见 ExchangeRateHistory），
 * 不是 exchange_rates——后者 (base, target) 唯一且每次抓取原地覆盖，只有当前值。
 *
 * 汇率趋势是平台级财务视角的数据，对商户没有意义（商户看到的是下单时冻结的
 * 汇率快照），所以 canView() 直接限超级管理员，与 MerchantSalesRankingChart 一致。
 */
class ExchangeRateTrendChart extends ChartWidget
{
    /**
     * 基准币种。整个系统的记账口径就是 USD（orders.converted_amount、
     * 商户余额、风控阈值全部按 USD），这里不做成可配置项。
     */
    private const BASE_CURRENCY = 'USD';

    /**
     * 允许的窗口天数。筛选器下拉的 value 来自这里，getData() 也会用它做白名单
     * 校验——$filter 是公开的 Livewire 属性，前端可以传任意值，不能直接拿去查库。
     */
    private const WINDOWS = [7, 30, 90];

    /**
     * 折线配色，按币种字母序依次取用；币种数超过调色板长度时循环复用。
     * 不复用 Filament 的主题色（$color），因为一个 Widget 里要同时画多条线，
     * 单色主题色区分不开。
     */
    private const PALETTE = [
        '#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4',
        '#a855f7', '#84cc16', '#ec4899', '#14b8a6', '#f97316',
    ];

    /**
     * 默认窗口 30 天：7 天在初期数据稀疏时几乎画不出线，90 天点位太密，
     * 30 天是最能看出趋势的折中值（与仪表盘「订单趋势」的默认窗口一致）。
     */
    public ?string $filter = '30';

    protected ?string $maxHeight = '380px';

    public function getHeading(): ?string
    {
        return __('admin.exchange_rate.trend.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.exchange_rate.trend.description');
    }

    /**
     * 窗口筛选器。用 ChartWidget 自带的 $filter + getFilters() 而不是页面级
     * HasFiltersForm：这个筛选只影响本图表，页面级筛选器会通过 pageFilters
     * 广播给页面上所有 Widget，属于过度设计。
     */
    protected function getFilters(): ?array
    {
        return collect(self::WINDOWS)
            ->mapWithKeys(fn (int $days) => [(string) $days => __('admin.exchange_rate.trend.windows.'.$days)])
            ->all();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = $this->resolveDays();

        $series = ExchangeRateHistory::dailySeries($days, self::BASE_CURRENCY);

        // 一条快照都没有（刚部署 / 抓取一直失败）时返回空数组，触发 ChartWidget
        // 的空状态渲染（isEmpty() 就是 empty(getCachedData())）——比画出一堆
        // 全 null 的空白坐标系更能说明"还没有历史数据"。
        if (empty($series['series'])) {
            return [];
        }

        $datasets = [];
        $index = 0;

        foreach ($series['series'] as $currency => $points) {
            $color = self::PALETTE[$index % count(self::PALETTE)];
            $index++;

            $datasets[] = [
                'label' => __('admin.exchange_rate.trend.dataset_label', [
                    'currency' => $currency,
                    'base' => self::BASE_CURRENCY,
                ]),
                'data' => $points,
                'borderColor' => $color,
                'backgroundColor' => $color,
                'fill' => false,
                'tension' => 0.25,
                // 缺数据的日期是 null。spanGaps 保持 false（Chart.js 默认），
                // 让折线在该处断开，而不是把前后两天直连成一条看着像真实波动的斜线。
                'spanGaps' => false,
                // 90 天窗口有 90 个点，画出圆点会糊成一片；短窗口才显示数据点。
                'pointRadius' => $days > 31 ? 0 : 3,
                'pointHoverRadius' => 4,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $series['labels'],
        ];
    }

    /**
     * Chart.js 配置。Filament 的 chart Alpine 组件会把这里返回的数组直接当作
     * Chart.js options（只对没设置的键用 ??= 补默认值），所以只写需要偏离默认的部分。
     */
    protected function getOptions(): array
    {
        return [
            // 同一天多个币种共用一条竖直辅助线，鼠标不用精确压在某个点上。
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'x' => [
                    // 90 天窗口下 90 个日期标签会互相重叠，让 Chart.js 自动抽稀。
                    'ticks' => [
                        'maxRotation' => 0,
                        'autoSkip' => true,
                        'maxTicksLimit' => 12,
                    ],
                ],
                'y' => [
                    'title' => [
                        'display' => true,
                        'text' => __('admin.exchange_rate.trend.y_axis', ['base' => self::BASE_CURRENCY]),
                    ],
                    // 汇率波动通常在千分位级别，从 0 开始画会让曲线压成一条直线。
                    // 不写死 min/max，交给 Chart.js 按数据自适应，但关掉"必须包含 0"。
                    'beginAtZero' => false,
                    'grid' => [
                        'display' => true,
                    ],
                ],
            ],
        ];
    }

    public function getEmptyStateHeading(): string
    {
        return __('admin.exchange_rate.trend.empty_heading');
    }

    public function getEmptyStateDescription(): ?string
    {
        return __('admin.exchange_rate.trend.empty_description');
    }

    public function getEmptyStateIcon(): string
    {
        // 注意：当前 blade-heroicons 版本里没有 chart-line，可用的是
        // chart-bar / chart-pie / arrow-trending-up，趋势语义用后者最贴。
        return 'heroicon-o-arrow-trending-up';
    }

    /**
     * 把 $filter 收敛成白名单里的窗口天数。$filter 是 public Livewire 属性，
     * 可以被前端任意赋值，非法值一律回退到默认 30 天。
     */
    private function resolveDays(): int
    {
        $days = (int) $this->filter;

        return in_array($days, self::WINDOWS, true) ? $days : 30;
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }
}
