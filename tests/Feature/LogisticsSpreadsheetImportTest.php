<?php

namespace Tests\Feature;

use App\Events\LogisticsImportCompleted;
use App\Events\OrderStatusChanged;
use App\Jobs\SyncOrderTrackingJob;
use App\Models\Carrier;
use App\Models\LogisticsImportTask;
use App\Models\Merchant;
use App\Models\User;
use App\Services\LogisticsImportService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class LogisticsSpreadsheetImportTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    #[TestWith(['xlsx'])]
    #[TestWith(['xls'])]
    public function test_it_imports_only_the_first_sheet_and_preserves_text_tracking_numbers(string $extension): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-EXCEL-1', 'M-EXCEL-1', ['status' => 'paid']);
        $ignoredOrder = $this->makeOrder('SSC-EXCEL-2', 'M-EXCEL-2', ['status' => 'paid']);
        $laterOrder = $this->makeOrder('SSC-EXCEL-3', 'M-EXCEL-3', ['status' => 'paid']);
        $trackingNumber = '001234567890123456789012345678';
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->fromArray(['运单号', '备注', '系统订单号', '物流公司'], null, 'A1');
        $sheet->setCellValue('A2', '# 请填写物流信息');
        $sheet->fromArray([$trackingNumber, '已核对', $order->order_no, 'ups'], null, 'A4');
        $sheet->setCellValueExplicit('A4', $trackingNumber, DataType::TYPE_STRING);
        $sheet->getStyle('A4')->getNumberFormat()->setFormatCode('0');
        $sheet->fromArray(['AFTER-EMPTY-CHUNK', '', $laterOrder->order_no, 'ups'], null, 'A1004');
        $workbook->createSheet()->fromArray([
            ['order_no', 'logistics_company', 'tracking_number'],
            [$ignoredOrder->order_no, 'ups', 'SHOULD-NOT-IMPORT'],
        ]);
        $workbook->setActiveSheetIndex(1);

        $task = $this->importWorkbook($workbook, $extension, $operator);

        $this->assertSame('completed', $task->status, json_encode($task->error_log, JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, $task->total_records);
        $this->assertSame(2, $task->success_count);
        $this->assertSame(0, $task->fail_count);
        $records = $task->records()->orderBy('row_number')->get();
        $this->assertSame([4, 1004], $records->pluck('row_number')->all());
        $record = $records[0];
        $this->assertSame(4, $record->row_number);
        $this->assertSame('success', $record->status);
        $this->assertSame($order->id, $record->order_id);
        $this->assertSame($trackingNumber, $record->tracking_number);
        $this->assertSame($trackingNumber, $record->raw_data['运单号']);
        $this->assertSame('已核对', $order->fresh()->shipping->remark);
        $this->assertSame($trackingNumber, $order->fresh()->shipping->tracking_number);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('AFTER-EMPTY-CHUNK', $laterOrder->fresh()->shipping->tracking_number);
        $this->assertNull($ignoredOrder->fresh()->shipping);
        Queue::assertPushed(SyncOrderTrackingJob::class, 2);
        Event::assertDispatched(LogisticsImportCompleted::class, 1);
    }

    #[TestWith(['xlsx'])]
    #[TestWith(['xls'])]
    public function test_excel_date_cells_preserve_the_shipping_date_and_time(string $extension): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-DATE', 'M-DATE', ['status' => 'paid']);
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->fromArray([
            ['order_no', 'logistics_company', 'tracking_number', 'shipped_at'],
            [$order->order_no, 'ups', 'DATE-TRACKING', Date::PHPToExcel(new DateTimeImmutable('2026-10-21 09:45:30'))],
        ]);
        $sheet->getStyle('D2')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm:ss');

        $task = $this->importWorkbook($workbook, $extension, $operator);

        $this->assertSame(1, $task->success_count);
        $this->assertSame('2026-10-21 09:45:30', $order->fresh()->shipping->shipped_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 09:45:30', $task->records()->sole()->shipped_at);
        Queue::assertPushed(SyncOrderTrackingJob::class, 1);
        Event::assertDispatched(LogisticsImportCompleted::class, 1);
    }

    public function test_invalid_rows_and_other_merchants_orders_do_not_block_valid_rows(): void
    {
        $operator = $this->prepareImport();
        $validOrder = $this->makeOrder('SSC-VALID', 'M-VALID', ['status' => 'paid']);
        $invalidOrder = $this->makeOrder('SSC-INVALID', 'M-INVALID', ['status' => 'paid']);
        $otherMerchant = Merchant::create([
            'name' => 'Other merchant',
            'contact_person' => 'Other',
            'contact_phone' => '123456',
            'contact_email' => 'other@example.com',
        ]);
        $otherOrder = $this->makeOrder('SSC-OTHER', 'M-OTHER', ['status' => 'paid', 'merchant_id' => $otherMerchant->id]);
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray([
            ['order_no', 'logistics_company', 'tracking_number'],
            [$validOrder->order_no, 'ups', 'VALID-TRACKING'],
            [$invalidOrder->order_no, 'unknown-carrier', 'INVALID-TRACKING'],
            [$otherOrder->order_no, 'ups', 'OTHER-TRACKING'],
        ]);

        $task = $this->importWorkbook($workbook, 'xlsx', $operator);

        $this->assertSame('completed', $task->status);
        $this->assertSame(3, $task->total_records);
        $this->assertSame(1, $task->success_count);
        $this->assertSame(2, $task->fail_count);
        $records = $task->records()->orderBy('row_number')->get();
        $this->assertSame([2, 3, 4], $records->pluck('row_number')->all());
        $this->assertSame(['success', 'failed', 'failed'], $records->pluck('status')->all());
        $this->assertStringContainsString('unknown-carrier', $records[1]->error_message);
        $this->assertSame('订单不存在或不是待发货状态', $records[2]->error_message);
        $this->assertSame('VALID-TRACKING', $validOrder->fresh()->shipping->tracking_number);
        $this->assertNull($invalidOrder->fresh()->shipping);
        $this->assertNull($otherOrder->fresh()->shipping);
        $this->assertSame('paid', $otherOrder->fresh()->status);
        Queue::assertPushed(SyncOrderTrackingJob::class, 1);
        Event::assertDispatched(LogisticsImportCompleted::class, 1);
    }

    #[TestWith(['xlsx'])]
    #[TestWith(['xls'])]
    public function test_a_workbook_without_an_order_number_header_fails_before_shipping(string $extension): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-NO-HEADER', 'M-NO-HEADER', ['status' => 'paid']);
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray([
            ['logistics_company', 'tracking_number'],
            ['ups', 'NO-HEADER-TRACKING'],
        ]);

        $task = $this->importWorkbook($workbook, $extension, $operator);

        $this->assertSame('failed', $task->status);
        $this->assertSame(0, $task->total_records);
        $this->assertSame(0, $task->records()->count());
        $this->assertStringContainsString('order_no', $task->error_log[0]['error']);
        $this->assertNull($order->fresh()->shipping);
        Queue::assertNothingPushed();
        Event::assertDispatched(LogisticsImportCompleted::class, 1);
    }

    public function test_a_damaged_workbook_fails_without_writing_shipping_records(): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-DAMAGED', 'M-DAMAGED', ['status' => 'paid']);

        $task = $this->importContents('not an Excel workbook', 'xlsx', $operator);

        $this->assertSame('failed', $task->status);
        $this->assertSame(0, $task->records()->count());
        $this->assertNotEmpty($task->error_log[0]['error']);
        $this->assertNull($order->fresh()->shipping);
        Queue::assertNothingPushed();
        Event::assertDispatched(LogisticsImportCompleted::class, 1);
    }

    private function prepareImport(): User
    {
        $this->createMerchantAndApplication();
        Carrier::create([
            'carrier_name' => 'UPS',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        Storage::fake('oss');
        Queue::fake([SyncOrderTrackingJob::class]);
        Event::fake([LogisticsImportCompleted::class, OrderStatusChanged::class]);

        return $operator;
    }

    private function importWorkbook(Spreadsheet $workbook, string $extension, User $operator): LogisticsImportTask
    {
        $path = tempnam(sys_get_temp_dir(), 'logistics_spreadsheet_test_');

        if ($path === false) {
            $this->fail('无法创建测试工作簿');
        }

        try {
            IOFactory::createWriter($workbook, $extension === 'xls' ? 'Xls' : 'Xlsx')->save($path);

            return $this->importContents((string) file_get_contents($path), $extension, $operator);
        } finally {
            $workbook->disconnectWorksheets();
            unlink($path);
        }
    }

    private function importContents(string $contents, string $extension, User $operator): LogisticsImportTask
    {
        $path = 'imports/logistics.'.$extension;
        Storage::disk('oss')->put($path, $contents);
        $task = LogisticsImportTask::query()->withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'operator_id' => $operator->id,
            'file_name' => 'logistics.'.$extension,
            'oss_path' => $path,
            'status' => 'pending',
        ]);

        app(LogisticsImportService::class)->processImport($task->id);

        return $task->refresh();
    }
}
