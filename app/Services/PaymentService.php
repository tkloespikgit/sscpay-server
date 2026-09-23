<?php

namespace App\Services;

use App\Exceptions\NoAvailablePaymentMethodException;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
 * "当天/当月"窗口按系统默认时区（config('app.timezone')）确定，不跟随支付组时区——
 * 详见 dayRange() 的注释。统计维度是支付方式本身
 * （orders.payment_method_id），跨商户汇总——系统级支付方式可分配给多个商户，
 * 限额和均衡都以通道总量为准，见 dailyStatsForMethods()。
 *
 * 阈值判断的"当前累计值"以 orders 表实时查询为准（口径是全部"曾经支付成功过"
 * 的状态，见 Order::NEVER_PAID_STATUSES），按首次支付时间 paid_at 归入日/月窗口；
 * paid_at 为空的历史已成交订单暂按 created_at 归入窗口。
 * （2.4 节允许用 Redis 计数器加速，这里先给出 DB 查询版本作为权威实现；
 * 如果后续要接入 Redis 计数器，替换 dailyStatsForMethods()/monthlyAmountForMethod()
 * 内部实现即可，对外接口不变）。
 */
class PaymentService
{
    public function __construct(private readonly TelegramNotificationService $telegram) {}

    /**
     * @throws NoAvailablePaymentMethodException
     */
    public function resolvePaymentMethod(PaymentGroup $group, float $amountUsd): PaymentMethod
    {
        $candidates = $group->activePaymentMethods()->get();

        // 一次 SQL 批量取全部候选的当天统计，再逐一过风控，避免每个通道一次查询。
        [$dayStart, $dayEnd] = $this->dayRange();
        $dailyStats = $this->dailyStatsForMethods($candidates->pluck('id')->all(), $dayStart, $dayEnd);

        $passing = [];

        foreach ($candidates as $method) {
            $stats = $dailyStats[$method->id] ?? ['amount' => 0.0, 'count' => 0];

            if ($this->passesRiskControl($method, $amountUsd, $stats)) {
                $passing[] = $method;
            }
        }

        if ($passing === []) {
            $this->alertNoAvailablePaymentMethod($group, $amountUsd, $candidates->count());

            throw new NoAvailablePaymentMethodException($group->group_key);
        }

        return $this->pickLeastLoaded($passing, $dailyStats);
    }

    /**
     * 订单进来但整个支付组下没有一个通道能用（组内压根没启用的支付方式，
     * 或者全部候选都被风控阈值挡住）——这笔订单会直接建单失败，商户很可能
     * 完全不知道自己在丢单，所以主动推一条 Telegram 提醒，而不是等商户自己
     * 发现"最近订单量怎么掉了"才去后台排查。
     *
     * 加一层短时去重（按支付组，10 分钟）：真实场景下这通常是配置问题
     * （比如所有通道的日限额都设太低），短时间内会被同一批客户结账重试
     * 反复触发，不去重会把 Telegram 刷屏，也会占掉 30 条/分钟的全局限流额度，
     * 挤掉其他更重要的通知（支付成功、争议提醒等）。
     */
    private function alertNoAvailablePaymentMethod(PaymentGroup $group, float $amountUsd, int $candidateCount): void
    {
        $dedupeKey = "no_available_payment_method_alert:{$group->id}";

        if (! Cache::add($dedupeKey, true, now()->addMinutes(10))) {
            return;
        }

        $reason = $candidateCount === 0
            ? __('admin.telegram_notification.no_available_payment_method_reasons.no_active_method')
            : __('admin.telegram_notification.no_available_payment_method_reasons.risk_control_blocked');

        $this->telegram->send($group->merchant_id, __('admin.telegram_notification.no_available_payment_method', [
            'group_name' => $group->group_name,
            'group_key' => $group->group_key,
            'amount' => number_format($amountUsd, 2),
            'reason' => $reason,
        ]));
    }

