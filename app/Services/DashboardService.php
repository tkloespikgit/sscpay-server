<?php

namespace App\Services;

use App\Models\OrderDailyStat;
use App\Models\OrderStatsRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * 仪表盘只读取每日汇总；统计范围沿用 manageableMerchantIds 的 null / 数组语义。
 */
class DashboardService
{
    public const PERIODS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'custom'];

    /** @return array<int>|null */
    public static function viewerMerchantIds(): ?array
    {
        $viewer = auth()->user();

        return $viewer instanceof User ? $viewer->manageableMerchantIds() : [];
    }

    /**
     * 非法日期回退当天，不能因筛选输入无效而扩大统计范围。
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dateRange(array $filters = []): array
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();

        if (($filters['period'] ?? 'today') === 'custom'
            && is_string($filters['date_from'] ?? null)
            && is_string($filters['date_to'] ?? null)) {
            $validator = Validator::make($filters, [
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:'.$today->toDateString()],
            ]);

            if ($validator->passes()) {
                $dates = $validator->validated();

                return [
                    Carbon::createFromFormat('!Y-m-d', $dates['date_from'], config('app.timezone'))->startOfDay(),
                    Carbon::createFromFormat('!Y-m-d', $dates['date_to'], config('app.timezone'))->endOfDay(),
                ];
            }
        }

        return match ($filters['period'] ?? 'today') {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()->endOfDay()],
            'last_7_days' => [$today->copy()->subDays(6), $today->copy()->endOfDay()],
            'last_30_days' => [$today->copy()->subDays(29), $today->copy()->endOfDay()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$today, $today->copy()->endOfDay()],
        };
    }

    /**
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     * @return array{total_orders: int, paid_orders: int, failed_orders: int, total_amount_usd: float, total_merchants: int, unpaid_terminal_orders: int, missing_dates: list<string>, is_complete: bool}
     */
    public function summary(?array $merchantIds, array $filters = []): array
    {
        $row = $this->scopedStats($merchantIds, $filters)
            ->selectRaw('COALESCE(SUM(created_orders), 0) as total_orders, COALESCE(SUM(paid_orders), 0) as paid_orders, COALESCE(SUM(failed_orders), 0) as failed_orders, COALESCE(SUM(paid_amount), 0) as total_amount_usd, COALESCE(SUM(unpaid_terminal_orders), 0) as unpaid_terminal_orders')
            ->first();
        [$from, $to] = $this->dateRange($filters);
        $missing = $merchantIds === [] ? [] : OrderStatsRun::missingDatesBetween($from, $to);

        return [
            'total_orders' => (int) $row->total_orders,
            'paid_orders' => (int) $row->paid_orders,
            'failed_orders' => (int) $row->failed_orders,
            'total_amount_usd' => (float) $row->total_amount_usd,
            'total_merchants' => $this->scopedStats($merchantIds, $filters)->where('paid_orders', '>', 0)->distinct()->count('order_daily_stats.merchant_id'),
            'unpaid_terminal_orders' => (int) $row->unpaid_terminal_orders,
            'missing_dates' => $missing,
            'is_complete' => $missing === [],
        ];
    }

    /**
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     * @return list<array{date: string, order_count: int, amount_usd: float}>
     */
    public function trend(?array $merchantIds, array $filters = []): array
    {
        [$from, $to] = $this->dateRange($filters);
        $rows = $this->scopedStats($merchantIds, $filters)
            ->selectRaw('stat_date, SUM(created_orders) as order_count, COALESCE(SUM(paid_amount), 0) as amount_usd')
            ->groupBy('stat_date')->orderBy('stat_date')->get()
            ->keyBy(fn (OrderDailyStat $row): string => $row->stat_date->toDateString());
        $result = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $row = $rows->get($date->toDateString());
            $result[] = [
                'date' => $date->toDateString(),
                'order_count' => (int) ($row?->order_count ?? 0),
                'amount_usd' => (float) ($row?->amount_usd ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     * @return list<array{merchant_id: int, merchant_name: string, amount_usd: float}>
     */
    public function merchantSalesRanking(?array $merchantIds, array $filters = [], int $limit = 10): array
    {
        return $this->scopedStats($merchantIds, $filters)
            ->join('merchants', 'merchants.id', '=', 'order_daily_stats.merchant_id')
            ->selectRaw('order_daily_stats.merchant_id, merchants.name as merchant_name, SUM(paid_amount) as amount_usd')
            ->groupBy('order_daily_stats.merchant_id', 'merchants.name')
            ->havingRaw('SUM(paid_orders) > 0')
            ->orderByDesc('amount_usd')->orderBy('order_daily_stats.merchant_id')->limit(max(1, $limit))->get()
            ->map(fn (OrderDailyStat $row): array => [
                'merchant_id' => (int) $row->merchant_id,
                'merchant_name' => (string) $row->merchant_name,
                'amount_usd' => (float) $row->amount_usd,
            ])->all();
    }

    /**
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     * @return list<array{payment_method: string, order_count: int, percentage: float}>
     */
    public function paymentMethodBreakdown(?array $merchantIds, array $filters = []): array
    {
        $rows = $this->scopedStats($merchantIds, $filters)
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'order_daily_stats.payment_method_id')
            ->selectRaw('payment_methods.method_code as payment_method, SUM(paid_orders) as order_count')
            ->groupBy('payment_methods.method_code')->havingRaw('SUM(paid_orders) > 0')
            ->orderByDesc('order_count')->orderBy('payment_methods.method_code')->get();
        $total = (int) $rows->sum('order_count');

        return $rows->map(fn (OrderDailyStat $row): array => [
            'payment_method' => (string) ($row->payment_method ?? __('admin.order_stats.unknown_dimension')),
            'order_count' => (int) $row->order_count,
            'percentage' => $total > 0 ? round($row->order_count / $total * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * 已支付事件加未支付终态事件作分母，退款/拒付事件不重复增加分母。
     *
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     * @return array{success_rate: float, paid_count: int, terminal_count: int}
     */
    public function getPaymentSuccessRate(?array $merchantIds, array $filters = []): array
    {
        $row = $this->scopedStats($merchantIds, $filters)
            ->selectRaw('COALESCE(SUM(paid_orders), 0) as paid_orders, COALESCE(SUM(unpaid_terminal_orders), 0) as unpaid_terminal_orders')->first();
        $paid = (int) $row->paid_orders;
        $terminal = $paid + (int) $row->unpaid_terminal_orders;

        return ['success_rate' => $terminal > 0 ? round($paid / $terminal * 100, 1) : 0.0, 'paid_count' => $paid, 'terminal_count' => $terminal];
    }

    /**
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getAdminStats(?array $merchantIds = null, array $filters = []): array
    {
        return [
            ...$this->summary($merchantIds, $filters),
            'today_new_orders' => $this->summary($merchantIds)['total_orders'],
            'trend_7d' => $this->trend($merchantIds, ['period' => 'last_7_days']),
            'trend_30d' => $this->trend($merchantIds, ['period' => 'last_30_days']),
            'merchant_ranking' => $this->merchantSalesRanking($merchantIds, $filters),
            'payment_method_breakdown' => $this->paymentMethodBreakdown($merchantIds, $filters),
        ];
    }

    /** @return array<string, mixed> */
    public function getMerchantStats(int $merchantId, array $filters = []): array
    {
        return $this->getAdminStats([$merchantId], $filters);
    }

    /**
     * @param  array<int>|null  $merchantIds
     * @param  array<string, mixed>  $filters
     */
    private function scopedStats(?array $merchantIds, array $filters): Builder
    {
        [$from, $to] = $this->dateRange($filters);

        return OrderDailyStat::query()->forViewer($merchantIds)
            ->whereBetween('order_daily_stats.stat_date', [$from->toDateString(), $to->toDateString()]);
    }
}
