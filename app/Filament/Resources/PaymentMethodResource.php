<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Jobs\SyncSiteProductsJob;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * 支付方式 + 风控阈值配置（3.6 节）。四个阈值字段填 0 表示不限制，
 * 用 helperText 反复强调这一点，避免商户误以为 0 = 禁止交易。
 */
class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

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
        $isViewerSuperAdmin = (bool) auth()->user()?->is_super_admin;

        return $schema->components([
            // 整体两栏布局：基本信息占左侧 2/3，网关配置、风控阈值、手续费三个面板堆叠在最右侧。
            Grid::make(1)->schema([
                Section::make(__('admin.payment_method.sections.basic_info'))->schema([
                    // 商户用户锁定成自己所在商户；超级管理员的 merchant_id 本来就是 NULL，
                    // 不选就直接建，会导致 merchant_id 外键列为空。见 BelongsToMerchant：
                    // 自动回填 merchant_id 只对非超管用户生效，超管必须在这里显式选。

                    TextEntry::make('sub_title')
                        ->hiddenLabel()
                        ->state(new HtmlString('<h3 class="fi-section-header-heading">基本信息</h3>'))
                        ->columnSpanFull(),
                    Select::make('merchant_id')
                        ->label(__('admin.payment_method.fields.merchant'))
                        ->options(fn() => Merchant::query()->where('status', true)->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->disabled(!$isViewerSuperAdmin)
                        ->default(fn() => $isViewerSuperAdmin ? null : auth()->user()->merchant_id)
                        ->dehydrated()
                        ->columnSpanFull(),
                    TextInput::make('method_code')->label(__('admin.payment_method.fields.method_code'))->required()->maxLength(50)->placeholder('paypal / stripe'),
                    TextInput::make('method_name')->label(__('admin.payment_method.fields.method_name'))->required()->maxLength(50),

                    Toggle::make('is_active')->label(__('admin.payment_method.fields.is_active'))->default(true)->inline(false),
                    Toggle::make('sync_logistics')
                        ->label(__('admin.payment_method.fields.sync_logistics'))
                        ->default(true)
                        ->inline(false),
                    Toggle::make('allow_returned_source')
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
                            'url'   => __('admin.payment_method.validation.domain_format'),
                        ])->columnSpanFull(),

                    TextInput::make('domain_client_id')
                        ->label(__('admin.payment_method.fields.domain_client_id'))
                        ->placeholder('ck_xxxxxxxx')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('domain_client_sk')
                        ->label(__('admin.payment_method.fields.domain_client_sk'))
                        ->placeholder('cs_xxxxxxxx')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('order_account')
                        ->label(__('admin.payment_method.fields.order_account'))
                        ->maxLength(255),
                    TextInput::make('order_password')
                        ->label(__('admin.payment_method.fields.order_password'))
                        ->maxLength(255),
                    TextInput::make('config_account')
                        ->label(__('admin.payment_method.fields.config_account'))
                        ->maxLength(255),
                    TextInput::make('config_password')
                        ->label(__('admin.payment_method.fields.config_password'))
                        ->maxLength(255),
                    TextInput::make('payment_config_id')
                        ->label(__('admin.payment_method.fields.payment_config_id'))
                        ->maxLength(255),


                    Select::make('product_match_mode')
                        ->label(__('admin.payment_method.fields.product_match_mode'))
                        // 选项来自系统配置 payment.product_match_modes（见 PaymentMethod::supportedProductMatchModes()）
                        ->options(fn() => static::matchModeOptions())
                        ->default('MATCH')
                        ->columnSpanFull()
                        ->required(),
                    TextInput::make('invoice_prefix')
                        ->label(__('admin.payment_method.fields.invoice_prefix'))
                        ->maxLength(50),
                    TextInput::make('virtual_product_prefix')
                        ->label(__('admin.payment_method.fields.virtual_product_prefix'))
                        ->maxLength(50),


                ])->columns(2)->columnSpan(2),
            ]),
            Grid::make(1)->schema([
                Section::make(__('admin.payment_method.sections.gateway_config'))
                    ->schema([
                        Select::make('config_map_id')
                            ->label(__('admin.payment_method.fields.config_map'))
                            ->options(fn() => PaymentMethodConfigMap::query()->where('is_active',
                                true)->pluck('name',
                                'id'))
                            ->searchable()
                            ->live()
                            // 换了模板之后，上一个模板残留的 config.* 值没有意义，清掉避免脏数据。
                            ->afterStateUpdated(fn(Set $set) => $set('config', [])),

                        // 选完模板后动态展示 webhook 地址：域名取自当前表单的 domain，
                        // 路径尾段 {gateway} 用所选模板的 payment_config_tag 替换。
                        Placeholder::make('webhook_url')
                            ->label(__('admin.payment_method.fields.webhook_url'))
                            ->visible(fn(Get $get) => filled($get('config_map_id')))
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

                            if (!$configMap) {
                                return [];
                            }

                            return collect($configMap->fields)
                                ->map(fn(array $field) => TextInput::make('config.'.$field['key'])
                                    ->label($field['label'])
                                    ->required((bool) ($field['required'] ?? false)))
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
                    ])->columns(2),

                Section::make(__('admin.payment_method.sections.fees'))
                    ->description(__('admin.payment_method.help.fees'))
                    ->schema([
                        TextInput::make('refund_fee')->label(__('admin.payment_method.fields.refund_fee'))->numeric()->default(0)->prefix('$'),
                        TextInput::make('chargeback_fee')->label(__('admin.payment_method.fields.chargeback_fee'))->numeric()->default(0)->prefix('$'),
                    ])->columns(2),
            ])->columnSpan(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        $columns = [];

        // 商户用户在全局 Scope 下只能看到自己的支付方式，商户列没有意义；
        // 超管看全量数据时才需要展示归属商户。
        if ((bool) auth()->user()?->is_super_admin) {
            $columns[] = TextColumn::make('merchant.name')->label(__('admin.payment_method.fields.merchant'))->searchable()->sortable();
        }

        return $table
            ->columns(array_merge($columns, [
                TextColumn::make('method_name')->label(__('admin.payment_method.fields.method_name'))->searchable(),
                TextColumn::make('method_code')->label(__('admin.payment_method.columns.code'))->badge(),
                TextColumn::make('configMap.name')->label(__('admin.payment_method.fields.config_map'))->placeholder('—'),
                TextColumn::make('product_match_mode')
                    ->label(__('admin.payment_method.columns.product_match_mode'))
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => blank($state) ? '—' : static::matchModeLabel($state)),
                TextColumn::make('site_products_count')
                    ->label(__('admin.payment_method.columns.site_products_count'))
                    ->counts('siteProducts')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('max_amount_per_transaction')->label(__('admin.payment_method.columns.per_transaction_limit'))->money('usd'),
                TextColumn::make('max_amount_per_day')->label(__('admin.payment_method.columns.daily_limit'))->money('usd'),
                TextColumn::make('max_amount_per_month')->label(__('admin.payment_method.columns.monthly_limit'))->money('usd'),
                TextColumn::make('refund_fee')->label(__('admin.payment_method.fields.refund_fee'))->money('usd')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('chargeback_fee')->label(__('admin.payment_method.fields.chargeback_fee'))->money('usd')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('sync_logistics')->label(__('admin.payment_method.columns.sync_logistics'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('allow_returned_source')->label(__('admin.payment_method.columns.allow_returned_source'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label(__('admin.payment_method.fields.is_active'))->boolean(),
            ]))
            ->recordActions([
                static::syncProductsAction(),
                static::duplicateAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    /**
     * 商品匹配模式下拉选项：值取自系统配置，文案取 match_modes 翻译，
     * 配置里新增的未翻译模式直接回退展示原始值。
     */
    protected static function matchModeOptions(): array
    {
        return collect(PaymentMethod::supportedProductMatchModes())
            ->mapWithKeys(fn(string $mode) => [$mode => static::matchModeLabel($mode)])
            ->all();
    }

    protected static function matchModeLabel(string $mode): string
    {
        return __('admin.payment_method.match_modes.'.strtolower($mode)).'（'.$mode.'）';
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
            ->requiresConfirmation()
            ->modalHeading(__('admin.payment_method.actions.sync_products_heading'))
            ->modalDescription(__('admin.payment_method.actions.sync_products_desc'))
            ->action(function (PaymentMethod $record) {
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
     * 复制支付方式：整份配置原样复制，仅标识字段（代码/名称）追加 _copy，
     * 副本固定为禁用状态，避免直接可收款；风控阈值、手续费、商户保持不变。
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('admin.payment_method.actions.duplicate'))
            ->icon('heroicon-o-square-2-stack')
            ->requiresConfirmation()
            ->modalHeading(__('admin.payment_method.actions.duplicate_heading'))
            ->modalDescription(__('admin.payment_method.actions.duplicate_desc'))
            ->action(function (PaymentMethod $record) {
                // method_code_uniq 是虚拟生成列，不能出现在 INSERT 里，
                // replicate 默认会把它复制过来，必须显式排除。
                $copy              = $record->replicate(['method_code_uniq']);
                $copy->is_active   = false;
                $copy->method_code = static::nextCopyCode($record);
                $copy->method_name = Str::limit($record->method_name.'_copy', 100, '');

                $copy->save();

                Notification::make()->title(__('admin.payment_method.actions.duplicate_success'))->success()->send();
            });
    }

    /**
     * 生成不与同商户已有记录冲突的副本代码：先试 xxx_copy，
     * 被占用则递增后缀（xxx_copy2、xxx_copy3……）。
     * method_code 唯一约束是软删安全的，只需在未删除记录里查重。
     */
    protected static function nextCopyCode(PaymentMethod $record): string
    {
        $code = Str::limit($record->method_code.'_copy', 50, '');

        $suffix = 2;

        while (PaymentMethod::query()
            ->where('merchant_id', $record->merchant_id)
            ->where('method_code', $code)
            ->exists()) {
            $code = Str::limit($record->method_code.'_copy'.$suffix, 50, '');
            $suffix++;
        }

        return $code;
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit'   => Pages\EditPaymentMethod::route('/{record}/edit'),
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
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }
}
