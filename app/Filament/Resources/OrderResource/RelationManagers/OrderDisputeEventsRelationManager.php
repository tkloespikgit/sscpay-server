<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Filament\Resources\OrderDisputeEventResource;
use App\Models\OrderDisputeEvent;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 订单详情页展示该订单的历史争议审核事件（含已结束的），只读——
 * 详情内容（原因/回复线程/结束动作）都在 OrderDisputeEventResource 的
 * 专用详情页里，这里的"查看"直接跳转过去，而不是内嵌弹窗。
 */
class OrderDisputeEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'disputeEvents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.order_dispute_event.model_label_plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('event_no')
            ->columns([
                TextColumn::make('event_no')->label(__('admin.order_dispute_event.fields.event_no')),
                TextColumn::make('status')->label(__('admin.order_dispute_event.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.order_dispute_event.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        OrderDisputeEvent::STATUS_PROCESSING => 'warning',
                        OrderDisputeEvent::STATUS_CLOSED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('opened_at')->label(__('admin.order_dispute_event.fields.opened_at'))->dateTime(),
                TextColumn::make('closed_at')->label(__('admin.order_dispute_event.fields.closed_at'))->dateTime()
                    ->placeholder(__('admin.order_dispute_event.placeholders.none')),
            ])
            ->defaultSort('opened_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('view')
                    ->label(__('admin.order_dispute_event.actions.view'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (OrderDisputeEvent $record) => OrderDisputeEventResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
