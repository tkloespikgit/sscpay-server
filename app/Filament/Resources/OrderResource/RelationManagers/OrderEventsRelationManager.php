<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 订单详情页里的日志时间线，按 occurred_at 倒序（本轮明确要求）。
 * 只读——日志数据来自插件 /order-logs 接口同步，不允许在这里增删改。
 *
 * 标题用 getTitle() 方法覆盖而不是静态属性——PHP 静态属性的默认值
 * 必须是编译期常量，不能在里面调用 __() 这种函数，所以翻译文本
 * 只能通过方法覆盖的方式提供。
 */
class OrderEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.order_event.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('message')
            ->columns([
                TextColumn::make('occurred_at')->label(__('admin.order_event.fields.occurred_at'))->dateTime(),
                TextColumn::make('level')->label(__('admin.order_event.fields.level'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ERROR' => 'danger',
                        'WARNING' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('message')->label(__('admin.order_event.fields.message'))->wrap(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
