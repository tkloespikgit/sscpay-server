<?php

namespace Tests\Feature;

use App\Filament\Pages\AdminDashboard;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
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

    public function test_dashboard_uses_quick_period_buttons_and_removes_the_payment_method_filter(): void
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
            ->assertSee(__('admin.dashboard.periods.custom'))
            ->assertDontSeeHtml('<select');

        $page->call('selectPeriod', 'last_7_days')
            ->assertHasNoErrors()
            ->assertSet('filters', ['period' => 'last_7_days', 'date_from' => null, 'date_to' => null]);
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
            ->assertSet('filters', ['period' => 'custom', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']);

        $page->call('selectPeriod', 'today')
            ->assertSet('filters', ['period' => 'today', 'date_from' => null, 'date_to' => null]);
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
