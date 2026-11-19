<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Merchant;
use App\Models\OrderDailyStat;
use App\Models\OrderStatsRun;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 「订单统计」看板的读取侧：把 order_daily_stats 按周期 + 筛选条件汇总出来。
 *
 * 统计范围一律用 $merchantIds 表达，语义与 User::manageableMerchantIds() 完全一致
 * （null = 不限，仅超级管理员；数组 = 只看这些商户；空数组 = 什么都看不到），
 * 与 DashboardService::viewerMerchantIds() 共用同一个入口，不在这里另起一套。
 *
 * 所有日期窗口按系统时区划分，与写入侧（OrderStatsAggregator）的 stat_date 口径
 * 保持一致——绝不能出现"系统时区分桶、商户时区取窗口"这种两头不靠的组合。
 */
class OrderStatsQueryService
{
    public const PERIOD_TODAY = 'today';

    public const PERIOD_YESTERDAY = 'yesterday';

    public const PERIOD_THIS_MONTH = 'this_month';

    public const PERIOD_LAST_MONTH = 'last_month';

    public const PERIODS = [
        self::PERIOD_TODAY,
        self::PERIOD_YESTERDAY,
        self::PERIOD_THIS_MONTH,
        self::PERIOD_LAST_MONTH,
    ];

    public const BREAKDOWN_DIMENSIONS = ['payment_method_id', 'application_id', 'merchant_id'];

    /**
     * 周期 -> [起始日, 结束日]。非法值一律回退当天：$period 来自 Livewire 的
     * public 属性，前端可以随意赋值。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodRange(?string $period): array
    {
        $now = Carbon::now(config('app.timezone'));

        return match ($period) {
            self::PERIOD_YESTERDAY => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            self::PERIOD_THIS_MONTH => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            self::PERIOD_LAST_MONTH => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /**
     * 四个指标的总计。
     *
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters  merchant_id / application_id / payment_method_id
     * @return array<string, float|int>
     */
    public function totals(?array $merchantIds, ?string $period, array $filters = []): array
    {
        $row = $this->scoped($merchantIds, $period, $filters)
            ->selectRaw(implode(', ', array_map(
                fn (string $column) => "COALESCE(SUM({$column}), 0) as {$column}",
                OrderDailyStat::METRIC_COLUMNS,
            )))
            ->first();

        $totals = [];

        foreach (OrderDailyStat::METRIC_COLUMNS as $column) {
            $totals[$column] = str_ends_with($column, '_orders')
                ? (int) ($row?->{$column} ?? 0)
                : (float) ($row?->{$column} ?? 0);
        }

        return $totals;
    }

    /**
     * 按日的趋势数据（当月/上一月才有意义，当天/昨天只有一个点）。
     *
     * @param  array<int>|null  $merchantIds
     * @return array<int, array{date: string, paid_amount: float, paid_orders: int}>
     */
    public function dailyTrend(?array $merchantIds, ?string $period, array $filters = []): array
    {
        [$from, $to] = $this->periodRange($period);

        $rows = $this->scoped($merchantIds, $period, $filters)
            ->selectRaw('stat_date, SUM(paid_orders) as paid_orders, COALESCE(SUM(paid_amount), 0) as paid_amount')
            ->groupBy('stat_date')
            ->orderBy('stat_date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->stat_date)->toDateString());

        // 把空白日期补成 0，折线不会在没有交易的那天断开
        $result = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($to)) {
            $row = $rows->get($cursor->toDateString());

            $result[] = [
                'date' => $cursor->toDateString(),
                'paid_orders' => (int) ($row->paid_orders ?? 0),
                'paid_amount' => (float) ($row->paid_amount ?? 0),
            ];

            $cursor->addDay();
        }

        return $result;
    }

    /**
     * 按某个维度分组的明细，用于看板下方的分解表。
     *
     * @param  array<int>|null  $merchantIds
     * @param  'merchant_id'|'application_id'|'payment_method_id'  $dimension
     * @return Collection<int, object>
     */
    public function breakdown(?array $merchantIds, ?string $period, string $dimension, array $filters = []): Collection
    {
        return $this->breakdownFromQuery($this->scoped($merchantIds, $period, $filters), $dimension);
    }

