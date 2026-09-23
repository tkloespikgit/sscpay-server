<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

        $before = $this->dashboard->getAdminStats(null)['total_amount_usd'];

        $order->update(['status' => 'shipped']);

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

    /** 12 种状态各一张订单，金额一律 100 USD，方便直接心算。 */
    private function seedOneOrderPerStatus(): void
    {
        $statuses = [...self::PAID_EVER, ...Order::NEVER_PAID_STATUSES];

        foreach ($statuses as $i => $status) {
            $this->makeOrder("SSC-{$i}", "M-{$i}", [
                'status' => $status,
                'payment_method' => 'alipay',
                'amount' => '100.00',
                'converted_amount' => '100.00',
            ]);
        }
    }
}
