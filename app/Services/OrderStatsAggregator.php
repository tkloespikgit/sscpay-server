<?php

namespace App\Services;

use App\Console\Commands\AggregateOrderStats;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderDailyStat;
use App\Models\OrderRefund;
use App\Models\OrderStatsRun;
use App\Models\Scopes\MerchantScope;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 订单每日统计的聚合器（商户 × 应用 × 支付方式），落 order_daily_stats。
 *
 * 【归日口径：事件时间】每个指标按各自事件真正发生的时间归日，而不是下单时间：
 *
 *   | 指标     | 时间锚点                                   | 金额                      |
 *   |----------|--------------------------------------------|---------------------------|
 *   | 支付成功 | orders.paid_at（空则 created_at）          | orders.converted_amount   |
 *   | 失败     | orders.failed_at（空则 created_at）        | orders.converted_amount   |
 *   | 退款     | order_refunds.created_at                   | order_refunds.amount_usd  |
 *   | 拒付     | 拒付本金流水的 created_at                  | 流水金额取绝对值          |
 *
 * 这样过去的日子一旦统计完就基本不再变动（三天前付的款今天退，落进今天那格）。
 * paid_at/failed_at 的 NULL 兜底回 created_at，口径与
 * PaymentService::dailyStatsForMethods() 对历史订单的处理完全一致——两边若不一致，
 * 看板和风控限额会对不上，那正是 DashboardOrderStatsAlignmentTest 盯的那类 bug。
 *
 * 【为什么是 delete-then-insert 而不是 upsert】upsert 只能改写仍然出现在结果集里的
 * 键，抹不掉已经不该存在的行：某天先统计出 (商户1, 应用3, 渠道7) = 5 笔，随后这批
 * 订单被软删除，重算时该键不再出现在任何一条查询结果里，upsert 不会碰它——那 5 笔
 * 就永远留着了。整日删掉重插才是自愈的。
 *
 * 【重算窗口】"事件时间"让绝大多数历史不再变动，但有两个例外必须靠滚动重算兜住：
 * 网关可能带着真实 paid_at 延迟补推（OrderPaymentStatusService 优先采用 payload 里的
 * 时间，会写进过去的某一天），以及定时任务失败一次之后的自愈。所以调度是
 * 每天凌晨重算最近若干天，而不是只算昨天一天。
 *
 * @see AggregateOrderStats
 */
class OrderStatsAggregator
{
    /** 分组键的维度列，顺序即 groupBy 顺序。 */
    private const DIMENSIONS = ['merchant_id', 'application_id', 'payment_method_id'];

    /**
     * 重算某一天的统计。整日删掉重插，可反复执行。
     *
     * @return int 写入的统计行数（0 表示当天确实没有任何交易）
     */
    public function aggregateDate(\DateTimeInterface $date): int
    {
        $startedAt = microtime(true);

        $day = Carbon::parse($date->format('Y-m-d'), config('app.timezone'));
        [$from, $to] = [$day->copy()->startOfDay(), $day->copy()->endOfDay()];

        $buckets = [];

        $this->collectPaid($buckets, $from, $to);
        $this->collectFailed($buckets, $from, $to);
        $this->collectRefunded($buckets, $from, $to);
        $this->collectChargeback($buckets, $from, $to);

        $rows = $this->toRows($buckets, $day);

        DB::transaction(function () use ($day, $rows) {
            OrderDailyStat::query()->where('stat_date', $day->toDateString())->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                OrderDailyStat::query()->insert($chunk);
            }
        });

        OrderStatsRun::query()->updateOrCreate(
            ['stat_date' => $day->toDateString()],
            [
                'completed_at' => now(),
                'rows_written' => count($rows),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
        );

        return count($rows);
    }

    /**
     * 重算最近 $days 天（含今天）。
     *
     * @return int 写入的总行数
     */
    public function aggregateRecentDays(int $days): int
    {
        $written = 0;

        for ($i = $days - 1; $i >= 0; $i--) {
            $written += $this->aggregateDate(now()->subDays($i));
        }

        return $written;
    }

    // ------------------------------------------------------------------
    // 四个指标的取数
    // ------------------------------------------------------------------

    /**
     * 支付成功：口径是 Order::scopePaidEver()（NEVER_PAID_STATUSES 的补集），
     * 只要支付成功过就算，不看订单终态——订单一发货状态就流转成 shipped，
     * 只数 status='paid' 会把已发货/已完成的成交额全漏掉。
     */
    private function collectPaid(array &$buckets, Carbon $from, Carbon $to): void
    {
        $rows = $this->orders()
            ->paidEver()
            ->where(fn (Builder $q) => $this->whereEventBetween($q, 'paid_at', $from, $to))
            ->selectRaw(implode(',', self::DIMENSIONS).', COUNT(*) as c, COALESCE(SUM(converted_amount), 0) as a')
            ->groupBy(self::DIMENSIONS)
            ->get();

        $this->merge($buckets, $rows, 'paid_orders', 'paid_amount');
    }

    /** 失败：只数 status='failed'，不含 cancelled/expired（业务方指定口径）。 */
    private function collectFailed(array &$buckets, Carbon $from, Carbon $to): void
    {
        $rows = $this->orders()
            ->where('status', 'failed')
            ->where(fn (Builder $q) => $this->whereEventBetween($q, 'failed_at', $from, $to))
            ->selectRaw(implode(',', self::DIMENSIONS).', COUNT(*) as c, COALESCE(SUM(converted_amount), 0) as a')
            ->groupBy(self::DIMENSIONS)
            ->get();

        $this->merge($buckets, $rows, 'failed_orders', 'failed_amount');
    }

