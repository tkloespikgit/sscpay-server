<?php

namespace App\Filament\Observer\Resources\OrderResource\Pages;

use App\Filament\Observer\Resources\OrderResource;
use App\Filament\Observer\Resources\OrderResource\RelationManagers\OrderDisputeEventsRelationManager;
use App\Models\Order;
use App\Models\OrderShipping;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * 只读订单详情，金额类字段一律经 Observer::scaleAmount() 折算展示。
 * 争议审核事件历史见 OrderDisputeEventsRelationManager（本页面底部关联表格）。
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getRelationManagers(): array
    {
        return [
            OrderDisputeEventsRelationManager::class,
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $observer = OrderResource::currentObserver();

        return $schema->components([
            Section::make(__('admin.order.sections.order_info'))->schema([
                Grid::make(3)->schema([
                    TextEntry::make('order_no')->label(__('admin.order.fields.order_no'))->copyable(),
                    TextEntry::make('merchant_order_no')->label(__('admin.order.fields.merchant_order_no'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('status')->label(__('admin.order.fields.status'))->badge()
                        ->formatStateUsing(fn (string $state) => __('admin.order.statuses.'.$state)),
                    TextEntry::make('paymentMethod.method_name')->label(__('admin.order.fields.payment_method'))
                        ->formatStateUsing(fn (?string $state, Order $record) => $state ?? $record->payment_method),
                    TextEntry::make('transaction_id')->label(__('admin.order.fields.transaction_id'))
                        ->copyable()
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('created_at')->label(__('admin.order.fields.created_at'))->dateTime(),
                    TextEntry::make('paid_at')->label(__('admin.order.fields.paid_at'))->dateTime()
                        ->placeholder(__('admin.order.placeholders.none')),
                ]),
            ]),

            Section::make(__('admin.order.sections.amount_info'))->schema([
                Grid::make(3)->schema([
                    TextEntry::make('currency')->label(__('admin.order.fields.currency')),
                    TextEntry::make('amount')->label(__('admin.order.fields.amount'))
                        ->getStateUsing(fn (Order $record) => $observer?->scaleAmount($record->amount))
                        ->money(fn (Order $record) => $record->currency),
                    TextEntry::make('converted_amount')->label(__('admin.order.fields.converted_amount'))
                        ->getStateUsing(fn (Order $record) => $observer?->scaleAmount($record->converted_amount))
                        ->money('usd'),
                    TextEntry::make('refunded_amount')->label(__('admin.order.fields.refunded_amount'))
                        ->getStateUsing(fn (Order $record) => $observer?->scaleAmount($record->refunded_amount))
                        ->money(fn (Order $record) => $record->currency),
                ]),
            ]),

            Section::make(__('admin.order.sections.customer_info'))->schema([
                Grid::make(2)->schema([
                    TextEntry::make('customer_first_name')->label(__('admin.order.fields.customer_first_name')),
                    TextEntry::make('customer_last_name')->label(__('admin.order.fields.customer_last_name')),
                    TextEntry::make('customer_email')->label(__('admin.order.fields.customer_email')),
                    TextEntry::make('customer_phone')->label(__('admin.order.fields.customer_phone'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping_address_line1')->label(__('admin.order.fields.address'))
                        ->placeholder(__('admin.order.placeholders.none'))
                        ->columnSpanFull(),
                    TextEntry::make('shipping_city')->label(__('admin.order.fields.city'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping_country')->label(__('admin.order.fields.country'))
                        ->placeholder(__('admin.order.placeholders.none')),
                ]),
            ]),

            Section::make(__('admin.order.sections.shipping_info'))->schema([
                Grid::make(3)->schema([
                    TextEntry::make('shipping.logistics_company')->label(__('admin.order.fields.logistics_company'))
                        ->placeholder(__('admin.order.placeholders.not_shipped')),
                    TextEntry::make('shipping.tracking_number')->label(__('admin.order.fields.tracking_number'))
                        ->copyable()
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping.shipped_at')->label(__('admin.order.fields.shipped_at'))->dateTime()
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping.sync_status')->label(__('admin.order.fields.sync_status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => $state ? __('admin.order.sync_statuses.'.$state) : null)
                        ->color(fn (?string $state) => match ($state) {
                            OrderShipping::SYNC_STATUS_SYNCED => 'success',
                            OrderShipping::SYNC_STATUS_FAILED => 'danger',
                            default => 'gray',
                        }),
                ]),
            ]),
        ]);
    }
}
