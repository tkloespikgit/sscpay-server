<?php

namespace Tests\Feature;

use App\Models\LogisticsImportTask;
use App\Models\LogisticsImportTaskRecord;
use App\Models\Merchant;
use App\Filament\Resources\LogisticsImportTaskResource\Pages\ViewLogisticsImportTask;
use App\Filament\Resources\LogisticsImportTaskResource\RelationManagers\RecordsRelationManager;
use App\Models\User;
use App\Services\LogisticsImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 物流上传记录（列表 + 详情 + 失败行导出 + 90 天清理）。
 *
 * 重点覆盖三类容易回归的点：
 *   1. 原始文件名：新数据落 original_file_name，历史数据回退到保存名；
 *   2. 解析状态：started_at/finished_at 必须写，卡死判定不能误伤正在跑的任务；
 *   3. 清理命令：只删过期任务，明细要跟着走，试运行不能真删。
 */
class LogisticsImportTaskLogTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 导入会投递插件同步 Job 与通知 Job，两者都不该在测试里真的跑
        Queue::fake();
        Storage::fake('oss');

        $this->createMerchantAndApplication();
    }

    public function test_new_task_keeps_original_file_name_and_displays_it(): void
    {
        $task = $this->makeTask(['original_file_name' => '我的物流单.csv', 'file_name' => '01M5GRAZ.csv']);

        $this->assertSame('我的物流单.csv', $task->displayFileName());
        $this->assertFalse($task->hasLegacyFileName());
    }

    public function test_legacy_task_falls_back_to_stored_file_name(): void
    {
        $task = $this->makeTask(['original_file_name' => null, 'file_name' => '01M5GRAZ.csv']);

        $this->assertSame('01M5GRAZ.csv', $task->displayFileName());
        $this->assertTrue($task->hasLegacyFileName());
    }

    public function test_process_import_records_start_and_finish_time(): void
    {
        Storage::disk('oss')->put('imports/test.csv', "order_no,logistics_company,tracking_number\nNOT-EXIST,ups,1Z999\n");

        $task = $this->makeTask(['oss_path' => 'imports/test.csv']);

        app(LogisticsImportService::class)->processImport($task->id);

        $task->refresh();

        $this->assertSame(LogisticsImportTask::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->finished_at);
        $this->assertSame(1, $task->total_records);
        $this->assertSame(1, $task->fail_count);
        $this->assertFalse($task->isStale());
    }

    public function test_file_level_failure_still_writes_finish_time(): void
    {
        // 缺 order_no 列 -> 文件级失败，一条明细都落不了库
        Storage::disk('oss')->put('imports/bad.csv', "foo,bar\n1,2\n");

        $task = $this->makeTask(['oss_path' => 'imports/bad.csv']);

        app(LogisticsImportService::class)->processImport($task->id);

        $task->refresh();

        $this->assertSame(LogisticsImportTask::STATUS_FAILED, $task->status);
        $this->assertNotNull($task->finished_at);
        $this->assertSame(0, $task->records()->count());
    }

    public function test_processing_task_stuck_over_thirty_minutes_is_stale(): void
    {
        $task = $this->makeTask(['status' => LogisticsImportTask::STATUS_PROCESSING]);
        $task->forceFill(['started_at' => now()->subMinutes(31)])->save();

        $this->assertTrue($task->fresh()->isStale());

        // 刚开跑 5 分钟的任务不能被标成中断
        $fresh = $this->makeTask(['status' => LogisticsImportTask::STATUS_PROCESSING]);
        $fresh->forceFill(['started_at' => now()->subMinutes(5)])->save();

        $this->assertFalse($fresh->fresh()->isStale());
    }

    public function test_completed_task_is_never_stale(): void
    {
        $task = $this->makeTask(['status' => LogisticsImportTask::STATUS_COMPLETED]);
        $task->forceFill(['started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)])->save();

        $this->assertFalse($task->fresh()->isStale());
    }

    public function test_failed_rows_csv_only_exports_failed_rows(): void
    {
        $task = $this->makeTask([
            'status' => LogisticsImportTask::STATUS_COMPLETED,
            'total_records' => 2,
            'success_count' => 1,
            'fail_count' => 1,
        ]);

        $this->makeRecord($task, 2, 'SSC-OK', LogisticsImportTaskRecord::STATUS_SUCCESS);
        $this->makeRecord($task, 3, 'SSC-BAD', LogisticsImportTaskRecord::STATUS_FAILED, '订单不存在或不是待发货状态');

        $csv = app(LogisticsImportService::class)->failedRowsCsv($task);

        // Excel 双击打开需要 BOM，否则中文会乱码
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $rows = $this->parseCsv($csv);

        $this->assertSame(LogisticsImportService::FAILED_EXPORT_HEADERS, $rows[0]);
        $this->assertCount(2, $rows, '表头 + 1 条失败行');
        $this->assertSame('3', $rows[1][0]);
        $this->assertSame('SSC-BAD', $rows[1][1]);
        $this->assertSame('订单不存在或不是待发货状态', $rows[1][7]);

        // 成功行绝对不能出现：商户会照着导出的文件重传，把成功行带上去等于重复导入
        $this->assertStringNotContainsString('SSC-OK', $csv);
    }

    public function test_prune_removes_tasks_beyond_retention_with_records(): void
    {
        $old = $this->makeTask();
        $old->forceFill(['created_at' => now()->subDays(100)])->save();
        $this->makeRecord($old, 2, 'SSC-OLD', LogisticsImportTaskRecord::STATUS_FAILED);

        $recent = $this->makeTask();
        $recent->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->makeRecord($recent, 2, 'SSC-NEW', LogisticsImportTaskRecord::STATUS_SUCCESS);

        $this->artisan('logistics-imports:prune --days=90')->assertExitCode(0);

        $this->assertDatabaseMissing('logistics_import_tasks', ['id' => $old->id]);
        $this->assertDatabaseMissing('logistics_import_task_records', ['task_id' => $old->id]);
        $this->assertDatabaseHas('logistics_import_tasks', ['id' => $recent->id]);
        $this->assertDatabaseHas('logistics_import_task_records', ['task_id' => $recent->id]);
    }

    public function test_prune_also_removes_soft_deleted_tasks(): void
    {
        $task = $this->makeTask();
        $task->forceFill(['created_at' => now()->subDays(120)])->save();
        $task->delete();

        $this->artisan('logistics-imports:prune --days=90')->assertExitCode(0);

        $this->assertDatabaseMissing('logistics_import_tasks', ['id' => $task->id]);
    }

    public function test_prune_dry_run_deletes_nothing(): void
    {
        $task = $this->makeTask();
        $task->forceFill(['created_at' => now()->subDays(100)])->save();

        $this->artisan('logistics-imports:prune --days=90 --dry-run')->assertExitCode(0);

        $this->assertDatabaseHas('logistics_import_tasks', ['id' => $task->id]);
    }

    public function test_prune_rejects_out_of_range_days(): void
    {
        $this->artisan('logistics-imports:prune --days=0')->assertExitCode(2);
    }

    public function test_merchant_user_only_sees_own_merchant_tasks(): void
    {
        $otherMerchant = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other',
            'contact_phone' => '654321',
            'contact_email' => 'other@example.com',
        ]);

        $otherTask = LogisticsImportTask::query()->withoutGlobalScopes()->create([
            'merchant_id' => $otherMerchant->id,
            'operator_id' => $this->makeOperator()->id,
            'file_name' => 'other.csv',
            'oss_path' => 'imports/other.csv',
            'status' => LogisticsImportTask::STATUS_COMPLETED,
        ]);

        $ownTask = $this->makeTask();

        $this->actingAs($this->makeOperator());

        // MerchantScope 只在已登录 User 时生效，这里正是要验证它的隔离效果
        $ids = LogisticsImportTask::query()->pluck('id')->all();

        $this->assertContains($ownTask->id, $ids);
        $this->assertNotContains($otherTask->id, $ids);
    }

    public function test_super_admin_sees_all_merchants_tasks(): void
    {
        $otherMerchant = Merchant::create([
            'name' => 'Other Merchant 2',
            'contact_person' => 'Other',
            'contact_phone' => '111222',
            'contact_email' => 'other2@example.com',
        ]);

        $otherTask = LogisticsImportTask::query()->withoutGlobalScopes()->create([
            'merchant_id' => $otherMerchant->id,
            'operator_id' => $this->makeOperator()->id,
            'file_name' => 'other.csv',
            'oss_path' => 'imports/other.csv',
            'status' => LogisticsImportTask::STATUS_COMPLETED,
        ]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin);

        $this->assertContains($otherTask->id, LogisticsImportTask::query()->pluck('id')->all());
    }

    public function test_list_and_view_pages_render_for_super_admin(): void
    {
        $task = $this->makeTask(['original_file_name' => '我的物流单.csv']);
        $task->forceFill(['total_records' => 2, 'success_count' => 1, 'fail_count' => 1])->save();
        $this->makeRecord($task, 2, 'SSC-1', LogisticsImportTaskRecord::STATUS_FAILED, '订单不存在');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-page@example.com',
            'password' => bcrypt('secret'),
            'is_super_admin' => true,
            // status 在 DB 里有默认值 1，但模型实例上不会自动回填，
            // 不显式传会让 canAccessPanel() 读到 null 从而返回 false。
            'status' => true,
        ]);

        $this->actingAs($admin);

        // 真正渲染一次：表格列（含自定义 state）、筛选、infolist 与 RelationManager
        // 的 API 一旦用错，这里会直接抛异常，比只测模型能更早暴露问题。
        $this->get('/admin/logistics-import-tasks')
            ->assertSuccessful()
            // 列表展示的是商户上传时的原名，不是随机保存名
            ->assertSee('我的物流单.csv')
            ->assertSee('部分成功');

        $this->get('/admin/logistics-import-tasks/'.$task->id)
            ->assertSuccessful()
            ->assertSee('我的物流单.csv');

        // 明细表在详情页里是独立 Livewire 组件（首屏只渲染占位），单独挂载一次才能
        // 真正验证列定义：行号、单号、状态 badge、失败原因、原始行数据。
        Livewire::test(RecordsRelationManager::class, [
            'ownerRecord' => $task,
            'pageClass' => ViewLogisticsImportTask::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($task->records()->get())
            ->assertSee('订单不存在');
    }

    private function makeTask(array $overrides = []): LogisticsImportTask
    {
        return LogisticsImportTask::query()->withoutGlobalScopes()->create(array_merge([
            'merchant_id' => $this->merchant->id,
            'operator_id' => $this->makeOperator()->id,
            'file_name' => '01M5GRAZ.csv',
            'oss_path' => 'imports/test.csv',
            'status' => LogisticsImportTask::STATUS_COMPLETED,
        ], $overrides));
    }

    private function makeRecord(
        LogisticsImportTask $task,
        int $rowNumber,
        string $orderNo,
        string $status,
        ?string $errorMessage = null,
    ): LogisticsImportTaskRecord {
        return LogisticsImportTaskRecord::query()->withoutGlobalScopes()->create([
            'task_id' => $task->id,
            'merchant_id' => $task->merchant_id,
            'row_number' => $rowNumber,
            'order_no' => $orderNo,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z999',
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    private function makeOperator(): User
    {
        return User::firstOrCreate(
            ['email' => 'operator@example.com'],
            ['name' => 'Operator', 'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id],
        );
    }

    /**
     * 解析导出的 CSV（去掉 BOM），返回二维数组，第 0 行是表头。
     *
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, str_replace("\xEF\xBB\xBF", '', $csv));
        rewind($handle);

        $rows = [];

        while (($cells = fgetcsv($handle)) !== false) {
            $rows[] = $cells;
        }

        fclose($handle);

        return $rows;
    }
}
