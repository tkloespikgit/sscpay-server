<?php

namespace Tests\Feature;

use App\Events\LogisticsImportCompleted;
use App\Events\OrderStatusChanged;
use App\Jobs\RegisterLogisticsTrackingJob;
use App\Jobs\SyncOrderTrackingJob;
use App\Models\Carrier;
use App\Models\LogisticsImportTask;
use App\Models\Merchant;
use App\Models\OrderShipping;
use App\Models\User;
use App\Services\LogisticsImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class LogisticsOrderIdentifierImportTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    #[TestWith(['csv', 'order_no'])]
    #[TestWith(['csv', 'merchant_order_no'])]
    #[TestWith(['xlsx', 'order_no'])]
    #[TestWith(['xlsx', 'merchant_order_no'])]
    #[TestWith(['xls', 'order_no'])]
    #[TestWith(['xls', 'merchant_order_no'])]
    public function test_three_column_files_import_with_either_order_identifier(string $extension, string $identifier): void
    {
        $this->freezeTime();
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-MINIMAL', '00'.str_repeat('1234567890', 7), ['status' => 'paid']);
        $trackingNumber = '001234567890123456789012345678';

        $task = $this->importRows([
            [$identifier, 'logistics_company', 'tracking_number'],
            [$order->{$identifier}, 'ups', $trackingNumber],
        ], $extension, $operator);

        $this->assertSame(1, $task->success_count);
        $this->assertSame(0, $task->fail_count);
        $record = $task->records()->sole();
        $this->assertSame($order->id, $record->order_id);
        $this->assertSame($order->{$identifier}, $record->{$identifier});
        $this->assertDatabaseHas('order_shippings', [
            'order_id' => $order->id,
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => $trackingNumber,
            'shipped_at' => $task->created_at->format('Y-m-d H:i:s'),
        ]);
        Queue::assertPushed(SyncOrderTrackingJob::class, 1);
        Queue::assertPushed(RegisterLogisticsTrackingJob::class, 1);
        Event::assertDispatched(LogisticsImportCompleted::class, 1);
    }

    #[TestWith(['merchant order no'])]
    #[TestWith(['商户订单号'])]
    #[TestWith(['商户单号'])]
    public function test_merchant_order_number_header_aliases_import(string $header): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-ALIAS', 'M-ALIAS', ['status' => 'paid']);

        $task = $this->importRows([
            [$header, 'logistics_company', 'tracking_number'],
            ['M-ALIAS', 'ups', 'ALIAS-TRACK'],
        ], 'csv', $operator);

        $this->assertSame(1, $task->success_count);
        $this->assertSame('M-ALIAS', $task->records()->sole()->merchant_order_no);
        $this->assertSame('ALIAS-TRACK', $order->fresh()->shipping->tracking_number);
        Queue::assertPushed(SyncOrderTrackingJob::class, 1);
    }

    public function test_hash_prefixed_merchant_order_numbers_are_imported_while_note_rows_are_skipped(): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-HASH', '#1001', ['status' => 'paid']);
        $spacedOrder = $this->makeOrder('SSC-HASH-SPACE', '# 1002', ['status' => 'paid']);

        $task = $this->importRows([
            ['merchant_order_no', 'logistics_company', 'tracking_number'],
            ['# 请填写物流信息', '', ''],
            ['#1001', 'ups', 'HASH-TRACK'],
            ['# 1002', 'ups', 'HASH-SPACE-TRACK'],
        ], 'csv', $operator);

        $this->assertSame(2, $task->total_records);
        $this->assertSame(2, $task->success_count);
        $this->assertSame('HASH-TRACK', $order->fresh()->shipping->tracking_number);
        $this->assertSame('HASH-SPACE-TRACK', $spacedOrder->fresh()->shipping->tracking_number);
        Queue::assertPushed(SyncOrderTrackingJob::class, 2);
    }

    public function test_rows_can_use_either_identifier_or_both_matching_identifiers(): void
    {
        $operator = $this->prepareImport();
        $systemOrder = $this->makeOrder('SSC-SYSTEM', 'M-SYSTEM', ['status' => 'paid']);
        $merchantOrder = $this->makeOrder('SSC-MERCHANT', 'M-MERCHANT', ['status' => 'paid']);
        $bothOrder = $this->makeOrder('SSC-BOTH', 'M-BOTH', ['status' => 'paid']);

        $task = $this->importRows([
            ['order_no', 'merchant_order_no', 'logistics_company', 'tracking_number'],
            ['SSC-SYSTEM', '', 'ups', 'SYSTEM-TRACK'],
            ['', 'M-MERCHANT', 'ups', 'MERCHANT-TRACK'],
            ['SSC-BOTH', 'M-BOTH', 'ups', 'BOTH-TRACK'],
        ], 'csv', $operator);

        $this->assertSame(3, $task->success_count);
        $this->assertSame(0, $task->fail_count);
        $this->assertSame([$systemOrder->id, $merchantOrder->id, $bothOrder->id], $task->records()->orderBy('row_number')->pluck('order_id')->all());
        $this->assertSame('SYSTEM-TRACK', $systemOrder->fresh()->shipping->tracking_number);
        $this->assertSame('MERCHANT-TRACK', $merchantOrder->fresh()->shipping->tracking_number);
        $this->assertSame('BOTH-TRACK', $bothOrder->fresh()->shipping->tracking_number);
        Queue::assertPushed(SyncOrderTrackingJob::class, 3);
    }

    #[TestWith(['SSC-A', 'M-B', '对应同一订单'])]
    #[TestWith(['MISSING', 'M-A', '对应同一订单'])]
    #[TestWith(['SSC-A', 'MISSING', '对应同一订单'])]
    #[TestWith(['', '', '至少一项'])]
    public function test_missing_or_conflicting_order_identifiers_fail_without_shipping(string $orderNo, string $merchantOrderNo, string $error): void
    {
        $operator = $this->prepareImport();
        $this->makeOrder('SSC-A', 'M-A', ['status' => 'paid']);
        $this->makeOrder('SSC-B', 'M-B', ['status' => 'paid']);

        $task = $this->importRows([
            ['order_no', 'merchant_order_no', 'logistics_company', 'tracking_number'],
            [$orderNo, $merchantOrderNo, 'ups', 'INVALID-TRACK'],
        ], 'csv', $operator);

        $this->assertSame(0, $task->success_count);
        $this->assertSame(1, $task->fail_count);
        $this->assertStringContainsString($error, $task->records()->sole()->error_message);
        $this->assertDatabaseCount('order_shippings', 0);
        Queue::assertNothingPushed();
    }

    public function test_overlong_merchant_order_numbers_are_rejected_without_matching_a_truncated_prefix(): void
    {
        $operator = $this->prepareImport();
        $merchantOrderNo = str_repeat('M', 80);
        $this->makeOrder('SSC-LONG', $merchantOrderNo, ['status' => 'paid']);

        $task = $this->importRows([
            ['merchant_order_no', 'logistics_company', 'tracking_number'],
            [$merchantOrderNo.'X', 'ups', 'LONG-TRACK'],
        ], 'csv', $operator);

        $this->assertSame(1, $task->fail_count);
        $record = $task->records()->sole();
        $this->assertSame($merchantOrderNo.'X', $record->merchant_order_no);
        $this->assertStringContainsString('80', $record->error_message);
        $this->assertDatabaseCount('order_shippings', 0);
        Queue::assertNothingPushed();
    }

    public function test_merchant_order_matching_stays_within_the_uploading_merchant(): void
    {
        $operator = $this->prepareImport();
        $otherMerchant = Merchant::create(['name' => 'Other merchant']);
        $ownOrder = $this->makeOrder('SSC-OWN', 'M-SHARED', ['status' => 'paid']);
        $otherOrder = $this->makeOrder('SSC-OTHER', 'M-SHARED', ['status' => 'paid', 'merchant_id' => $otherMerchant->id]);
        $otherOnlyOrder = $this->makeOrder('SSC-OTHER-ONLY', 'M-OTHER-ONLY', ['status' => 'paid', 'merchant_id' => $otherMerchant->id]);

        $task = $this->importRows([
            ['merchant_order_no', 'logistics_company', 'tracking_number', 'merchant_id'],
            ['M-SHARED', 'ups', 'OWN-TRACK', (string) $otherMerchant->id],
            ['M-OTHER-ONLY', 'ups', 'OTHER-TRACK', (string) $otherMerchant->id],
        ], 'csv', $operator);

        $this->assertSame(1, $task->success_count);
        $this->assertSame(1, $task->fail_count);
        $this->assertSame('OWN-TRACK', $ownOrder->fresh()->shipping->tracking_number);
        $this->assertNull($otherOrder->fresh()->shipping);
        $this->assertNull($otherOnlyOrder->fresh()->shipping);
        $this->assertSame('订单不存在或不属于可录入物流的未发货订单', $task->records()->where('row_number', 3)->sole()->error_message);
        Queue::assertPushed(SyncOrderTrackingJob::class, 1);
    }

    #[TestWith(['pending', false])]
    #[TestWith(['refunded', false])]
    #[TestWith(['paid', true])]
    public function test_merchant_order_numbers_do_not_bypass_shipping_eligibility(string $status, bool $hasShipping): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-STATE', 'M-STATE', ['status' => $status]);

        if ($hasShipping) {
            OrderShipping::create([
                'order_id' => $order->id,
                'merchant_id' => $this->merchant->id,
                'logistics_company' => 'ups',
                'tracking_number' => 'EXISTING-TRACK',
                'shipped_at' => now(),
                'operator_id' => $operator->id,
            ]);
        }

        $task = $this->importRows([
            ['merchant_order_no', 'logistics_company', 'tracking_number'],
            ['M-STATE', 'ups', 'REPLACEMENT-TRACK'],
        ], 'csv', $operator);

        $this->assertSame(1, $task->fail_count);
        $this->assertSame(0, $task->success_count);
        $this->assertDatabaseMissing('order_shippings', ['tracking_number' => 'REPLACEMENT-TRACK']);

        if ($hasShipping) {
            $this->assertSame('EXISTING-TRACK', $order->fresh()->shipping->tracking_number);
        }

        Queue::assertNothingPushed();
    }

    public function test_failed_rows_preserve_merchant_order_numbers_and_can_be_corrected_and_reuploaded(): void
    {
        $operator = $this->prepareImport();
        $order = $this->makeOrder('SSC-RETRY', 'M-RETRY', ['status' => 'paid']);
        $task = $this->importRows([
            ['merchant_order_no', 'logistics_company', 'tracking_number'],
            ['M-RETRY', 'unknown-carrier', 'RETRY-TRACK'],
        ], 'csv', $operator);
        $service = app(LogisticsImportService::class);

        $csv = $service->failedRowsCsv($task);

        $this->assertSame(1, $task->fail_count);
        $this->assertSame('M-RETRY', $task->records()->sole()->merchant_order_no);
        $this->assertSame('M-RETRY', $task->error_log[0]['merchant_order_no']);
        $this->assertStringContainsString('merchant_order_no', $csv);
        $this->assertStringContainsString('M-RETRY', $csv);
        $this->assertNull($order->fresh()->shipping);
        Queue::assertNothingPushed();

        $retry = $this->importContents(str_replace('unknown-carrier', 'ups', $csv), 'csv', $operator);

        $this->assertSame(1, $retry->success_count);
        $this->assertSame(0, $retry->fail_count);
        $this->assertSame('RETRY-TRACK', $order->fresh()->shipping->tracking_number);
        Queue::assertPushed(SyncOrderTrackingJob::class, 1);
    }

    private function prepareImport(): User
    {
        $this->createMerchantAndApplication();
        Carrier::create(['carrier_name' => 'UPS', 'carrier_code' => 'ups', 'status' => Carrier::STATUS_ENABLED]);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        Storage::fake('oss');
        Queue::fake([SyncOrderTrackingJob::class, RegisterLogisticsTrackingJob::class]);
        Event::fake([LogisticsImportCompleted::class, OrderStatusChanged::class]);

        return $operator;
    }

    /** @param array<int, array<int, string>> $rows */
    private function importRows(array $rows, string $extension, User $operator): LogisticsImportTask
    {
        $path = tempnam(sys_get_temp_dir(), 'logistics_identifier_test_');

        if ($path === false) {
            $this->fail('无法创建物流导入测试文件');
        }

        try {
            if ($extension === 'csv') {
                $handle = fopen($path, 'w');

                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            } else {
                $workbook = new Spreadsheet;

                try {
                    foreach ($rows as $rowIndex => $row) {
                        foreach ($row as $columnIndex => $value) {
                            $workbook->getActiveSheet()->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
                        }
                    }

                    IOFactory::createWriter($workbook, $extension === 'xlsx' ? 'Xlsx' : 'Xls')->save($path);
                } finally {
                    $workbook->disconnectWorksheets();
                }
            }

            return $this->importContents((string) file_get_contents($path), $extension, $operator);
        } finally {
            unlink($path);
        }
    }

    private function importContents(string $contents, string $extension, User $operator): LogisticsImportTask
    {
        $path = 'imports/identifier.'.$extension;
        Storage::disk('oss')->put($path, $contents);
        $task = LogisticsImportTask::query()->withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'operator_id' => $operator->id,
            'file_name' => 'identifier.'.$extension,
            'oss_path' => $path,
            'status' => LogisticsImportTask::STATUS_PENDING,
        ]);

        app(LogisticsImportService::class)->processImport($task->id);

        return $task->refresh();
    }
}
