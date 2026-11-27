<?php

namespace Tests\Feature\Filament;

use App\Filament\Observer\Resources\OrderResource;
use App\Filament\Observer\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Observer\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Merchant;
use App\Models\Observer;
use App\Models\Order;
use App\Models\OrderShipping;
use App\Models\User;
use App\Services\ObserverOrderExportService;
use App\Support\ObserverOrderStatus;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\Attributes\TestWith;
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

    public function test_list_details_and_export_show_customer_and_order_merchant_agent_for_shared_channels(): void
    {
        $agent = User::factory()->create(['name' => 'Order Merchant Agent', 'merchant_id' => null, 'is_super_admin' => false]);
        $channelOwner = User::factory()->create(['name' => 'Channel Owner', 'merchant_id' => null, 'is_super_admin' => false]);
        $this->merchant->update(['name' => 'Order Merchant', 'owner_id' => $agent->id]);
        $this->observer->paymentMethods()->firstOrFail()->update(['merchant_id' => null, 'owner_id' => $channelOwner->id]);
        $order = $this->makeOrder('SSC-SHARED', 'M-SHARED', [
            'status' => 'paid', 'payment_method_id' => $this->methodId,
            'customer_email' => 'shared-customer@example.com',
        ]);

        $page = Livewire::test(ListOrders::class)
            ->assertSuccessful()
            ->assertCanRenderTableColumn('merchant.name')
            ->assertCanRenderTableColumn('merchant.owner.name')
            ->assertTableColumnStateSet('merchant.name', 'Order Merchant', $order)
            ->assertTableColumnStateSet('merchant.owner.name', 'Order Merchant Agent', $order);
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Order Merchant')
            ->assertSee('Order Merchant Agent')
            ->assertSee('shared-customer@example.com')
            ->assertDontSee('Channel Owner');
        $rows = $this->spreadsheetRows($this->downloadContents(
            app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery())
        ));

        $this->assertSame([__('observer.orders.columns.agent_name'), __('observer.orders.columns.merchant_name')], array_slice($rows[0], 0, 2));
        $this->assertSame(['Order Merchant Agent', 'Order Merchant'], array_slice($rows[1], 0, 2));
        $this->assertSame(__('admin.order.columns.customer_email'), $rows[0][11]);
        $this->assertSame('shared-customer@example.com', $rows[1][11]);
    }

    public function test_platform_managed_merchant_is_displayed_and_exported_without_an_agent(): void
    {
        $order = $this->makeOrder('SSC-PLATFORM', 'M-PLATFORM', ['status' => 'paid', 'payment_method_id' => $this->methodId]);

        $page = Livewire::test(ListOrders::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$order])
            ->assertSee(__('admin.merchant.placeholders.owner_platform'));
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertSee(__('admin.merchant.placeholders.owner_platform'));
        $rows = $this->spreadsheetRows($this->downloadContents(
            app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery())
        ));

        $this->assertSame(__('admin.merchant.placeholders.owner_platform'), $rows[1][0]);
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
        $orderNumbers = array_column(array_slice($rows, 1), 2);
        $this->assertNotContains('IGNORE-SEARCH', $orderNumbers);
        $this->assertNotContains('MATCH-OUTSIDE', $orderNumbers);
        $this->assertNotContains('MATCH-PENDING', $orderNumbers);

        // 即使传入未经授权过滤的查询，导出也必须重新限定渠道及状态。
        $rows = $this->spreadsheetRows($this->downloadContents(
            $service->download($this->observer, Order::withoutGlobalScopes())
        ));
        $orderNumbers = array_column(array_slice($rows, 1), 2);
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
            ->filterTable('created_from', ['created_from' => '2026-10-01'])
            ->filterTable('created_to', ['created_to' => '2026-10-02'])
            ->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$start, $end])->assertCanNotSeeTableRecords([$before, $after, $outside]);
        $response = app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery());
        $rows = array_slice($this->spreadsheetRows($this->downloadContents($response)), 1);
        $this->assertSame(['SSC-START', 'SSC-END'], array_column($rows, 2));
        foreach ($rows as $row) {
            $this->assertSame(50.0, (float) $row[7]);
            $this->assertSame(50.0, (float) $row[8]);
        }
        $page->callAction('exportOrders')->assertFileDownloaded('observer_orders_'.now()->format('Ymd_His').'.xlsx');
        $this->assertFalse(OrderResource::getEloquentQuery()->whereKey($outside->id)->exists());
    }

    public function test_export_preserves_numeric_identifiers_and_formula_like_text_with_numeric_amounts(): void
    {
        $orderNumber = '001234567890123456789012345678';
        $trackingNumber = '009876543210987654321098765432';
        $merchantName = '000123456789012345678';
        $agent = User::factory()->create(['name' => '=2+2', 'merchant_id' => null, 'is_super_admin' => false]);
        $this->merchant->update(['name' => $merchantName, 'owner_id' => $agent->id]);
        $this->observer->paymentMethods()->firstOrFail()->update(['method_name' => '=1+1']);
        $order = $this->makeOrder($orderNumber, 'M-NUMERIC', [
            'status' => 'shipped', 'payment_method_id' => $this->methodId,
            'amount' => '123.40', 'converted_amount' => '98.76',
            'settlement_amount' => '87.64', 'transaction_id' => '000123456789012345678901234567',
            'customer_email' => '=customer@example.com',
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
            foreach (['A2' => '=2+2', 'B2' => $merchantName, 'C2' => $orderNumber, 'D2' => 'M-NUMERIC', 'E2' => '000123456789012345678901234567', 'F2' => '=1+1', 'L2' => '=customer@example.com', 'N2' => $trackingNumber] as $coordinate => $value) {
                $this->assertSame($value, $sheet->getCell($coordinate)->getValue());
            }
            foreach (['A2', 'B2', 'C2', 'D2', 'E2', 'F2', 'G2', 'K2', 'L2', 'M2', 'N2'] as $coordinate) {
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($coordinate)->getDataType());
                $this->assertSame('@', $sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode());
            }
            foreach (['H2' => '61.70', 'I2' => '49.38', 'J2' => '43.82'] as $coordinate => $amount) {
                $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($coordinate)->getDataType());
                $this->assertSame($amount, $sheet->getCell($coordinate)->getFormattedValue());
            }
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function test_columns_and_four_column_filters_follow_requested_order_without_a_view_button(): void
    {
        $order = $this->makeOrder('SSC-LAYOUT', 'M-LAYOUT', ['status' => 'paid', 'payment_method_id' => $this->methodId]);

        $page = Livewire::test(ListOrders::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$order])
            ->assertTableActionDoesNotExist('view', record: $order);
        $table = $page->instance()->getTable();

        $this->assertSame([
            'merchant.owner.name', 'merchant.name', 'order_no', 'merchant_order_no', 'transaction_id',
            'paymentMethod.method_name', 'status', 'shipping.tracking_number', 'amount', 'converted_amount', 'settlement_amount', 'created_at',
        ], array_keys($table->getColumns()));
        $this->assertSame([
            'agent_id', 'merchant_id', 'created_from', 'created_to', 'payment_method_id', 'order_no',
            'merchant_order_no', 'transaction_id', 'amount', 'customer_email', 'status', 'shipping_status',
        ], array_keys($table->getFilters()));
        $this->assertSame(4, $table->getFiltersFormColumns());
    }

    public function test_agent_and_merchant_filters_only_offer_authorized_order_owners_and_keep_export_scoped(): void
    {
        $agent = User::factory()->create(['name' => 'Visible Agent', 'merchant_id' => null, 'is_super_admin' => false]);
        $outsideAgent = User::factory()->create(['name' => 'Hidden Agent', 'merchant_id' => null, 'is_super_admin' => false]);
        $this->merchant->update(['owner_id' => $agent->id]);
        $platformMerchant = Merchant::create(['name' => 'Platform Merchant']);
        $outsideMerchant = Merchant::create(['name' => 'Hidden Merchant', 'owner_id' => $outsideAgent->id]);
        $visible = $this->makeOrder('SSC-VISIBLE', 'M-VISIBLE', ['status' => 'paid', 'payment_method_id' => $this->methodId]);
        $platform = $this->makeOrder('SSC-PLATFORM-FILTER', 'M-PLATFORM-FILTER', [
            'status' => 'paid', 'payment_method_id' => $this->methodId, 'merchant_id' => $platformMerchant->id,
        ]);
        $outside = $this->makeOrder('SSC-HIDDEN', 'M-HIDDEN', ['status' => 'paid', 'merchant_id' => $outsideMerchant->id]);

        $page = Livewire::test(ListOrders::class)->assertSuccessful();
        $agentOptions = $page->instance()->getTable()->getFilter('agent_id')->getOptions();
        $merchantOptions = $page->instance()->getTable()->getFilter('merchant_id')->getOptions();

        $this->assertSame('Visible Agent', $agentOptions[$agent->id]);
        $this->assertSame(__('admin.merchant.placeholders.owner_platform'), $agentOptions['platform']);
        $this->assertArrayNotHasKey($outsideAgent->id, $agentOptions);
        $this->assertSame('Test Merchant', $merchantOptions[$this->merchant->id]);
        $this->assertSame('Platform Merchant', $merchantOptions[$platformMerchant->id]);
        $this->assertArrayNotHasKey($outsideMerchant->id, $merchantOptions);
        $page->filterTable('agent_id', $agent->id)
            ->filterTable('merchant_id', $this->merchant->id)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$platform, $outside]);
        $rows = $this->spreadsheetRows($this->downloadContents(
            app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery())
        ));
        $this->assertSame(['SSC-VISIBLE'], array_column(array_slice($rows, 1), 2));
        Livewire::test(ListOrders::class)->filterTable('agent_id', 'platform')
            ->assertCanSeeTableRecords([$platform])
            ->assertCanNotSeeTableRecords([$visible, $outside]);
        Livewire::test(ListOrders::class)->filterTable('merchant_id', $outsideMerchant->id)
            ->assertCanNotSeeTableRecords([$visible, $platform, $outside]);
    }

    #[TestWith(['order_no', 'SSC-MATCH', 'SSC-OTHER'])]
    #[TestWith(['merchant_order_no', 'M-MATCH', 'M-OTHER'])]
    #[TestWith(['transaction_id', 'TXN-MATCH', 'TXN-OTHER'])]
    #[TestWith(['customer_email', 'match@example.com', 'other@example.com'])]
    public function test_order_identifier_and_email_filters_find_matching_authorized_orders(string $field, string $matchingValue, string $otherValue): void
    {
        $matching = $this->makeOrder('SSC-MATCH', 'M-MATCH', [
            'status' => 'paid', 'payment_method_id' => $this->methodId, $field => $matchingValue,
        ]);
        $other = $this->makeOrder('SSC-OTHER', 'M-OTHER', [
            'status' => 'paid', 'payment_method_id' => $this->methodId, $field => $otherValue,
        ]);
        $outside = $this->makeOrder('SSC-OUTSIDE-FILTER', 'M-OUTSIDE-FILTER', ['status' => 'paid', $field => 'OUTSIDE-'.$matchingValue]);

        Livewire::test(ListOrders::class)
            ->filterTable($field, [$field => 'MATCH'])
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other, $outside]);
    }

    public function test_payment_and_shipping_filters_combine_with_identifiers_and_email_in_the_export(): void
    {
        $otherMethod = $this->makePaymentMethod('observer-other-channel');
        $this->observer->paymentMethods()->attach($otherMethod);
        $matching = $this->makeOrder('SSC-COMBINED-MATCH', 'M-COMBINED-MATCH', [
            'status' => 'shipped', 'payment_method_id' => $this->methodId, 'transaction_id' => 'TXN-COMBINED-MATCH',
            'customer_email' => 'combined@example.com',
        ]);
        $unshipped = $this->makeOrder('SSC-COMBINED-UNSHIPPED', 'M-COMBINED-UNSHIPPED', [
            'status' => 'shipped', 'payment_method_id' => $this->methodId,
        ]);
        $otherChannel = $this->makeOrder('SSC-COMBINED-CHANNEL', 'M-COMBINED-CHANNEL', [
            'status' => 'paid', 'payment_method_id' => $otherMethod->id,
        ]);
        $matching->shipping()->create([
            'merchant_id' => $this->merchant->id, 'logistics_company' => 'ups',
            'tracking_number' => 'COMBINED-TRACKING', 'shipped_at' => now(), 'operator_id' => 0,
        ]);

        $page = Livewire::test(ListOrders::class)
            ->filterTable('payment_method_id', $this->methodId)
            ->filterTable('shipping_status', 'shipped')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$unshipped, $otherChannel])
            ->filterTable('order_no', ['order_no' => 'COMBINED'])
            ->filterTable('merchant_order_no', ['merchant_order_no' => 'M-COMBINED'])
            ->filterTable('transaction_id', ['transaction_id' => 'TXN-COMBINED'])
            ->filterTable('customer_email', ['customer_email' => 'combined@'])
            ->filterTable('amount', ['amount' => '50.00'])
            ->filterTable('status', 'paid');
        $rows = $this->spreadsheetRows($this->downloadContents(
            app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery())
        ));

        $this->assertSame(['SSC-COMBINED-MATCH'], array_column(array_slice($rows, 1), 2));
        Livewire::test(ListOrders::class)
            ->filterTable('payment_method_id', $this->methodId)
            ->filterTable('shipping_status', 'unshipped')
            ->assertCanSeeTableRecords([$unshipped])
            ->assertCanNotSeeTableRecords([$matching, $otherChannel]);
    }

    #[TestWith(['50.00', '50.00', '100.00', '100.01', '99.99', '100.02'])]
    #[TestWith(['33.33', '33.33', '100.00', '100.03', '99.99', '100.04'])]
    public function test_amount_filter_matches_the_truncated_display_amount(string $ratio, string $displayedAmount, string $firstAmount, string $lastAmount, string $lowerAmount, string $upperAmount): void
    {
        $this->observer->update(['amount_display_ratio' => $ratio]);
        $first = $this->makeOrder('SSC-AMOUNT-FIRST', 'M-AMOUNT-FIRST', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'amount' => $firstAmount]);
        $last = $this->makeOrder('SSC-AMOUNT-LAST', 'M-AMOUNT-LAST', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'amount' => $lastAmount]);
        $lower = $this->makeOrder('SSC-AMOUNT-LOWER', 'M-AMOUNT-LOWER', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'amount' => $lowerAmount]);
        $upper = $this->makeOrder('SSC-AMOUNT-UPPER', 'M-AMOUNT-UPPER', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'amount' => $upperAmount]);

        $page = Livewire::test(ListOrders::class)
            ->filterTable('amount', ['amount' => $displayedAmount])
            ->assertCanSeeTableRecords([$first, $last])
            ->assertCanNotSeeTableRecords([$lower, $upper]);
        $rows = $this->spreadsheetRows($this->downloadContents(
            app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery())
        ));

        $this->assertSame(['SSC-AMOUNT-FIRST', 'SSC-AMOUNT-LAST'], array_column(array_slice($rows, 1), 2));
        $this->assertSame([(float) $displayedAmount, (float) $displayedAmount], array_map(fn (array $row): float => (float) $row[7], array_slice($rows, 1)));
    }

    public function test_zero_ratio_amount_filter_only_matches_zero_without_dividing_by_zero(): void
    {
        $this->observer->update(['amount_display_ratio' => '0.00']);
        $order = $this->makeOrder('SSC-ZERO-RATIO', 'M-ZERO-RATIO', ['status' => 'paid', 'payment_method_id' => $this->methodId]);

        Livewire::test(ListOrders::class)->filterTable('amount', ['amount' => '0'])
            ->assertCanSeeTableRecords([$order]);
        Livewire::test(ListOrders::class)->filterTable('amount', ['amount' => '1'])
            ->assertCanNotSeeTableRecords([$order]);
    }

    #[TestWith(['invalid'])]
    #[TestWith(['-1.00'])]
    #[TestWith(['50.001'])]
    public function test_invalid_amount_filter_returns_no_orders(string $amount): void
    {
        $order = $this->makeOrder('SSC-INVALID-AMOUNT', 'M-INVALID-AMOUNT', ['status' => 'paid', 'payment_method_id' => $this->methodId]);

        Livewire::test(ListOrders::class)->filterTable('amount', ['amount' => $amount])
            ->assertCanNotSeeTableRecords([$order]);
    }

    public function test_net_settlement_is_scaled_and_missing_or_crypto_amounts_stay_blank_in_list_and_export(): void
    {
        $net = $this->makeOrder('SSC-NET', 'M-NET', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'settlement_amount' => '96.70']);
        $zero = $this->makeOrder('SSC-ZERO-NET', 'M-ZERO-NET', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'settlement_amount' => '0.00']);
        $missing = $this->makeOrder('SSC-MISSING-NET', 'M-MISSING-NET', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'settlement_amount' => null]);
        $crypto = $this->makeOrder('SSC-CRYPTO-NET', 'M-CRYPTO-NET', ['status' => 'paid', 'payment_method_id' => $this->methodId, 'payment_type' => 'crypto', 'settlement_amount' => '0.00']);

        $page = Livewire::test(ListOrders::class)
            ->assertTableColumnFormattedStateSet('settlement_amount', '$48.35', $net)
            ->assertTableColumnFormattedStateSet('settlement_amount', '$0.00', $zero)
            ->assertTableColumnStateSet('settlement_amount', null, $missing)
            ->assertTableColumnStateSet('settlement_amount', null, $crypto);
        $rows = $this->spreadsheetRows($this->downloadContents(
            app(ObserverOrderExportService::class)->download($this->observer, $page->instance()->getFilteredTableQuery())
        ));

        $this->assertSame([48.35, 0.0, '', ''], array_map(fn (array $row): float|string => in_array($row[9], [null, ''], true) ? '' : (float) $row[9], array_slice($rows, 1)));
        $this->assertSame('96.70', $net->fresh()->settlement_amount);
    }

    public function test_unshipped_order_shows_placeholder_and_cannot_open_shipping_modal(): void
    {
        $order = $this->makeOrder('SSC-UNSHIPPED-MODAL', 'M-UNSHIPPED-MODAL', ['status' => 'paid', 'payment_method_id' => $this->methodId]);

        $page = Livewire::test(ListOrders::class)
            ->assertCanRenderTableColumn('shipping.tracking_number')
            ->assertTableColumnStateSet('shipping.tracking_number', null, $order)
            ->assertSee(__('admin.order.placeholders.not_shipped'))
            ->assertActionDisabled(TestAction::make('viewShippingInfo')->table($order))
            ->mountAction(TestAction::make('viewShippingInfo')->table($order))
            ->assertActionNotMounted();
        $column = $page->instance()->getTable()->getColumn('shipping.tracking_number');
        $column->record($order);

        $this->assertSame(__('admin.order.placeholders.not_shipped'), $column->getPlaceholder());
        $this->assertSame('gray', $column->getColor($column->getState()));
    }

    #[TestWith([OrderShipping::SYNC_STATUS_PENDING, 'gray'])]
    #[TestWith([OrderShipping::SYNC_STATUS_SYNCED, 'success'])]
    #[TestWith([OrderShipping::SYNC_STATUS_FAILED, 'danger'])]
    public function test_shipping_record_controls_tracking_display_and_sync_color_independently_of_order_status(string $syncStatus, string $color): void
    {
        $order = $this->makeOrder('SSC-SHIPPING-COLOR', 'M-SHIPPING-COLOR', ['status' => 'refunded', 'payment_method_id' => $this->methodId]);
        $order->shipping()->create([
            'merchant_id' => $this->merchant->id, 'logistics_company' => 'ups', 'tracking_number' => '1Z-OBSERVER-SHIPMENT',
            'shipped_at' => now(), 'operator_id' => 0, 'sync_status' => $syncStatus,
        ]);

        $page = Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertTableColumnStateSet('shipping.tracking_number', '1Z-OBSERVER-SHIPMENT', $order)
            ->assertActionEnabled(TestAction::make('viewShippingInfo')->table($order));
        $column = $page->instance()->getTable()->getColumn('shipping.tracking_number');
        $column->record($order->fresh(['shipping']));

        $this->assertSame($color, $column->getColor($column->getState()));
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_shipping_modal_displays_full_read_only_shipping_information(): void
    {
        $operator = User::factory()->create(['name' => 'Shipment Operator', 'merchant_id' => $this->merchant->id]);
        $order = $this->makeOrder('SSC-SHIPPING-MODAL', 'M-SHIPPING-MODAL', ['status' => 'paid', 'payment_method_id' => $this->methodId]);
        $order->shipping()->create([
            'merchant_id' => $this->merchant->id, 'logistics_company' => 'ups', 'tracking_number' => '1Z-MODAL-TRACKING',
            'tracking_url' => 'https://tracking.example.com/1Z-MODAL-TRACKING', 'shipped_at' => '2026-11-27 10:24:36',
            'operator_id' => $operator->id, 'sync_status' => OrderShipping::SYNC_STATUS_FAILED,
            'sync_message' => 'Shipment synchronization failed', 'remark' => 'Shipping note for the observer',
        ]);

        $page = Livewire::test(ListOrders::class)
            ->mountAction(TestAction::make('viewShippingInfo')->table($order))
            ->assertActionMounted(TestAction::make('viewShippingInfo')->table($order))
            ->assertMountedActionModalSee('ups')
            ->assertMountedActionModalSee('1Z-MODAL-TRACKING')
            ->assertMountedActionModalSee('https://tracking.example.com/1Z-MODAL-TRACKING')
            ->assertMountedActionModalSee('10:24:36')
            ->assertMountedActionModalSee(__('admin.order.sync_statuses.failed'))
            ->assertMountedActionModalSee('Shipment synchronization failed')
            ->assertMountedActionModalSee('Shipment Operator')
            ->assertMountedActionModalDontSee($operator->email)
            ->assertMountedActionModalSee('Shipping note for the observer')
            ->assertMountedActionModalSeeHtml('href="https://tracking.example.com/1Z-MODAL-TRACKING"');
        $action = $page->instance()->getMountedAction();

        $this->assertNotNull($action);
        $this->assertNull($action->getModalSubmitAction());
        $this->assertSame(__('admin.order.modals.close'), $action->getModalCancelAction()?->getLabel());
    }

    #[TestWith(['other_channel'])]
    #[TestWith(['hidden_status'])]
    public function test_shipping_modal_cannot_be_opened_with_an_order_outside_the_observer_scope(string $scope): void
    {
        $outside = $this->makeOrder('SSC-PRIVATE-SHIPPING', 'M-PRIVATE-SHIPPING', [
            'status' => $scope === 'hidden_status' ? 'pending' : 'paid',
            'payment_method_id' => $scope === 'hidden_status' ? $this->methodId : $this->makePaymentMethod('observer-private-channel')->id,
        ]);
        $outside->shipping()->create([
            'merchant_id' => $this->merchant->id, 'logistics_company' => 'PRIVATE-CARRIER',
            'tracking_number' => 'PRIVATE-TRACKING-NUMBER', 'shipped_at' => now(), 'operator_id' => 0,
            'remark' => 'PRIVATE-SHIPPING-REMARK',
        ]);

        Livewire::test(ListOrders::class)
            ->assertCanNotSeeTableRecords([$outside])
            ->mountAction(TestAction::make('viewShippingInfo')->table($outside->id))
            ->assertActionNotMounted()
            ->assertDontSee('PRIVATE-CARRIER')
            ->assertDontSee('PRIVATE-TRACKING-NUMBER')
            ->assertDontSee('PRIVATE-SHIPPING-REMARK');
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
