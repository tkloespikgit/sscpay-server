<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 订单详情底部的物流回传记录。摘要留在物流信息里，逐次回传和请求明细放在这个 tab。
 */
class OrderShipmentSyncsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipmentSyncs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.merchant_shipment_sync.tab');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('attempts')->latest('id'))
            ->columns([
                TextColumn::make('created_at')->label(__('admin.merchant_shipment_sync.fields.created_at'))->dateTime(),
                TextColumn::make('payload.tracking_number')->label(__('admin.order.fields.tracking_number'))->placeholder('—'),
                TextColumn::make('status')->label(__('admin.merchant_shipment_sync.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.merchant_shipment_sync.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'retrying', 'processing' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('attempt_count')->label(__('admin.merchant_shipment_sync.fields.attempt_count')),
                TextColumn::make('synced_at')->label(__('admin.merchant_shipment_sync.fields.returned_at'))->dateTime()->placeholder('—'),
                TextColumn::make('attempts.request_url')->label(__('admin.merchant_shipment_sync.fields.request_url'))->placeholder('—')->wrap(),
                TextColumn::make('attempts.response_body')->label(__('admin.merchant_shipment_sync.fields.response_body'))->placeholder('—')->limit(200)->wrap(),
                TextColumn::make('last_error')->label(__('admin.merchant_shipment_sync.fields.last_error'))->placeholder('—')->wrap(),
                TextColumn::make('alert_status')->label(__('admin.merchant_shipment_sync.fields.alert_status'))
                    ->formatStateUsing(fn (?string $state): ?string => $state ? __('admin.merchant_shipment_sync.alert_statuses.'.$state) : null)
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        RepeatableEntry::make('attempts')
                            ->label(__('admin.merchant_shipment_sync.fields.attempts'))
                            ->schema([
                                TextEntry::make('attempt_number')->label(__('admin.merchant_shipment_sync.fields.attempt_number')),
                                TextEntry::make('attempted_at')->label(__('admin.merchant_shipment_sync.fields.attempted_at'))->dateTime(),
                                TextEntry::make('response_status')->label(__('admin.merchant_shipment_sync.fields.response_status'))->placeholder('—'),
                                TextEntry::make('request_url')->label(__('admin.merchant_shipment_sync.fields.request_url'))->columnSpanFull(),
                                TextEntry::make('response_body')->label(__('admin.merchant_shipment_sync.fields.response_body'))->placeholder('—')->columnSpanFull(),
                                TextEntry::make('error_message')->label(__('admin.merchant_shipment_sync.fields.last_error'))->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ]),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
