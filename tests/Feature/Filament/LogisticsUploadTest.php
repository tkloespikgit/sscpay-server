<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Jobs\ProcessLogisticsImportJob;
use App\Models\LogisticsImportTask;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class LogisticsUploadTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('merchant');
    }

    #[TestWith(['csv'])]
    #[TestWith(['txt'])]
    #[TestWith(['xlsx'])]
    #[TestWith(['xls'])]
    #[TestWith(['xls', null, 1500])]
    #[TestWith(['xls', 'application/x-ole-storage'])]
    #[TestWith(['xls', 'application/vnd.ms-office'])]
    #[TestWith(['xls', 'application/msexcel'])]
    #[TestWith(['xls', 'application/x-msexcel'])]
    public function test_upload_stores_the_file_and_queues_a_task_for_the_current_merchant(string $extension, ?string $mimeType = null, int $rowCount = 1): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::LOGISTICS_IMPORTS_MANAGE]);
        $this->actingAs($user);
        Storage::fake('local');
        Storage::fake('oss');
        Queue::fake([ProcessLogisticsImportJob::class]);
        Http::preventStrayRequests();
        $content = $this->fileContent($extension, $rowCount);
        $file = UploadedFile::fake()->createWithContent('logistics.'.$extension, $content);
        // Livewire 仅检测前 64 KiB，大 XLS 可能因此被识别为通用 OLE 容器。
        $file->mimeType($mimeType ?? (new \finfo(FILEINFO_MIME_TYPE))->buffer(substr($content, 0, 64 * 1024)));

        Livewire::test(ListOrders::class)
            ->callAction('uploadLogistics', ['file' => $file])
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.order.actions.upload_success'));

        $task = LogisticsImportTask::query()->withoutGlobalScopes()->sole();
        $this->assertDatabaseHas('logistics_import_tasks', [
            'id' => $task->id,
            'merchant_id' => $this->merchant->id,
            'operator_id' => $user->id,
            'status' => 'pending',
        ]);
        $this->assertStringEndsWith('.'.$extension, $task->file_name);
        $this->assertNotSame('logistics.'.$extension, $task->file_name);
        Storage::disk('oss')->assertExists($task->oss_path);
        $this->assertSame($content, Storage::disk('oss')->get($task->oss_path));
        Storage::disk('local')->assertMissing('logistics-imports-tmp/'.$task->file_name);
        Queue::assertPushed(ProcessLogisticsImportJob::class, fn (ProcessLogisticsImportJob $job): bool => $job->taskId === $task->id && $job->queue === 'low');
    }

    #[TestWith(['logistics.png'])]
    #[TestWith(['logistics.xls'])]
    public function test_upload_rejects_an_unsupported_file_without_creating_a_task(string $fileName): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::LOGISTICS_IMPORTS_MANAGE]);
        $this->actingAs($user);
        Storage::fake('local');
        Storage::fake('oss');
        Queue::fake();
        $file = UploadedFile::fake()->image($fileName)->mimeType('image/png');

        Livewire::test(ListOrders::class)
            ->callAction('uploadLogistics', ['file' => $file])
            ->assertHasActionErrors(['file']);

        $this->assertDatabaseCount('logistics_import_tasks', 0);
        $this->assertSame([], Storage::disk('oss')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_upload_rejects_an_ole_file_with_an_unsupported_extension(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::LOGISTICS_IMPORTS_MANAGE]);
        $this->actingAs($user);
        Storage::fake('local');
        Storage::fake('oss');
        Queue::fake();
        $file = UploadedFile::fake()->createWithContent('logistics.doc', $this->fileContent('xls'))
            ->mimeType('application/x-ole-storage');

        Livewire::test(ListOrders::class)
            ->callAction('uploadLogistics', ['file' => $file])
            ->assertHasActionErrors(['file']);

        $this->assertDatabaseCount('logistics_import_tasks', 0);
        $this->assertSame([], Storage::disk('oss')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_upload_is_hidden_and_cannot_be_mounted_without_import_permission(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $user->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->actingAs($user);
        Queue::fake();

        Livewire::test(ListOrders::class)
            ->assertActionHidden('uploadLogistics')
            ->mountAction('uploadLogistics')
            ->assertActionNotMounted();

        $this->assertDatabaseCount('logistics_import_tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_super_admin_cannot_mount_the_upload_action(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Queue::fake();

        Livewire::test(ListOrders::class)
            ->assertActionHidden('uploadLogistics')
            ->mountAction('uploadLogistics')
            ->assertActionNotMounted();

        $this->assertDatabaseCount('logistics_import_tasks', 0);
        Queue::assertNothingPushed();
    }

    private function fileContent(string $extension, int $rowCount = 1): string
    {
        if (in_array($extension, ['csv', 'txt'], true)) {
            return "order_no,logistics_company,tracking_number\nSSC-0001,ups,000012345678901234\n";
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['order_no', 'logistics_company', 'tracking_number']);

        for ($row = 2; $row <= $rowCount + 1; $row++) {
            $sheet->setCellValueExplicit('A'.$row, 'SSC-'.$row, DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$row, 'ups');
            $sheet->setCellValueExplicit('C'.$row, '000012345678901234', DataType::TYPE_STRING);
        }

        $temporaryFile = tmpfile();

        try {
            IOFactory::createWriter($spreadsheet, $extension === 'xlsx' ? 'Xlsx' : 'Xls')
                ->save(stream_get_meta_data($temporaryFile)['uri']);

            return file_get_contents(stream_get_meta_data($temporaryFile)['uri']);
        } finally {
            fclose($temporaryFile);
            $spreadsheet->disconnectWorksheets();
        }
    }
}
