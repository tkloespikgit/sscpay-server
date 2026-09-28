<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CheckoutLinkResource\Pages;
use App\Models\Application;
use App\Models\CheckoutLink;
use App\Models\Merchant;
use App\Models\MerchantDomain;
use App\Models\PaymentGroup;
use App\Models\SystemConfig;
use App\Support\Permissions;
use App\Support\Countries;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 收款链接（Checkout Link）。商户创建一条可反复使用的收银链接，客户打开后
 * 填写联系方式与地址，系统当场建单并跳转支付网关。
 *
 * ⚠️ 和订单详情页里的「付款链接」（orders.payment_link_token）不是一回事：
 * 那个是订单级一次性链接、下单后邮件发给客户；这里是先于订单存在、
 * 可产生任意多笔订单的收银链接。
 *
 * 表单里最要紧的一条规则（需求 3）：配了商品明细或运费/税费，金额就必须是
 * 固定的，而且加起来要和固定金额严格相等。这里在保存时拦一次给出友好提示，
 * OrderCreationService 下单时还会按同一条公式再算一次（2.1 节铁律），
 * 两道都过不了不会建单。
 */
class CheckoutLinkResource extends Resource
{
    protected static ?string $model = CheckoutLink::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.payment_settings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.checkout_link.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.checkout_link.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $canPickMerchant = (bool) $viewer?->isPlatformStaff();
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();
        $currencies = SystemConfig::getArray('exchange.supported_currencies', ['EUR', 'JPY', 'GBP']);

