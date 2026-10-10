<?php

namespace Tests\Feature\Filament;

use App\Filament\Observer\Resources\OrderResource;
use App\Filament\Observer\Resources\OrderResource\Pages\ListOrders;
use App\Models\Observer;
use App\Models\Order;
use App\Services\ObserverOrderExportService;
use App\Support\ObserverOrderStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ObserverOrdersTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private Observer $observer;

    private int $methodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('observer-channel');
        $this->methodId = $method->id;
        $this->observer = Observer::create([
            'name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => bcrypt('secret'),
            'status' => true, 'amount_display_ratio' => '50.00',
        ]);
        $this->observer->paymentMethods()->attach($method);
        $method->delete();
        $this->actingAs($this->observer, 'observer');
        Filament::setCurrentPanel('observer');
    }

    public function test_status_groups_and_hidden_orders(): void
    {
        $groups = [];
        foreach (ObserverOrderStatus::GROUPS as $group => $statuses) {
            foreach ($statuses as $status) {
                $groups[$group][] = $this->makeOrder('SSC-'.$status, 'M-'.$status, ['status' => $status, 'payment_method_id' => $this->methodId]);
            }
        }
        $hidden = [];
        foreach (['pending', 'failed', 'cancelled', 'expired'] as $status) {
            $hidden[] = $this->makeOrder('SSC-'.$status, 'M-'.$status, ['status' => $status, 'payment_method_id' => $this->methodId]);
        }
        $outside = $this->makeOrder('SSC-OUTSIDE', 'M-OUTSIDE', ['status' => 'paid']);
        $page = Livewire::test(ListOrders::class)->assertCanNotSeeTableRecords([...$hidden, $outside]);
        $this->assertSame(ObserverOrderStatus::options(), $page->instance()->getTable()->getFilter('status')->getOptions());
        foreach ($groups as $group => $orders) {
            Livewire::test(ListOrders::class)->filterTable('status', $group)
                ->assertCanSeeTableRecords($orders)
                ->assertCanNotSeeTableRecords(array_merge(...array_values(array_diff_key($groups, [$group => true]))))
                ->assertTableColumnFormattedStateSet('status', ObserverOrderStatus::label($orders[0]->status), $orders[0]);
        }
    }

    public function test_export_rechecks_scope_and_exports_search_results_beyond_one_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeOrder('MATCH-'.$i, 'M-'.$i, ['status' => 'paid', 'payment_method_id' => $this->methodId]);
        }
        $this->makeOrder('IGNORE-SEARCH', 'M-IGNORE', ['status' => 'paid', 'payment_method_id' => $this->methodId]);
        $this->makeOrder('MATCH-OUTSIDE', 'M-OUTSIDE', ['status' => 'paid']);
        $this->makeOrder('MATCH-PENDING', 'M-PENDING', ['status' => 'pending', 'payment_method_id' => $this->methodId]);

        $service = app(ObserverOrderExportService::class);
        $page = Livewire::test(ListOrders::class)->searchTable('MATCH-');
        ob_start();
        $service->download($this->observer, $page->instance()->getFilteredTableQuery())->sendContent();
        $csv = ob_get_clean();
        $this->assertSame(31, count(array_filter(explode("\n", trim($csv)))));
        $this->assertStringNotContainsString('IGNORE-SEARCH', $csv);
        $this->assertStringNotContainsString('MATCH-OUTSIDE', $csv);
        $this->assertStringNotContainsString('MATCH-PENDING', $csv);

        // 即使传入未经授权过滤的查询，导出也必须重新限定渠道及状态。
        ob_start();
        $service->download($this->observer, Order::withoutGlobalScopes())->sendContent();
        $csv = ob_get_clean();
        $this->assertStringNotContainsString('MATCH-OUTSIDE', $csv);
        $this->assertStringNotContainsString('MATCH-PENDING', $csv);
    }

    public function test_date_range_and_export_keep_authorization_and_scaled_amounts(): void
    {
        $start = $this->makeOrder('SSC-START', 'M-START', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'created_at' => '2026-10-01 00:00:00']);
        $end = $this->makeOrder('SSC-END', 'M-END', ['status' => 'shipped', 'payment_method_id' => $this->methodId, 'created_at' => '2026-10-02 23:59:59']);
        $before = $this->makeOrder('SSC-BEFORE', 'M-BEFORE', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'created_at' => '2026-09-30 23:59:59']);
        $after = $this->makeOrder('SSC-AFTER', 'M-AFTER', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'created_at' => '2026-10-03 00:00:00']);
        $outside = $this->makeOrder('SSC-OUTSIDE', 'M-OUTSIDE', ['status' => 'paid', 'created_at' => '2026-10-01']);
        foreach ([[$start, '2026-10-01 00:00:00'], [$end, '2026-10-02 23:59:59'], [$before, '2026-09-30 23:59:59'], [$after, '2026-10-03 00:00:00'], [$outside, '2026-10-01 12:00:00']] as [$order, $date]) {
            $order->forceFill(['created_at' => $date])->save();
        }
        $page = Livewire::test(ListOrders::class)
            ->filterTable('created_at', ['created_from' => '2026-10-01', 'created_to' => '2026-10-02'])
            ->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$start, $end])->assertCanNotSeeTableRecords([$before, $after, $outside]);
        $response = app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery());
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('SSC-START', $csv);
        $this->assertStringContainsString('SSC-END', $csv);
        foreach (['SSC-BEFORE', 'SSC-AFTER', 'SSC-OUTSIDE', '100.00'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $csv);
        }
        $this->assertStringContainsString('50.00', $csv);
        $page->callAction('exportOrders')->assertFileDownloaded('observer_orders_'.now()->format('Ymd_His').'.csv');
        $this->assertFalse(OrderResource::getEloquentQuery()->whereKey($outside->id)->exists());
    }
}
