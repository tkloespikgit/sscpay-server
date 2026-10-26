<?php

namespace App\Filament\Resources\LogisticsImportTaskResource\Pages;

use App\Filament\Resources\LogisticsImportTaskResource;
use App\Filament\Resources\LogisticsImportTaskResource\RelationManagers\RecordsRelationManager;
use App\Models\LogisticsImportTask;
use App\Services\LogisticsImportService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewLogisticsImportTask extends ViewRecord
{
    protected static string $resource = LogisticsImportTaskResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.logistics_import_task.sections.summary'))->schema([
                Grid::make(3)->schema([
                    TextEntry::make('created_at')
                        ->label(__('admin.logistics_import_task.fields.created_at'))
                        ->dateTime(),

                    TextEntry::make('merchant.name')
                        ->label(__('admin.logistics_import_task.fields.merchant'))
                        ->placeholder(__('admin.logistics_import_task.placeholders.none')),

                    TextEntry::make('operator.name')
                        ->label(__('admin.logistics_import_task.fields.operator'))
                        ->placeholder(__('admin.logistics_import_task.placeholders.none')),

                    TextEntry::make('display_file_name')
                        ->label(__('admin.logistics_import_task.fields.original_file_name'))
                        ->state(fn (LogisticsImportTask $record): string => $record->displayFileName())
                        ->helperText(fn (LogisticsImportTask $record): ?string => $record->hasLegacyFileName()
                            ? __('admin.logistics_import_task.placeholders.legacy_file_name')
                            : null)
                        ->columnSpan(2),

                    TextEntry::make('file_name')
                        ->label(__('admin.logistics_import_task.fields.file_name'))
                        ->copyable(),

                    TextEntry::make('status')
                        ->label(__('admin.logistics_import_task.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state, LogisticsImportTask $record): string => LogisticsImportTaskResource::statusLabel($record))
                        ->color(fn (string $state, LogisticsImportTask $record): string => LogisticsImportTaskResource::statusColor($record)),

                    TextEntry::make('total_records')
                        ->label(__('admin.logistics_import_task.fields.total_records'))
                        ->numeric(),

                    TextEntry::make('success_count')
                        ->label(__('admin.logistics_import_task.fields.success_count'))
                        ->numeric(),

                    TextEntry::make('fail_count')
                        ->label(__('admin.logistics_import_task.fields.fail_count'))
                        ->numeric()
                        ->color(fn (LogisticsImportTask $record): string => $record->fail_count > 0 ? 'danger' : 'gray'),
                ]),
            ]),

            // 文件级失败（OSS 下载失败 / 表头缺失等）只有一条错误，明细表里没有任何行，
            // 必须在这一层把原因显示出来，否则页面只剩"失败"两个字。
            Section::make(__('admin.logistics_import_task.sections.file_error'))
                ->visible(fn (LogisticsImportTask $record): bool => $record->status === LogisticsImportTask::STATUS_FAILED)
                ->schema([
                    TextEntry::make('file_error')
                        ->hiddenLabel()
                        ->state(fn (LogisticsImportTask $record): string => (string) (collect($record->error_log)->pluck('error')->filter()->first()
                            ?? __('admin.logistics_import_task.placeholders.none')))
                        ->columnSpanFull(),
                ]),

            // 卡死提示：worker 中断时任务会永远停在 processing，这里给出可操作的说明。
            Section::make(__('admin.logistics_import_task.sections.stale_hint'))
                ->visible(fn (LogisticsImportTask $record): bool => $record->isStale())
                ->schema([
                    TextEntry::make('stale_hint')
                        ->hiddenLabel()
                        ->state(fn (): string => __('admin.logistics_import_task.placeholders.stale_hint'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportFailedRowsAction(),
        ];
    }

    /**
     * 失败行导出。
     *
     * 导出的列就是商户需要修改的字段 + 失败原因（见
     * LogisticsImportService::FAILED_EXPORT_HEADERS），改完可直接重传——
     * 上传解析按表头名映射，不要求列顺序或列数与原模板一致。
     */
    private function exportFailedRowsAction(): Action
    {
        return Action::make('exportFailedRows')
            ->label(__('admin.logistics_import_task.actions.export_failed_rows'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (LogisticsImportTask $record): bool => $record->fail_count > 0)
            ->action(function (LogisticsImportTask $record, LogisticsImportService $service) {
                return response()->streamDownload(
                    fn () => print ($service->failedRowsCsv($record)),
                    'logistics_failed_'.$record->id.'_'.now()->format('Ymd_His').'.csv'
                );
            });
    }

    public function getRelationManagers(): array
    {
        return [
            RecordsRelationManager::class,
        ];
    }
}