    /**
     * 在通过风控的通道里，挑"当天已成交金额 / 权重"最小（最欠载）的那个。
     * 平局时权重大的优先，再平按 id 升序保证结果确定。
     *
     * @param  PaymentMethod[]  $methods
     * @param  array<int, array{amount: float, count: int}>  $dailyStats
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
     * @param  array<int, array{amount: float, count: int}>  $dailyStats
     */
    private function loadRatio(PaymentMethod $method, array $dailyStats): float
    {
        $amount = $dailyStats[$method->id]['amount'] ?? 0.0;

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
    private function passesRiskControl(PaymentMethod $method, float $amountUsd, array $daily): bool
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
            [$monthStart, $monthEnd] = $this->monthRange();
            $monthlyAmount = $this->monthlyAmountForMethod($method, $monthStart, $monthEnd);

            if (bccomp((string) ($monthlyAmount + $amountUsd), (string) $method->max_amount_per_month, 2) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 风控窗口一律按系统默认时区（config('app.timezone')）划分，不用支付组时区。
     *
     * 限额统计的维度是"支付方式本身"、跨商户汇总（见 dailyStatsForMethods()）。
     * 窗口如果跟着下单那一方的支付组时区走，同一条通道就会有多个互不对齐的"当天"：
     * 一条系统级支付方式分配给上海和纽约的商户，两地 0 点差十几个小时，上海侧把日
     * 限额跑满后，纽约侧用的是另一个偏移过的窗口，只看得到落在该窗口内的部分流水，
     * 于是继续放行——真实 24 小时内成交额可以接近两倍日限额。月限额在跨月边界同理。
     *
     * 统一成系统时区后，同一条通道的窗口全局只有一个，累计值和阈值才是可比的。
     * 注意这只改风控口径；后台展示用的时区（payment_groups.timezone /
     * merchants.timezone）不受影响，两者本来就是不同用途。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dayRange(): array
    {
        $now = Carbon::now($this->riskControlTimezone());

        return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
    }

    /**
     * 当月窗口，口径同 dayRange()：系统默认时区。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthRange(): array
    {
        $now = Carbon::now($this->riskControlTimezone());

        return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
    }

    private function riskControlTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /**
     * 批量取各支付方式当天（系统时区）已成交统计。
     * 统计口径是"这条支付方式（payment_methods.id）本身"的总量，不按商户拆分、
     * 也不区分支付组：日/月限额保护的是通道本身，一条系统级支付方式分配给多个
     * 商户使用时，各商户的成交要合在一起算，否则限额实际变成"每个商户各一份"。
     * 按 orders.payment_method_id 而不是 method_code 统计，也顺带避免不同商户
     * 各自配了同 code 支付方式时互相串账。
     *
     * 口径是"曾经支付成功过"的全部状态（Order::NEVER_PAID_STATUSES 的补集），
     * 不是只数 status = 'paid'：订单一录物流就变成 shipped，退款/拒付/争议还会
     * 变成别的状态，这些钱都已经过了通道，必须计入当天/当月累计值。
     * 窗口按 paid_at 计算；历史订单没有 paid_at 时才退回 created_at。
     *
     * @param  int[]  $methodIds
     * @return array<int, array{amount: float, count: int}> 以 payment_methods.id 为键
     */
    private function dailyStatsForMethods(array $methodIds, Carbon $start, Carbon $end): array
    {
        if ($methodIds === []) {
            return [];
        }

        $rows = Order::query()
            ->withoutGlobalScopes()
            ->whereIn('payment_method_id', $methodIds)
            ->whereNotIn('status', Order::NEVER_PAID_STATUSES)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('paid_at', [$start, $end])
                    // paid_at 上线前的历史已成交订单没有支付时间，保留旧口径兜底。
                    ->orWhere(fn ($legacy) => $legacy->whereNull('paid_at')
                        ->whereBetween('created_at', [$start, $end]));
            })
            ->selectRaw('payment_method_id, COALESCE(SUM(converted_amount), 0) as total_amount, COUNT(*) as total_count')
            ->groupBy('payment_method_id')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [
            (int) $row->payment_method_id => [
                'amount' => (float) $row->total_amount,
                'count' => (int) $row->total_count,
            ],
        ])->all();
    }

    /**
     * 当月已成交金额，口径同 dailyStatsForMethods()：按支付方式本身跨商户汇总，
     * 且计入全部"曾经支付成功过"的状态。
     */
    private function monthlyAmountForMethod(PaymentMethod $method, Carbon $start, Carbon $end): float
    {
        $result = Order::query()
            ->withoutGlobalScopes()
            ->where('payment_method_id', $method->id)
            ->whereNotIn('status', Order::NEVER_PAID_STATUSES)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('paid_at', [$start, $end])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('paid_at')
                        ->whereBetween('created_at', [$start, $end]));
            })
            ->sum('converted_amount');

        return (float) $result;
    }
}
