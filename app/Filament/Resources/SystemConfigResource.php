<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SystemConfigResource\Pages;
use App\Filament\Support\SystemConfigForm;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Services\MerchantRoleProvisioningService;
use App\Support\OrderStatusColor;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;

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
     * 编辑页与列表弹框共用 SystemConfigForm 转换 config_value。
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.system_config.sections.details'))->schema([
                TextInput::make('config_key')
                    ->label(__('admin.system_config.fields.config_key'))
                    ->disabled()
                    ->columnSpanFull(),
                TextInput::make('group')
                    ->label(__('admin.system_config.fields.group'))
                    ->formatStateUsing(fn (?string $state): string => static::groupLabel($state))
                    ->disabled(),
                Select::make('value_type')
                    ->label(__('admin.system_config.fields.value_type'))
                    ->options(static::valueTypeOptions())
                    ->disabled(),
                Textarea::make('description')
                    ->label(__('admin.system_config.fields.description'))
                    ->rows(3)
                    ->disabled()
                    ->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
            Section::make(__('admin.system_config.fields.config_value'))->schema([
                TextInput::make('value_string')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->required()
                    ->visible(fn (Get $get) => $get('value_type') === SystemConfig::TYPE_STRING),

                TextInput::make('value_number')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->numeric()
                    ->required()
                    ->rules(fn (?SystemConfig $record): array => SystemConfigForm::numberRules($record))
                    ->helperText(fn (?SystemConfig $record): ?string => match ($record?->config_key) {
                        PaymentMethod::MAX_FAILED_TIMES_CONFIG_KEY => __('admin.system_config.max_failed_times.helper'),
                        'logistics_tracking.stale_days' => __('admin.system_config.logistics_anomaly.stale_days_helper'),
                        'logistics_tracking.no_delivery_days' => __('admin.system_config.logistics_anomaly.no_delivery_days_helper'),
                        default => null,
                    })
                    ->visible(fn (Get $get) => $get('value_type') === SystemConfig::TYPE_NUMBER),

                Repeater::make('value_json')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->simple(TextInput::make('value')->required())
                    ->addActionLabel(__('admin.system_config.actions.add_item'))
                    ->visible(fn (Get $get, ?SystemConfig $record): bool => $get('value_type') === SystemConfig::TYPE_JSON
                        && $record?->config_key !== OrderStatusColor::CONFIG_KEY),

                Textarea::make('value_json_object')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->helperText(__('admin.system_config.order_status_colors.helper'))
                    ->rows(18)
                    ->required()
                    ->visible(fn (?SystemConfig $record): bool => $record?->config_key === OrderStatusColor::CONFIG_KEY),

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
            ])->columnSpanFull(),
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

    public static function groupLabel(?string $group): string
    {
        if (blank($group)) {
            return __('admin.system_config.ungrouped');
        }

        $key = 'admin.system_config.groups.'.$group;

        return Lang::has($key) ? __($key) : $group;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('config_key')
                    ->label(__('admin.system_config.fields.config_key'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('group')
                    ->label(__('admin.system_config.fields.group'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::groupLabel($state))
                    ->placeholder(__('admin.system_config.ungrouped'))
                    ->sortable(),
                TextColumn::make('config_value')
                    ->label(__('admin.system_config.fields.config_value'))
                    ->formatStateUsing(fn (?string $state, SystemConfig $record): ?string => $record->value_type === SystemConfig::TYPE_BOOLEAN
                        ? __('admin.system_config.boolean_values.'.(filter_var($state, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'))
                        : $state)
                    ->badge(fn (SystemConfig $record): bool => $record->value_type === SystemConfig::TYPE_BOOLEAN)
                    ->color(fn (SystemConfig $record): string => $record->value_type === SystemConfig::TYPE_BOOLEAN
                        && filter_var($record->config_value, FILTER_VALIDATE_BOOLEAN) ? 'success' : 'gray')
                    ->limit(100)
                    ->wrap()
                    ->placeholder(__('admin.system_config.not_set')),
                TextColumn::make('description')
                    ->label(__('admin.system_config.fields.description'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('value_type')
                    ->label(__('admin.system_config.fields.value_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.system_config.value_types.'.$state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('admin.system_config.fields.updated_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make()
                    ->modal()
                    ->modalHeading(__('admin.system_config.actions.edit'))
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->mutateRecordDataUsing(fn (array $data, SystemConfig $record): array => SystemConfigForm::fill($data, $record))
                    ->mutateDataUsing(fn (array $data, SystemConfig $record, EditAction $action): array => SystemConfigForm::save(
                        $data,
                        $record,
                        'mountedActions.'.$action->getNestingIndex().'.data',
                    ))
                    ->after(fn (SystemConfig $record) => Cache::forget('system_config:'.$record->config_key)),
            ])
            ->recordUrl(null)
            ->recordAction('edit')
            ->searchPlaceholder(__('admin.system_config.search_placeholder'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('group')->orderBy('config_key'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemConfigs::route('/'),
            'edit' => Pages\EditSystemConfig::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // 角色模板必须经专用页面保存，确保配置与各商户权限一同更新。
        return parent::getEloquentQuery()
            ->where('config_key', '!=', MerchantRoleProvisioningService::TEMPLATE_CONFIG_KEY);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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
