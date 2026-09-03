<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SystemConfigResource\Pages;
use App\Models\SystemConfig;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * 系统配置管理（超管专属）：汇率汇损、订单事件同步、付款链接有效期、
 * MFA 强制、通知重试策略等，全部走 system_configs 表。
 *
 * 只提供"编辑"，不提供"新建/删除"——配置项的 key 由 Seeder 统一管理，
 * 后台不应该允许随手加一个野生 config_key，容易和代码里 SystemConfig::get()
 * 读取的 key 对不上，产生"改了后台却不生效"的诡异 bug。
 *
 * 国际化限制：config_key 对应的 description 字段是存在数据库里的
 * （由 SystemConfigSeeder 写入），不是代码里的字符串，这份语言包机制
 * 覆盖不到它——数据库里的文案要翻译，需要另外维护多语言版本的 Seeder
 * 或者做一张 config_translations 表，这里没有实现，仍然会显示 Seeder
 * 写入时用的原始语言。
 */
class SystemConfigResource extends Resource
{
    protected static ?string $model = SystemConfig::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.system_config.nav_label');
    }

    /**
     * 值类型（string/number/json/boolean/image）由 Seeder 统一定好，跟 config_key
     * 一样后台不允许改——改了类型但没同步改代码里读它的方式（get()/getArray()/
     * getBool()），会直接读出脏数据。这里只用它来决定 config_value 该用哪个
     * 控件编辑，参见下面按 value_type 分流出来的这一组 value_* 字段：
     * 编辑页在 EditSystemConfig::mutateFormDataBeforeFill/BeforeSave 里
     * 把它们跟真正的 config_value 互相转换，表单本身不直接编辑 config_value。
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('config_key')->label(__('admin.system_config.fields.config_key'))->disabled(),
                TextInput::make('description')->label(__('admin.system_config.fields.description'))->disabled(),
                Select::make('value_type')
                    ->label(__('admin.system_config.fields.value_type'))
                    ->options(static::valueTypeOptions())
                    ->disabled(),

                TextInput::make('value_string')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->required()
                    ->visible(fn (Get $get) => $get('value_type') === SystemConfig::TYPE_STRING),

                TextInput::make('value_number')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->numeric()
                    ->required()
                    ->visible(fn (Get $get) => $get('value_type') === SystemConfig::TYPE_NUMBER),

                Repeater::make('value_json')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->simple(TextInput::make('value')->required())
                    ->addActionLabel(__('admin.system_config.actions.add_item'))
                    ->visible(fn (Get $get) => $get('value_type') === SystemConfig::TYPE_JSON),

                Toggle::make('value_boolean')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->visible(fn (Get $get) => $get('value_type') === SystemConfig::TYPE_BOOLEAN),

                FileUpload::make('value_image')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->image()
                    ->disk('public')
                    ->directory('system-configs')
                    ->required()
                    ->visible(fn (Get $get) => $get('value_type') === SystemConfig::TYPE_IMAGE),
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected static function valueTypeOptions(): array
    {
        return collect(SystemConfig::VALUE_TYPES)
            ->mapWithKeys(fn (string $type) => [$type => __('admin.system_config.value_types.'.$type)])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('group')->label(__('admin.system_config.fields.group'))->badge(),
                TextColumn::make('config_key')->label(__('admin.system_config.fields.config_key'))->searchable(),
                TextColumn::make('value_type')
                    ->label(__('admin.system_config.fields.value_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.system_config.value_types.'.$state)),
                TextColumn::make('config_value')->label(__('admin.system_config.fields.config_value'))->limit(50),
                TextColumn::make('description')->label(__('admin.system_config.fields.description'))->wrap(),
            ])
            ->filters([
                SelectFilter::make('group')->label(__('admin.system_config.fields.group'))->options([
                    'exchange' => __('admin.system_config.groups.exchange'),
                    'order_event' => __('admin.system_config.groups.order_event'),
                    'payment' => __('admin.system_config.groups.payment'),
                    'security' => __('admin.system_config.groups.security'),
                    'notify' => __('admin.system_config.groups.notify'),
                ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('group');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemConfigs::route('/'),
            'edit' => Pages\EditSystemConfig::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return false;
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
