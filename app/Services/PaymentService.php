<?php

namespace App\Services;

use App\Exceptions\NoAvailablePaymentMethodException;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use Illuminate\Support\Carbon;

/**
 * 支付路由服务（分散流量防打满策略）。
 *
 * 按最新业务决定：下单时就要锁死唯一的支付方式，不再返回候选列表给前端选择。
 * 策略为加权均匀分配：组内启用的支付方式按各自权重（pivot priority，数值越大
 * 占比越高）分摊进单，每笔订单路由给"当天已成交金额 / 权重"最小且通过风控的
 * 通道，长期下来各通道的当天成交额会收敛到配置的占比，避免单通道先打满日限额。
 * 全部候选都不通过风控则整单失败（NoAvailablePaymentMethodException，
 * 调用方应让整个下单事务回滚）。
 *
 * "当天/当月"窗口按组时区（payment_groups.timezone，未配置回退系统时区）确定，
 * 与日/月限额风控的统计窗口保持同一时区口径。
 *
 * 阈值判断的"当前累计值"以 orders 表（status = paid）实时查询为准
 * （2.4 节允许用 Redis 计数器加速，这里先给出 DB 查询版本作为权威实现；
 * 如果后续要接入 Redis 计数器，替换 dailyStatsForMethods()/monthlyAmountForMethod()
 * 内部实现即可，对外接口不变）。
 */
class PaymentService
{
    /**
     * @throws NoAvailablePaymentMethodException
     */
    public function resolvePaymentMethod(PaymentGroup $group, float $amountUsd): PaymentMethod
    {
        $candidates = $group->activePaymentMethods()->get();

        // 一次 SQL 批量取全部候选的当天统计，再逐一过风控，避免每个通道一次查询。
        [$dayStart, $dayEnd] = $this->groupDayRange($group);
        $dailyStats = $this->dailyStatsForMethods($group, $candidates->pluck('method_code')->all(), $dayStart, $dayEnd);

        $passing = [];

        foreach ($candidates as $method) {
            $stats = $dailyStats[$method->method_code] ?? ['amount' => 0.0, 'count' => 0];

            if ($this->passesRiskControl($group, $method, $amountUsd, $stats)) {
                $passing[] = $method;
            }
        }

        if ($passing === []) {
            throw new NoAvailablePaymentMethodException($group->group_key);
        }

        return $this->pickLeastLoaded($passing, $dailyStats);
    }

    /**
     * 在通过风控的通道里，挑"当天已成交金额 / 权重"最小（最欠载）的那个。
     * 平局时权重大的优先，再平按 id 升序保证结果确定。
     *
     * @param  PaymentMethod[]  $methods
     * @param  array<string, array{amount: float, count: int}>  $dailyStats
     */
    private function pickLeastLoaded(array $methods, array $dailyStats): PaymentMethod
    {
        usort($methods, function (PaymentMethod $a, PaymentMethod $b) use ($dailyStats) {
            return [
                $this->loadRatio($a, $dailyStats),
                -$this->weightOf($a),
                $a->id,
            ] <=> [
                $this->loadRatio($b, $dailyStats),
                -$this->weightOf($b),
                $b->id,
            ];
        });

        return $methods[0];
    }

    /**
     * 负载率 = 当天已成交金额 / 权重，越小表示越欠载、越应该进单。
     *
     * @param  array<string, array{amount: float, count: int}>  $dailyStats
     */
    private function loadRatio(PaymentMethod $method, array $dailyStats): float
    {
        $amount = $dailyStats[$method->method_code]['amount'] ?? 0.0;

        return $amount / $this->weightOf($method);
    }

    /**
     * 权重取组内 pivot 的 priority（数值越大占比越高），至少为 1 防止除零。
     */
    private function weightOf(PaymentMethod $method): int
    {
        return max(1, (int) $method->pivot?->priority);
    }

    /**
     * @param  array{amount: float, count: int}  $daily
     */
    private function passesRiskControl(PaymentGroup $group, PaymentMethod $method, float $amountUsd, array $daily): bool
    {
        if ($method->exceedsPerTransactionLimit($amountUsd)) {
            return false;
        }

        if (! $method->isUnlimited('max_amount_per_day')
            && bccomp((string) ($daily['amount'] + $amountUsd), (string) $method->max_amount_per_day, 2) > 0) {
            return false;
        }

        if (! $method->isUnlimited('max_count_per_day')
            && ($daily['count'] + 1) > $method->max_count_per_day) {
            return false;
        }

        if (! $method->isUnlimited('max_amount_per_month')) {
            [$monthStart, $monthEnd] = $this->groupMonthRange($group);
            $monthlyAmount = $this->monthlyAmountForMethod($group, $method, $monthStart, $monthEnd);

            if (bccomp((string) ($monthlyAmount + $amountUsd), (string) $method->max_amount_per_month, 2) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 组的"当天"窗口（组时区的 0 点到 24 点），换算成 DB 存储时区用于查询。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function groupDayRange(PaymentGroup $group): array
    {
        $now = Carbon::now($group->effectiveTimezone());
        $dbTimezone = (string) config('app.timezone', 'UTC');

        return [
            $now->copy()->startOfDay()->setTimezone($dbTimezone),
            $now->copy()->endOfDay()->setTimezone($dbTimezone),
        ];
    }

    /**
     * 组的"当月"窗口（组时区），换算成 DB 存储时区用于查询。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function groupMonthRange(PaymentGroup $group): array
    {
        $now = Carbon::now($group->effectiveTimezone());
        $dbTimezone = (string) config('app.timezone', 'UTC');

        return [
            $now->copy()->startOfMonth()->setTimezone($dbTimezone),
            $now->copy()->endOfMonth()->setTimezone($dbTimezone),
        ];
    }

    /**
     * 批量取各支付方式当天（组时区）已成交统计。
     * 统计口径为整个商户维度（与日/月限额风控一致），不区分支付组。
     *
     * @param  string[]  $methodCodes
     * @return array<string, array{amount: float, count: int}> 以 method_code 为键
     */
    private function dailyStatsForMethods(PaymentGroup $group, array $methodCodes, Carbon $start, Carbon $end): array
    {
        if ($methodCodes === []) {
            return [];
        }

        $rows = Order::query()
            ->withoutGlobalScopes()
            ->where('merchant_id', $group->merchant_id)
            ->whereIn('payment_method', $methodCodes)
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('payment_method, COALESCE(SUM(converted_amount), 0) as total_amount, COUNT(*) as total_count')
            ->groupBy('payment_method')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [
            $row->payment_method => [
                'amount' => (float) $row->total_amount,
                'count' => (int) $row->total_count,
            ],
        ])->all();
    }

    private function monthlyAmountForMethod(PaymentGroup $group, PaymentMethod $method, Carbon $start, Carbon $end): float
    {
        $result = Order::query()
            ->withoutGlobalScopes()
            ->where('merchant_id', $group->merchant_id)
            ->where('payment_method', $method->method_code)
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->sum('converted_amount');

        return (float) $result;
    }
}
