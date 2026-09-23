<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Order;
use App\Models\OrderDailyStat;
use App\Models\OrderStatsRun;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderStatsAggregator;
use App\Services\OrderStatsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 订单每日统计的聚合口径。
 *
 * 核心不变量：按**事件时间**归日（支付按 paid_at、失败按 failed_at、
 * 退款按退款发生时间、拒付按拒付流水时间），所以过去的日子统计完就不再变动；
 * 以及整日 delete-then-insert，重算能自愈。
 */
class OrderStatsAggregatorTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    /** 支付成功过的 8 个状态 —— Order::NEVER_PAID_STATUSES 的补集。 */
    private const PAID_EVER = [
        'paid', 'shipped', 'completed', 'refunded',
        'partially_refunded', 'chargeback', 'disputing', 'dispute_review',
    ];

    private PaymentMethod $paymentMethod;

    private OrderStatsAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []], 200)]);

        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '100000.00']);
        $this->paymentMethod = $this->makePaymentMethod('stripe');
        $this->aggregator = app(OrderStatsAggregator::class);
    }

    public function test_paid_metric_counts_every_status_that_was_ever_paid(): void
    {
        foreach (self::PAID_EVER as $i => $status) {
            $this->statOrder("P{$i}", ['status' => $status, 'paid_at' => now()]);
        }

        // 从未收到过钱的状态不计入
        foreach (Order::NEVER_PAID_STATUSES as $i => $status) {
            $this->statOrder("N{$i}", ['status' => $status, 'paid_at' => null]);
        }

        $this->aggregator->aggregateDate(now());

        $row = OrderDailyStat::query()->sole();
        $this->assertSame(8, $row->paid_orders);
        $this->assertSame('800.00', (string) $row->paid_amount);
    }

    /** 发货后 status 变成 shipped，成交额不能因此从统计里消失。 */
    public function test_shipping_an_order_keeps_it_in_the_paid_metric(): void
    {
        $order = $this->statOrder('SSC-1', ['status' => 'paid', 'paid_at' => now()]);

        $this->aggregator->aggregateDate(now());
        $before = OrderDailyStat::query()->sole()->paid_amount;

        $order->update(['status' => 'shipped']);
        $this->aggregator->aggregateDate(now());

        $this->assertSame($before, OrderDailyStat::query()->sole()->paid_amount);
    }

    /**
     * 失败订单按 failed_at 而不是 created_at 归日 —— 这是"过去的日子不再变动"
     * 这个前提的关键：付款链接 7 天有效，周一下的单周四才判失败。
     */
    public function test_failed_orders_are_bucketed_by_failed_at_not_created_at(): void
    {
        $this->statOrder('SSC-1', [
            'status' => 'failed',
            'created_at' => now()->subDays(3),
            'failed_at' => now(),
        ]);

        $this->aggregator->aggregateDate(now()->subDays(3));
        $this->aggregator->aggregateDate(now());

        $this->assertSame(0, $this->statFor(now()->subDays(3))?->failed_orders ?? 0);
        $this->assertSame(1, $this->statFor(now())->failed_orders);
    }

    /** 只数 status='failed'，不含 cancelled/expired（业务方指定口径）。 */
    public function test_failed_metric_excludes_cancelled_and_expired(): void
    {
        $this->statOrder('F', ['status' => 'failed', 'failed_at' => now()]);
        $this->statOrder('C', ['status' => 'cancelled']);
        $this->statOrder('E', ['status' => 'expired']);

        $this->aggregator->aggregateDate(now());

        $this->assertSame(1, $this->statFor(now())->failed_orders);
        $this->assertSame('100.00', (string) $this->statFor(now())->failed_amount);
    }

    /** 历史订单没有 paid_at，要回退按 created_at 归日（口径同 PaymentService）。 */
    public function test_legacy_orders_without_paid_at_fall_back_to_created_at(): void
    {
        $this->statOrder('SSC-1', [
            'status' => 'paid',
            'paid_at' => null,
            'created_at' => now()->subDays(2),
        ]);

        $this->aggregator->aggregateDate(now()->subDays(2));

        $this->assertSame(1, $this->statFor(now()->subDays(2))->paid_orders);
    }

    /** 三天前付的款今天退，退款要落进今天，不回头改三天前那一格。 */
    public function test_refunds_are_bucketed_on_the_day_the_refund_happened(): void
    {
        $order = $this->statOrder('SSC-1', [
            'status' => 'paid',
            'paid_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
        ]);

        app(BalanceService::class)->refund($order, '40.00', $this->operator(), '部分退款');

        $this->aggregator->aggregateDate(now()->subDays(3));
        $this->aggregator->aggregateDate(now());

        $paidDay = $this->statFor(now()->subDays(3));
        $this->assertSame(1, $paidDay->paid_orders);
        $this->assertSame(0, $paidDay->refunded_orders);

        $refundDay = $this->statFor(now());
        $this->assertSame(1, $refundDay->refunded_orders);
        $this->assertSame('40.00', (string) $refundDay->refunded_amount);
    }

    /** 同一单当天退两次，金额累加但订单数只算一单。 */
    public function test_multiple_partial_refunds_on_one_order_count_as_one_order(): void
    {
        $order = $this->statOrder('SSC-1', ['status' => 'paid', 'paid_at' => now()]);

        app(BalanceService::class)->refund($order, '30.00', $this->operator(), '第一次');
        app(BalanceService::class)->refund($order->refresh(), '20.00', $this->operator(), '第二次');

        $this->aggregator->aggregateDate(now());

        $row = $this->statFor(now());
        $this->assertSame(1, $row->refunded_orders);
        $this->assertSame('50.00', (string) $row->refunded_amount);
    }

    public function test_chargeback_metric_uses_the_principal_ledger_not_the_fee(): void
    {
        $this->paymentMethod->update(['chargeback_fee' => '15.00']);
        $order = $this->statOrder('SSC-1', ['status' => 'paid', 'paid_at' => now()]);

        app(BalanceService::class)->chargeback($order, $this->operator(), '网关拒付');

        $this->aggregator->aggregateDate(now());

        $row = $this->statFor(now());
        $this->assertSame(1, $row->chargeback_orders);
        // 只取拒付本金 100，不含 15 的拒付手续费
        $this->assertSame('100.00', (string) $row->chargeback_amount);
    }

    public function test_metrics_are_split_across_application_and_payment_method(): void
    {
        $otherApp = Application::createWithCredentials([
            'merchant_id' => $this->merchant->id,
            'name' => 'App B',
            'website' => 'https://b.example.com',
        ]);
        $otherMethod = $this->makePaymentMethod('paypal');

        $this->statOrder('A1', ['status' => 'paid', 'paid_at' => now()]);
        $this->statOrder('A2', ['status' => 'paid', 'paid_at' => now(), 'application_id' => $otherApp->id]);
        $this->statOrder('A3', ['status' => 'paid', 'paid_at' => now(), 'payment_method_id' => $otherMethod->id]);

        $this->aggregator->aggregateDate(now());

        $this->assertSame(3, OrderDailyStat::query()->count());
        $this->assertSame(3, (int) OrderDailyStat::query()->sum('paid_orders'));
    }

    public function test_re_aggregating_is_idempotent(): void
    {
        $this->statOrder('SSC-1', ['status' => 'paid', 'paid_at' => now()]);

        $this->aggregator->aggregateDate(now());
        $first = OrderDailyStat::query()->get()->map->only(OrderDailyStat::METRIC_COLUMNS)->toArray();

        $this->aggregator->aggregateDate(now());
        $this->aggregator->aggregateDate(now());

        $this->assertSame(1, OrderDailyStat::query()->count());
        $this->assertSame($first, OrderDailyStat::query()->get()->map->only(OrderDailyStat::METRIC_COLUMNS)->toArray());
    }

    /**
     * 整日 delete-then-insert 的关键回归：源订单消失后重算必须把那一行抹掉。
     * 用 upsert 的话该键不再出现在结果集里，旧行会永远留着。
     */
    public function test_re_aggregating_removes_rows_whose_source_orders_are_gone(): void
    {
        $order = $this->statOrder('SSC-1', ['status' => 'paid', 'paid_at' => now()]);

        $this->aggregator->aggregateDate(now());
        $this->assertSame(1, OrderDailyStat::query()->count());

        $order->delete();
        $this->aggregator->aggregateDate(now());

        $this->assertSame(0, OrderDailyStat::query()->count());
    }

    /** 软删除的订单不计入，与 DashboardService 的口径一致。 */
    public function test_soft_deleted_orders_are_excluded(): void
    {
        $this->statOrder('KEEP', ['status' => 'paid', 'paid_at' => now()]);
        $this->statOrder('GONE', ['status' => 'paid', 'paid_at' => now()])->delete();

        $this->aggregator->aggregateDate(now());

        $this->assertSame(1, $this->statFor(now())->paid_orders);
    }

    /** 零交易的日子也要留执行记录，否则分不清"没交易"和"任务没跑"。 */
    public function test_a_day_with_no_activity_still_records_a_run(): void
    {
        $this->aggregator->aggregateDate(now());

        $run = OrderStatsRun::query()->sole();
        $this->assertSame(0, $run->rows_written);
        $this->assertSame(now()->toDateString(), $run->stat_date);

        $this->assertSame([], app(OrderStatsQueryService::class)->missingDates('today'));
    }

    public function test_missing_dates_are_reported_for_days_that_were_never_aggregated(): void
    {
        // 只统计今天，昨天留空
        $this->aggregator->aggregateDate(now());

        $missing = app(OrderStatsQueryService::class)->missingDates('yesterday');

        $this->assertSame([now()->subDay()->toDateString()], $missing);
    }

    // ------------------------------------------------------------------

    private function statFor(\DateTimeInterface $date): ?OrderDailyStat
    {
        return OrderDailyStat::query()
            ->where('stat_date', Carbon::parse($date)->toDateString())
            ->first();
    }

    /**
     * 每张订单金额固定 100 USD，方便直接心算。
     *
     * created_at 要单独 forceFill：它不在 Order::$fillable 里，
     * 混在 create() 的数组里会被批量赋值静默丢掉，测试就会因为"下单时间其实是
     * 今天"而假通过（写这个测试时实际踩到过）。
     */
    private function statOrder(string $suffix, array $overrides = []): Order
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $order = $this->makeOrder("SSC-{$suffix}", "M-{$suffix}", array_merge([
            'payment_method' => $this->paymentMethod->method_code,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '100.00',
            'converted_amount' => '100.00',
            'subtotal' => '100.00',
            'subtotal_converted' => '100.00',
        ], $overrides));

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        return $order;
    }

    private function operator(): User
    {
        return User::firstOrCreate(
            ['email' => 'ops@example.com'],
            ['name' => 'Ops', 'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id],
        );
    }
}
