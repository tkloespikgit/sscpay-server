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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\CreatesTestOrders;
use Tests\Concerns\ReadsSpreadsheets;
use Tests\TestCase;

class ObserverOrdersTest extends TestCase
{
    use CreatesTestOrders;
    use ReadsSpreadsheets;
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
        $rows = $this->spreadsheetRows($this->downloadContents(
            $service->download($this->observer, $page->instance()->getFilteredTableQuery())
        ));
        $this->assertCount(31, $rows);
        $orderNumbers = array_column(array_slice($rows, 1), 0);
        $this->assertNotContains('IGNORE-SEARCH', $orderNumbers);
        $this->assertNotContains('MATCH-OUTSIDE', $orderNumbers);
        $this->assertNotContains('MATCH-PENDING', $orderNumbers);

        // 即使传入未经授权过滤的查询，导出也必须重新限定渠道及状态。
        $rows = $this->spreadsheetRows($this->downloadContents(
            $service->download($this->observer, Order::withoutGlobalScopes())
        ));
        $orderNumbers = array_column(array_slice($rows, 1), 0);
        $this->assertCount(31, $orderNumbers);
        $this->assertContains('IGNORE-SEARCH', $orderNumbers);
        $this->assertNotContains('MATCH-OUTSIDE', $orderNumbers);
        $this->assertNotContains('MATCH-PENDING', $orderNumbers);
    }

    public function test_date_range_and_export_keep_authorization_and_scaled_amounts(): void
    {
        $this->freezeTime();
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
        $rows = array_slice($this->spreadsheetRows($this->downloadContents($response)), 1);
        $this->assertSame(['SSC-START', 'SSC-END'], array_column($rows, 0));
        foreach ($rows as $row) {
            $this->assertSame(50.0, (float) $row[5]);
            $this->assertSame(50.0, (float) $row[7]);
        }
        $page->callAction('exportOrders')->assertFileDownloaded('observer_orders_'.now()->format('Ymd_His').'.xlsx');
        $this->assertFalse(OrderResource::getEloquentQuery()->whereKey($outside->id)->exists());
    }

    public function test_export_preserves_numeric_identifiers_and_formula_like_text_with_numeric_amounts(): void
    {
        $orderNumber = '001234567890123456789012345678';
        $trackingNumber = '009876543210987654321098765432';
        $merchantName = '000123456789012345678';
        $this->merchant->update(['name' => $merchantName]);
        $this->observer->paymentMethods()->firstOrFail()->update(['method_name' => '=1+1']);
        $order = $this->makeOrder($orderNumber, 'M-NUMERIC', [
            'status' => 'shipped', 'payment_method_id' => $this->methodId,
            'amount' => '123.40', 'converted_amount' => '98.76',
        ]);
        $order->shipping()->create([
            'merchant_id' => $this->merchant->id, 'logistics_company' => 'ups',
            'tracking_number' => $trackingNumber, 'shipped_at' => now(), 'operator_id' => 0,
        ]);

        $response = app(ObserverOrderExportService::class)->download($this->observer, Order::query());
        $workbook = $this->readSpreadsheet($this->downloadContents($response));

        try {
            $sheet = $workbook->getActiveSheet();
            $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
            foreach (['A2' => $orderNumber, 'B2' => $merchantName, 'C2' => '=1+1', 'E2' => $trackingNumber] as $coordinate => $value) {
                $this->assertSame($value, $sheet->getCell($coordinate)->getValue());
            }
            foreach (['A2', 'B2', 'C2', 'D2', 'E2', 'G2', 'I2'] as $coordinate) {
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($coordinate)->getDataType());
                $this->assertSame('@', $sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode());
            }
            foreach (['F2' => '61.70', 'H2' => '49.38'] as $coordinate => $amount) {
                $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($coordinate)->getDataType());
                $this->assertSame($amount, $sheet->getCell($coordinate)->getFormattedValue());
            }
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function downloadContents(StreamedResponse $response): string
    {
        ob_start();

        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
