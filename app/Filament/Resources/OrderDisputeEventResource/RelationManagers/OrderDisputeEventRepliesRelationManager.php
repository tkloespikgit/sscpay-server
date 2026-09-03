<?php

namespace App\Filament\Resources\OrderDisputeEventResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 回复线程，只读——回复只能通过详情页头部的"回复"动作提交（需要
 * XSS 过滤 + 图片转存 OSS，通用 RelationManager 建表单做不到），
 * 这里只负责展示历史记录。
 */
class OrderDisputeEventRepliesRelationManager extends RelationManager
{
    protected static string $relationship = 'replies';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.order_dispute_event_reply.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->columns([
                TextColumn::make('operator.name')->label(__('admin.order_dispute_event_reply.fields.operator')),
                TextColumn::make('created_at')->label(__('admin.order_dispute_event_reply.fields.created_at'))->dateTime(),
                TextColumn::make('content')->label(__('admin.order_dispute_event_reply.fields.content'))->html()->wrap(),
                ImageColumn::make('images')->label(__('admin.order_dispute_event_reply.fields.images'))
                    ->disk('oss')
                    ->visibility('private')
                    ->stacked(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
