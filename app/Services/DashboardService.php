<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 仪表盘统计服务（4.7 节）。
 *
 * 统计范围统一用 $merchantIds 表达，语义与 User::manageableMerchantIds() 完全一致：
 * null = 不限（仅超级管理员），数组 = 只统计这些商户。
 *
 * 原来这里收的是单个 $merchantId，调用方一律传 auth()->user()->merchant_id。
 * 这对超管（NULL → 全平台）和普通商户用户（自己那一个）都对，唯独漏了商户级管理员：
 * 这个角色按定义 merchant_id 恒为 NULL（User::isMerchantManager()），于是和超管
 * 一样走进"不过滤"分支，在首页看到的是全平台的订单数、成交额、趋势和支付方式占比。
 * 改成商户集合后三种角色都落在同一套口径上。
 *
 * 注意：这里的查询都用 withoutGlobalScopes() + 显式 whereIn('merchant_id', ...)，
 * 而不是依赖 MerchantScope 自动生效，以保证统计口径不受 Global Scope 行为影响。
 */
class DashboardService
{
    /**
     * 当前登录后台账号的统计范围。所有仪表盘部件统一走这里，避免各自再写一遍。
     *
     * 返回 null 表示不限（超级管理员）。没有登录用户、或登录的不是后台 User
     * （观察者面板用的是 observer guard，auth()->user() 会解析成 Observer，
     * 它没有 manageableMerchantIds()）时返回空数组——什么都看不到，而不是什么都看得到。
     *
     * @return array<int>|null
     */
    public static function viewerMerchantIds(): ?array
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return [];
        }

        return $viewer->manageableMerchantIds();
    }

    /**
     * @param  array<int>|null  $merchantIds  null 表示不限（仅超级管理员视角可用）
     */
    public function getAdminStats(?array $merchantIds = null): array
    {
        $orderQuery = fn () => $this->scopedOrders($merchantIds);

        return [
            'total_orders' => $orderQuery()->count(),
            'paid_orders' => $orderQuery()->where('status', 'paid')->count(),
            'total_amount_usd' => (float) $orderQuery()->where('status', 'paid')->sum('converted_amount'),
            'total_merchants' => $merchantIds === null ? Merchant::query()->count() : count($merchantIds),
            'today_new_orders' => $orderQuery()->whereDate('created_at', now()->toDateString())->count(),
            'trend_7d' => $this->orderTrend($merchantIds, 7),
            'trend_30d' => $this->orderTrend($merchantIds, 30),
            'merchant_ranking' => $this->merchantSalesRanking($merchantIds),
            'payment_method_breakdown' => $this->paymentMethodBreakdown($merchantIds),
            'event_exception_stats' => $this->eventExceptionStats($merchantIds),
        ];
    }

    public function getMerchantStats(int $merchantId): array
    {
        return $this->getAdminStats([$merchantId]);
    }

    /**
     * 支付成功率（口径 1，订单维度）：
     * 分母 = 时间窗内已到达终态的订单（除 pending 外全部状态），
     * 分子 = 其中支付成功过的订单（paid 及其流转状态，退款/拒付也算成功过）。
     * 排除 pending 是因为它们还没出结果，算进分母会拉低成功率。
     *
     * @param  array<int>|null  $merchantIds  null 表示不限（仅超级管理员视角）
     * @param  string|null  $paymentMethod  为空表示不限支付方式
     * @return array{success_rate: float, paid_count: int, terminal_count: int}
     */
    public function getPaymentSuccessRate(?array $merchantIds, ?string $paymentMethod = null, int $days = 30): array
    {
        $query = $this->scopedOrders($merchantIds)
            ->where('created_at', '>=', now()->subDays($days));

        if ($paymentMethod) {
            $query->where('payment_method', $paymentMethod);
        }

        // 支付成功过的状态：paid 本身 + 支付成功后流转出的状态。
        // refunded / partially_refunded / chargeback 虽然钱退了，但支付环节是成功的；
        // disputing / dispute_review 同理——订单已经收到过款，只是眼下有网关拒付争议
        // 或人工审核未决。
        $paidStatuses = ['paid', 'shipped', 'completed', 'refunded', 'partially_refunded', 'chargeback', 'disputing', 'dispute_review'];

        $terminalCount = (clone $query)->where('status', '!=', 'pending')->count();
        $paidCount = (clone $query)->whereIn('status', $paidStatuses)->count();

        return [
            'success_rate' => $terminalCount > 0 ? round($paidCount / $terminalCount * 100, 1) : 0.0,
            'paid_count' => $paidCount,
            'terminal_count' => $terminalCount,
        ];
    }

    /**
     * 实际出现过的支付方式列表（去重），供仪表盘支付方式筛选器使用。
     * 从订单表取而不是 payment_methods 表，避免列出从未被使用的支付方式。
     *
     * @return array<int, string>
     */
    public function getPaymentMethodOptions(?array $merchantIds): array
    {
        return $this->scopedOrders($merchantIds)
            ->whereNotNull('payment_method')
            ->where('payment_method', '!=', '')
            ->distinct()
            ->orderBy('payment_method')
            ->pluck('payment_method')
            ->values()
            ->all();
    }

    /**
     * @param  array<int>|null  $merchantIds  null = 不限；空数组 = 什么都不统计
     */
    private function scopedOrders(?array $merchantIds)
    {
        $query = Order::query()->withoutGlobalScopes();

        if ($merchantIds !== null) {
            $query->whereIn('merchant_id', $merchantIds);
        }

        return $query;
    }

    /**
     * @return array<int, array{date: string, order_count: int, amount_usd: float}>
     */
    private function orderTrend(?array $merchantIds, int $days): array
    {
        $rows = $this->scopedOrders($merchantIds)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as order_count, COALESCE(SUM(CASE WHEN status = "paid" THEN converted_amount ELSE 0 END), 0) as amount_usd')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $rows->get($date);

            $result[] = [
                'date' => $date,
                'order_count' => (int) ($row->order_count ?? 0),
                'amount_usd' => (float) ($row->amount_usd ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{merchant_id: int, merchant_name: string, amount_usd: float}>
     */
    private function merchantSalesRanking(?array $merchantIds = null, int $limit = 10): array
    {
        return DB::table('orders')
            ->join('merchants', 'merchants.id', '=', 'orders.merchant_id')
            ->where('orders.status', 'paid')
            ->whereNull('orders.deleted_at')
            ->when($merchantIds !== null, fn ($q) => $q->whereIn('orders.merchant_id', $merchantIds))
            ->selectRaw('orders.merchant_id, merchants.name as merchant_name, SUM(orders.converted_amount) as amount_usd')
            ->groupBy('orders.merchant_id', 'merchants.name')
            ->orderByDesc('amount_usd')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'merchant_id' => (int) $row->merchant_id,
                'merchant_name' => $row->merchant_name,
                'amount_usd' => (float) $row->amount_usd,
            ])
            ->all();
    }

    /**
     * @return array<int, array{payment_method: string, order_count: int, percentage: float}>
     */
    private function paymentMethodBreakdown(?array $merchantIds): array
    {
        $query = $this->scopedOrders($merchantIds)->where('status', 'paid');
        $total = (clone $query)->count();

        if ($total === 0) {
            return [];
        }

        return $query
            ->selectRaw('payment_method, COUNT(*) as order_count')
            ->groupBy('payment_method')
            ->orderByDesc('order_count')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'order_count' => (int) $row->order_count,
                'percentage' => round($row->order_count / $total * 100, 1),
            ])
            ->all();
    }

    /**
     * 订单日志异常统计：ERROR 级别日志（争议/拒付等，见
     * doc/s-system-payment-status-notify.md 第八节）占比。
     */
    private function eventExceptionStats(?array $merchantIds): array
    {
        $query = OrderEvent::query()->withoutGlobalScopes();

        if ($merchantIds !== null) {
            $query->whereIn('merchant_id', $merchantIds);
        }

        $query->where('occurred_at', '>=', now()->subDays(30));

        $total = (clone $query)->count();
        $failedCount = (clone $query)->where('level', 'ERROR')->count();

        return [
            'total_events_30d' => $total,
            'failed_events_30d' => $failedCount,
            'failure_rate_percentage' => $total > 0 ? round($failedCount / $total * 100, 1) : 0.0,
        ];
    }
}
