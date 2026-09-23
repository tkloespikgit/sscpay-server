<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrderStats;
use App\Filament\Widgets\OrderStatsOverview;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\OrderStatsAggregator;
use App\Services\OrderStatsQueryService;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
