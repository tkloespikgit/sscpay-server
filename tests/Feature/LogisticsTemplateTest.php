<?php

namespace Tests\Feature;

use App\Jobs\SyncOrderTrackingJob;
use App\Models\Carrier;
use App\Models\LogisticsImportTask;
use App\Models\LogisticsImportTaskRecord;
use App\Models\Order;
use App\Models\User;
use App\Services\LogisticsImportService;
use App\Support\XlsxExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OverflowException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Tests\Concerns\CreatesTestOrders;
use Tests\Concerns\ReadsSpreadsheets;
use Tests\TestCase;

/**
 * 物流模板的导出/导入。重点保证两件事：
 *   1. 导出带上客户手机号和完整收货地址（追加在表尾）；
 *   2. 加列之后导入仍然正常——导入端按表头名映射，不认列下标。
 */
class LogisticsTemplateTest extends TestCase
{
    use CreatesTestOrders;
    use ReadsSpreadsheets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 导入会投递 SyncOrderTrackingJob 并读写 OSS，两个都拦掉
        Queue::fake();
        Storage::fake('oss');

        $this->createMerchantAndApplication();
    }

    public function test_template_exports_customer_phone_and_full_shipping_address(): void
    {
        $this->makeOrder('SSC-1', 'M-1', [
            'customer_phone' => '+1-555-0100',
            'shipping_country' => 'US',
            'shipping_zip' => '94107',
            'shipping_state' => 'CA',
            'shipping_city' => 'San Francisco',
            'shipping_address_line1' => '1 Market St',
            'shipping_address_line2' => 'Suite 300',
        ]);

        $rows = $this->parseTemplate(
            app(LogisticsImportService::class)->generateTemplate($this->merchant->id, Order::query())
        );

        // 新增列一律追加在表尾，不打乱原有列顺序
        $this->assertSame([
            'customer_phone',
            'shipping_country',
            'shipping_zip',
            'shipping_state',
            'shipping_city',
            'shipping_address_line1',
            'shipping_address_line2',
        ], array_slice($rows['header'], -7));

        $this->assertSame([
            'customer_phone' => '+1-555-0100',
            'shipping_country' => 'US',
            'shipping_zip' => '94107',
            'shipping_state' => 'CA',
            'shipping_city' => 'San Francisco',
            'shipping_address_line1' => '1 Market St',
            'shipping_address_line2' => 'Suite 300',
        ], array_intersect_key($rows['rows'][0], array_flip([
            'customer_phone', 'shipping_country', 'shipping_zip', 'shipping_state',
            'shipping_city', 'shipping_address_line1', 'shipping_address_line2',
        ])));
    }

    public function test_new_template_still_imports_tracking_numbers(): void
    {
        $this->seedCarrier();

        $order = $this->makeOrder('001234567890123456789012', 'M-1', ['status' => 'paid']);
        $trackingNumber = '009876543210987654321098765432';

        // 走一遍真实的导出 -> 填写 -> 上传闭环：填好的正是刚导出的那份新模板
        $xlsx = app(LogisticsImportService::class)->generateTemplate($this->merchant->id, Order::query());
        $filled = $this->fillColumns($xlsx, ['logistics_company' => 'ups', 'tracking_number' => $trackingNumber, 'remark' => 'ok']);

        $this->assertSame(1, $this->runImport($filled, extension: 'xlsx')->success_count);

        $record = LogisticsImportTaskRecord::query()->withoutGlobalScopes()->sole();
        $this->assertSame('001234567890123456789012', $record->order_no);
        $this->assertSame($trackingNumber, $record->tracking_number);
        $this->assertSame('ups', $record->logistics_company);

        $this->assertSame($trackingNumber, $order->fresh()->shipping->tracking_number);
        Queue::assertPushed(SyncOrderTrackingJob::class, fn (SyncOrderTrackingJob $job): bool => $job->orderShippingId === $order->fresh()->shipping->id);
    }

    public function test_template_preserves_all_non_amount_cells_as_text_and_amounts_as_numbers(): void
    {
        $this->merchant->update(['name' => '00012345678901234567890']);
        $this->application->update(['app_id' => '00123456789012345678901']);
        $this->makeOrder('001234567890123456789012', '123456789012345678901234', [
            'transaction_id' => '009876543210987654321098',
            'customer_phone' => '00123456789012345678',
            'shipping_zip' => '00123',
            'shipping_address_line1' => '00012345678901234567890',
            'shipping_address_line2' => '=1+1',
            'amount' => '100.50',
            'converted_amount' => '109.25',
        ]);

        $workbook = $this->readSpreadsheet(
            app(LogisticsImportService::class)->generateTemplate($this->merchant->id, Order::query())
        );

        try {
            $sheet = $workbook->getActiveSheet();
            $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0];
            $expectedText = [
                'merchant_id' => (string) $this->merchant->id,
                'merchant_name' => '00012345678901234567890',
                'app_id' => '00123456789012345678901',
                'order_no' => '001234567890123456789012',
                'merchant_order_no' => '123456789012345678901234',
                'transaction_id' => '009876543210987654321098',
                'customer_phone' => '00123456789012345678',
                'shipping_zip' => '00123',
                'shipping_address_line1' => '00012345678901234567890',
                'shipping_address_line2' => '=1+1',
                'tracking_number' => '',
            ];

            foreach ($headers as $index => $header) {
                if (in_array($header, ['amount', 'converted_amount'], true)) {
                    continue;
                }

                $cell = $sheet->getCell([$index + 1, 3]);
                $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), $header);
                $this->assertSame(NumberFormat::FORMAT_TEXT, $cell->getStyle()->getNumberFormat()->getFormatCode(), $header);

                if (array_key_exists($header, $expectedText)) {
                    $this->assertSame($expectedText[$header], $cell->getValue(), $header);
                    $this->assertSame($expectedText[$header], $cell->getCalculatedValue(), $header);
                }
            }

            foreach (['amount' => 100.5, 'converted_amount' => 109.25] as $header => $expected) {
                $cell = $sheet->getCell([array_search($header, $headers, true) + 1, 3]);
                $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), $header);
                $this->assertSame($expected, $cell->getValue(), $header);
                $this->assertSame('0.00', $cell->getStyle()->getNumberFormat()->getFormatCode(), $header);
            }
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function test_single_sheet_export_accepts_the_row_limit_and_rejects_additional_rows(): void
    {
        $rows = [XlsxExport::row(['order_no']), XlsxExport::row(['00123'])];
        $this->assertSame([['order_no'], ['00123']], $this->spreadsheetRows(
            XlsxExport::contents($rows, rowLimit: 2)
        ));

        $rows[] = XlsxExport::row(['00456']);
        $this->expectException(OverflowException::class);

        XlsxExport::contents($rows, rowLimit: 2);
    }

    public function test_template_carries_a_note_row_listing_the_editable_columns(): void
    {
        $this->makeOrder('SSC-1', 'M-1');

        $parsed = $this->parseTemplate(
            app(LogisticsImportService::class)->generateTemplate($this->merchant->id, Order::query())
        );

        $this->assertNotNull($parsed['note']);
        foreach (['logistics_company', 'tracking_number', 'shipped_at', 'tracking_url', 'remark'] as $field) {
            $this->assertStringContainsString($field, $parsed['note']);
        }

        // 说明行不能被当成数据行：一张订单导出后就只有一行数据
        $this->assertCount(1, $parsed['rows']);
    }

    public function test_note_row_is_skipped_and_not_counted_on_upload(): void
    {
        $this->seedCarrier();
        $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        $xlsx = app(LogisticsImportService::class)->generateTemplate($this->merchant->id, Order::query());
        $task = $this->runImport($this->fillColumns($xlsx, ['logistics_company' => 'ups', 'tracking_number' => '1Z999']), extension: 'xlsx');

        $this->assertSame(1, $task->total_records);
        $this->assertSame(1, $task->success_count);
        $this->assertSame(0, $task->fail_count);
    }

    public function test_a_filled_shipped_at_and_tracking_url_are_imported(): void
    {
        $this->seedCarrier();
        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        $this->importRow([
            'order_no' => 'SSC-1',
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => '2026-09-01 08:30:00',
            'tracking_url' => 'https://track.example.com/1Z999',
        ]);

        $shipping = $order->fresh()->shipping;
        $this->assertSame('2026-09-01 08:30:00', $shipping->shipped_at->format('Y-m-d H:i:s'));
        $this->assertSame('https://track.example.com/1Z999', $shipping->tracking_url);
    }

    public function test_a_blank_shipped_at_falls_back_to_the_file_upload_time(): void
    {
        $this->seedCarrier();
        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        // 任务创建时间 = 文件上传时间；刻意和"这一行被处理的时刻"拉开距离
        $uploadedAt = now()->subHours(6)->startOfSecond();

        $this->importRow([
            'order_no' => 'SSC-1',
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => '',
        ], uploadedAt: $uploadedAt);

        $this->assertTrue($uploadedAt->equalTo($order->fresh()->shipping->shipped_at));
    }

    /**
     * 留空要落成 NULL，不能落成空字符串——列是 nullable，空串会让
     * 详情页的 placeholder（"—"）失效，前端渲染出一个空的超链接。
     *
     * 注：CSV 导入只认 paid 且未发货的订单（syncRecord() 里的 paidUnshipped()），
     * 所以"补发/改单时留空保留原链接"这条分支走不到导入这条路，只在手工录入
     * 和 API 上生效，对应的断言在 OrderShippingRecordTest。
     */
    public function test_a_blank_tracking_url_is_stored_as_null(): void
    {
        $this->seedCarrier();
        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        $this->importRow([
            'order_no' => 'SSC-1',
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'tracking_url' => '',
        ]);

        $this->assertNull($order->fresh()->shipping->tracking_url);
    }

    public function test_an_unparseable_shipped_at_fails_only_that_row(): void
    {
        $this->seedCarrier();
        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        $task = $this->importRow([
            'order_no' => 'SSC-1',
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'shipped_at' => '去年双十一',
        ]);

        $this->assertSame(1, $task->fail_count);
        $this->assertSame(0, $task->success_count);
        $this->assertStringContainsString(
            '发货时间',
            LogisticsImportTaskRecord::query()->withoutGlobalScopes()->sole()->error_message
        );
        // 整行失败，不会写入半截物流记录
        $this->assertNull($order->fresh()->shipping);
    }

    public function test_an_invalid_tracking_url_fails_only_that_row(): void
    {
        $this->seedCarrier();
        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        $task = $this->importRow([
            'order_no' => 'SSC-1',
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'tracking_url' => '看订单详情页',
        ]);

        $this->assertSame(1, $task->fail_count);
        $this->assertNull($order->fresh()->shipping);
    }

    public function test_legacy_four_column_template_still_imports(): void
    {
        $this->seedCarrier();

        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        $legacy = "order_no,logistics_company,tracking_number,remark\nSSC-1,ups,1Z999,ok\n";

        $this->assertSame(1, $this->runImport($legacy)->success_count);
        $this->assertSame('1Z999', $order->fresh()->shipping->tracking_number);
    }

    private function seedCarrier(): void
    {
        Carrier::create([
            'carrier_name' => 'UPS',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);
    }

    /**
     * 用一行最小 CSV 跑一次导入。只写出给定的列，模拟商户手工精简过的表格。
     *
     * @param  ?Carbon  $uploadedAt  任务创建时间（= 文件上传时间）
     */
    private function importRow(array $values, $uploadedAt = null): LogisticsImportTask
    {
        $header = implode(',', array_keys($values));
        $row = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $values));

        return $this->runImport($header."\n".$row."\n", $uploadedAt);
    }

    /** 把文件落到 OSS 并跑完一个导入任务，返回刷新后的任务。 */
    private function runImport(string $contents, $uploadedAt = null, string $extension = 'csv'): LogisticsImportTask
    {
        Storage::disk('oss')->put('imports/test.'.$extension, $contents);

        $operator = User::firstOrCreate(
            ['email' => 'operator@example.com'],
            ['name' => 'Operator', 'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id],
        );

        $task = LogisticsImportTask::query()->withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'operator_id' => $operator->id,
            'file_name' => 'test.'.$extension,
            'oss_path' => 'imports/test.'.$extension,
            'status' => 'pending',
        ]);

        // 文件上传时间 = 任务创建时间，shipped_at 留空时的兜底取值就是它
        if ($uploadedAt !== null) {
            $task->forceFill(['created_at' => $uploadedAt])->save();
            $task->refresh();
        }

        app(LogisticsImportService::class)->processImport($task->id);

        return $task->refresh();
    }

    /** 模拟商户在导出的模板里按列名填写，其余列（含说明行）原样不动。 */
    private function fillColumns(string $contents, array $values): string
    {
        $workbook = $this->readSpreadsheet($contents);
        $sheet = $workbook->getActiveSheet();
        $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0];
        $path = tempnam(sys_get_temp_dir(), 'logistics_filled_template_test_');

        if ($path === false) {
            $this->fail('无法创建测试工作簿');
        }

        try {
            for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
                foreach ($values as $header => $value) {
                    $sheet->setCellValueExplicit([array_search($header, $headers, true) + 1, $row], $value, DataType::TYPE_STRING);
                }
            }

            IOFactory::createWriter($workbook, 'Xlsx')->save($path);

            return (string) file_get_contents($path);
        } finally {
            $workbook->disconnectWorksheets();
            unlink($path);
        }
    }

    /**
     * 说明行（# 开头）单独拆出来，不混进 rows——它不是数据行。
     *
     * @return array{header: array<int, string>, note: ?string, rows: array<int, array<string, mixed>>}
     */
    private function parseTemplate(string $contents): array
    {
        $sheetRows = $this->spreadsheetRows($contents);
        $header = array_shift($sheetRows);
        $note = null;
        $rows = [];

        foreach ($sheetRows as $cells) {
            if (str_starts_with(trim((string) ($cells[0] ?? '')), '#')) {
                $note = $cells[0];

                continue;
            }

            $rows[] = array_combine($header, $cells);
        }

        return ['header' => $header, 'note' => $note, 'rows' => $rows];
    }
}
