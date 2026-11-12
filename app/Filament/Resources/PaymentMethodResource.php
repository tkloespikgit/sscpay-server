<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Filament\Resources\PaymentMethodResource\RelationManagers\PurchaseOrdersRelationManager;
use App\Jobs\SyncSiteProductsJob;
use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Services\CryptoPaymentConfigurationService;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use App\Services\PaymentMethodMerchantAssignmentService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * 支付方式 + 风控阈值配置（3.6 节）。四个阈值字段填 0 表示不限制，
 * 用 helperText 反复强调这一点，避免商户误以为 0 = 禁止交易。
 */
class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    /**
     * 本次恢复因代码冲突而改写的 method_code。成功通知在动作返回后才发送，用它把新代码带过去。
     */
    private static ?string $recoveredMethodCode = null;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.payment_settings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.payment_method.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payment_method.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $isViewerSuperAdmin = (bool) $viewer?->is_super_admin;
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();
        $canPickMerchant = $isViewerSuperAdmin || $isViewerMerchantManager;

        return $schema->components([
            // 两栏布局：基本信息占左侧 2/3，网关配置 / 风控阈值 / 手续费堆叠在右侧 1/3。
            Grid::make(1)->schema([
                Section::make(__('admin.payment_method.sections.basic_info'))->schema([
                    // 商户用户锁定成自己所在商户；超级管理员的 merchant_id 本来就是 NULL，
                    // 不选就直接建，会导致 merchant_id 外键列为空。见 BelongsToMerchant：
                    // 自动回填 merchant_id 只对非超管用户生效，超管必须在这里显式选。

                    TextEntry::make('administrator_name')
                        ->label(__('admin.payment_method.fields.owner'))
                        ->state(fn (?PaymentMethod $record): string => $record === null
                            ? ($viewer?->name ?? '—')
                            : ($record->isSystemLevel() ? ($record->owner?->name ?? __('admin.payment_method.authorization.unowned'))
                                : ($record->merchant?->owner?->name ?? __('admin.payment_method.authorization.platform'))))
                        ->badge()
                        ->color('primary')
                        ->columnSpanFull(),
                    TextEntry::make('sub_title')
                        ->hiddenLabel()
                        ->state(new HtmlString('<h3 class="fi-section-header-heading">基本信息</h3>'))
                        ->columnSpanFull(),
                    Select::make('merchant_id')
                        ->label(__('admin.payment_method.fields.merchant'))
                        ->options(function () use ($isViewerMerchantManager, $viewer) {
                            if ($isViewerMerchantManager) {
                                return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                            }

                            return Merchant::query()->where('status', true)->pluck('name', 'id');
                        })
                        // 超管/商户级管理员可以留空：留空 = 系统级支付方式（挂在自己名下，
                        // 见 PaymentMethod::booted() 里 owner_id 的自动回填），可在下方
                        // 分配给多个商户使用；普通商户用户这个字段被禁用+默认锁定成自己，
                        // 必然有值，required 对他们无影响。
                        ->required(! $canPickMerchant)
                        ->helperText($canPickMerchant ? __('admin.payment_method.help.merchant_blank_for_system_level') : null)
                        ->searchable()
                        ->live()
                        ->disabled(fn (?PaymentMethod $record): bool => ! $canPickMerchant || $record !== null)
                        ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                        ->dehydrated()
                        ->columnSpanFull(),
                    Section::make(__('admin.payment_method.authorization.action'))
                        ->description(__('admin.payment_method.authorization.description'))
                        ->visible(fn (Get $get, ?PaymentMethod $record): bool => $record !== null
                            && blank($get('merchant_id'))
                            && PaymentMethodMerchantAssignmentService::canManage(auth()->user(), $record))
                        ->schema([
                            View::make('filament.resources.payment-method-resource.merchant-authorization')
                                ->viewData(function (?PaymentMethod $record, PaymentMethodMerchantAssignmentService $service): array {
                                    $merchants = $record === null ? collect() : $service->merchants(auth()->user(), $record);

                                    return ['columns' => [
                                        'unassigned' => $merchants->where('is_assigned', false),
                                        'assigned' => $merchants->where('is_assigned', true),
                                    ]];
                                })
                                ->columnSpanFull(),
                        ])
                        ->columnSpanFull(),
                    Select::make('assignedMerchants')
                        ->label(__('admin.payment_method.fields.assigned_merchants'))
                        ->helperText(__('admin.payment_method.help.assigned_merchants'))
                        ->relationship(
                            'assignedMerchants',
                            'name',
                            // relationship() 的选项查询会 left join 中间表 merchant_payment_methods
                            // 做去重/排除已选，中间表自己也有 id 主键列，不加 merchants. 前缀会导致
                            // "Column 'id' in where clause is ambiguous"。
                            modifyQueryUsing: fn ($query) => $query->where('merchants.status', true)
                                ->where(fn (Builder $merchants): Builder => $merchants->where('merchants.owner_id', $viewer->id)
                                    ->when($isViewerSuperAdmin, fn (Builder $direct): Builder => $direct->orWhereNull('merchants.owner_id'))),
                        )
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && $canPickMerchant && blank($get('merchant_id')))
                        ->dehydrated(fn (Get $get, string $operation): bool => $operation === 'create' && $canPickMerchant && blank($get('merchant_id')))
                        ->columnSpanFull(),
                    Textarea::make('remark')
                        ->label(__('admin.payment_method.fields.remark'))
                        ->maxLength(5000)
                        ->rows(3)
                        ->columnSpanFull(),
                    TextInput::make('method_code')
                        ->label(__('admin.payment_method.fields.method_code'))
                        ->required()
                        ->maxLength(50)
                        ->placeholder('paypal / stripe')
                        // 数据库层是靠生成列 method_code_uniq 做的软删安全全局唯一索引
                        // （method_code 在所有未删除记录之间不能重复，不分商户/系统级），
                        // 这里复刻同样的判断规则，保证冲突时是友好的表单校验提示，而不是
                        // 未捕获的 SQL 唯一键冲突异常直接抛到用户面前。
                        // unique() 走的是 Laravel 原生 Rule::unique（直接查表、不经过
                        // Eloquent 全局 Scope），天然是全局口径——商户级管理员当前看不到的
                        // 其它商户/其它管理员的记录同样会算进冲突范围，正是我们要的。
                        // whereNull('deleted_at') 用于对齐生成列"只有未删除记录参与唯一性"
                        // 的语义，否则已软删记录的 code 会被误判成占用、永远无法复用。
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'),
                        )
                        ->validationMessages([
                            'unique' => __('admin.payment_method.validation.method_code_duplicate'),
                        ]),
                    TextInput::make('method_name')->label(__('admin.payment_method.fields.method_name'))->required()->maxLength(50),
                    Radio::make('payment_type')
                        ->label(__('crypto.payment_type'))
                        ->options(['fiat' => __('crypto.fiat'), 'crypto' => __('crypto.crypto')])
                        ->default('fiat')->required()->in(['fiat', 'crypto'])->inline()->live()
                        ->disabled(fn (?PaymentMethod $record): bool => $record !== null)
                        ->dehydrated()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('config_map_id', null);
                            $set('config', []);
                        })
                        ->columnSpanFull(),
                    Placeholder::make('crypto_configuration_help')->hiddenLabel()
                        ->content(__('crypto.configuration_help').' '.__('crypto.public_derivation_help'))
                        ->visible(fn (Get $get): bool => $get('payment_type') === 'crypto')->columnSpanFull(),
                    TextInput::make('display_name')
                        ->label(__('admin.payment_method.fields.display_name'))
                        ->helperText(__('admin.payment_method.help.display_name'))
                        ->maxLength(100),

                    Toggle::make('is_active')->label(__('admin.payment_method.fields.is_active'))->default(true)->inline(false),
                    Toggle::make('sync_logistics')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.sync_logistics'))
                        ->default(true)
                        ->inline(false),
                    Toggle::make('allow_returned_source')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.allow_returned_source'))
                        ->helperText(__('admin.payment_method.help.allow_returned_source'))
                        ->default(true)
                        ->inline(false),

                    TextEntry::make('divider')
                        ->hiddenLabel() // V5 推荐用 ->hiddenLabel() 代替 ->label('')
                        ->state(new HtmlString('<hr class="border-t border-gray-200 dark:border-gray-800 my-5" />'))
                        ->columnSpanFull(),
                    TextEntry::make('sub_title')
                        ->hiddenLabel()
                        ->state(new HtmlString('<h3 class="fi-section-header-heading">电商网站配置</h3>'))
                        ->columnSpanFull(),

                    TextInput::make('domain')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.domain'))
                        ->placeholder('https://example.com')
                        ->required()
                        ->url()
                        // 失焦同步状态，让右侧网关配置里的 webhook 地址能跟随域名实时更新。
                        ->live(onBlur: true)
                        // 强制 https://example.com 格式：https 协议开头 + 合法 URL。
                        ->regex('#^https://#i')
                        ->validationMessages([
                            'regex' => __('admin.payment_method.validation.domain_format'),
                            'url' => __('admin.payment_method.validation.domain_format'),
                        ])->columnSpanFull(),

                    TextInput::make('domain_client_id')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.domain_client_id'))
                        ->placeholder('ck_xxxxxxxx')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('domain_client_sk')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.domain_client_sk'))
                        ->placeholder('cs_xxxxxxxx')
                        ->required()
                        ->maxLength(255),
                    // 支付插件的所有接口（/pay /sync-tracking /gateway-config /order-logs 等）
                    // 统一用上面这对 WooCommerce REST API 密钥做 Basic Auth；
                    // 原「订单账号/密码」「配置账号/密码」四个字段已弃用。
                    // payment_config_id 由保存后的自动同步（syncGatewayConfigAndNotify）回填，
                    // 不再作为可编辑字段展示。

                    Select::make('product_match_mode')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.product_match_mode'))
                        // 选项来自系统配置 payment.product_match_modes（见 PaymentMethod::supportedProductMatchModes()）
                        ->options(fn () => static::matchModeOptions())
                        ->default('MATCH')
                        ->columnSpanFull()
                        ->required(),
                    TextInput::make('invoice_prefix')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.invoice_prefix'))
                        ->maxLength(50),
                    TextInput::make('virtual_product_prefix')
                        ->visible(fn (Get $get): bool => $get('payment_type') !== 'crypto')
                        ->label(__('admin.payment_method.fields.virtual_product_prefix'))
                        ->maxLength(50),
                    TextInput::make('order_no_prefix')
                        ->label(__('admin.payment_method.fields.order_no_prefix'))
                        ->maxLength(10)
                        ->placeholder('PAY'),
                    Select::make('order_no_format')
                        ->label(__('admin.payment_method.fields.order_no_format'))
                        ->options([
                            'numeric' => __('admin.payment_method.order_no_formats.numeric'),
                            'alnum' => __('admin.payment_method.order_no_formats.alnum'),
                        ])
                        ->native(false)
                        ->live()
                        ->helperText(__('admin.payment_method.help.order_no_format')),
                    TextInput::make('order_no_length')
                        ->label(__('admin.payment_method.fields.order_no_length'))
                        ->numeric()
                        ->minValue(15)
                        ->maxValue(30)
                        ->default(20)
                        ->required(fn (Get $get) => filled($get('order_no_format')))
                        ->helperText(__('admin.payment_method.help.order_no_length')),

                ])->columns(2)->columnSpan(2),
            ]),
            Grid::make(1)->schema([
                Section::make(__('admin.payment_method.sections.gateway_config'))
                    ->schema([
                        Select::make('config_map_id')
                            ->label(__('admin.payment_method.fields.config_map'))
                            ->options(fn (Get $get) => PaymentMethodConfigMap::query()->where('is_active', true)
                                ->where('payment_type', $get('payment_type') ?? 'fiat')->pluck('name', 'id'))
                            ->required(fn (Get $get): bool => $get('payment_type') === 'crypto')
                            ->searchable()
                            ->live()
                            ->helperText(__('admin.payment_method.help.config_map'))
                            // 换了模板之后，上一个模板残留的 config.* 值没有意义，清掉避免脏数据。
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $map = PaymentMethodConfigMap::find($get('config_map_id'));
                                $defaults = [];
                                foreach ($map?->fields ?? [] as $field) {
                                    if (array_key_exists('default', $field)) {
                                        $defaults[$field['key']] = $field['default'];
                                    }
                                }
                                $set('config', $defaults);
                            }),

                        // 选完模板后动态展示 webhook 地址：域名取自当前表单的 domain，
                        // 路径尾段 {gateway} 用所选模板的 payment_config_tag 替换。
                        Placeholder::make('webhook_url')
                            ->label(__('admin.payment_method.fields.webhook_url'))
                            ->visible(fn (Get $get) => $get('payment_type') !== 'crypto' && filled($get('config_map_id')))
                            ->content(function (Get $get) {
                                $configMap = $get('config_map_id')
                                    ? PaymentMethodConfigMap::find($get('config_map_id'))
                                    : null;

                                if (blank($configMap?->payment_config_tag)) {
                                    return '—';
                                }

                                $domain = trim((string) $get('domain'));
                                $domain = blank($domain) ? 'https://<你的站点域名>' : rtrim($domain, '/');

                                return $domain.'/wp-json/payment-plugin/v1/webhook/'.$configMap->payment_config_tag;
                            }),

                        Grid::make(2)->schema(function (Get $get) {
                            $configMap = $get('config_map_id')
                                ? PaymentMethodConfigMap::find($get('config_map_id'))
                                : null;

                            if (! $configMap || $configMap->payment_type !== ($get('payment_type') ?? 'fiat')) {
                                return [];
                            }

                            return collect($configMap->fields)
                                ->map(function (array $field) use ($configMap) {
                                    $component = ($field['type'] ?? 'text') === 'select'
                                        ? Select::make('config.'.$field['key'])->options($field['options'] ?? [])
                                        : TextInput::make('config.'.$field['key'])->maxLength(1000);

                                    return $component->label($configMap->payment_type === 'crypto' ? __('crypto.config.'.$field['key']) : $field['label'])
                                        ->default($field['default'] ?? null)->required((bool) ($field['required'] ?? false));
                                })
                                ->all();
                        }),
                    ]),

                Section::make(__('admin.payment_method.sections.risk_control'))
                    ->description(__('admin.payment_method.help.risk_control'))
                    ->schema([
                        TextInput::make('max_amount_per_month')->label(__('admin.payment_method.fields.max_amount_per_month'))->numeric()->default(0)->prefix('$'),
                        TextInput::make('max_amount_per_transaction')->label(__('admin.payment_method.fields.max_amount_per_transaction'))->numeric()->default(0)->prefix('$'),
                        TextInput::make('max_amount_per_day')->label(__('admin.payment_method.fields.max_amount_per_day'))->numeric()->default(0)->prefix('$'),
                        TextInput::make('max_count_per_day')->label(__('admin.payment_method.fields.max_count_per_day'))->numeric()->default(0),
                        TextInput::make('max_failed_times')
                            ->label(__('admin.payment_method.fields.max_failed_times'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(4294967295)
                            ->placeholder(fn (): string => __('admin.payment_method.help.max_failed_times_inherited', ['count' => PaymentMethod::defaultMaxFailedTimes()]))
                            ->helperText(__('admin.payment_method.help.max_failed_times')),
                    ])->columns(2),

                Section::make(__('admin.payment_method.sections.fees'))
                    ->description(__('admin.payment_method.help.fees'))
                    ->visible(fn (?PaymentMethod $record): bool => static::canManageFinancialSettings($record))
                    ->schema([
                        TextInput::make('refund_fee')->label(__('admin.payment_method.fields.refund_fee'))->numeric()->minValue(0)->rules(['decimal:0,2'])->default(0)->prefix('$'),
                        TextInput::make('chargeback_fee')->label(__('admin.payment_method.fields.chargeback_fee'))->numeric()->minValue(0)->rules(['decimal:0,2'])->default(0)->prefix('$'),
                        TextInput::make('fee_percent')
                            ->label(__('admin.payment_method.fields.fee_percent'))
                            ->helperText(__('admin.payment_method.help.transaction_fees'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(99.9999)
                            ->rules(['decimal:0,4'])
                            ->suffix('%')
                            ->live(onBlur: true),
                        TextInput::make('fee_fixed')
                            ->label(__('admin.payment_method.fields.fee_fixed'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->rules(['decimal:0,2'])
                            ->prefix('$')
                            ->live(onBlur: true),
                        TextInput::make('reserve_percent')
                            ->label(__('payment_finance.reserve_percent'))
                            ->helperText(__('payment_finance.reserve_help'))
                            ->numeric()->minValue(0)->maxValue(100)->rules(['decimal:0,4'])->default(0)->suffix('%'),
                        TextInput::make('reserve_days')
                            ->label(__('payment_finance.reserve_days'))
                            ->integer()->minValue(0)->maxValue(36500)->default(0)->suffix(__('payment_finance.days')),
                        Placeholder::make('min_transaction_amount')
                            ->label(__('admin.payment_method.fields.min_transaction_amount'))
                            ->columnSpanFull()
                            ->content(function (Get $get) {
                                $feePercent = (float) ($get('fee_percent') ?? 0);
                                $feeFixed = (float) ($get('fee_fixed') ?? 0);

                                if ($feeFixed <= 0) {
                                    return __('admin.payment_method.help.min_transaction_amount_none');
                                }

                                $remainingRatio = 1 - $feePercent / 100;

                                if ($remainingRatio <= 0) {
                                    return __('admin.payment_method.help.min_transaction_amount_undefined');
                                }

                                $minAmount = number_format($feeFixed / $remainingRatio, 2);

                                return __('admin.payment_method.help.min_transaction_amount', ['amount' => $minAmount]);
                            }),
                    ])->columns(2),
            ])->columnSpan(1),

        ]);
    }

    public static function table(Table $table): Table
    {
        $columns = [];

        // 商户用户在全局 Scope 下只能看到自己的支付方式，商户列没有意义；
        // 平台侧账号（超管、商户级管理员）看到的是多个商户的数据，才需要展示归属商户。
        if ((bool) auth()->user()?->isPlatformStaff()) {
            $columns[] = TextColumn::make('merchant.name')
                ->label(__('admin.payment_method.fields.merchant_or_owner'))
                ->state(fn (PaymentMethod $record): ?string => $record->isSystemLevel()
                    ? $record->owner?->name
                    : $record->merchant?->name)
                ->placeholder(__('admin.payment_method.columns.system_level'))
                ->badge(fn (PaymentMethod $record): bool => $record->isSystemLevel())
                ->icon(fn (PaymentMethod $record): ?Heroicon => $record->isSystemLevel() ? Heroicon::OutlinedServerStack : null)
                ->tooltip(fn (PaymentMethod $record): ?string => $record->isSystemLevel() ? __('admin.payment_method.columns.system_level') : null)
                ->searchable()
                ->sortable();
            $columns[] = TextColumn::make('assigned_merchants_count')
                ->label(__('admin.payment_method.columns.assigned_merchants_count'))
                ->counts('assignedMerchants')
                ->placeholder('—')
                ->formatStateUsing(fn (?int $state) => $state ? (string) $state : '—')
                ->toggleable();
        }

        return $table
            ->description(fn (Pages\ListPaymentMethods $livewire): string => $livewire->financialMerchant
                ? __('payment_finance.selected_merchant_description', ['merchant' => $livewire->financialMerchant->name])
                : __('payment_finance.platform_default_description'))
            ->modifyQueryUsing(function (Builder $query, Pages\ListPaymentMethods $livewire): Builder {
                $query->with('owner');
                $actor = auth()->user();

                if ($actor instanceof User && $actor->isMerchantManager()) {
                    $query->where(fn (Builder $visible): Builder => $visible
                        ->whereIn('payment_methods.merchant_id', $actor->manageableMerchantIds())
                        ->orWhere(fn (Builder $system): Builder => $system->whereNull('payment_methods.merchant_id')->where('payment_methods.owner_id', $actor->id)));
                }

                if ($livewire->hasInvalidFinancialMerchantFilter()) {
                    return $query->whereRaw('1 = 0');
                }

                if ($merchant = $livewire->financialMerchant) {
                    $query->with(['merchantPaymentSettings' => fn (HasMany $settings) => $settings->forMerchant($merchant->id)]);
                }

                return $query;
            })
            ->columns(array_merge($columns, [
                TextColumn::make('payment_type')->label(__('crypto.payment_type'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'crypto' ? __('crypto.crypto') : __('crypto.fiat')),
                TextColumn::make('method_name')->label(__('admin.payment_method.fields.method_name'))->searchable(),
                TextColumn::make('display_name')->label(__('admin.payment_method.fields.display_name'))->searchable()
                    ->placeholder('—'),
                TextColumn::make('method_code')->label(__('admin.payment_method.columns.code'))->badge(),
                TextColumn::make('configMap.name')->label(__('admin.payment_method.fields.config_map'))->placeholder('—'),
                TextColumn::make('product_match_mode')
                    ->label(__('admin.payment_method.columns.product_match_mode'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => blank($state) ? '—' : static::matchModeLabel($state)),
                TextColumn::make('site_products_count')
                    ->label(__('admin.payment_method.columns.site_products_count'))
                    ->counts('siteProducts')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('site_products_exists')
                    ->label(__('admin.payment_method.columns.sync_status'))
                    ->exists('siteProducts')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, PaymentMethod $record) => $record->payment_type === 'crypto'
                        ? '—' : ($state
                        ? __('admin.payment_method.sync_statuses.synced')
                        : __('admin.payment_method.sync_statuses.not_synced')))
                    ->color(fn (bool $state) => $state ? 'success' : 'gray')
                    ->action(static::viewSyncStatusAction()),
                TextColumn::make('max_amount_per_transaction')->label(__('admin.payment_method.columns.per_transaction_limit'))->money('usd'),
                TextColumn::make('max_amount_per_day')->label(__('admin.payment_method.columns.daily_limit'))->money('usd'),
                TextColumn::make('max_amount_per_month')->label(__('admin.payment_method.columns.monthly_limit'))->money('usd'),
                TextColumn::make('consecutive_failed_times')
                    ->label(__('admin.payment_method.fields.consecutive_failed_times'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('refund_fee')->label(__('admin.payment_method.fields.refund_fee'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'refund_fee', $livewire->financialMerchant))
                    ->money('usd')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('chargeback_fee')->label(__('admin.payment_method.fields.chargeback_fee'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'chargeback_fee', $livewire->financialMerchant))
                    ->money('usd')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fee_percent')->label(__('admin.payment_method.fields.fee_percent'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'fee_percent', $livewire->financialMerchant))
                    ->suffix('%')->toggleable(),
                TextColumn::make('fee_fixed')->label(__('admin.payment_method.fields.fee_fixed'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'fee_fixed', $livewire->financialMerchant))
                    ->money('usd')->toggleable(),
                TextColumn::make('reserve_percent')->label(__('payment_finance.reserve_percent'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'reserve_percent', $livewire->financialMerchant))
                    ->suffix('%')->toggleable(),
                TextColumn::make('reserve_days')->label(__('payment_finance.reserve_days'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'reserve_days', $livewire->financialMerchant))
                    ->suffix(__('payment_finance.days'))->toggleable(),
                TextColumn::make('settlement_mode')->label(__('settlement.fields.mode'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'settlement_mode', $livewire->financialMerchant))
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : __('settlement.modes.'.$state))
                    ->visible(fn (Pages\ListPaymentMethods $livewire): bool => $livewire->financialMerchant !== null)
                    ->toggleable(),
                TextColumn::make('settlement_days')->label(__('settlement.fields.cycle_days'))
                    ->state(fn (PaymentMethod $record, Pages\ListPaymentMethods $livewire) => static::displayFinancialSetting($record, 'settlement_days', $livewire->financialMerchant))
                    ->suffix(__('settlement.days'))
                    ->visible(fn (Pages\ListPaymentMethods $livewire): bool => $livewire->financialMerchant !== null)
                    ->toggleable(),
                IconColumn::make('sync_logistics')->label(__('admin.payment_method.columns.sync_logistics'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('allow_returned_source')->label(__('admin.payment_method.columns.allow_returned_source'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label(__('admin.payment_method.fields.is_active'))->boolean(),
                TextColumn::make('deleted_at')
                    ->label(__('admin.payment_method.columns.deleted_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->visible(function (Pages\ListPaymentMethods $livewire): bool {
                        if (! static::viewerIsSuperAdmin()) {
                            return false;
                        }

                        $state = $livewire->getTableFilterState('trashed');

                        return filled(is_array($state) ? ($state['value'] ?? null) : null);
                    }),
            ]))
            ->filters([
                SelectFilter::make('merchant_id')
                    ->label(__('payment_finance.merchant_filter'))
                    ->visible(fn (): bool => auth()->user() instanceof User && auth()->user()->isPlatformStaff())
                    ->options(function (): array {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return [];
                        }

                        $query = Merchant::query()->where('status', true);
                        $merchantIds = $actor->manageableMerchantIds();

                        if ($merchantIds !== null) {
                            $query->whereIn('id', $merchantIds);
                        }

                        return $query->orderBy('name')->pluck('name', 'id')->all();
                    })
                    ->searchable()
                    ->query(function (Builder $query, Pages\ListPaymentMethods $livewire): Builder {
                        if ($livewire->hasInvalidFinancialMerchantFilter()) {
                            return $query->whereRaw('1 = 0');
                        }

                        return ($merchant = $livewire->financialMerchant)
                            ? $query->forMerchant($merchant->id)
                            : $query;
                    }),
                TrashedFilter::make()
                    ->visible(fn (): bool => static::viewerIsSuperAdmin()),
            ])
            ->recordActions([
                static::syncProductsAction(),
                static::duplicateAction(),
                EditAction::make()->visible(fn (PaymentMethod $record): bool => ! $record->trashed() && static::canManageRecord($record)),
                DeleteAction::make()->visible(fn (PaymentMethod $record): bool => ! $record->trashed() && static::canManageRecord($record)),
                static::restoreAction(),
            ])
            ->defaultSort('sort_order');
    }

    protected static function displayFinancialSetting(PaymentMethod $record, string $field, ?Merchant $merchant): string|int|null
    {
        $override = $merchant === null ? null : $record->merchantPaymentSettings->firstWhere('merchant_id', $merchant->id);

        if (in_array($field, ['settlement_mode', 'settlement_days'], true)) {
            return $merchant === null ? null : ($override?->{$field} ?? $merchant->{$field});
        }

        return $override?->{$field} ?? $record->{$field};
    }

    /**
     * 商品匹配模式下拉选项：值取自系统配置，文案取 match_modes 翻译，
     * 配置里新增的未翻译模式直接回退展示原始值。
     */
    protected static function matchModeOptions(): array
    {
        return collect(PaymentMethod::supportedProductMatchModes())
            ->mapWithKeys(fn (string $mode) => [$mode => static::matchModeLabel($mode)])
            ->all();
    }

    protected static function matchModeLabel(string $mode): string
    {
        return __('admin.payment_method.match_modes.'.strtolower($mode)).'（'.$mode.'）';
    }

    /**
     * 同步支付配置的核心逻辑：POST /gateway-config 到站点的支付插件，成功后把返回的
     * config_id 落库到 payment_config_id。Edit 页面的手动同步按钮（用未保存的表单状态）
     * 和保存后的自动同步（用刚落库的 $record）共用这份实现，只是 $data 的来源不同。
     */
    public static function syncGatewayConfigFromData(PaymentMethod $record, array $data): array
    {
        $configMap = filled($data['config_map_id'] ?? null)
            ? PaymentMethodConfigMap::find($data['config_map_id'])
            : null;

        if ($record->payment_type !== 'fiat' || ($data['payment_type'] ?? 'fiat') !== 'fiat' || $configMap?->payment_type === 'crypto') {
            return ['success' => false, 'reason' => 'not_applicable'];
        }

        if (! $configMap || blank($configMap->payment_config_tag)) {
            return ['success' => false, 'reason' => 'missing_tag'];
        }

        if (blank($data['domain_client_id'] ?? null) || blank($data['domain_client_sk'] ?? null)) {
            return ['success' => false, 'reason' => 'missing_credentials'];
        }

        // config_key 插件要求字母数字下划线中划线且 ≤64 字符；
        // 按 支付方式ID + 支付类型标签 生成，重复同步时幂等覆盖旧配置。
        $configKey = Str::limit(
            preg_replace('/[^A-Za-z0-9_-]/', '_', 'pm_'.$record->id.'_'.$configMap->payment_config_tag),
            64,
            ''
        );

        try {
            $result = app(PaymentGatewayService::class)
                ->withConnection(
                    rtrim((string) $data['domain'], '/').'/wp-json/payment-plugin/v1',
                    (string) $data['domain_client_id'],
                    (string) $data['domain_client_sk'],
                )
                ->registerGatewayConfig(
                    $configKey,
                    $configMap->payment_config_tag,
                    (array) ($data['config'] ?? []),
                );
        } catch (PaymentGatewayException $e) {
            return ['success' => false, 'reason' => 'api_error', 'message' => $e->getMessage()];
        }

        $configId = (string) ($result['config_id'] ?? '');
        $record->update(['payment_config_id' => $configId]);

        return ['success' => true, 'config_id' => $configId];
    }

    /**
     * 用 $record 自身已落库的字段做同步，供创建/编辑保存后的自动同步调用。
     */
    public static function syncGatewayConfig(PaymentMethod $record): array
    {
        return static::syncGatewayConfigFromData(
            $record,
            $record->only(['domain', 'domain_client_id', 'domain_client_sk', 'config_map_id', 'config'])
        );
    }

    /**
     * 保存（创建/编辑）后自动触发一次同步，并按用户可读的统一文案提示保存+同步的结果：
     * 同步成功提示已同步，失败提示已保存但同步失败，不打断保存本身。
     */
    public static function syncGatewayConfigAndNotify(PaymentMethod $record): void
    {
        if ($record->payment_type === 'crypto') {
            Notification::make()->success()->title(__('crypto.saved'))->send();

            return;
        }
        $result = static::syncGatewayConfig($record);

        if ($result['success']) {
            Notification::make()
                ->success()
                ->title(__('admin.payment_method.actions.save_sync_success'))
                ->send();

            return;
        }

        Notification::make()
            ->warning()
            ->title(__('admin.payment_method.actions.save_sync_failed'))
            ->send();
    }

    /**
     * 同步网站商品：用站点配置（域名 + WooCommerce REST API 密钥）拉取该网站全部商品，
     * 含变体价格、名称翻译写入 site_products 表。商品量大且翻译限频，派发到队列执行。
     */
    public static function syncProductsAction(): Action
    {
        return Action::make('syncProducts')
            ->label(__('admin.payment_method.actions.sync_products'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (PaymentMethod $record): bool => $record->payment_type === 'fiat' && ! $record->trashed() && static::canManageRecord($record))
            ->requiresConfirmation()
            ->modalHeading(__('admin.payment_method.actions.sync_products_heading'))
            ->modalDescription(__('admin.payment_method.actions.sync_products_desc'))
            ->action(function (PaymentMethod $record) {
                abort_unless($record->payment_type === 'fiat' && static::canManageRecord($record), 403);
                // 旧记录可能尚未补齐站点配置，缺配置时不允许派发。
                if (blank($record->domain) || blank($record->domain_client_id) || blank($record->domain_client_sk)) {
                    Notification::make()
                        ->warning()
                        ->title(__('admin.payment_method.actions.sync_products_missing_credentials'))
                        ->send();

                    return;
                }

                SyncSiteProductsJob::dispatch($record->id);

                Notification::make()
                    ->success()
                    ->title(__('admin.payment_method.actions.sync_products_dispatched'))
                    ->send();
            });
    }

    /**
     * 列表页"商品同步状态"列点击弹窗：展示该网站已同步商品的统计信息
     * （数量、最高/最低价格、上次同步时间）。未同步（site_products 无记录）
     * 时禁用点击，列文案已显示"未同步"，无需弹出空弹窗。
     */
    public static function viewSyncStatusAction(): Action
    {
        return Action::make('viewSyncStatus')
            ->label(__('admin.payment_method.columns.sync_status'))
            ->modalHeading(__('admin.payment_method.modals.sync_status_heading'))
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (Action $action) => $action->label(__('admin.payment_method.modals.close')))
            ->disabled(fn (PaymentMethod $record) => ! $record->site_products_exists)
            ->schema([
                TextEntry::make('site_products_summary_count')
                    ->label(__('admin.payment_method.fields.site_products_summary_count'))
                    ->state(fn (PaymentMethod $record) => $record->siteProducts()->count()),
                TextEntry::make('site_products_summary_price_max')
                    ->label(__('admin.payment_method.fields.site_products_summary_price_max'))
                    ->state(fn (PaymentMethod $record) => $record->siteProducts()->max('price_max'))
                    ->money('usd'),
                TextEntry::make('site_products_summary_price_min')
                    ->label(__('admin.payment_method.fields.site_products_summary_price_min'))
                    ->state(fn (PaymentMethod $record) => $record->siteProducts()->min('price_min'))
                    ->money('usd'),
                TextEntry::make('site_products_summary_synced_at')
                    ->label(__('admin.payment_method.fields.site_products_summary_synced_at'))
                    ->state(fn (PaymentMethod $record) => $record->siteProducts()->max('synced_at'))
                    ->dateTime(),
            ]);
    }

    /**
     * 复制支付方式：整份配置原样复制，仅标识字段（代码/名称）追加 _copy，
     * 副本固定为禁用状态，避免直接可收款；风控阈值、手续费保持不变。
     *
     * 归属规则：商户级管理员复制出来的副本一律变成系统级（merchant_id 置空、
     * owner_id 是自己），即便原记录是某个商户自有的——商户级管理员复制的目的
     * 通常是"拿一份自己维护、再分配给名下多个商户"，而不是给那个商户再加一条。
     * 超管/普通商户用户复制则保持原记录的归属商户不变。
     *
     * 可见性走 canDuplicateRecord() 而不是 canManageRecord()：复制不动原记录，
     * 平台侧账号只要在列表里看得见就该能 fork 一份，见该方法的注释。
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('admin.payment_method.actions.duplicate'))
            ->icon('heroicon-o-square-2-stack')
            ->visible(fn (PaymentMethod $record): bool => ! $record->trashed() && static::canDuplicateRecord($record))
            ->requiresConfirmation()
            ->modalHeading(__('admin.payment_method.actions.duplicate_heading'))
            ->modalDescription(__('admin.payment_method.actions.duplicate_desc'))
            ->action(function (PaymentMethod $record) {
                // method_code_uniq 是虚拟生成列，assigned_merchants_count / site_products_count / site_products_exists
                // 是列表页 withCount/withExists('siteProducts') 附加的聚合别名，都不是真实
                // 表字段，不能出现在 INSERT 里，replicate 默认会把它们复制过来，必须显式排除。
                $copy = $record->replicate([
                    'method_code_uniq', 'assigned_merchants_count', 'site_products_count', 'site_products_exists',
                    'sender_email', 'sender_name', 'mail_driver', 'mail_credentials', 'payment_link_mail_template',
                    'consecutive_failed_times',
                ]);
                $copy->is_active = false;
                // 商户级管理员复制：副本脱离原商户，变成挂在自己名下的系统级支付方式；
                // 其它角色复制：副本继续归属原记录的商户（超管复制系统级记录时也是 NULL）。
                $copy->merchant_id = auth()->user()->isMerchantManager() ? null : $record->merchant_id;
                $copy->method_code = static::nextCopyCode($record->method_code);
                $copy->method_name = Str::limit($record->method_name.'_copy', 100, '');
                // replicate() 会把 owner_id 原样复制过来；系统级支付方式的副本应该归属于
                // 触发复制的这个人（谁复制的归谁管），而不是继续挂在原记录创建人名下，
                // 否则超管复制商户级管理员建的系统级支付方式后，那个管理员依旧管得到副本。
                $copy->owner_id = $copy->isSystemLevel() ? auth()->id() : null;

                $copy->save();

                Notification::make()->title(__('admin.payment_method.actions.duplicate_success'))->success()->send();
            });
    }

    /**
     * 生成全局不冲突的副本代码：先试 xxx_copy，被占用则递增后缀（xxx_copy2、xxx_copy3……）。
     * method_code 是全局唯一的（不分商户/系统级），唯一约束又是软删安全的，
     * 所以查重范围就是"全部未删除记录"。故意 withoutGlobalScopes()：商户级管理员
     * 看不到的其它商户的记录同样占用 code，不算进来会撞唯一键。
     */
    protected static function nextCopyCode(string $baseCode): string
    {
        $code = Str::limit($baseCode.'_copy', 50, '');

        $suffix = 2;

        while (PaymentMethod::query()
            ->withoutGlobalScopes()
            ->where('method_code', $code)
            ->exists()) {
            $code = Str::limit($baseCode.'_copy'.$suffix, 50, '');
            $suffix++;
        }

        return $code;
    }

    public static function getRelations(): array
    {
        return [PurchaseOrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit' => Pages\EditPaymentMethod::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::PAYMENT_METHODS_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && static::canManageRecord($record);
    }

    public static function canManageFinancialSettings(?PaymentMethod $record = null): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->isPlatformStaff()) {
            return false;
        }

        return $record === null || static::canManageRecord($record);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function authorizedFinancialData(array $data, ?PaymentMethod $record = null): array
    {
        $actor = auth()->user();
        $data = Arr::except($data, ['owner_id']);

        if ($record !== null) {
            $data['merchant_id'] = $record->merchant_id;
        }

        if ($actor instanceof User && ! $actor->isPlatformStaff()) {
            $data['merchant_id'] = $actor->merchant_id;
        }

        $data = app(CryptoPaymentConfigurationService::class)->validate($data, $record);

        if (! static::canManageFinancialSettings($record)) {
            return Arr::except($data, MerchantPaymentSetting::FINANCIAL_FIELDS);
        }

        return $data;
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    public static function restoreAction(): RestoreAction
    {
        return RestoreAction::make()
            ->visible(fn (PaymentMethod $record): bool => static::canRestoreRecord($record))
            ->authorize(fn (PaymentMethod $record): bool => static::canRestoreRecord($record))
            ->failureNotificationTitle(__('admin.payment_method.actions.restore_failed'))
            ->successNotification(function (): Notification {
                $code = static::$recoveredMethodCode;
                static::$recoveredMethodCode = null;

                if (! is_string($code)) {
                    return Notification::make()
                        ->success()
                        ->title(__('filament-actions::restore.single.notifications.restored.title'));
                }

                return Notification::make()
                    ->success()
                    ->title(__('admin.payment_method.actions.restore_code_recovered_title'))
                    ->body(__('admin.payment_method.actions.restore_code_recovered_body', ['code' => $code]));
            })
            ->using(function (PaymentMethod $record): bool {
                static::$recoveredMethodCode = null;
                $renamedTo = static::recoverAndRestore($record);

                if ($renamedTo === false) {
                    return false;
                }

                if (is_string($renamedTo)) {
                    static::$recoveredMethodCode = $renamedTo;
                }

                return true;
            });
    }

    public static function canRestoreRecord(PaymentMethod $record): bool
    {
        return static::viewerIsSuperAdmin() && $record->trashed();
    }

    protected static function viewerIsSuperAdmin(): bool
    {
        $viewer = auth()->user();

        return $viewer instanceof User && $viewer->is_super_admin;
    }

    /**
     * 恢复软删除的支付方式。未删除记录之间 method_code 全局唯一；
     * 原代码已被占用时改成仍可用的 `_recover` / `_recover2`…，并返回新代码。
     * 代码未改时返回 true，记录已经不在删除状态时返回 false。
     */
    protected static function recoverAndRestore(PaymentMethod $record): string|bool
    {
        if (! static::canRestoreRecord($record)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($record): string|bool {
                $locked = PaymentMethod::query()
                    ->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->find($record->getKey());

                if (! $locked instanceof PaymentMethod || ! $locked->trashed()) {
                    return false;
                }

                $renamedTo = null;

                if (static::methodCodeIsTaken($locked->method_code, $locked->id)) {
                    $locked->method_code = static::nextRecoverCode($locked->method_code);
                    $renamedTo = $locked->method_code;
                }

                if (! $locked->restore()) {
                    return false;
                }

                return $renamedTo ?? true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * 生成不超过 50 个字符、且未被未删除记录占用的恢复代码。
     * 后缀始终保留；原代码过长时从前面截断。
     */
    protected static function nextRecoverCode(string $baseCode): string
    {
        $code = $baseCode;

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $suffix = $attempt === 1 ? '_recover' : '_recover'.$attempt;
            $code = mb_substr($baseCode, 0, 50 - mb_strlen($suffix)).$suffix;

            if (! static::methodCodeIsTaken($code)) {
                return $code;
            }
        }

        return $code;
    }

    protected static function methodCodeIsTaken(string $code, ?int $ignoreId = null): bool
    {
        return PaymentMethod::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->where('method_code', $code)
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * 商户自有的支付方式：本商户/其商户级管理员/超管可管理。
     * 系统级支付方式（merchant_id 为空）：只有归属管理员（owner_id）本人和超管可管理，
     * 被分配使用的商户只能看、能在支付组里勾选，不能改配置、删除或触发同步
     * ——这些操作全部复用这个方法做统一判断（见下方 syncProductsAction）。
     * 「复制」不在此列，它有更宽的判断，见 canDuplicateRecord()。
     */
    public static function canManageRecord(PaymentMethod $record): bool
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return false;
        }

        if ($viewer->is_super_admin) {
            return true;
        }

        if ($record->merchant_id !== null) {
            return in_array($record->merchant_id, $viewer->manageableMerchantIds() ?? [], true);
        }

        return $record->owner_id !== null && $record->owner_id === $viewer->id;
    }

    /**
     * 平台账号可复制查询范围内的渠道；商户用户只能复制自己可管理的渠道。
     */
    public static function canDuplicateRecord(PaymentMethod $record): bool
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return false;
        }

        if ($viewer->isPlatformStaff()) {
            return true;
        }

        return static::canManageRecord($record);
    }

    /**
     * 取消对某些商户的分配后，把这些商户名下支付组里对该支付方式的引用一并摘除，
     * 避免"已取消分配"的商户继续通过自己的支付组路由到这条支付方式收单。
     * 见 EditPaymentMethod::afterSave()。
     */
    public static function detachFromGroupsOfMerchants(PaymentMethod $record, array $merchantIds): void
    {
        $groupIds = PaymentGroup::query()->whereIn('merchant_id', $merchantIds)->pluck('id');

        if ($groupIds->isNotEmpty()) {
            $record->paymentGroups()->detach($groupIds);
        }
    }
}
