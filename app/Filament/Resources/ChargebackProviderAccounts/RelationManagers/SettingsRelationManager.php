<?php

namespace App\Filament\Resources\ChargebackProviderAccounts\RelationManagers;

use App\Filament\Resources\ChargebackProviderAccounts\ChargebackProviderAccountResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'settings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('chargeback_alerts.provider_settings');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ChargebackProviderAccountResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service')->label(__('chargeback_alerts.service'))->formatStateUsing(fn (string $state): string => strtoupper($state)),
            TextColumn::make('external_id')->label(__('chargeback_alerts.external_id')),
            TextColumn::make('scope_key')->label(__('chargeback_alerts.scope'))->searchable(),
            TextColumn::make('remote_status')->label(__('chargeback_alerts.remote_status'))->badge(),
            TextColumn::make('details.auto_refund_platform')->label(__('chargeback_alerts.auto_refund_platform'))->placeholder('—'),
            TextColumn::make('observed_at')->label(__('chargeback_alerts.observed_at'))->dateTime(),
        ])->defaultSort('observed_at', 'desc');
    }
}
