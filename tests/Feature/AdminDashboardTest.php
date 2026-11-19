<?php

namespace Tests\Feature;

use App\Filament\Pages\AdminDashboard;
use App\Filament\Pages\OrderStats;
use App\Filament\Resources\ChargebackOrders\ChargebackOrderResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\RefundOrders\RefundOrderResource;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', config('app.timezone')));
    }

    public function test_dashboard_uses_quick_period_buttons(): void
    {
        $this->actingAs($this->administrator());

        $page = Livewire::test(AdminDashboard::class)
            ->assertOk()
            ->assertSet('filters.period', 'today')
            ->assertSee(__('admin.dashboard.periods.today'))
            ->assertSee(__('admin.dashboard.periods.yesterday'))
            ->assertSee(__('admin.dashboard.periods.last_7_days'))
            ->assertSee(__('admin.dashboard.periods.last_30_days'))
            ->assertSee(__('admin.dashboard.periods.this_month'))
            ->assertSee(__('admin.dashboard.periods.last_month'))
            ->assertSee(__('admin.dashboard.periods.custom'));

        $page->call('selectPeriod', 'last_7_days')
            ->assertHasNoErrors()
            ->assertSet('filters.period', 'last_7_days')
            ->assertSet('filters.date_from', null)
            ->assertSet('filters.date_to', null);
    }

    #[TestWith(['super_admin', true, true])]
    #[TestWith(['manager', false, true])]
    #[TestWith(['merchant', false, false])]
    public function test_dashboard_shows_the_scope_filters_for_each_role(string $role, bool $seesAgent, bool $seesMerchant): void
    {
        $merchant = $role === 'merchant' ? Merchant::create(['name' => 'Viewer merchant']) : null;
        $viewer = User::factory()->create(['merchant_id' => $merchant?->id, 'is_super_admin' => $role === 'super_admin', 'status' => true]);
        Filament::setCurrentPanel($merchant ? 'merchant' : 'admin');
        $this->actingAs($viewer);

        $page = Livewire::test(AdminDashboard::class)
            ->assertOk()
            ->assertFormFieldVisible('application_id', 'dimensionFiltersForm')
            ->assertFormFieldVisible('payment_method_id', 'dimensionFiltersForm');

        $seesAgent
            ? $page->assertFormFieldVisible('owner_id', 'dimensionFiltersForm')
            : $page->assertFormFieldHidden('owner_id', 'dimensionFiltersForm');
        $seesMerchant
            ? $page->assertFormFieldVisible('merchant_id', 'dimensionFiltersForm')
            : $page->assertFormFieldHidden('merchant_id', 'dimensionFiltersForm');
    }

    #[TestWith(['super_admin', 'unknown', true, true])]
    #[TestWith(['manager', 'owner', false, true])]
    #[TestWith(['merchant', 'merchant', false, false])]
    public function test_dashboard_restricts_report_tabs_by_role(string $role, string $forbiddenReport, bool $seesAgent, bool $seesMerchant): void
    {
        $merchant = $role === 'merchant' ? Merchant::create(['name' => 'Viewer merchant']) : null;
        $viewer = User::factory()->create(['merchant_id' => $merchant?->id, 'is_super_admin' => $role === 'super_admin', 'status' => true]);
        $viewer->givePermissionTo(Permissions::ORDERS_VIEW);
        Filament::setCurrentPanel($merchant ? 'merchant' : 'admin');
        $this->actingAs($viewer);

        $page = Livewire::test(AdminDashboard::class)
            ->assertSee(__('admin.dashboard.breakdowns.payment_method'))
            ->assertSee(__('admin.dashboard.breakdowns.application'));

        $seesAgent
            ? $page->assertSee(__('admin.dashboard.breakdowns.owner'))
            : $page->assertDontSee(__('admin.dashboard.breakdowns.owner'));
        $seesMerchant
            ? $page->assertSee(__('admin.dashboard.breakdowns.merchant'))
            : $page->assertDontSee(__('admin.dashboard.breakdowns.merchant'));

        $page->set('activeReport', $forbiddenReport)->assertForbidden();
    }

    public function test_switching_agent_limits_options_and_clears_previous_selections(): void
    {
        $firstAgent = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $secondAgent = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $firstMerchant = Merchant::create(['name' => 'First agent merchant', 'owner_id' => $firstAgent->id]);
        $secondMerchant = Merchant::create(['name' => 'Second agent merchant', 'owner_id' => $secondAgent->id]);
        $firstApp = Application::createWithCredentials(['name' => 'First agent application', 'merchant_id' => $firstMerchant->id, 'website' => 'https://first.example.com']);
        $this->actingAs($this->administrator());

        $page = Livewire::test(AdminDashboard::class)
            ->set('dimensionFilters.owner_id', $firstAgent->id)
            ->set('dimensionFilters.merchant_id', $firstMerchant->id)
            ->set('dimensionFilters.application_id', $firstApp->id)
            ->assertHasNoErrors()
            ->assertSet('filters.application_id', $firstApp->id)
            ->call('selectPeriod', 'yesterday')
            ->assertSet('filters.application_id', $firstApp->id);

        $page->set('dimensionFilters.owner_id', $secondAgent->id)
            ->assertHasNoErrors()
            ->assertSet('filters.period', 'yesterday')
            ->assertSet('filters.owner_id', $secondAgent->id)
            ->assertSet('dimensionFilters.merchant_id', null)
            ->assertSet('dimensionFilters.application_id', null);

        $merchantOptions = $page->instance()->dimensionFiltersForm->getFlatFields()['merchant_id']->getOptions();
        $this->assertSame([$secondMerchant->id => $secondMerchant->name], $merchantOptions);
    }

    public function test_a_merchant_cannot_apply_an_application_from_another_merchant(): void
    {
        $merchant = Merchant::create(['name' => 'Viewer merchant']);
        $otherMerchant = Merchant::create(['name' => 'Other merchant']);
        $otherApp = Application::createWithCredentials(['name' => 'Other application', 'merchant_id' => $otherMerchant->id, 'website' => 'https://other.example.com']);
        $this->actingAs(User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]));
        Filament::setCurrentPanel('merchant');

        $page = Livewire::test(AdminDashboard::class)->set('dimensionFilters.application_id', $otherApp->id);

        $page->assertHasErrors(['dimensionFilters.application_id']);
        $this->assertNull($page->get('filters')['application_id'] ?? null);
    }

    public function test_sidebar_uses_distinct_order_labels_and_keeps_statistics_on_the_dashboard(): void
    {
        app()->setLocale('zh_CN');
        $this->actingAs($this->administrator());

        $items = collect(Filament::getNavigation())
            ->flatMap(fn (NavigationGroup $group): array => collect($group->getItems())->all())
            ->keyBy(fn (NavigationItem $item): ?string => $item->getUrl());

        $this->assertTrue($items->has(AdminDashboard::getUrl()));
        $this->assertFalse($items->has(AdminDashboard::getUrl().'#data-reports'));
        $this->assertFalse($items->has(OrderStats::getUrl()));
        $this->assertSame('订单列表', $items->get(OrderResource::getUrl())?->getLabel());
        $this->assertSame('拒付订单', $items->get(ChargebackOrderResource::getUrl())?->getLabel());
        $this->assertSame('退款订单', $items->get(RefundOrderResource::getUrl())?->getLabel());
    }

    public function test_custom_period_is_only_applied_after_confirming_the_modal(): void
    {
        $this->actingAs($this->administrator());

        $page = Livewire::test(AdminDashboard::class)
            ->call('selectPeriod', 'yesterday')
            ->mountAction('customDateRange')
            ->assertSet('filters.period', 'yesterday')
            ->setActionData(['date_from' => '2026-10-01', 'date_to' => '2026-10-31'])
            ->unmountAction()
            ->assertSet('filters.period', 'yesterday');

        $page->callAction('customDateRange', data: ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'])
            ->assertHasNoActionErrors()
            ->assertSet('filters.period', 'custom')
            ->assertSet('filters.date_from', '2026-10-01')
            ->assertSet('filters.date_to', '2026-10-31');

        $page->call('selectPeriod', 'today')
            ->assertSet('filters.period', 'today')
            ->assertSet('filters.date_from', null)
            ->assertSet('filters.date_to', null);
    }

    #[TestWith(['2026-11-02', '2026-11-01'])]
    #[TestWith(['2026-11-01', '2026-11-04'])]
    #[TestWith(['2026-02-30', '2026-11-01'])]
    public function test_invalid_custom_dates_leave_the_selected_period_unchanged(string $from, string $to): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(AdminDashboard::class)
            ->call('selectPeriod', 'yesterday')
            ->callAction('customDateRange', data: ['date_from' => $from, 'date_to' => $to])
            ->assertHasActionErrors()
            ->assertSet('filters.period', 'yesterday');
    }

    public function test_unknown_quick_periods_cannot_bypass_the_custom_date_modal(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(AdminDashboard::class)
            ->call('selectPeriod', 'custom')
            ->assertHasErrors(['period'])
            ->assertSet('filters.period', 'today');
    }

    public function test_raw_filter_state_cannot_be_changed_by_the_browser(): void
    {
        $this->actingAs($this->administrator());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(AdminDashboard::class)->set('filters.period', 'last_30_days');
    }

    public function test_customer_service_accounts_cannot_open_the_dashboard(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'status' => true]);
        $user->givePermissionTo(Permissions::CUSTOMER_SERVICE_ORDERS_VIEW);
        $this->actingAs($user);

        Livewire::test(AdminDashboard::class)->assertForbidden();
    }

    public function test_disabled_accounts_cannot_open_the_dashboard(): void
    {
        $this->actingAs($this->administrator(false));

        Livewire::test(AdminDashboard::class)->assertForbidden();
    }

    private function administrator(bool $enabled = true): User
    {
        return User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => $enabled]);
    }
}
