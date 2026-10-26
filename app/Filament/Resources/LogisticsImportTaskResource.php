<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogisticsImportTaskResource\Pages;
use App\Models\LogisticsImportTask;
use App\Support\Permissions;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 物流上传记录（只读）。
 *
 * 入口只放在订单列表页头一处（业务确认，不做导航菜单），所以
 * $shouldRegisterNavigation = false。页面本身仍然可以直接访问，
 * 因为权限仍走 canViewAny() 判断。
 *
 * 三类账号的可见范围：
 *   - 普通商户用户：MerchantScope 自动限制为本商户；
 *   - 商户级管理员：manageableMerchantIds() 覆盖名下商户；
 *   - 超级管理员：Scope 不介入，可看全部商户（业务确认允许只读查看）。
 *
 * 列表统计一律读任务表的汇总列，绝不 join 明细表做 count —— 一次导入
 * 可能上万行，详情页的明细也必须分页。
 */
class LogisticsImportTaskResource extends Resource
{
    protected static ?string $model = LogisticsImportTask::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-up';

    /**
     * 不注册导航项：入口唯一（订单列表页头的「物流上传记录」按钮）。
     * 注意这只是隐藏菜单，直接访问 URL 仍由 canViewAny() 把关。
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getModelLabel(): string
    {
        return __('admin.logistics_import_task.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.logistics_import_task.model_label_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.logistics_import_task.nav_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.logistics_import_task.fields.created_at'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('display_file_name')
                    ->label(__('admin.logistics_import_task.fields.original_file_name'))
                    ->state(fn (LogisticsImportTask $record): string => $record->displayFileName())
                    ->description(fn (LogisticsImportTask $record): ?string => $record->hasLegacyFileName()
                        ? __('admin.logistics_import_task.placeholders.legacy_file_name')
                        : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $inner) => $inner
                            ->where('logistics_import_tasks.original_file_name', 'like', "%{$search}%")
                            ->orWhere('logistics_import_tasks.file_name', 'like', "%{$search}%")
                    ))
                    ->wrap(),

                TextColumn::make('file_name')
                    ->label(__('admin.logistics_import_task.fields.file_name'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // 超管才需要知道是哪个商户上传的；商户账号看这一列全是自己，属于噪音。
                TextColumn::make('merchant.name')
                    ->label(__('admin.logistics_import_task.fields.merchant'))
                    ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),

                TextColumn::make('status')
                    ->label(__('admin.logistics_import_task.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, LogisticsImportTask $record): string => static::statusLabel($record))
                    ->color(fn (string $state, LogisticsImportTask $record): string => static::statusColor($record)),

                TextColumn::make('total_records')
                    ->label(__('admin.logistics_import_task.fields.total_records'))
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('success_count')
                    ->label(__('admin.logistics_import_task.fields.success_count'))
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('fail_count')
                    ->label(__('admin.logistics_import_task.fields.fail_count'))
                    ->numeric()
                    ->alignEnd()
                    ->color(fn (LogisticsImportTask $record): string => $record->fail_count > 0 ? 'danger' : 'gray'),

                TextColumn::make('operator.name')
                    ->label(__('admin.logistics_import_task.fields.operator'))
                    ->placeholder(__('admin.logistics_import_task.placeholders.none')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.logistics_import_task.filters.status'))
                    ->multiple()
                    ->options(fn (): array => collect(LogisticsImportTask::STATUSES)
                        ->mapWithKeys(fn (string $status): array => [
                            $status => __('admin.logistics_import_task.statuses.'.$status),
                        ])
                        ->all()),

                Filter::make('created_from')
                    ->label(__('admin.logistics_import_task.filters.created_from'))
                    ->schema([
                        DateTimePicker::make('created_from')
                            ->label(__('admin.logistics_import_task.filters.created_from'))
                            ->native(false)
                            ->seconds()
                            ->displayFormat('Y-m-d H:i:s')
                            ->timezone(config('app.timezone')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['created_from'] ?? null,
                        fn (Builder $q, string $date): Builder => $q->where('logistics_import_tasks.created_at', '>=', $date)
                    )),

                Filter::make('created_to')
                    ->label(__('admin.logistics_import_task.filters.created_to'))
                    ->schema([
                        DateTimePicker::make('created_to')
                            ->label(__('admin.logistics_import_task.filters.created_to'))
                            ->native(false)
                            ->seconds()
                            ->displayFormat('Y-m-d H:i:s')
                            ->timezone(config('app.timezone')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['created_to'] ?? null,
                        fn (Builder $q, string $date): Builder => $q->where('logistics_import_tasks.created_at', '<=', $date)
                    )),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25);
    }

    /**
     * 状态文案。两处特殊处理：
     *   1. completed + fail_count>0 → 「部分成功」：任务成功结束不代表全部行进去了，
     *      直接显示「完成」会让商户以为都导入成功；
     *   2. processing 且超过 30 分钟 → 「解析中断」：worker 挂掉后任务永远停在
     *      processing，不提示的话页面只会一直显示「解析中」。
     */
    public static function statusLabel(LogisticsImportTask $task): string
    {
        if ($task->isStale()) {
            return __('admin.logistics_import_task.statuses.stale');
        }

        if ($task->status === LogisticsImportTask::STATUS_COMPLETED && $task->fail_count > 0) {
            return $task->success_count > 0
                ? __('admin.logistics_import_task.statuses.partially_completed')
                : __('admin.logistics_import_task.statuses.all_failed');
        }

        return __('admin.logistics_import_task.statuses.'.$task->status);
    }

    public static function statusColor(LogisticsImportTask $task): string
    {
        if ($task->isStale()) {
            return 'danger';
        }

        return match ($task->status) {
            LogisticsImportTask::STATUS_PENDING => 'gray',
            LogisticsImportTask::STATUS_PROCESSING => 'info',
            LogisticsImportTask::STATUS_COMPLETED => $task->fail_count > 0 ? 'warning' : 'success',
            LogisticsImportTask::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogisticsImportTasks::route('/'),
            'view' => Pages\ViewLogisticsImportTask::route('/{record}'),
        ];
    }

    /**
     * 上传记录是系统产生的，不提供任何写入口（重建/重跑都不在这里做）。
     * 二期若加「重新解析」，也应作为详情页动作实现，而不是 Resource 的 create/edit。
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * 与「上传物流单号」共用 logistics_imports.manage：能看到记录的人就是能上传的人。
     * 超管通过 Gate::before 自动通过，因此可以只读查看全部商户的记录（业务确认）。
     */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::LOGISTICS_IMPORTS_MANAGE);
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }
}
