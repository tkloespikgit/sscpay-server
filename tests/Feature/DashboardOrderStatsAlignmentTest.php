<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderDailyStat;
use App\Models\OrderStatsRun;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\OrderStatsAggregator;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 仪表盘和订单列表的金额口径必须对得上。历史上这里有三个各不相干的缺口：
 *   1. 仪表盘用 where('status','paid')，订单一发货就从成交额里消失；
 *   2. 同一个 service 里「支付成功率」另抄了一份 8 个状态的清单，和卡片对不上；
 *   3. scopedOrders() 用不带参的 withoutGlobalScopes()，连软删除一起摘了，
 *      卡片算已删订单而商户排行不算。
 */
class DashboardOrderStatsAlignmentTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    /** 支付成功过的 8 个状态 —— Order::NEVER_PAID_STATUSES 的补集。 */
    private const PAID_EVER = [
        'paid', 'shipped', 'completed', 'refunded',
        'partially_refunded', 'chargeback', 'disputing', 'dispute_review',
    ];

    private DashboardService $dashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        $this->dashboard = app(DashboardService::class);
    }

    public function test_paid_amount_counts_every_status_that_was_ever_paid(): void
    {
        $this->seedOneOrderPerStatus();

        $stats = $this->dashboard->getAdminStats(null);

        // 8 个"支付成功过"的状态 × 100 USD；pending/failed/cancelled/expired 不算
        $this->assertSame(8, $stats['paid_orders']);
        $this->assertSame(800.0, $stats['total_amount_usd']);
    }

    /** 最直接的回归：订单发货后不能从成交额里消失。 */
    public function test_shipping_an_order_does_not_shrink_the_total(): void
    {
        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid', 'converted_amount' => '100.00']);
        app(OrderStatsAggregator::class)->aggregateDate(now());

        $before = $this->dashboard->getAdminStats(null)['total_amount_usd'];

        $order->update(['status' => 'shipped']);
        app(OrderStatsAggregator::class)->aggregateDate(now());

        $this->assertSame($before, $this->dashboard->getAdminStats(null)['total_amount_usd']);
    }

    public function test_success_rate_and_paid_orders_agree_on_what_counts_as_paid(): void
    {
        $this->seedOneOrderPerStatus();

        $stats = $this->dashboard->getAdminStats(null);
        $rate = $this->dashboard->getPaymentSuccessRate(null);

        $this->assertSame($stats['paid_orders'], $rate['paid_count']);
    }

    public function test_trend_and_breakdown_use_the_same_paid_definition(): void
    {
        $this->seedOneOrderPerStatus();

        $stats = $this->dashboard->getAdminStats(null);

        // 今天的这批订单全在 7 日趋势的最后一格里
        $this->assertSame(800.0, (float) end($stats['trend_7d'])['amount_usd']);

        // 支付方式占比只统计支付成功过的订单
        $this->assertSame(
            [['payment_method' => 'alipay', 'order_count' => 8, 'percentage' => 100.0]],
            $stats['payment_method_breakdown'],
        );
    }

    public function test_soft_deleted_orders_are_excluded_everywhere(): void
    {
        $keep = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid', 'converted_amount' => '100.00']);
        $trash = $this->makeOrder('SSC-2', 'M-2', ['status' => 'paid', 'converted_amount' => '50.00']);

        $trash->delete();
        app(OrderStatsAggregator::class)->aggregateDate(now());

        $stats = $this->dashboard->getAdminStats(null);

        $this->assertSame(1, $stats['paid_orders']);
        $this->assertSame(100.0, $stats['total_amount_usd']);
        $this->assertSame(1, $stats['total_orders']);
        // 卡片和商户排行必须给出同一个数（原先排行 100、卡片 150）
        $this->assertSame(100.0, $stats['merchant_ranking'][0]['amount_usd']);

        $this->assertNotNull($keep->fresh());
    }

    /**
     * 订单列表的「本次查询统计」多出来的"其中已支付"，要和仪表盘的总成交额
     * 完全相等——这正是当初两个页面数字对不上的那一栏。
     */
    public function test_order_list_paid_subtotal_matches_the_dashboard(): void
    {
        $this->seedOneOrderPerStatus();

        $admin = User::create([
            'name' => 'Root',
            'email' => 'root@example.com',
            'password' => bcrypt('secret'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $rows = OrderResource::currencyStats(Livewire::test(ListOrders::class)->instance());

        // 命中总额包含从未成交的订单（12 张 × 100），已支付小计只算 8 张
        $this->assertSame(12, (int) $rows->sum('orders_count'));
        $this->assertSame(1200.0, (float) $rows->sum('total_converted_amount'));

        $this->assertSame(8, (int) $rows->sum('paid_orders_count'));
        $this->assertSame(
            $this->dashboard->getAdminStats(null)['total_amount_usd'],
            (float) $rows->sum('paid_converted_amount'),
        );
    }

    #[TestWith([[], '2026-11-03', '2026-11-03'])]
    #[TestWith([['period' => 'yesterday'], '2026-11-02', '2026-11-02'])]
    #[TestWith([['period' => 'last_7_days'], '2026-10-28', '2026-11-03'])]
    #[TestWith([['period' => 'last_30_days'], '2026-10-05', '2026-11-03'])]
    #[TestWith([['period' => 'this_month'], '2026-11-01', '2026-11-03'])]
    #[TestWith([['period' => 'last_month'], '2026-10-01', '2026-10-31'])]
    #[TestWith([['period' => 'custom', 'date_from' => '2026-10-30', 'date_to' => '2026-11-02'], '2026-10-30', '2026-11-02'])]
    public function test_date_ranges_follow_the_system_calendar(array $filters, string $expectedFrom, string $expectedTo): void
    {
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(Carbon::parse('2026-11-03 14:15:00', 'Asia/Shanghai'));

        [$from, $to] = $this->dashboard->dateRange($filters);

        $this->assertSame($expectedFrom.' 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame($expectedTo.' 23:59:59', $to->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Shanghai', $from->getTimezone()->getName());
    }

    #[TestWith([['period' => 'invalid']])]
    #[TestWith([['period' => ['last_30_days']]])]
    #[TestWith([['period' => 'custom']])]
    #[TestWith([['period' => 'custom', 'date_from' => '2026-11-02', 'date_to' => '2026-11-01']])]
    #[TestWith([['period' => 'custom', 'date_from' => '2026-02-30', 'date_to' => '2026-11-01']])]
    #[TestWith([['period' => 'custom', 'date_from' => ['2026-11-01'], 'date_to' => '2026-11-03']])]
    #[TestWith([['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-04']])]
    public function test_invalid_date_filters_fall_back_to_today(array $filters): void
    {
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(Carbon::parse('2026-11-03 14:15:00', 'Asia/Shanghai'));

        [$from, $to] = $this->dashboard->dateRange($filters);

        $this->assertSame('2026-11-03 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 23:59:59', $to->format('Y-m-d H:i:s'));
    }

    public function test_dashboard_queries_only_daily_statistics_and_preserves_tenant_scope(): void
    {
        $method = $this->makePaymentMethod('stripe');
        $other = Merchant::create(['name' => 'Other Merchant']);
        $otherApp = Application::createWithCredentials(['merchant_id' => $other->id, 'name' => 'Other App', 'website' => 'https://other.example.com']);
        $this->dailyStat(now()->toDateString(), [
            'payment_method_id' => $method->id, 'created_orders' => 12, 'paid_orders' => 8,
            'paid_amount' => '800.00', 'failed_orders' => 2, 'unpaid_terminal_orders' => 3,
            'refunded_orders' => 4, 'chargeback_orders' => 5,
        ]);
        $this->dailyStat(now()->toDateString(), [
            'merchant_id' => $other->id, 'application_id' => $otherApp->id,
            'created_orders' => 100, 'paid_orders' => 90, 'paid_amount' => '9000.00',
        ]);
        $this->dailyStat(now()->subDay()->toDateString(), ['created_orders' => 3, 'paid_orders' => 1, 'paid_amount' => '50.00']);
        $this->makeOrder('UNAGGREGATED', 'UNAGGREGATED', ['status' => 'paid', 'converted_amount' => '999.00']);
        $sourceQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$sourceQueries): void {
            if (preg_match('/\b(?:from|join)\s+["`]?orders["`]?\b|\b(?:from|join)\s+["`]?order_events["`]?\b/i', $query->sql)) {
                $sourceQueries[] = $query->sql;
            }
        });

        $summary = $this->dashboard->summary([$this->merchant->id]);
        $rate = $this->dashboard->getPaymentSuccessRate([$this->merchant->id]);
        $ranking = $this->dashboard->merchantSalesRanking([$this->merchant->id]);
        $breakdown = $this->dashboard->paymentMethodBreakdown([$this->merchant->id]);
        $trend = $this->dashboard->trend([$this->merchant->id], [
            'period' => 'custom', 'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString(),
        ]);
        $empty = $this->dashboard->summary([]);

        $this->assertSame(12, $summary['total_orders']);
        $this->assertSame(8, $summary['paid_orders']);
        $this->assertSame(2, $summary['failed_orders']);
        $this->assertSame(800.0, $summary['total_amount_usd']);
        $this->assertSame(1, $summary['total_merchants']);
        $this->assertSame(['success_rate' => 72.7, 'paid_count' => 8, 'terminal_count' => 11], $rate);
        $this->assertSame([['merchant_id' => $this->merchant->id, 'merchant_name' => $this->merchant->name, 'amount_usd' => 800.0]], $ranking);
        $this->assertSame([['payment_method' => 'stripe', 'order_count' => 8, 'percentage' => 100.0]], $breakdown);
        $this->assertSame([
            ['date' => now()->subDay()->toDateString(), 'order_count' => 3, 'amount_usd' => 50.0],
            ['date' => now()->toDateString(), 'order_count' => 12, 'amount_usd' => 800.0],
        ], $trend);
        $this->assertSame(0, $empty['total_orders']);
        $this->assertSame(0, $empty['total_merchants']);
        $this->assertSame([], $sourceQueries);
    }

    public function test_created_order_count_and_paid_amount_use_their_own_event_dates(): void
    {
        $order = $this->makeOrder('LATE-PAID', 'LATE-PAID', ['status' => 'refunded', 'paid_at' => now()]);
        $order->forceFill(['created_at' => now()->subDay()])->save();
        app(OrderStatsAggregator::class)->aggregateDate(now()->subDay());
        app(OrderStatsAggregator::class)->aggregateDate(now());

        $today = $this->dashboard->summary([$this->merchant->id]);
        $yesterday = $this->dashboard->summary([$this->merchant->id], ['period' => 'yesterday']);

        $this->assertSame(0, $today['total_orders']);
        $this->assertSame(1, $today['paid_orders']);
        $this->assertSame(100.0, $today['total_amount_usd']);
        $this->assertSame(1, $yesterday['total_orders']);
        $this->assertSame(0, $yesterday['paid_orders']);
        $this->assertSame(0.0, $yesterday['total_amount_usd']);
    }

    public function test_missing_statistics_and_old_metrics_versions_are_reported_without_reaggregation(): void
    {
        OrderStatsRun::create(['stat_date' => now()->toDateString(), 'completed_at' => now(), 'metrics_version' => 1]);

        $summary = $this->dashboard->summary([$this->merchant->id]);
        $trend = $this->dashboard->trend([$this->merchant->id], ['period' => 'last_7_days']);

        $this->assertFalse($summary['is_complete']);
        $this->assertSame([now()->toDateString()], $summary['missing_dates']);
        $this->assertCount(7, $trend);
        $this->assertSame(0, array_sum(array_column($trend, 'order_count')));
        $this->assertSame(1, OrderStatsRun::query()->sole()->metrics_version);
    }

    public function test_transaction_merchant_count_excludes_pending_only_and_out_of_window_merchants(): void
    {
        $this->dailyStat(now()->toDateString(), ['created_orders' => 1]);
        $this->dailyStat(now()->subDay()->toDateString(), ['created_orders' => 1, 'paid_orders' => 1, 'paid_amount' => '100.00']);

        $this->assertSame(0, $this->dashboard->summary(null)['total_merchants']);
        $this->assertSame(1, $this->dashboard->summary(null, ['period' => 'yesterday'])['total_merchants']);
    }

    /** @param array<string, int|string> $metrics */
    private function dailyStat(string $date, array $metrics): void
    {
        OrderDailyStat::query()->insert(array_merge(array_fill_keys(OrderDailyStat::METRIC_COLUMNS, 0), [
            'stat_date' => $date, 'merchant_id' => $this->merchant->id,
            'application_id' => $this->application->id, 'payment_method_id' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $metrics));
    }

    /** 12 种状态各一张订单，金额一律 100 USD，方便直接心算。 */
    private function seedOneOrderPerStatus(): void
    {
        $statuses = [...self::PAID_EVER, ...Order::NEVER_PAID_STATUSES];
        $method = $this->makePaymentMethod('alipay');

        foreach ($statuses as $i => $status) {
            $this->makeOrder("SSC-{$i}", "M-{$i}", [
                'status' => $status,
                'payment_method' => 'alipay',
                'payment_method_id' => $method->id,
                'amount' => '100.00',
                'converted_amount' => '100.00',
            ]);
        }

        app(OrderStatsAggregator::class)->aggregateDate(now());
    }
}
