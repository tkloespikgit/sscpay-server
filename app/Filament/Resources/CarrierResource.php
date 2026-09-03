<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarrierResource\Pages;
use App\Models\Carrier;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * 系统支持的物流承运商清单（由 CarrierSeeder 落库）。所有商户用户都能查看，
 * 只有超级管理员能新建/编辑/删除——这份清单是 order/ship API、手工录入物流、
 * CSV 批量导入物流三个入口共用的校验依据（见 Carrier::isValidCode()），
 * 商户自己不该有能力往里面塞一个野生 carrier_code。
 */
class CarrierResource extends Resource
{
    protected static ?string $model = Carrier::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.carrier.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.carrier.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.carrier.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.carrier.sections.basic_info'))->schema([
                TextInput::make('carrier_name')
                    ->label(__('admin.carrier.fields.carrier_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('carrier_code')
                    ->label(__('admin.carrier.fields.carrier_code'))
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText(__('admin.carrier.help.carrier_code')),
                TextInput::make('country_code')
                    ->label(__('admin.carrier.fields.country_code'))
                    ->required()
                    ->maxLength(20)
                    ->default('GLOBAL'),
                TextInput::make('country_name')
                    ->label(__('admin.carrier.fields.country_name'))
                    ->required()
                    ->maxLength(100)
                    ->default('Global'),
                Select::make('status')
                    ->label(__('admin.carrier.fields.status'))
                    ->options([
                        Carrier::STATUS_ENABLED => __('admin.carrier.statuses.enabled'),
                        Carrier::STATUS_DISABLED => __('admin.carrier.statuses.disabled'),
                    ])
                    ->required()
                    ->default(Carrier::STATUS_ENABLED),
                Toggle::make('pp_supported')
                    ->label(__('admin.carrier.fields.pp_supported'))
                    ->helperText(__('admin.carrier.help.pp_supported'))
                    ->default(true)
                    ->inline(false),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carrier_name')->label(__('admin.carrier.fields.carrier_name'))->searchable(),
                TextColumn::make('carrier_code')->label(__('admin.carrier.fields.carrier_code'))->searchable()->badge()->copyable(),
                TextColumn::make('country_name')->label(__('admin.carrier.fields.country_name'))->toggleable(),
                TextColumn::make('status')->label(__('admin.carrier.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.carrier.statuses.'.$state))
                    ->color(fn (string $state) => $state === Carrier::STATUS_ENABLED ? 'success' : 'gray'),
                IconColumn::make('pp_supported')->label(__('admin.carrier.fields.pp_supported'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.carrier.fields.status'))->options([
                    Carrier::STATUS_ENABLED => __('admin.carrier.statuses.enabled'),
                    Carrier::STATUS_DISABLED => __('admin.carrier.statuses.disabled'),
                ]),
                TernaryFilter::make('pp_supported')->label(__('admin.carrier.fields.pp_supported')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('carrier_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarriers::route('/'),
            'create' => Pages\CreateCarrier::route('/create'),
            'edit' => Pages\EditCarrier::route('/{record}/edit'),
        ];
    }

    /**
     * 所有能进后台的用户（商户 + 超管）都能看这份清单，不做权限门槛，
     * 只在写操作上区分身份。
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }
}
