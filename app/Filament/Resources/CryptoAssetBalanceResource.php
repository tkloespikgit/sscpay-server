<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CryptoAssetBalanceResource\Pages\ManageCryptoAssetBalances;
use App\Filament\Support\CryptoFinanceDisplay as Display;
use App\Filament\Support\CryptoFinanceResource;
use App\Filament\Support\CryptoWithdrawalActions;
use App\Models\CryptoAssetBalance;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CryptoAssetBalanceResource extends CryptoFinanceResource
{
    protected static ?string $model = CryptoAssetBalance::class;

    protected static string $cryptoLabel = 'balances';

    public static function table(Table $table): Table
    {
        return $table->description(__('crypto.finance.balance_help'))->columns([
            TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable(),
            Display::network(),
            TextColumn::make('asset')->label(__('crypto.config.asset')),
            Display::column('balance_units'),
            Display::column('debt_units'),
            Display::column('reserved_units', 'current_reserved'),
            TextColumn::make('available')->label(__('crypto.finance.fields.available_units'))
                ->state(fn (CryptoAssetBalance $record): string => Display::amount($record, $record->availableUnits())),
            TextColumn::make('updated_at')->label(__('crypto.finance.fields.updated_at'))->dateTime(),
        ])->filters(Display::filters())->recordActions([
            ViewAction::make(),
            CryptoWithdrawalActions::request(),
            Action::make('income')->label(__('crypto.finance.income'))
                ->url(fn (CryptoAssetBalance $record): string => CryptoLedgerEntryResource::getUrl('index', [
                    'filters' => ['crypto_asset_balance_id' => ['value' => $record->id]],
                ])),
        ])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('merchant.name')->label(__('admin.finance.fields.merchant')),
            TextEntry::make('network')->label(__('crypto.config.network'))->formatStateUsing(fn (string $state): string => __('crypto.finance.networks.'.$state)),
            TextEntry::make('asset')->label(__('crypto.config.asset')),
            Display::entry('balance_units'), Display::entry('debt_units'), Display::entry('reserved_units', 'current_reserved'),
            TextEntry::make('available')->label(__('crypto.finance.fields.available_units'))
                ->state(fn (CryptoAssetBalance $record): string => Display::amount($record, $record->availableUnits())),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCryptoAssetBalances::route('/')];
    }
}
