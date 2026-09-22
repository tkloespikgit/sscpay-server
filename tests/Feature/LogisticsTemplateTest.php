<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\LogisticsImportTask;
use App\Models\LogisticsImportTaskRecord;
use App\Models\Order;
use App\Models\User;
use App\Services\LogisticsImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Queue::fake();
        Storage::fake('oss');

        Carrier::create([
            'carrier_name' => 'UPS',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);

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

    public function test_legacy_four_column_template_still_imports(): void
    {
        Queue::fake();
        Storage::fake('oss');

        Carrier::create([
            'carrier_name' => 'UPS',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);

        $order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);

        $legacy = "order_no,logistics_company,tracking_number,remark\nSSC-1,ups,1Z999,ok\n";

        $this->assertSame(1, $this->runImport($legacy)->success_count);
        $this->assertSame('1Z999', $order->fresh()->shipping->tracking_number);
    }

    /** 把 CSV 落到 OSS 并跑完一个导入任务，返回刷新后的任务。 */
    private function runImport(string $csv): LogisticsImportTask
    {
        Storage::disk('oss')->put('imports/test.csv', $csv);

        $operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => bcrypt('secret'),
            'merchant_id' => $this->merchant->id,
        ]);

        $task = LogisticsImportTask::query()->withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'operator_id' => $operator->id,
            'file_name' => 'test.csv',
            'oss_path' => 'imports/test.csv',
            'status' => 'pending',
        ]);

        app(LogisticsImportService::class)->processImport($task->id);

        return $task->refresh();
    }

    /** 模拟商户在导出的模板里按列名填写，其余列原样不动。 */
    private function fillColumns(string $csv, array $values): string
    {
        $parsed = $this->parseCsv($csv);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $parsed['header']);

        foreach ($parsed['rows'] as $row) {
            fputcsv($out, array_values(array_merge($row, $values)));
        }

        rewind($out);
        $filled = stream_get_contents($out);
        fclose($out);

        return $filled;
    }

    /** @return array{header: array<int, string>, rows: array<int, array<string, string>>} */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, str_replace("\xEF\xBB\xBF", '', $csv));
        rewind($handle);

        $header = fgetcsv($handle);
        $rows = [];

        while (($cells = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $cells);
        }

        fclose($handle);

        return ['header' => $header, 'rows' => $rows];
    }
}
