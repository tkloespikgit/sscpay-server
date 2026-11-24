<?php

namespace App\Filament\Resources\ChargebackProviderAccounts\RelationManagers;

use App\Filament\Resources\ChargebackProviderAccounts\ChargebackProviderAccountResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class BindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bindings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('chargeback_alerts.bindings');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ChargebackProviderAccountResource::canEdit($ownerRecord);
    }

    #[On('chargeback-bindings-updated')]
    public function refreshBindings(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service')->label(__('chargeback_alerts.service'))->formatStateUsing(fn (string $state): string => strtoupper($state)),
            TextColumn::make('scope_key')->label(__('chargeback_alerts.scope'))->searchable(),
            TextColumn::make('paymentMethod.method_name')->label(__('chargeback_alerts.payment_method'))->placeholder('—'),
            TextColumn::make('reference_type')->label(__('chargeback_alerts.reference_type'))
                ->formatStateUsing(fn (string $state): string => __('chargeback_alerts.references.'.$state)),
            TextColumn::make('valid_from')->label(__('chargeback_alerts.valid_from'))->dateTime(),
            TextColumn::make('valid_until')->label(__('chargeback_alerts.valid_until'))->dateTime()->placeholder('—'),
        ])->defaultSort('id', 'desc');
    }
}
