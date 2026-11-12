<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CryptoLedgerEntryResource\Pages\ManageCryptoLedgerEntries;
use App\Filament\Support\CryptoFinanceDisplay as Display;
use App\Filament\Support\CryptoFinanceResource;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoLedgerEntry;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CryptoLedgerEntryResource extends CryptoFinanceResource
{
    protected static ?string $model = CryptoLedgerEntry::class;

    protected static string $cryptoLabel = 'income';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['order:id,order_no,merchant_id', 'cryptoPaymentRequest:id,merchant_id,status']);
    }

    public static function table(Table $table): Table
    {
        return $table->description(__('crypto.finance.income_help'))->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable(),
            TextColumn::make('order.order_no')->label(__('admin.finance.txn.order_no'))->searchable(),
            Display::network(), Display::column('gross_units'), Display::column('fee_units'), Display::column('amount_units', 'net_units'),
            TextColumn::make('cryptoPaymentRequest.status')->label(__('crypto.finance.fields.request_status'))->badge()
                ->formatStateUsing(fn (string $state): string => __('crypto.finance.request_states.'.$state))
                ->color(fn (string $state): string => $state === 'credited' ? 'success' : 'warning'),
            TextColumn::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime()->sortable(),
        ])->filters([
            ...Display::filters(),
            SelectFilter::make('crypto_asset_balance_id')->label(__('crypto.finance.fields.balance_id'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => CryptoAssetBalanceResource::getEloquentQuery()
                    ->where(fn (Builder $query): Builder => $query->where('asset', 'like', '%'.$search.'%')
                        ->orWhere('network', 'like', '%'.$search.'%')
                        ->orWhereHas('merchant', fn (Builder $merchants): Builder => $merchants->where('name', 'like', '%'.$search.'%')))
                    ->orderBy('id')->limit(50)->get()
                    ->mapWithKeys(fn (CryptoAssetBalance $balance): array => [$balance->id => self::balanceLabel($balance)])->all())
                ->getOptionLabelUsing(fn ($value): ?string => self::balanceLabel(CryptoAssetBalanceResource::getEloquentQuery()->find($value))),
        ])->recordActions([ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('order.order_no')->label(__('admin.finance.txn.order_no')),
            TextEntry::make('crypto_payment_request_id')->label(__('crypto.finance.fields.request_id')),
            TextEntry::make('network')->label(__('crypto.config.network')),
            Display::entry('gross_units'), Display::entry('fee_units'), Display::entry('amount_units', 'net_units'),
            Display::entry('balance_before_units'), Display::entry('balance_after_units'), Display::entry('debt_before_units'), Display::entry('debt_after_units'),
            ...array_map(fn (string $field): TextEntry => TextEntry::make('fee_snapshot.'.$field)
                ->label(__('crypto.finance.fields.'.$field)),
                ['fiat_amount', 'currency', 'fiat_usd_rate', 'price_usd', 'markup_percent', 'fee_percent', 'fee_fixed_usd']),
            TextEntry::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCryptoLedgerEntries::route('/')];
    }

    private static function balanceLabel(?CryptoAssetBalance $balance): ?string
    {
        return $balance ? ($balance->merchant?->name ?? '#'.$balance->merchant_id).' / '.$balance->network.' / '.$balance->asset : null;
    }
}
