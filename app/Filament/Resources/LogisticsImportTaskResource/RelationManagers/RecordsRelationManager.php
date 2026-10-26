<?php

namespace App\Filament\Resources\LogisticsImportTaskResource\RelationManagers;

use App\Filament\Resources\OrderResource;
use App\Models\LogisticsImportTaskRecord;
use App\Support\Permissions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 逐行解析记录
 *
 * 只读：物流数据的写入入口统一是 OrderShippingService::record()，这里不能改也不能删
 * ——否则台账和实际发货记录会对不上。
 *
 * 必须分页（默认 50）：一次导入可能上万行，不做分页会直接把详情页拖死。
 */
class RecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'records';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.logistics_import_task_record.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_no')
            ->columns([
                TextColumn::make('row_number')
                    ->label(__('admin.logistics_import_task_record.fields.row_number'))
                    ->numeric()
                    ->sortable(),

                // order_id 为空说明这一行根本没匹配到订单，自然没有可跳转的详情页。
                TextColumn::make('order_no')
                    ->label(__('admin.logistics_import_task_record.fields.order_no'))
                    ->searchable()
                    ->copyable()
                    ->url(fn (LogisticsImportTaskRecord $record): ?string => filled($record->order_id)
                        && auth()->user()?->can(Permissions::ORDERS_VIEW)
                            ? OrderResource::getUrl('view', ['record' => $record->order_id])
                            : null)
                    ->openUrlInNewTab(),

                TextColumn::make('logistics_company')
                    ->label(__('admin.logistics_import_task_record.fields.logistics_company'))
                    ->placeholder(__('admin.logistics_import_task_record.placeholders.none')),

                TextColumn::make('tracking_number')
                    ->label(__('admin.logistics_import_task_record.fields.tracking_number'))
                    ->placeholder(__('admin.logistics_import_task_record.placeholders.none'))
                    ->copyable(),

                // 存的是文件里的原文，不是时间类型：解析失败的行也要能看到商户当时填了什么。
                TextColumn::make('shipped_at')
                    ->label(__('admin.logistics_import_task_record.fields.shipped_at'))
                    ->placeholder(__('admin.logistics_import_task_record.placeholders.none')),

                TextColumn::make('status')
                    ->label(__('admin.logistics_import_task_record.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.logistics_import_task_record.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        LogisticsImportTaskRecord::STATUS_SUCCESS => 'success',
                        LogisticsImportTaskRecord::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('error_message')
                    ->label(__('admin.logistics_import_task_record.fields.error_message'))
                    ->placeholder(__('admin.logistics_import_task_record.placeholders.none'))
                    ->wrap(),

                // 核对用：商户到底填了什么。默认隐藏，避免表格过宽。
                TextColumn::make('raw_data')
                    ->label(__('admin.logistics_import_task_record.fields.raw_data'))
                    ->formatStateUsing(fn ($state): string => is_array($state)
                        ? collect($state)->map(fn ($value, $key): string => $key.': '.(string) $value)->implode(' | ')
                        : (string) $state)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.logistics_import_task_record.filters.status'))
                    ->multiple()
                    ->options(fn (): array => collect(LogisticsImportTaskRecord::STATUSES)
                        ->mapWithKeys(fn (string $status): array => [
                            $status => __('admin.logistics_import_task_record.statuses.'.$status),
                        ])
                        ->all()),
            ])
            ->defaultSort('row_number')
            ->defaultPaginationPageOption(50)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }

    /** 明细行不允许从界面删除：删了之后任务的成功/失败汇总数就对不上了。 */
    public function canDeleteAny(): bool
    {
        return false;
    }

    public function canDelete(Model $record): bool
    {
        return false;
    }

    public function canEdit(Model $record): bool
    {
        return false;
    }
}
