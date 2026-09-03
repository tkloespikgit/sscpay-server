<?php

namespace App\Filament\Resources\OrderDisputeEventResource\Pages;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\OrderDisputeEventResource;
use App\Filament\Resources\OrderDisputeEventResource\RelationManagers\OrderDisputeEventRepliesRelationManager;
use App\Models\OrderDisputeEvent;
use App\Services\OrderDisputeService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewOrderDisputeEvent extends ViewRecord
{
    protected static string $resource = OrderDisputeEventResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.order_dispute_event.model_label'))->schema([
                Grid::make(3)->schema([
                    TextEntry::make('order_no')->label(__('admin.order_dispute_event.fields.order_no'))->copyable(),
                    TextEntry::make('event_no')->label(__('admin.order_dispute_event.fields.event_no'))->copyable(),
                    TextEntry::make('status')->label(__('admin.order_dispute_event.fields.status'))->badge()
                        ->formatStateUsing(fn (string $state) => __('admin.order_dispute_event.statuses.'.$state)),
                    TextEntry::make('payment_method')->label(__('admin.order_dispute_event.fields.payment_method'))
                        ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                    TextEntry::make('final_action')->label(__('admin.order_dispute_event.fields.final_action'))->badge()
                        ->formatStateUsing(fn (string $state) => __('admin.order_dispute_event.final_actions.'.$state)),
                    TextEntry::make('frozen_amount')->label(__('admin.order_dispute_event.fields.frozen_amount'))->money('usd'),
                    TextEntry::make('due_at')->label(__('admin.order_dispute_event.fields.due_at'))->dateTime(),
                    TextEntry::make('openedBy.name')->label(__('admin.order_dispute_event.fields.opened_by'))
                        ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                    TextEntry::make('opened_at')->label(__('admin.order_dispute_event.fields.opened_at'))->dateTime(),
                    TextEntry::make('closedBy.name')->label(__('admin.order_dispute_event.fields.closed_by'))
                        ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                    TextEntry::make('closed_at')->label(__('admin.order_dispute_event.fields.closed_at'))->dateTime()
                        ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                    TextEntry::make('close_type')->label(__('admin.order_dispute_event.fields.close_type'))
                        ->formatStateUsing(fn (?string $state) => $state ? __('admin.order_dispute_event.close_types.'.$state) : null)
                        ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                ]),
                TextEntry::make('close_remark')->label(__('admin.order_dispute_event.fields.close_remark'))
                    ->placeholder(__('admin.order_dispute_event.placeholders.none'))
                    ->columnSpanFull(),
            ]),

            Section::make(__('admin.order_dispute_event.fields.reason'))->schema([
                // reason 入库前已在 OrderDisputeService::open() 里做过 XSS 过滤
                // （见 App\Support\RichTextSanitizer），这里直接渲染是安全的。
                TextEntry::make('reason')->label('')->html()->columnSpanFull(),
                ImageEntry::make('images')->label(__('admin.order_dispute_event.fields.images'))
                    ->disk('oss')
                    ->visibility('private')
                    ->stacked()
                    ->visible(fn ($record) => filled($record->images)),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->replyAction(),
            OrderDisputeEventResource::closeAction(),
        ];
    }

    /**
     * 回复（仅商户交易订单管理员，处理中才可见）。提交后硬跳转重载当前页，
     * 保证回复线程 RelationManager 拿到最新数据（同 ViewOrder::recordShipment 的做法）。
     */
    private function replyAction(): Action
    {
        return Action::make('replyDisputeEvent')
            ->label(__('admin.order_dispute_event.actions.reply'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->visible(fn (OrderDisputeEvent $record) => $record->isProcessing() && auth()->user()->can(Permissions::ORDER_DISPUTES_REPLY))
            ->schema([
                RichEditor::make('content')->label(__('admin.order_dispute_event_reply.fields.content'))->required(),
                FileUpload::make('images')
                    ->label(__('admin.order_dispute_event_reply.fields.images'))
                    ->image()
                    ->multiple()
                    ->maxFiles(10)
                    ->disk('local')
                    ->directory('dispute-events-tmp'),
            ])
            ->action(function (array $data, OrderDisputeService $service) {
                try {
                    $service->reply($this->record, auth()->user(), $data);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.order_dispute_event.notifications.replied'))->success()->send();
                $this->redirect(static::getUrl(['record' => $this->record]));
            });
    }

    public function getRelationManagers(): array
    {
        return [
            OrderDisputeEventRepliesRelationManager::class,
        ];
    }
}