    /**
     * 退款：按退款单归日。订单数用 COUNT(DISTINCT order_id)——同一单当天多次
     * 部分退款算一单（用户问的是"退款订单笔数"）。这个数事后无法从日汇总反推，
     * 所以必须在这里就定死口径。
     */
    private function collectRefunded(array &$buckets, Carbon $from, Carbon $to): void
    {
        $rows = $this->joinedToOrders(OrderRefund::query()->withoutGlobalScopes()->toBase(), 'order_refunds')
            ->whereBetween('order_refunds.created_at', [$from, $to])
            ->selectRaw($this->orderDimensions().', COUNT(DISTINCT order_refunds.order_id) as c, COALESCE(SUM(order_refunds.amount_usd), 0) as a')
            ->groupBy($this->orderDimensionColumns())
            ->get();

        $this->merge($buckets, $rows, 'refunded_orders', 'refunded_amount');
    }

    /**
     * 拒付：按拒付本金流水归日（金额取绝对值，流水里是负数）。
     * 只取 TYPE_CHARGEBACK 不取 TYPE_CHARGEBACK_FEE——手续费是平台收的，
     * 不是被拒付的订单金额。
     */
    private function collectChargeback(array &$buckets, Carbon $from, Carbon $to): void
    {
        $rows = $this->joinedToOrders(
            MerchantBalanceTransaction::query()->withoutGlobalScopes()->toBase(),
            'merchant_balance_transactions'
        )
            ->where('merchant_balance_transactions.type', MerchantBalanceTransaction::TYPE_CHARGEBACK)
            ->whereBetween('merchant_balance_transactions.created_at', [$from, $to])
            ->selectRaw($this->orderDimensions().', COUNT(DISTINCT merchant_balance_transactions.order_id) as c, COALESCE(SUM(ABS(merchant_balance_transactions.amount)), 0) as a')
            ->groupBy($this->orderDimensionColumns())
            ->get();

        $this->merge($buckets, $rows, 'chargeback_orders', 'chargeback_amount');
    }

    // ------------------------------------------------------------------
    // 查询构造
    // ------------------------------------------------------------------

    /**
     * 统计用的订单查询。
     *
     * 只摘 MerchantScope（统计本来就是全平台口径，且命令行没有登录态），
     * **不能用不带参的 withoutGlobalScopes()**——那会把软删除作用域一起摘掉，
     * 把已删除的订单算进来。软删除订单一律排除，与 DashboardService 对齐。
     */
    private function orders(): Builder
    {
        return Order::query()->withoutGlobalScopes([MerchantScope::class]);
    }

    /**
     * 事件时间落在窗口内。历史订单该时间戳为 NULL，回退用 created_at 判断——
     * 写法照搬 PaymentService::dailyStatsForMethods()，两边口径必须一致。
     */
    private function whereEventBetween(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($column, [$from, $to])
            ->orWhere(fn (Builder $legacy) => $legacy
                ->whereNull($column)
                ->whereBetween('created_at', [$from, $to]));
    }

    /**
     * 把退款单/余额流水 join 回 orders 取维度（应用、支付方式）。
     *
     * 用 join 而不是 leftJoin：orders 是财务记录，外键都是 restrictOnDelete，
     * 不会出现孤儿退款单。但要显式排除软删除订单，口径与 orders() 一致。
     */
    private function joinedToOrders(QueryBuilder $query, string $table): QueryBuilder
    {
        return $query
            ->join('orders', 'orders.id', '=', $table.'.order_id')
            ->whereNull('orders.deleted_at');
    }

    /** join 场景下的维度取值，payment_method_id 为空时归到 0（未知渠道）。 */
    private function orderDimensions(): string
    {
        return 'orders.merchant_id, orders.application_id, COALESCE(orders.payment_method_id, 0) as payment_method_id';
    }

    /** @return array<int, Expression|string> */
    private function orderDimensionColumns(): array
    {
        return ['orders.merchant_id', 'orders.application_id', DB::raw('COALESCE(orders.payment_method_id, 0)')];
    }

    // ------------------------------------------------------------------
    // 合并与落库
    // ------------------------------------------------------------------

    /**
     * 把一次分组查询的结果并进 $buckets，键为 "商户|应用|渠道"。
     *
     * @param  iterable<object>  $rows
     */
    private function merge(array &$buckets, iterable $rows, string $countColumn, string $amountColumn): void
    {
        foreach ($rows as $row) {
            $merchantId = (int) $row->merchant_id;
            $applicationId = (int) $row->application_id;
            $paymentMethodId = (int) ($row->payment_method_id ?? 0);

            $key = "{$merchantId}|{$applicationId}|{$paymentMethodId}";

            $buckets[$key] ??= [
                'merchant_id' => $merchantId,
                'application_id' => $applicationId,
                'payment_method_id' => $paymentMethodId,
            ] + array_fill_keys(OrderDailyStat::METRIC_COLUMNS, 0);

            $buckets[$key][$countColumn] = (int) $row->c;
            $buckets[$key][$amountColumn] = (string) $row->a;
        }
    }

    /**
     * 补上 stat_date / 时间戳，转成可直接 insert 的行。
     *
     * @return array<int, array<string, mixed>>
     */
    private function toRows(array $buckets, Carbon $day): array
    {
        $now = now();

        return array_values(array_map(fn (array $bucket) => $bucket + [
            'stat_date' => $day->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $buckets));
    }
}
