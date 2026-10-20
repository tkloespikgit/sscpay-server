<?php

namespace App\Filament\Resources\PaymentMethodResource\RelationManagers;

use App\Filament\Support\GeneratePurchaseOrderAction;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class PurchaseOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrders';

    public function boot(): void
    {
        abort_unless(GeneratePurchaseOrderAction::canGenerate(), 403);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('purchase_order.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return GeneratePurchaseOrderAction::canGenerate();
    }

    #[On('purchase-order-generated.{ownerRecord.id}')]
    public function refreshPurchaseOrders(): void
    {
        $this->resetTable();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('purchase_order.details'))
                ->schema([
                    TextEntry::make('order_no')->label(__('purchase_order.fields.order_no')),
                    TextEntry::make('transaction_id')->label(__('purchase_order.fields.transaction_id'))->copyable(),
                    TextEntry::make('ordered_at')->label(__('purchase_order.fields.ordered_at'))->dateTime(),
                    TextEntry::make('paid_at')->label(__('purchase_order.fields.paid_at'))->dateTime(),
                    TextEntry::make('shipped_at')->label(__('purchase_order.fields.shipped_at'))->dateTime(),
                    TextEntry::make('received_at')->label(__('purchase_order.fields.received_at'))->dateTime(),
                ])->columns(2)->columnSpanFull(),
            RepeatableEntry::make('items')
                ->label(__('purchase_order.items'))
                ->table([
                    TableColumn::make(__('purchase_order.fields.product_name')),
                    TableColumn::make(__('purchase_order.fields.sku')),
                    TableColumn::make(__('purchase_order.fields.source_price')),
                    TableColumn::make(__('purchase_order.fields.unit_price')),
                    TableColumn::make(__('purchase_order.fields.quantity')),
                    TableColumn::make(__('purchase_order.fields.line_total')),
                ])
                ->schema([
                    TextEntry::make('product_name')->wrap(),
                    TextEntry::make('sku')->placeholder('—'),
                    TextEntry::make('source_price')->money('USD'),
                    TextEntry::make('unit_price')->money('CNY'),
                    TextEntry::make('quantity'),
                    TextEntry::make('subtotal')->money('CNY'),
                ])->columnSpanFull(),
            Section::make(__('purchase_order.amounts'))
                ->schema([
                    TextEntry::make('subtotal')->label(__('purchase_order.fields.subtotal'))->money('CNY'),
                    TextEntry::make('shipping_fee')->label(__('purchase_order.fields.shipping_fee'))->money('CNY'),
                    TextEntry::make('discount')->label(__('purchase_order.fields.discount'))->money('CNY'),
                    TextEntry::make('total')->label(__('purchase_order.fields.total'))->money('CNY'),
                ])->columns(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_no')
            ->columns([
                TextColumn::make('order_no')->label(__('purchase_order.fields.order_no'))->searchable(),
                TextColumn::make('ordered_at')->label(__('purchase_order.fields.ordered_at'))->dateTime()->sortable(),
                TextColumn::make('subtotal')->label(__('purchase_order.fields.subtotal'))->money('CNY'),
                TextColumn::make('shipping_fee')->label(__('purchase_order.fields.shipping_fee'))->money('CNY'),
                TextColumn::make('discount')->label(__('purchase_order.fields.discount'))->money('CNY'),
                TextColumn::make('total')->label(__('purchase_order.fields.total'))->money('CNY'),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('purchase_order.empty'))
            ->recordActions([
                Action::make('viewPurchaseDocument')
                    ->label(__('purchase_order.view_document'))
                    ->icon('heroicon-o-document-text')
                    ->authorize(fn (): bool => GeneratePurchaseOrderAction::canGenerate())
                    ->url(fn (PurchaseOrder $record): string => route('purchase-orders.show', $record->getKey()))
                    ->openUrlInNewTab(),
                ViewAction::make()
                    ->authorize(fn (): bool => GeneratePurchaseOrderAction::canGenerate())
                    ->modalWidth('7xl'),
                Action::make('editCharges')
                    ->label(__('purchase_order.edit_charges'))
                    ->icon('heroicon-o-pencil-square')
                    ->authorize(fn (): bool => GeneratePurchaseOrderAction::canGenerate())
                    ->fillForm(fn (PurchaseOrder $record): array => [
                        'shipping_fee' => $record->shipping_fee,
                        'discount' => $record->discount,
                    ])
                    ->schema(GeneratePurchaseOrderAction::chargeFields(required: true))
                    ->action(function (array $data, PurchaseOrder $record, PurchaseOrderService $service, Schema $schema): void {
                        abort_unless(GeneratePurchaseOrderAction::canGenerate(), 403);

                        try {
                            $service->updateCharges($record, auth()->user(), $data);
                        } catch (ValidationException $exception) {
                            GeneratePurchaseOrderAction::throwSchemaValidationException($exception, $schema);
                        }

                        Notification::make()->success()->title(__('purchase_order.charges_updated'))->send();
                    }),
            ]);
    }
}
