<?php

namespace Tests\Feature;

use App\Filament\Widgets\MerchantBalanceOverview;
use App\Filament\Widgets\MerchantSalesRankingChart;
use App\Filament\Widgets\OrderTrendChart;
use App\Filament\Widgets\PaymentMethodBreakdownChart;
use App\Filament\Widgets\PaymentSuccessRate;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TransactionAmountTrendChart;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Models\OrderDailyStat;
use App\Models\User;
use App\Services\OrderStatsAggregator;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(12, 0));
    }

    public function test_summary_receives_the_selected_period_and_manager_scope_and_displays_missing_data(): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->manager();
        $this->dailyStat('2026-11-02', [
            'created_orders' => 42, 'paid_orders' => 10, 'paid_amount' => '1234.56',
            'failed_orders' => 4, 'unpaid_terminal_orders' => 4,
        ]);
        $this->dailyStat('2026-11-03', ['created_orders' => 99, 'paid_orders' => 99, 'paid_amount' => '9000.00']);
        $this->otherMerchantStat('2026-11-02', ['created_orders' => 50, 'paid_orders' => 50, 'paid_amount' => '5000.00']);
        $this->actingAs($manager);

        Livewire::withoutLazyLoading()->test(StatsOverview::class, ['pageFilters' => ['period' => 'yesterday']])
            ->assertOk()
            ->assertSee(__('admin.dashboard.stats.failed_orders'))
            ->assertSee(__('admin.dashboard.stats.incomplete'))
            ->assertSee('$1,234.56')
            ->assertDontSee('$5,000.00')->assertDontSee('$9,000.00')
            ->assertDontSee(__('admin.dashboard.stats.total_merchants'));
    }

    public function test_success_rate_uses_the_same_page_filter_and_merchant_scope(): void
    {
        $this->createMerchantAndApplication();
        $this->dailyStat('2026-11-02', ['created_orders' => 4, 'paid_orders' => 2, 'paid_amount' => '200.00', 'unpaid_terminal_orders' => 2]);
        $this->dailyStat('2026-10-20', ['created_orders' => 100, 'paid_orders' => 100, 'paid_amount' => '1000.00']);
        $this->otherMerchantStat('2026-11-02', ['created_orders' => 20, 'paid_orders' => 20, 'paid_amount' => '2000.00']);
        $this->actingAs($this->manager());

        Livewire::withoutLazyLoading()->test(PaymentSuccessRate::class, ['pageFilters' => ['period' => 'last_7_days']])
            ->assertOk()->assertSee('50%')->assertSee(__('admin.dashboard.success_rate.terminal_orders'));
    }

    public function test_transaction_widgets_display_the_same_aggregated_day(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('dashboard_period');
        Http::preventStrayRequests();
        $this->makeOrder('DASH-TODAY-PAID', 'DT-P', [
            'status' => 'shipped', 'paid_at' => now(), 'created_at' => now(),
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        $this->makeOrder('DASH-TODAY-FAILED', 'DT-F', [
            'status' => 'failed', 'failed_at' => now(), 'created_at' => now(),
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        $yesterdayOrder = $this->makeOrder('DASH-YESTERDAY', 'DY-P', [
            'status' => 'paid', 'paid_at' => now()->subDay(), 'created_at' => now()->subDay(),
            'converted_amount' => '200.00', 'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        $yesterdayOrder->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        app(OrderStatsAggregator::class)->aggregateRecentDays(2);
        $this->actingAs($this->merchantUser());

        Livewire::withoutLazyLoading()->test(StatsOverview::class, ['pageFilters' => ['period' => 'today']])
            ->assertOk()->assertSee('$100.00')->assertDontSee('$200.00');
        Livewire::withoutLazyLoading()->test(PaymentSuccessRate::class, ['pageFilters' => ['period' => 'today']])
            ->assertOk()->assertSee('50%');
        $todayOrders = Livewire::withoutLazyLoading()->test(OrderTrendChart::class, ['pageFilters' => ['period' => 'today']]);
        $todayAmount = Livewire::withoutLazyLoading()->test(TransactionAmountTrendChart::class, ['pageFilters' => ['period' => 'today']]);
        $yesterdayAmount = Livewire::withoutLazyLoading()->test(TransactionAmountTrendChart::class, ['pageFilters' => ['period' => 'yesterday']]);

        $this->assertSame([2], $this->chartData($todayOrders->instance())['datasets'][0]['data']);
        $this->assertSame([100.0], $this->chartData($todayAmount->instance())['datasets'][0]['data']);
        $this->assertSame([200.0], $this->chartData($yesterdayAmount->instance())['datasets'][0]['data']);
        Http::assertNothingSent();
    }

    #[TestWith([OrderTrendChart::class, [3, 7]])]
    #[TestWith([TransactionAmountTrendChart::class, [10.5, 20.0]])]
    public function test_order_and_amount_trends_have_separate_datasets_and_share_the_custom_period(string $widgetClass, array $expected): void
    {
        $this->createMerchantAndApplication();
        $this->dailyStat('2026-11-01', ['created_orders' => 3, 'paid_orders' => 1, 'paid_amount' => '10.50']);
        $this->dailyStat('2026-11-02', ['created_orders' => 7, 'paid_orders' => 2, 'paid_amount' => '20.00']);
        $this->dailyStat('2026-11-03', ['created_orders' => 100, 'paid_orders' => 10, 'paid_amount' => '1000.00']);
        $this->otherMerchantStat('2026-11-01', ['created_orders' => 70, 'paid_orders' => 70, 'paid_amount' => '700.00']);
        $this->actingAs($this->manager());
        $filters = ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-02'];

        $component = Livewire::withoutLazyLoading()->test($widgetClass, ['pageFilters' => $filters])->assertOk();
        $data = $this->chartData($component->instance());

        $this->assertCount(1, $data['datasets']);
        $this->assertSame($expected, $data['datasets'][0]['data']);
        $this->assertSame(['2026-11-01', '2026-11-02'], $data['labels']);
    }

    public function test_payment_method_breakdown_receives_the_selected_period(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('stripe');
        $this->dailyStat('2026-10-20', ['created_orders' => 4, 'paid_orders' => 4, 'paid_amount' => '400.00', 'payment_method_id' => $method->id]);
        $this->dailyStat('2026-11-02', ['created_orders' => 99, 'paid_orders' => 99, 'paid_amount' => '9900.00', 'payment_method_id' => $method->id]);
        $this->otherMerchantStat('2026-10-20', ['created_orders' => 50, 'paid_orders' => 50, 'paid_amount' => '5000.00']);
        $this->actingAs($this->manager());

        $component = Livewire::withoutLazyLoading()->test(PaymentMethodBreakdownChart::class, ['pageFilters' => ['period' => 'last_month']])->assertOk();
        $data = $this->chartData($component->instance());

        $this->assertSame(['stripe'], $data['labels']);
        $this->assertSame([4], $data['datasets'][0]['data']);
    }

    public function test_super_administrator_ranking_receives_the_selected_period(): void
    {
        $this->createMerchantAndApplication();
        $this->merchant->update(['name' => 'Ranked Merchant']);
        $this->dailyStat('2026-11-02', ['created_orders' => 8, 'paid_orders' => 8, 'paid_amount' => '800.00']);
        $this->dailyStat('2026-10-31', ['created_orders' => 99, 'paid_orders' => 99, 'paid_amount' => '9900.00']);
        $this->otherMerchantStat('2026-11-02', ['created_orders' => 4, 'paid_orders' => 4, 'paid_amount' => '400.00']);
        $this->actingAs(User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]));

        $component = Livewire::withoutLazyLoading()->test(MerchantSalesRankingChart::class, ['pageFilters' => ['period' => 'this_month']])->assertOk();
        $data = $this->chartData($component->instance());

        $this->assertSame(['Ranked Merchant', 'Other Merchant'], $data['labels']);
        $this->assertSame([800.0, 400.0], $data['datasets'][0]['data']);
    }

    #[TestWith([StatsOverview::class])]
    #[TestWith([PaymentSuccessRate::class])]
    #[TestWith([OrderTrendChart::class])]
    #[TestWith([TransactionAmountTrendChart::class])]
    #[TestWith([PaymentMethodBreakdownChart::class])]
    #[TestWith([MerchantSalesRankingChart::class])]
    #[TestWith([MerchantBalanceOverview::class])]
    public function test_guests_cannot_mount_dashboard_widgets_directly(string $widgetClass): void
    {
        Livewire::withoutLazyLoading()->test($widgetClass)->assertForbidden();
    }

    public function test_manager_cannot_mount_the_cross_merchant_ranking_directly(): void
    {
        $this->createMerchantAndApplication();
        $this->actingAs($this->manager());

        Livewire::withoutLazyLoading()->test(MerchantSalesRankingChart::class)->assertForbidden();
    }

    public function test_merchant_balances_use_current_account_values_and_classify_legacy_withdrawal_holds(): void
    {
        $this->createMerchantAndApplication();
        $this->merchant->update([
            'balance' => '1000.00', 'frozen_balance' => '200.00',
            'pending_settlement_balance' => '100.00', 'pending_withdrawal_balance' => '50.00',
        ]);
        $other = Merchant::create(['name' => 'Other Rich Merchant', 'balance' => '9999.00']);
        $user = $this->merchantUser();
        MerchantWithdrawal::create([
            'merchant_id' => $this->merchant->id, 'amount' => '120.00', 'status' => 'pending',
            'requested_by' => $user->id, 'uses_pending_balance' => false,
        ]);
        $this->actingAs($user);
        Http::preventStrayRequests();

        $component = Livewire::withoutLazyLoading()->test(MerchantBalanceOverview::class, [
            'pageFilters' => ['period' => 'yesterday', 'merchant_id' => $other->id],
        ])->assertOk()
            ->assertSee('$1,000.00')->assertSee('$650.00')->assertSee('$100.00')
            ->assertSee('$170.00')->assertSee('$80.00')->assertDontSee('$9,999.00');

        $this->merchant->update(['balance' => '1100.00']);
        $component->call('$refresh')->assertOk()->assertSee('$1,100.00')->assertSee('$750.00');

        Http::assertNothingSent();
    }

    #[TestWith([false, true, true, true])]
    #[TestWith([true, false, true, true])]
    #[TestWith([true, true, false, true])]
    #[TestWith([true, true, true, false])]
    public function test_balance_widget_rejects_platform_accounts_disabled_accounts_and_missing_finance_permission(bool $isMerchantUser, bool $isEnabled, bool $isMerchantEnabled, bool $hasFinancePermission): void
    {
        $this->createMerchantAndApplication();
        $this->merchant->update(['status' => $isMerchantEnabled]);
        $user = User::factory()->create([
            'merchant_id' => $isMerchantUser ? $this->merchant->id : null,
            'status' => $isEnabled, 'is_super_admin' => ! $isMerchantUser,
        ]);

        if ($hasFinancePermission) {
            $user->givePermissionTo(Permissions::FINANCE_VIEW);
        }

        $this->actingAs($user);

        $this->assertFalse(MerchantBalanceOverview::canView());
        Livewire::withoutLazyLoading()->test(MerchantBalanceOverview::class)->assertForbidden();
    }

    public function test_open_balance_widget_rechecks_finance_permission_on_every_request(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->merchantUser();
        $this->actingAs($user);
        $component = Livewire::withoutLazyLoading()->test(MerchantBalanceOverview::class)->assertOk();
        $user->revokePermissionTo(Permissions::FINANCE_VIEW);
        $this->actingAs($user->fresh());

        $component->call('$refresh')->assertForbidden();
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $this->merchant->update(['owner_id' => $manager->id]);

        return $manager;
    }

    private function merchantUser(): User
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $user->givePermissionTo(Permissions::FINANCE_VIEW);

        return $user;
    }

    private function chartData(ChartWidget $widget): array
    {
        return (new ReflectionMethod($widget, 'getCachedData'))->invoke($widget);
    }

    /**
     * 按聚合器的 DATE 格式写入，避免 SQLite 将 date cast 保存为带时间的字符串。
     *
     * @param  array<string, int|string>  $values
     */
    private function dailyStat(string $date, array $values): OrderDailyStat
    {
        $stat = new OrderDailyStat;
        $stat->setDateFormat('Y-m-d');
        $stat->fill([
            'stat_date' => $date,
            'merchant_id' => $this->merchant->id,
            'application_id' => $this->application->id,
            'payment_method_id' => 0,
            ...$values,
        ]);
        $stat->save();

        return $stat;
    }

    /** @param array<string, int|string> $values */
    private function otherMerchantStat(string $date, array $values): OrderDailyStat
    {
        $merchant = Merchant::create(['name' => 'Other Merchant']);
        $application = Application::createWithCredentials([
            'merchant_id' => $merchant->id, 'name' => 'Other Application', 'website' => 'https://other.example.com',
        ]);

        return $this->dailyStat($date, [
            'merchant_id' => $merchant->id, 'application_id' => $application->id, ...$values,
        ]);
    }
}