        return $schema->components([
            Section::make(__('admin.checkout_link.sections.basic_info'))->schema([
                Grid::make(2)->schema([
                    Select::make('merchant_id')
                        ->label(__('admin.checkout_link.fields.merchant'))
                        ->options(function () use ($isViewerMerchantManager, $viewer) {
                            if ($isViewerMerchantManager) {
                                return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                            }

                            return Merchant::query()->where('status', true)->pluck('name', 'id');
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->disabled(! $canPickMerchant)
                        ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                        ->dehydrated()
                        // 换商户后，上一个商户的应用/支付组/域名全部失效，
                        // 不清掉会把别家商户的资源挂到这条链接上。
                        ->afterStateUpdated(function (Set $set) {
                            $set('application_id', null);
                            $set('payment_group_id', null);
                            $set('merchant_domain_id', null);
                        }),

                    TextInput::make('title')
                        ->label(__('admin.checkout_link.fields.title'))
                        ->required()
                        ->maxLength(255)
                        ->placeholder('TV box - model 209343 checkout')
                        ->helperText(__('admin.checkout_link.help.title')),
                ]),

                Grid::make(2)->schema([
                    // application 只提供下单身份（订单归属 + 邮件/广告凭证）；
                    // payment_group 决定走哪条通道。两者都直属商户、彼此无关联，
                    // 所以是两个独立的选择，不存在级联过滤。
                    Select::make('application_id')
                        ->label(__('admin.checkout_link.fields.application'))
                        ->options(fn (Get $get) => static::scopedOptions(Application::class, $get, 'name'))
                        ->required()
                        ->searchable()
                        ->helperText(__('admin.checkout_link.help.application')),

                    Select::make('payment_group_id')
                        ->label(__('admin.checkout_link.fields.payment_group'))
                        ->options(fn (Get $get) => static::scopedOptions(PaymentGroup::class, $get, 'group_name', 'is_active'))
                        ->required()
                        ->searchable()
                        ->helperText(__('admin.checkout_link.help.payment_group')),
                ]),

                Grid::make(2)->schema([
                    Select::make('merchant_domain_id')
                        ->label(__('admin.checkout_link.fields.domain'))
                        ->options(fn (Get $get) => static::domainOptions($get))
                        ->searchable()
                        ->placeholder(__('admin.checkout_link.placeholders.platform_domain'))
                        ->helperText(__('admin.checkout_link.help.domain')),

                    FileUpload::make('logo_path')
                        ->label(__('admin.checkout_link.fields.logo'))
                        ->image()
                        // 必须是公开可读的盘：落地页的访问者是未登录的终端客户，
                        // 用不了争议附件那套 temporaryUrl 签名地址。
                        ->disk(config('checkout.media_disk', 'public'))
                        ->directory(config('checkout.logo_directory'))
                        ->visibility('public')
                        ->maxSize(2048)
                        ->helperText(__('admin.checkout_link.help.logo')),
                ]),

                TextInput::make('google_maps_browser_key')
                    ->label(__('admin.checkout_link.fields.google_maps_browser_key'))
                    ->maxLength(255)
                    ->helperText(__('admin.checkout_link.help.google_maps_browser_key')),

                Select::make('supported_countries')
                    ->label(__('admin.checkout_link.fields.supported_countries'))
                    ->options(Countries::withPhoneCodes())
                    ->multiple()
                    ->searchable()
                    ->helperText(__('admin.checkout_link.help.supported_countries')),

                Textarea::make('customer_notice')
                    ->label(__('admin.checkout_link.fields.customer_notice'))
                    ->rows(4)
                    ->maxLength(2000)
                    ->helperText(__('admin.checkout_link.help.customer_notice')),

                Toggle::make('is_active')
                    ->label(__('admin.checkout_link.fields.is_active'))
                    ->default(true)
                    ->inline(false)
                    ->helperText(__('admin.checkout_link.help.is_active')),
            ]),

            Section::make(__('admin.checkout_link.sections.amount'))->schema([
                Grid::make(3)->schema([
                    // 币种单值、不可多选（需求 1）。客户不能在落地页上切币种，
                    // 否则同一条链接会产生多币种订单，对账口径全乱。
                    Select::make('currency')
                        ->label(__('admin.checkout_link.fields.currency'))
                        ->options(array_combine($currencies, $currencies))
                        ->required()
                        ->searchable()
                        ->helperText(__('admin.checkout_link.help.currency')),

                    Select::make('amount_mode')
                        ->label(__('admin.checkout_link.fields.amount_mode'))
                        ->options([
                            CheckoutLink::MODE_FIXED => __('admin.checkout_link.amount_modes.fixed'),
                            CheckoutLink::MODE_RANGE => __('admin.checkout_link.amount_modes.range'),
                        ])
                        ->default(CheckoutLink::MODE_FIXED)
                        ->required()
                        ->live()
                        ->helperText(__('admin.checkout_link.help.amount_mode')),

                    TextInput::make('fixed_amount')
                        ->label(__('admin.checkout_link.fields.fixed_amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->visible(fn (Get $get) => $get('amount_mode') === CheckoutLink::MODE_FIXED)
                        ->required(fn (Get $get) => $get('amount_mode') === CheckoutLink::MODE_FIXED),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('min_amount')
                        ->label(__('admin.checkout_link.fields.min_amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->visible(fn (Get $get) => $get('amount_mode') === CheckoutLink::MODE_RANGE)
                        ->required(fn (Get $get) => $get('amount_mode') === CheckoutLink::MODE_RANGE),

                    TextInput::make('max_amount')
                        ->label(__('admin.checkout_link.fields.max_amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->visible(fn (Get $get) => $get('amount_mode') === CheckoutLink::MODE_RANGE)
                        ->required(fn (Get $get) => $get('amount_mode') === CheckoutLink::MODE_RANGE)
                        ->gte('min_amount'),
                ]),

                Grid::make(3)->schema([
                    TextInput::make('shipping_fee')
                        ->label(__('admin.checkout_link.fields.shipping_fee'))
                        ->numeric()->minValue(0)->default(0)->required(),
                    TextInput::make('tax')
                        ->label(__('admin.checkout_link.fields.tax'))
                        ->numeric()->minValue(0)->default(0)->required(),
                    TextInput::make('discount')
                        ->label(__('admin.checkout_link.fields.discount'))
                        ->numeric()->minValue(0)->default(0)->required()
                        ->helperText(__('admin.checkout_link.help.discount')),
                ]),
            ]),

            Section::make(__('admin.checkout_link.sections.items'))
                ->description(__('admin.checkout_link.help.items_section'))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->schema([
                            TextInput::make('product_name')
                                ->label(__('admin.checkout_link.fields.product_name'))
                                ->required()->maxLength(255)->columnSpan(2),
                            TextInput::make('unit_price')
                                ->label(__('admin.checkout_link.fields.unit_price'))
                                ->numeric()->minValue(0)->required(),
                            TextInput::make('quantity')
                                ->label(__('admin.checkout_link.fields.quantity'))
                                ->numeric()->minValue(1)->default(1)->required(),
                            FileUpload::make('image_path')
                                ->label(__('admin.checkout_link.fields.product_image'))
                                ->image()
                                ->disk(config('checkout.media_disk', 'public'))
                                ->directory(config('checkout.item_image_directory'))
                                ->visibility('public')
                                ->maxSize(2048)
                                ->columnSpan(2),
                            TextInput::make('product_sku')
                                ->label(__('admin.checkout_link.fields.product_sku'))
                                ->maxLength(64),
                            TextInput::make('product_url')
                                ->label(__('admin.checkout_link.fields.product_url'))
                                ->url()->maxLength(500)
                                ->helperText(__('admin.checkout_link.help.product_url')),
                        ])
                        ->columns(4)
                        ->addActionLabel(__('admin.checkout_link.actions.add_item'))
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->defaultItems(0)
                        ->collapsible()
                        ->itemLabel(fn (array $state) => $state['product_name'] ?? null),
                ]),

        ]);
    }

    /**
     * 按表单上已选的商户过滤下拉选项。
     *
     * 平台侧账号（超管、商户级管理员）的 merchant_id 是 NULL，全局 MerchantScope
     * 对他们不生效，必须显式用 forMerchant() 过滤，否则会把别家商户的应用/支付组
     * 列出来；商户用户则已被全局 Scope 过滤，直接查即可。
     */
    private static function scopedOptions(string $modelClass, Get $get, string $labelColumn, ?string $activeColumn = null)
    {
        $query = $modelClass::query();

        if ((bool) auth()->user()?->isPlatformStaff()) {
            if (blank($get('merchant_id'))) {
                // 还没选商户时不给任何选项，逼操作者先选商户——
                // 否则很容易在没选商户的情况下挑到别家的资源。
                return [];
            }

            $query->forMerchant((int) $get('merchant_id'));
        }

        if ($activeColumn) {
            $query->where($activeColumn, true);
        } else {
            $query->where('status', true);
        }

        return $query->pluck($labelColumn, 'id');
    }

    /**
     * 只列出「已就绪」的域名：DNS 归属已验证 + CF 证书已生效。
     * 让商户选一个还没签出证书的域名，等于给客户一个打不开的链接。
     */
    private static function domainOptions(Get $get)
    {
        $query = MerchantDomain::query()
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->where('cf_ssl_status', MerchantDomain::CF_STATUS_ACTIVE);

        if ((bool) auth()->user()?->isPlatformStaff()) {
            if (blank($get('merchant_id'))) {
                return [];
            }

            $query->forMerchant((int) $get('merchant_id'));
        }

        return $query->pluck('host', 'id');
    }

    public static function table(Table $table): Table
    {
        $columns = [];

        if ((bool) auth()->user()?->isPlatformStaff()) {
            $columns[] = TextColumn::make('merchant.name')
                ->label(__('admin.checkout_link.fields.merchant'))
                ->searchable()
                ->sortable();
        }

        return $table
            ->columns(array_merge($columns, [
                TextColumn::make('title')->label(__('admin.checkout_link.fields.title'))->searchable()->wrap(),
                TextColumn::make('url')
                    ->label(__('admin.checkout_link.columns.url'))
                    ->state(fn (CheckoutLink $record) => $record->url())
                    ->copyable()
                    ->copyableState(fn (CheckoutLink $record) => $record->url())
                    ->copyMessage(__('admin.checkout_link.notifications.url_copied'))
                    ->wrap(),
                TextColumn::make('currency')->label(__('admin.checkout_link.fields.currency'))->badge(),
                TextColumn::make('amount')
                    ->label(__('admin.checkout_link.columns.amount'))
                    ->state(fn (CheckoutLink $record) => $record->isFixedAmount()
                        ? number_format((float) $record->fixed_amount, 2)
                        : number_format((float) $record->min_amount, 2).' – '.number_format((float) $record->max_amount, 2)),
                TextColumn::make('orders_count')->label(__('admin.checkout_link.columns.orders_count'))->sortable(),
                IconColumn::make('is_active')->label(__('admin.checkout_link.fields.is_active'))->boolean(),
            ]))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCheckoutLinks::route('/'),
            'create' => Pages\CreateCheckoutLink::route('/create'),
            'edit' => Pages\EditCheckoutLink::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::CHECKOUT_LINKS_MANAGE);
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
