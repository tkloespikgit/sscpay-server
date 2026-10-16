<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderSettlementResource\Pages;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Support\Permissions;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderSettlementResource extends Resource
{
    protected static ?string $model = OrderSettlement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.finance');
    }

    public static function getModelLabel(): string
    {
        return __('settlement.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('settlement.model_label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable()
                    ->visible(fn (): bool => (bool) auth()->user()?->isPlatformStaff()),
                TextColumn::make('order.order_no')->label(__('settlement.fields.order'))->searchable(),
                TextColumn::make('amount')->label(__('settlement.fields.amount'))->money('usd')->sortable(),
                TextColumn::make('remaining_amount')->label(__('settlement.fields.remaining_amount'))->money('usd'),
                TextColumn::make('reserve_amount')->label(__('settlement.fields.reserve_amount'))->money('usd'),
                TextColumn::make('available_amount')->label(__('settlement.fields.available_amount'))->money('usd'),
                TextColumn::make('status')->label(__('admin.finance.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('settlement.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'settled' => 'success',
                        'pending' => 'warning',
                        'waiting_shipment' => 'info',
                        default => 'gray',
                    })
                    ->description(fn (OrderSettlement $record): ?string => in_array($record->order?->status, ['disputing', Order::STATUS_DISPUTE_REVIEW], true)
                        && $record->status !== 'cancelled'
                        ? __('settlement.dispute_paused')
                        : null),
                TextColumn::make('mode')->label(__('settlement.fields.mode'))
                    ->formatStateUsing(fn (string $state): string => __('settlement.modes.'.$state))->toggleable(),
                TextColumn::make('cycle_days')->label(__('settlement.fields.cycle_days'))->suffix(' '.__('settlement.days'))->toggleable(),
                TextColumn::make('reserve_percent')->label(__('settlement.fields.reserve_percent'))->suffix('%')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reserve_days')->label(__('settlement.fields.reserve_days'))->suffix(' '.__('settlement.days'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')->label(__('settlement.fields.started_at'))->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('due_at')->label(__('settlement.fields.due_at'))->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('settled_at')->label(__('settlement.fields.settled_at'))->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('cancelled_at')->label(__('settlement.fields.cancelled_at'))->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.finance.fields.status'))->options([
                    'waiting_shipment' => __('settlement.statuses.waiting_shipment'),
                    'pending' => __('settlement.statuses.pending'),
                    'settled' => __('settlement.statuses.settled'),
                    'cancelled' => __('settlement.statuses.cancelled'),
                ]),
                SelectFilter::make('mode')->label(__('settlement.fields.mode'))->options([
                    'immediate' => __('settlement.modes.immediate'),
                    'shipped' => __('settlement.modes.shipped'),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderSettlements::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::FINANCE_VIEW);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
