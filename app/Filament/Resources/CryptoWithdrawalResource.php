<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CryptoWithdrawalResource\Pages\ManageCryptoWithdrawals;
use App\Filament\Support\CryptoFinanceDisplay as Display;
use App\Filament\Support\CryptoFinanceResource;
use App\Filament\Support\CryptoWithdrawalActions;
use App\Models\CryptoWithdrawal;
use App\Models\CryptoWithdrawalEvent;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CryptoWithdrawalResource extends CryptoFinanceResource
{
    protected static ?string $model = CryptoWithdrawal::class;

    protected static string $cryptoLabel = 'withdrawals';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['requestedBy:id,name', 'reviewedBy:id,name', 'completedBy:id,name', 'events.operator:id,name', 'events.withdrawal']);
    }

    public static function table(Table $table): Table
    {
        return $table->description(__('crypto.finance.withdrawal_help'))->columns([
            TextColumn::make('id')->label('ID')->sortable()->searchable(),
            TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable(),
            Display::network(), Display::column('amount_units'), Display::column('fee_limit_units'), Display::column('actual_fee_units'),
            TextColumn::make('status')->label(__('crypto.finance.fields.status'))->badge()
                ->formatStateUsing(fn (string $state): string => __('crypto.finance.states.'.$state))
                ->color(fn (string $state): string => match ($state) {
                    'pending', 'approved' => 'warning', 'completed' => 'success', default => 'gray'
                }),
            TextColumn::make('destination_address')->label(__('crypto.finance.fields.destination_address'))->limit(16)->copyable()->searchable(),
            TextColumn::make('requestedBy.name')->label(__('crypto.finance.fields.requested_by')),
            TextColumn::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime()->sortable(),
        ])->filters([
            ...Display::filters(),
            SelectFilter::make('status')->label(__('crypto.finance.fields.status'))->options(__('crypto.finance.states')),
        ])->recordActions([ViewAction::make(), ...CryptoWithdrawalActions::review()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('merchant.name')->label(__('admin.finance.fields.merchant')),
            TextEntry::make('network')->label(__('crypto.config.network'))->formatStateUsing(fn (string $state): string => __('crypto.finance.networks.'.$state)),
            TextEntry::make('status')->label(__('crypto.finance.fields.status'))->formatStateUsing(fn (string $state): string => __('crypto.finance.states.'.$state)),
            TextEntry::make('destination_address')->label(__('crypto.finance.fields.destination_address'))->copyable()->columnSpanFull(),
            Display::entry('amount_units'), Display::entry('fee_limit_units'), Display::entry('reserved_units'), Display::entry('actual_fee_units'),
            TextEntry::make('remark')->label(__('crypto.finance.fields.remark'))->placeholder('—'),
            TextEntry::make('requestedBy.name')->label(__('crypto.finance.fields.requested_by')),
            TextEntry::make('reviewedBy.name')->label(__('crypto.finance.fields.reviewed_by'))->placeholder('—'),
            TextEntry::make('reviewed_at')->label(__('crypto.finance.fields.reviewed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('review_remark')->label(__('crypto.finance.fields.review_remark'))->placeholder('—'),
            TextEntry::make('completedBy.name')->label(__('crypto.finance.fields.completed_by'))->placeholder('—'),
            TextEntry::make('completed_at')->label(__('crypto.finance.fields.completed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('manual_reference')->label(__('crypto.finance.fields.manual_reference'))->placeholder('—'),
            TextEntry::make('completion_remark')->label(__('crypto.finance.fields.completion_remark'))->placeholder('—'),
            RepeatableEntry::make('events')->label(__('crypto.finance.audit'))->columnSpanFull()->schema([
                TextEntry::make('action')->label(__('crypto.finance.fields.action'))->formatStateUsing(fn (string $state): string => __('crypto.finance.actions.'.$state)),
                TextEntry::make('operator.name')->label(__('admin.finance.txn.operator')),
                TextEntry::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime(),
                TextEntry::make('from_status')->label(__('crypto.finance.fields.from_status'))->formatStateUsing(fn (string $state): string => __('crypto.finance.states.'.$state))->placeholder('—'),
                TextEntry::make('to_status')->label(__('crypto.finance.fields.status'))->formatStateUsing(fn (string $state): string => __('crypto.finance.states.'.$state)),
                ...array_map(fn (string $field): TextEntry => TextEntry::make($field)->label(__('crypto.finance.fields.'.$field))
                    ->formatStateUsing(fn (CryptoWithdrawalEvent $record, string $state): string => Display::amount($record->withdrawal, $state)),
                    ['balance_before_units', 'balance_after_units', 'reserved_before_units', 'reserved_after_units', 'debt_before_units', 'debt_after_units']),
                TextEntry::make('reason')->label(__('crypto.finance.fields.reason')),
                TextEntry::make('manual_reference')->label(__('crypto.finance.fields.manual_reference'))->placeholder('—'),
            ])->columns(3),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCryptoWithdrawals::route('/')];
    }
}
