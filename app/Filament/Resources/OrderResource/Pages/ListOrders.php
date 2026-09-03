<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Jobs\ProcessLogisticsImportJob;
use App\Models\LogisticsImportTask;
use App\Services\LogisticsImportService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportLogisticsTemplate')
                ->label(__('admin.order.actions.export_logistics_template'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => auth()->user()->can(Permissions::LOGISTICS_IMPORTS_MANAGE))
                ->schema([
                    DatePicker::make('date_from')->label(__('admin.order.actions.date_from')),
                    DatePicker::make('date_to')->label(__('admin.order.actions.date_to')),
                ])
                ->action(function (array $data, LogisticsImportService $service) {
                    $csv = $service->generateTemplate(auth()->user()->merchant_id, $data);

                    return response()->streamDownload(
                        fn () => print ($csv),
                        'logistics_template_'.now()->format('Ymd_His').'.csv'
                    );
                }),

            Action::make('uploadLogistics')
                ->label(__('admin.order.actions.upload_logistics'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => auth()->user()->can(Permissions::LOGISTICS_IMPORTS_MANAGE))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('admin.order.actions.csv_file'))
                        ->acceptedFileTypes(['text/csv', 'text/plain'])
                        ->required()
                        ->disk('local')
                        ->directory('logistics-imports-tmp'),
                ])
                ->action(function (array $data) {
                    $merchantId = auth()->user()->merchant_id;
                    $localTmpPath = $data['file'];

                    $ossPath = "merchants/{$merchantId}/logistics_imports/".now()->format('Y-m-d')."/{$localTmpPath}";
                    Storage::disk('oss')->put($ossPath, Storage::disk('local')->get($localTmpPath));
                    Storage::disk('local')->delete($localTmpPath);

                    $task = LogisticsImportTask::create([
                        'merchant_id' => $merchantId,
                        'operator_id' => auth()->id(),
                        'file_name' => basename($localTmpPath),
                        'oss_path' => $ossPath,
                        'status' => 'pending',
                    ]);

                    ProcessLogisticsImportJob::dispatch($task->id);

                    Notification::make()
                        ->title(__('admin.order.actions.upload_success'))
                        ->success()
                        ->send();
                }),

            Action::make('createManualOrder')
                ->label(__('admin.order.actions.create_manual_order'))
                ->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()->can(Permissions::ORDERS_CREATE_MANUAL))
                ->url(fn () => OrderResource::getUrl('create-manual')),
        ];
    }
}
