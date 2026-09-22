<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\LogisticsImportTask;
use App\Models\LogisticsImportTaskRecord;
use App\Models\Order;
use App\Models\User;
use App\Services\LogisticsImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 物流模板的导出/导入。重点保证两件事：
 *   1. 导出带上客户手机号和完整收货地址（追加在表尾）；
 *   2. 加列之后导入仍然正常——导入端按表头名映射，不认列下标。
 */
class LogisticsTemplateTest extends TestCase
{
    use CreatesTestOrders;
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

        $rows = $this->parseCsv(
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

        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        // 走一遍真实的导出 -> 填写 -> 上传闭环：填好的正是刚导出的那份新模板
        $csv = app(LogisticsImportService::class)->generateTemplate($this->merchant->id, Order::query());
        $filled = $this->fillColumns($csv, ['logistics_company' => 'ups', 'tracking_number' => '1Z999', 'remark' => 'ok']);

        $this->assertSame(1, $this->runImport($filled)->success_count);

        $record = LogisticsImportTaskRecord::query()->withoutGlobalScopes()->sole();
        $this->assertSame('SSC-1', $record->order_no);
        $this->assertSame('1Z999', $record->tracking_number);
        $this->assertSame('ups', $record->logistics_company);

        $this->assertSame('1Z999', $order->fresh()->shipping->tracking_number);
    }

    public function test_template_carries_a_note_row_listing_the_editable_columns(): void
    {
        $this->makeOrder('SSC-1', 'M-1');

        $parsed = $this->parseCsv(
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

        $csv = app(LogisticsImportService::class)->generateTemplate($this->merchant->id, Order::query());
        $task = $this->runImport($this->fillColumns($csv, ['logistics_company' => 'ups', 'tracking_number' => '1Z999']));

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

    /** 把 CSV 落到 OSS 并跑完一个导入任务，返回刷新后的任务。 */
    private function runImport(string $csv, $uploadedAt = null): LogisticsImportTask
    {
        Storage::disk('oss')->put('imports/test.csv', $csv);

        $operator = User::firstOrCreate(
            ['email' => 'operator@example.com'],
            ['name' => 'Operator', 'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id],
        );

        $task = LogisticsImportTask::query()->withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'operator_id' => $operator->id,
            'file_name' => 'test.csv',
            'oss_path' => 'imports/test.csv',
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
    private function fillColumns(string $csv, array $values): string
    {
        $parsed = $this->parseCsv($csv);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $parsed['header']);

        if ($parsed['note'] !== null) {
            fputcsv($out, array_pad([$parsed['note']], count($parsed['header']), ''));
        }

        foreach ($parsed['rows'] as $row) {
            fputcsv($out, array_values(array_merge($row, $values)));
        }

        rewind($out);
        $filled = stream_get_contents($out);
        fclose($out);

        return $filled;
    }

    /**
     * 说明行（# 开头）单独拆出来，不混进 rows——它不是数据行。
     *
     * @return array{header: array<int, string>, note: ?string, rows: array<int, array<string, string>>}
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, str_replace("\xEF\xBB\xBF", '', $csv));
        rewind($handle);

        $header = fgetcsv($handle);
        $note = null;
        $rows = [];

        while (($cells = fgetcsv($handle)) !== false) {
            if (str_starts_with(trim((string) ($cells[0] ?? '')), '#')) {
                $note = $cells[0];

                continue;
            }

            $rows[] = array_combine($header, $cells);
        }

        fclose($handle);

        return ['header' => $header, 'note' => $note, 'rows' => $rows];
    }
}
