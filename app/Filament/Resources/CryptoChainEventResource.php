<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CryptoChainEventResource\Pages\ManageCryptoChainEvents;
use App\Filament\Support\CryptoFinanceDisplay as Display;
use App\Filament\Support\CryptoFinanceResource;
use App\Models\CryptoChainEvent;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CryptoChainEventResource extends CryptoFinanceResource
{
    protected static ?string $model = CryptoChainEvent::class;

    protected static string $cryptoLabel = 'transactions';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['cryptoPaymentRequest.order:id,order_no,merchant_id', 'cryptoPaymentRequest.cryptoAddress:id,address,merchant_id']);
    }

    public static function table(Table $table): Table
    {
        return $table->description(__('crypto.finance.chain_help'))->columns([
            TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable(),
            TextColumn::make('cryptoPaymentRequest.order.order_no')->label(__('admin.finance.txn.order_no'))->searchable(),
            Display::network(), Display::column('amount_units'),
            TextColumn::make('transaction_hash')->label(__('crypto.finance.fields.transaction_hash'))->searchable()->limit(20)->copyable(),
            TextColumn::make('event_position')->label(__('crypto.finance.fields.event_position'))->toggleable(),
            TextColumn::make('chain_status')->label(__('crypto.finance.fields.chain_status'))->state(fn (CryptoChainEvent $record): string => Display::eventStatus($record))
                ->badge()->formatStateUsing(fn (string $state): string => __('crypto.finance.chain_states.'.$state))
                ->color(fn (string $state): string => match ($state) {
                    'confirmed' => 'success', 'orphaned' => 'danger', default => 'warning'
                }),
            TextColumn::make('cryptoPaymentRequest.status')->label(__('crypto.finance.fields.request_status'))->badge()
                ->formatStateUsing(fn (string $state): string => __('crypto.finance.request_states.'.$state)),
            TextColumn::make('block_time')->label(__('crypto.finance.fields.block_time'))->dateTime()->sortable(),
        ])->filters([
            ...Display::filters(),
            SelectFilter::make('chain_status')->label(__('crypto.finance.fields.chain_status'))->options(__('crypto.finance.chain_states'))
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'orphaned' => $query->whereNotNull('orphaned_at'),
                        'unverified' => $query->whereNull('orphaned_at')->whereNull('verified_at'),
                        'confirming' => $query->whereNull('orphaned_at')->whereNotNull('verified_at')->whereNull('confirmed_at'),
                        'confirmed' => $query->whereNull('orphaned_at')->whereNotNull('verified_at')->whereNotNull('confirmed_at'),
                        default => $query,
                    };
                }),
        ])->recordActions([ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('cryptoPaymentRequest.order.order_no')->label(__('admin.finance.txn.order_no')),
            TextEntry::make('crypto_payment_request_id')->label(__('crypto.finance.fields.request_id')),
            TextEntry::make('network')->label(__('crypto.config.network')),
            TextEntry::make('cryptoPaymentRequest.cryptoAddress.address')->label(__('crypto.finance.fields.receive_address'))->copyable(),
            TextEntry::make('transaction_hash')->label(__('crypto.finance.fields.transaction_hash'))->copyable()->columnSpanFull(),
            TextEntry::make('event_position')->label(__('crypto.finance.fields.event_position')),
            Display::entry('amount_units'),
            TextEntry::make('block_height')->label(__('crypto.finance.fields.block_height')),
            TextEntry::make('block_hash')->label(__('crypto.finance.fields.block_hash'))->columnSpanFull(),
            TextEntry::make('block_time')->label(__('crypto.finance.fields.block_time'))->dateTime(),
            TextEntry::make('verified_at')->label(__('crypto.finance.fields.verified_at'))->dateTime()->placeholder('—'),
            TextEntry::make('confirmed_at')->label(__('crypto.finance.fields.confirmed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('orphaned_at')->label(__('crypto.finance.fields.orphaned_at'))->dateTime()->placeholder('—'),
            TextEntry::make('cryptoPaymentRequest.status')->label(__('crypto.finance.fields.request_status'))
                ->formatStateUsing(fn (string $state): string => __('crypto.finance.request_states.'.$state)),
            TextEntry::make('cryptoPaymentRequest.review_reason')->label(__('crypto.finance.fields.review_reason'))->placeholder('—'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCryptoChainEvents::route('/')];
    }
}