    /**
     * @param  Builder<OrderDailyStat>  $query
     * @return Collection<int, object>
     */
    public function breakdownFromQuery(Builder $query, string $dimension): Collection
    {
        if (! in_array($dimension, self::BREAKDOWN_DIMENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported order statistics dimension.');
        }

        return $this->groupedBreakdown($query, $dimension)->get();
    }

    /**
     * 按商户当前归属分组，平台直管与无有效代理归属的订单合在一行。
     * Left join 保留历史商户的统计，即使商户已软删除。
     *
     * @param  Builder<OrderDailyStat>  $query
     * @return Collection<int, object>
     */
    public function breakdownByOwner(Builder $query): Collection
    {
        $viewer = auth()->user();

        abort_unless($viewer instanceof User && $viewer->status && $viewer->is_super_admin, 403);

        $owners = User::query()->select('id', 'name')->whereNull('merchant_id')->where('is_super_admin', false);

        $query->leftJoin('merchants', 'merchants.id', '=', 'order_daily_stats.merchant_id')
            ->leftJoinSub($owners, 'stat_owners', 'stat_owners.id', '=', 'merchants.owner_id');

        return $this->groupedBreakdown($query, 'stat_owners.id')
            ->addSelect('stat_owners.name as dimension_name')
            ->groupBy('stat_owners.name')
            ->orderBy('stat_owners.id')
            ->get();
    }

    /**
     * @param  Builder<OrderDailyStat>  $query
     * @return Builder<OrderDailyStat>
     */
    private function groupedBreakdown(Builder $query, string $dimension): Builder
    {
        $metrics = implode(', ', array_map(
            fn (string $column) => "COALESCE(SUM(order_daily_stats.{$column}), 0) as {$column}",
            OrderDailyStat::METRIC_COLUMNS,
        ));

        return $query->select("{$dimension} as dimension_id")
            ->selectRaw($metrics)
            ->groupBy($dimension)
            ->orderByDesc('paid_amount');
    }

    /**
     * 选定周期内缺少统计执行记录的日期。看板据此提示"这几天没统计过，
     * 数字可能偏小"——零交易的日子有 rows_written=0 的记录，区分得开。
     *
     * @return array<int, string>
     */
    public function missingDates(?string $period): array
    {
        [$from, $to] = $this->periodRange($period);

        // 未来的日期（当月周期会一直排到月末）不算缺失
        $today = Carbon::now(config('app.timezone'))->endOfDay();

        return OrderStatsRun::missingDatesBetween($from, $to->min($today));
    }

    /**
     * 筛选下拉的选项，一律收窄到当前账号可见的商户范围。
     *
     * @param  array<int>|null  $merchantIds
     * @return array<int, string>
     */
    public function merchantOptions(?array $merchantIds): array
    {
        return Merchant::query()
            ->when($merchantIds !== null, fn ($q) => $q->whereIn('id', $merchantIds))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @param array<int>|null $merchantIds */
    public function applicationOptions(?array $merchantIds): array
    {
        return Application::query()
            ->withoutGlobalScopes()
            ->when($merchantIds !== null, fn ($q) => $q->whereIn('merchant_id', $merchantIds))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * 支付方式选项。withTrashed()：已软删除的渠道在历史统计里仍有数据，
     * 不列出来就没法按它筛选（与订单列表那个筛选器的差异是刻意的——
     * 这里是历史统计，那里是当前可用渠道）。
     *
     * @param  array<int>|null  $merchantIds
     */
    public function paymentMethodOptions(?array $merchantIds): array
    {
        return PaymentMethod::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->whereIn('id', OrderDailyStat::query()
                ->forViewer($merchantIds)
                ->distinct()
                ->pluck('payment_method_id'))
            ->orderBy('sort_order')
            ->pluck('method_name', 'id')
            ->all();
    }

    /**
     * 应用了范围、周期、筛选的基础查询。
     *
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     */
    private function scoped(?array $merchantIds, ?string $period, array $filters): Builder
    {
        [$from, $to] = $this->periodRange($period);

        return OrderDailyStat::query()
            ->forViewer($merchantIds)
            ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
            // 商户筛选仍要落在可见范围内：$merchantIds 已经先收窄过，
            // 这里叠加的是用户在下拉里选的那一个。
            ->when(filled($filters['merchant_id'] ?? null), fn ($q) => $q->where('merchant_id', $filters['merchant_id']))
            ->when(filled($filters['application_id'] ?? null), fn ($q) => $q->where('application_id', $filters['application_id']))
            ->when(filled($filters['payment_method_id'] ?? null), fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
    }
}
