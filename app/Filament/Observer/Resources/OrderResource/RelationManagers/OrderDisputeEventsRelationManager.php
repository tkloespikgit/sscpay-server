<?php

namespace App\Filament\Observer\Resources\OrderResource\RelationManagers;

use App\Filament\Observer\Resources\OrderResource;
use App\Models\OrderDisputeEvent;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 订单详情页展示该订单的完整争议审核事件历史（含已结束的），纯只读——
 * 观察者面板没有独立的争议事件详情页，"查看"直接弹一个只读详情弹窗，
 * 不像 App\Filament\Resources\OrderResource 那样跳转到专用资源页。
 * frozen_amount 同样经 Observer::scaleAmount() 折算展示。
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
        $observer = OrderResource::currentObserver();

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
                TextColumn::make('frozen_amount')->label(__('admin.order_dispute_event.fields.frozen_amount'))
                    ->getStateUsing(fn (OrderDisputeEvent $record) => $observer?->scaleAmount($record->frozen_amount))
                    ->money('usd'),
                TextColumn::make('opened_at')->label(__('admin.order_dispute_event.fields.opened_at'))->dateTime(),
                TextColumn::make('due_at')->label(__('admin.order_dispute_event.fields.due_at'))->dateTime()
                    ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                TextColumn::make('closed_at')->label(__('admin.order_dispute_event.fields.closed_at'))->dateTime()
                    ->placeholder(__('admin.order_dispute_event.placeholders.none')),
            ])
            ->defaultSort('opened_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('view')
                    ->label(__('admin.order_dispute_event.actions.view'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(__('admin.order_dispute_event.actions.view'))
                    ->modalSubmitAction(false)
                    ->modalCancelAction(fn (Action $action) => $action->label(__('admin.order.modals.close')))
                    ->schema(fn (OrderDisputeEvent $record) => [
                        TextEntry::make('event_no')->label(__('admin.order_dispute_event.fields.event_no')),
                        TextEntry::make('status')->label(__('admin.order_dispute_event.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (string $state) => __('admin.order_dispute_event.statuses.'.$state)),
                        TextEntry::make('reason')->label(__('admin.order_dispute_event.fields.reason'))
                            ->columnSpanFull(),
                        TextEntry::make('final_action')->label(__('admin.order_dispute_event.fields.final_action'))
                            ->formatStateUsing(fn (?string $state) => $state ? __('admin.order_dispute_event.final_actions.'.$state) : null)
                            ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                        TextEntry::make('frozen_amount')->label(__('admin.order_dispute_event.fields.frozen_amount'))
                            ->getStateUsing(fn (OrderDisputeEvent $record) => $observer?->scaleAmount($record->frozen_amount))
                            ->money('usd'),
                        TextEntry::make('due_at')->label(__('admin.order_dispute_event.fields.due_at'))->dateTime()
                            ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                        TextEntry::make('closed_at')->label(__('admin.order_dispute_event.fields.closed_at'))->dateTime()
                            ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                        TextEntry::make('close_type')->label(__('admin.order_dispute_event.fields.close_type'))
                            ->formatStateUsing(fn (?string $state) => $state ? __('admin.order_dispute_event.close_types.'.$state) : null)
                            ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                        TextEntry::make('close_remark')->label(__('admin.order_dispute_event.fields.close_remark'))
                            ->placeholder(__('admin.order_dispute_event.placeholders.none'))
                            ->columnSpanFull(),
                    ]),
            ])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
