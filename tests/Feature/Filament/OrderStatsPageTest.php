<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrderStats;
use App\Filament\Widgets\OrderStatsOverview;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\OrderDailyStat;
use App\Models\OrderStatsRun;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\OrderStatsAggregator;
use App\Services\OrderStatsQueryService;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 订单统计看板的可见范围与周期切换。
 *
 * 数据范围要求：超管看全部、商户级管理员看名下商户、商户用户只看自己
 * 且不显示商户筛选器。
 */
class OrderStatsPageTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []], 200)]);

        $this->createMerchantAndApplication();
        $this->paymentMethod = $this->makePaymentMethod('stripe');

        Filament::setCurrentPanel('admin');
    }

    public function test_page_renders_with_the_four_metric_cards(): void
    {
        $this->seedPaidOrder('SSC-1');
        app(OrderStatsAggregator::class)->aggregateDate(now());

        Livewire::actingAs($this->superAdmin())
            ->test(OrderStats::class)
            ->assertSuccessful();

        Livewire::actingAs($this->superAdmin())
            ->test(OrderStatsOverview::class, ['filters' => ['period' => 'today']])
            ->assertSee(__('admin.order_stats.metrics.paid'))
            ->assertSee(__('admin.order_stats.metrics.failed'))
            ->assertSee(__('admin.order_stats.metrics.refunded'))
            ->assertSee(__('admin.order_stats.metrics.chargeback'))
            ->assertSee('$100.00');
    }

    public function test_period_switch_selects_the_right_days(): void
    {
        $this->seedPaidOrder('TODAY', now());
        $this->seedPaidOrder('YDAY', now()->subDay());

        app(OrderStatsAggregator::class)->aggregateRecentDays(2);

        $stats = app(OrderStatsQueryService::class);

        $this->assertSame(1, $stats->totals(null, 'today')['paid_orders']);
        $this->assertSame(1, $stats->totals(null, 'yesterday')['paid_orders']);
        // 两笔都在本月内
        $this->assertSame(2, $stats->totals(null, 'this_month')['paid_orders']);
        $this->assertSame(0, $stats->totals(null, 'last_month')['paid_orders']);
    }

    /** 非法 period 来自前端可随意赋值的 Livewire 属性，必须回退当天而不是报错。 */
    public function test_an_unknown_period_falls_back_to_today(): void
    {
        [$from, $to] = app(OrderStatsQueryService::class)->periodRange('../../etc/passwd');

        $this->assertSame(now()->toDateString(), $from->toDateString());
        $this->assertSame(now()->toDateString(), $to->toDateString());
    }

    public function test_a_merchant_user_only_sees_their_own_merchant_data(): void
    {
        $this->seedPaidOrder('MINE');

        $otherMerchant = Merchant::create([
            'name' => 'Other',
            'contact_person' => 'O',
            'contact_phone' => '1',
            'contact_email' => 'o@example.com',
        ]);
        $this->seedPaidOrderFor($otherMerchant, 'THEIRS');

        app(OrderStatsAggregator::class)->aggregateDate(now());

        // 超管看到两家
        $this->assertSame(2, app(OrderStatsQueryService::class)->totals(null, 'today')['paid_orders']);

        // 商户用户只看到自己那一家
        $merchantUser = $this->merchantUser();
        $this->actingAs($merchantUser);

        $this->assertSame(
            1,
            app(OrderStatsQueryService::class)
                ->totals($merchantUser->manageableMerchantIds(), 'today')['paid_orders'],
        );
    }

    public function test_the_merchant_filter_is_hidden_from_merchant_users(): void
    {
        $merchantUser = $this->merchantUser();

        $fields = Livewire::actingAs($merchantUser)
            ->test(OrderStats::class)
            ->instance()
            ->getFiltersForm()
            ->getComponents();

        $visible = collect($fields)
            ->filter(fn ($c) => $c->isVisible())
            ->map(fn ($c) => $c->getName())
            ->values()
            ->all();

        $this->assertNotContains('merchant_id', $visible);
        $this->assertContains('application_id', $visible);
        $this->assertContains('payment_method_id', $visible);
    }

    public function test_platform_staff_do_see_the_merchant_filter(): void
    {
        $visible = collect(
            Livewire::actingAs($this->superAdmin())
                ->test(OrderStats::class)
                ->instance()
                ->getFiltersForm()
                ->getComponents()
        )->filter(fn ($c) => $c->isVisible())->map(fn ($c) => $c->getName())->all();

        $this->assertContains('merchant_id', $visible);
    }

    public function test_page_is_hidden_without_the_orders_view_permission(): void
    {
        $user = User::create([
            'name' => 'No Perm',
            'email' => 'noperm@example.com',
            'password' => bcrypt('secret'),
            'merchant_id' => $this->merchant->id,
        ]);

        $this->actingAs($user);
        $this->assertFalse(OrderStats::canAccess());

        $user->givePermissionTo(Permissions::ORDERS_VIEW);
        $user->forgetCachedPermissions();

        $this->actingAs($user->fresh());
        $this->assertTrue(OrderStats::canAccess());
    }

    // ------------------------------------------------------------------
    // 手动刷新统计
    // ------------------------------------------------------------------

    /**
     * 手动刷新必须在所选周期对应的日期区间内逐日重算，而不是只刷今天。
     * 这里用「上一月」做断言：区间是 30 天，若实现退化成只刷今天，
     * order_stats_runs 就只会多出今天这一行。
     */
    public function test_manual_refresh_recomputes_the_selected_period_range(): void
    {
        $this->seedPaidOrder('LAST-MONTH', now()->subMonthNoOverflow()->startOfMonth());

        Livewire::actingAs($this->superAdmin())
            ->test(OrderStats::class, ['filters' => ['period' => 'last_month']])
            ->callAction('refreshOrderStats')
            ->assertHasNoActionErrors();

        $ranDates = OrderStatsRun::query()->orderBy('stat_date')->pluck('stat_date')->all();

        $this->assertNotEmpty($ranDates, '刷新后应有执行记录');
        // 上一月的第一天必须落在重算区间内
        $this->assertContains(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), $ranDates);
        // 恰好覆盖上一个月（30 或 31 天），不含今天
        $this->assertSame(
            now()->subMonthNoOverflow()->daysInMonth,
            count($ranDates),
        );
        $this->assertNotContains(now()->toDateString(), $ranDates);
    }

    /** 周期为「当天」时只重算今天，避免无谓地扫 30 天。 */
    public function test_manual_refresh_today_only_recomputes_a_single_day(): void
    {
        $this->seedPaidOrder('TODAY');

        Livewire::actingAs($this->superAdmin())
            ->test(OrderStats::class, ['filters' => ['period' => 'today']])
            ->callAction('refreshOrderStats')
            ->assertHasNoActionErrors();

        $this->assertSame(
            [now()->toDateString()],
            OrderStatsRun::query()->pluck('stat_date')->all(),
        );
    }

    /** 刷新后统计表要有真实数据：这是"手动刷新真的能拿到最新数据"的落点。 */
    public function test_manual_refresh_writes_fresh_figures_into_the_stats_table(): void
    {
        $this->seedPaidOrder('FRESH');

        $this->assertSame(0, app(OrderStatsQueryService::class)->totals(null, 'today')['paid_orders']);

        Livewire::actingAs($this->superAdmin())
            ->test(OrderStats::class, ['filters' => ['period' => 'today']])
            ->callAction('refreshOrderStats')
            ->assertHasNoActionErrors();

        $this->assertSame(1, app(OrderStatsQueryService::class)->totals(null, 'today')['paid_orders']);
        $this->assertSame('100.00', number_format(
            app(OrderStatsQueryService::class)->totals(null, 'today')['paid_amount'],
            2,
        ));
    }

    /**
     * 重算必须是幂等的（整日删后重插），反复点刷新不能让统计行数膨胀——
     * 这是"覆盖"而不是"追加"的回归护栏。
     */
    public function test_repeated_manual_refresh_does_not_duplicate_rows(): void
    {
        $this->seedPaidOrder('ONCE');

        foreach ([1, 2, 3] as $ignored) {
            Livewire::actingAs($this->superAdmin())
                ->test(OrderStats::class, ['filters' => ['period' => 'today']])
                ->callAction('refreshOrderStats')
                ->assertHasNoActionErrors();
        }

        $this->assertSame(1, OrderDailyStat::query()->count());
        $this->assertSame(1, OrderStatsRun::query()->count());
    }

    /**
     * 并发护栏：聚合是「整日删后重插」，两个请求同时跑会互相搅乱结果。
     * 锁被占用时必须直接拒绝并提示，而不是照常执行。
     */
    public function test_manual_refresh_is_rejected_while_another_refresh_holds_the_lock(): void
    {
        // 先落一条统计行：锁被占用期间这行必须原封不动，
        // 否则"被挡住"就只是一句提示，统计表其实已经被重算了。
        $this->seedPaidOrder('LOCKED');
        app(OrderStatsAggregator::class)->aggregateDate(now());

        $rowsBefore = OrderDailyStat::query()->count();
        $runsBefore = OrderStatsRun::query()->count();
        $this->assertGreaterThan(0, $runsBefore, '测试前置条件：先跑一次聚合');

        $held = Cache::lock(OrderStats::REFRESH_LOCK_KEY, 120);
        $this->assertTrue($held->get(), '测试前置条件：先占住锁');

        try {
            Livewire::actingAs($this->superAdmin())
                ->test(OrderStats::class, ['filters' => ['period' => 'today']])
                ->callAction('refreshOrderStats')
                ->assertNotified(__('admin.order_stats.actions.refresh_busy'));

            // 表没有被二次重算——证明动作确实被挡住了
            $this->assertSame($rowsBefore, OrderDailyStat::query()->count());
            $this->assertSame($runsBefore, OrderStatsRun::query()->count());
        } finally {
            $held->release();
        }
    }

    /** 锁释放后必须恢复正常可用，不能因为一次并发就把按钮永久锁死。 */
    public function test_manual_refresh_works_again_after_the_lock_is_released(): void
    {
        $held = Cache::lock(OrderStats::REFRESH_LOCK_KEY, 120);
        $held->get();
        $held->release();

        Livewire::actingAs($this->superAdmin())
            ->test(OrderStats::class, ['filters' => ['period' => 'today']])
            ->callAction('refreshOrderStats')
            ->assertHasNoActionErrors();

        $this->assertSame(1, OrderStatsRun::query()->count());
    }

    /** 有 orders.view 权限的普通商户用户也能刷新（权限口径与页面一致）。 */
    public function test_a_merchant_user_with_permission_can_refresh(): void
    {
        $this->seedPaidOrder('M-REF');

        Livewire::actingAs($this->merchantUser())
            ->test(OrderStats::class, ['filters' => ['period' => 'today']])
            ->callAction('refreshOrderStats')
            ->assertHasNoActionErrors();

        $this->assertSame(1, OrderStatsRun::query()->count());
    }

    /**
     * 聚合器层面的区间边界：起止颠倒时必须直接返回 0 且不碰表。
     * 静默对调两个日期会算出一批毫无意义的统计，比什么都不做更糟。
     */
    public function test_aggregate_date_range_with_inverted_bounds_writes_nothing(): void
    {
        $this->seedPaidOrder('INV');

        $written = app(OrderStatsAggregator::class)->aggregateDateRange(now(), now()->subDays(5));

        $this->assertSame(0, $written);
        $this->assertSame(0, OrderStatsRun::query()->count());
    }

    // ------------------------------------------------------------------

    private function seedPaidOrder(string $suffix, ?\DateTimeInterface $paidAt = null): void
    {
        $this->makeOrder("SSC-{$suffix}", "M-{$suffix}", [
            'status' => 'paid',
            'paid_at' => $paidAt ?? now(),
            'payment_method' => $this->paymentMethod->method_code,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '100.00',
            'converted_amount' => '100.00',
        ]);
    }

    private function seedPaidOrderFor(Merchant $merchant, string $suffix): void
    {
        $application = Application::createWithCredentials([
            'merchant_id' => $merchant->id,
            'name' => 'Other App',
            'website' => 'https://other.example.com',
        ]);

        $original = $this->merchant;
        $originalApp = $this->application;

        $this->merchant = $merchant;
        $this->application = $application;
        $this->seedPaidOrder($suffix);
        $this->merchant = $original;
        $this->application = $originalApp;
    }

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@example.com'],
            ['name' => 'Root', 'password' => bcrypt('secret'), 'is_super_admin' => true],
        );
    }

    private function merchantUser(): User
    {
        $user = User::firstOrCreate(
            ['email' => 'merchant@example.com'],
            ['name' => 'Merchant User', 'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id],
        );

        $user->givePermissionTo(Permissions::ORDERS_VIEW);

        return $user->fresh();
    }
}
