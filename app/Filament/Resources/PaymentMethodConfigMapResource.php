<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodConfigMapResource\Pages;
use App\Models\PaymentMethodConfigMap;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 支付类型配置模板（超管专属）：定义 Stripe/PayPal/Airwallex 等支付类型各自
 * 需要填哪些配置项，供 PaymentMethodResource 创建/编辑时按选中的模板动态渲染
 * 配置表单（见 PaymentMethodResource::form() 里 config_map_id 那部分）。
 *
 * 允许自由新建/改名/编辑字段，但不允许删除——已经被某个 PaymentMethod 引用的
 * 模板如果整条被删掉，那个 PaymentMethod 的 config_map_id 会被置空（见迁移里
 * ->nullOnDelete()），config 里存的值虽然还在但界面上就没地方再编辑了，
 * 所以干脆在后台层面禁止删除，改名/加字段不受影响。
 */
class PaymentMethodConfigMapResource extends Resource
{
    protected static ?string $model = PaymentMethodConfigMap::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.payment_method_config_map.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.payment_method_config_map.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payment_method_config_map.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')
                    ->label(__('admin.payment_method_config_map.fields.name'))
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Stripe'),
                TextInput::make('payment_config_tag')
                    ->label(__('admin.payment_method_config_map.fields.payment_config_tag'))
                    ->required()
                    ->maxLength(100)
                    ->helperText(__('admin.payment_method_config_map.help.payment_config_tag')),
                Radio::make('payment_type')->label(__('crypto.payment_type'))
                    ->options(['fiat' => __('crypto.fiat'), 'crypto' => __('crypto.crypto')])
                    ->default('fiat')->required()->in(['fiat', 'crypto'])->inline()->live()
                    ->disabled(fn(?PaymentMethodConfigMap $record): bool => $record !== null)->dehydrated(),
                Toggle::make('is_active')
                    ->label(__('admin.payment_method_config_map.fields.is_active'))
                    ->default(true)
                    ->inline(false)
            ])->columns(2),

            Section::make(__('admin.payment_method_config_map.sections.fields'))
                ->description(__('admin.payment_method_config_map.help.fields'))
                ->schema([
                    Repeater::make('fields')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('key')
                                ->label(__('admin.payment_method_config_map.fields.field_key'))
                                ->required()
                                ->alphaDash()
                                ->placeholder('secret_key'),
                            TextInput::make('label')
                                ->label(__('admin.payment_method_config_map.fields.field_label'))
                                ->required()
                                ->placeholder('Secret Key'),
                            Select::make('type')->label(__('crypto.field_type'))
                                ->options(['text' => __('crypto.text'), 'select' => __('crypto.select')])
                                ->default('text')->required()->live(),
                            KeyValue::make('options')->label(__('crypto.field_options'))
                                ->visible(fn(Get $get): bool => $get('type') === 'select')
                                ->required(fn(Get $get): bool => $get('type') === 'select'),
                            TextInput::make('default')->label(__('crypto.field_default'))->maxLength(1000),
                            Toggle::make('required')
                                ->label(__('admin.payment_method_config_map.fields.field_required'))
                                ->default(true),
                        ])
                        ->columns(3)
                        ->addActionLabel(__('admin.payment_method_config_map.actions.add_field'))
                        ->minItems(1)
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.payment_method_config_map.fields.name'))->searchable(),
                TextColumn::make('payment_config_tag')->label(__('admin.payment_method_config_map.fields.payment_config_tag'))->searchable(),
                IconColumn::make('is_active')->label(__('admin.payment_method_config_map.fields.is_active'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.payment_method_config_map.fields.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPaymentMethodConfigMaps::route('/'),
            'create' => Pages\CreatePaymentMethodConfigMap::route('/create'),
            'edit'   => Pages\EditPaymentMethodConfigMap::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }
}
