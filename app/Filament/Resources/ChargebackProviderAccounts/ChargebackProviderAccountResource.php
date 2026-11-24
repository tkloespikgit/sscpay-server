<?php

namespace App\Filament\Resources\ChargebackProviderAccounts;

use App\Filament\Resources\ChargebackProviderAccounts\Pages\CreateChargebackProviderAccount;
use App\Filament\Resources\ChargebackProviderAccounts\Pages\EditChargebackProviderAccount;
use App\Filament\Resources\ChargebackProviderAccounts\Pages\ListChargebackProviderAccounts;
use App\Filament\Resources\ChargebackProviderAccounts\RelationManagers\BindingsRelationManager;
use App\Filament\Resources\ChargebackProviderAccounts\RelationManagers\SettingsRelationManager;
use App\Models\ChargebackProviderAccount;
use App\Models\User;
use App\Services\ChargebackAlerts\ChargebackAlertDrivers;
use App\Services\ChargebackAlerts\ChargebackProviderAccountPolicy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChargebackProviderAccountResource extends Resource
{
    protected static ?string $model = ChargebackProviderAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('chargeback_alerts.provider_account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chargeback_alerts.provider_accounts');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ChargebackProviderAccountPolicy::class)->viewAny($user);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ChargebackProviderAccountPolicy::class)->update($user, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();
        if (! $user instanceof User || ! static::canViewAny()) {
            return $query->whereRaw('1 = 0');
        }

        return $user->is_super_admin ? $query : $query->where('owner_id', $user->id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('chargeback_alerts.name'))->required()->maxLength(120),
            Select::make('provider')->label(__('chargeback_alerts.driver'))
                ->options(fn (ChargebackAlertDrivers $drivers): array => $drivers->options())
                ->required()->live()->disabledOn('edit')
                ->afterStateUpdated(fn (Set $set) => $set('services', [])),
            TextInput::make('external_id')->label(fn (Get $get, ChargebackAlertDrivers $drivers): string => $drivers->find((string) $get('provider'))?->accountIdentifierLabel() ?? __('chargeback_alerts.external_id'))
                ->required()->maxLength(120)->disabledOn('edit'),
            Select::make('environment')->label(__('chargeback_alerts.environment'))->options([
                'test' => __('chargeback_alerts.test'), 'production' => __('chargeback_alerts.production'),
            ])->default('test')->required()->disabledOn('edit'),
            TextInput::make('timezone')->label(__('chargeback_alerts.provider_timezone'))->default('Asia/Shanghai')->required()->disabledOn('edit'),
            Select::make('services')->label(__('chargeback_alerts.services'))->multiple()->required()
                ->options(fn (Get $get, ChargebackAlertDrivers $drivers): array => $drivers->find((string) $get('provider'))?->services() ?? []),
            Grid::make(2)->columnSpanFull()->schema(fn (Get $get, ChargebackAlertDrivers $drivers): array => collect($drivers->find((string) $get('provider'))?->signingFields() ?? [])
                ->map(fn (string $label, string $field) => TextInput::make('signing_config.'.$field)
                    ->label($label)->password()->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('chargeback_alerts.keep_secret') : null)
                    ->maxLength(512))
                ->values()->all()),
            Toggle::make('enabled')->label(__('chargeback_alerts.enabled'))->default(false)
                ->helperText(__('chargeback_alerts.enabled_help')),
            TextInput::make('callback_path')->label(__('chargeback_alerts.webhook'))->readOnly()->dehydrated(false)
                ->visible(fn (?ChargebackProviderAccount $record): bool => $record !== null)
                ->formatStateUsing(fn (?ChargebackProviderAccount $record): ?string => $record ? route('webhooks.chargeback', ['provider' => $record->provider], absolute: false) : null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('chargeback_alerts.name'))->searchable(),
            TextColumn::make('provider')->label(__('chargeback_alerts.driver'))->formatStateUsing(fn (string $state, ChargebackAlertDrivers $drivers): string => $drivers->find($state)?->label() ?? $state),
            TextColumn::make('environment')->label(__('chargeback_alerts.environment'))->formatStateUsing(fn (string $state): string => __('chargeback_alerts.'.$state)),
            TextColumn::make('bindings_count')->counts('bindings')->label(__('chargeback_alerts.bindings')),
            IconColumn::make('enabled')->label(__('chargeback_alerts.enabled'))->boolean(),
            TextColumn::make('last_sync_at')->label(__('chargeback_alerts.last_sync'))->dateTime()->placeholder('—'),
            TextColumn::make('sync_error')->label(__('chargeback_alerts.sync_error'))->badge()->placeholder('—'),
        ])->recordActions([EditAction::make()])->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [BindingsRelationManager::class, SettingsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChargebackProviderAccounts::route('/'),
            'create' => CreateChargebackProviderAccount::route('/create'),
            'edit' => EditChargebackProviderAccount::route('/{record}/edit'),
        ];
    }
}
