<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CheckoutLinkResource\Pages;
use App\Models\Application;
use App\Models\CheckoutLink;
use App\Models\Merchant;
use App\Models\MerchantDomain;
use App\Models\PaymentGroup;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\Checkout\CheckoutLinkGroupSelection;
use App\Support\Countries;
use App\Support\Permissions;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
        // 兜底值必须与 SystemConfigSeeder 保持一致并包含 USD（基准币种）。
        $currencies = SystemConfig::getArray('exchange.supported_currencies', ['USD', 'EUR', 'JPY', 'GBP']);

        $record = $schema->getRecord();
        $existingMerchantId = $record instanceof CheckoutLink ? (int) $record->merchant_id : null;

        return $schema->columns(['default' => 1, 'xl' => 12])->components([
            Section::make(__('admin.checkout_link.sections.basic_info'))->schema([
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
                    ->disabled($existingMerchantId !== null || ! $canPickMerchant)
                    ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                    ->dehydrated()
                    ->helperText($existingMerchantId !== null ? __('admin.checkout_link.help.merchant_immutable') : null)
                    ->rule(function () {
                        return function (string $attribute, mixed $value, Closure $fail): void {
                            $actor = auth()->user();
                            $message = app(CheckoutLinkGroupSelection::class)->merchantMessage(
                                $actor instanceof User ? $actor : null,
                                (int) $value,
                            );

                            if ($message !== null) {
                                $fail($message);
                            }
                        };
                    }, $existingMerchantId === null)
                    ->afterStateUpdated(function (Set $set) use ($existingMerchantId) {
                        if ($existingMerchantId !== null) {
                            return;
                        }

                        $set('application_id', null);
                        $set('paymentGroups', []);
                        $set('merchant_domain_id', null);
                    }),
                TextInput::make('link_name')
                    ->label(__('admin.checkout_link.fields.link_name'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder('DE-TVBox-test'),
                TextInput::make('title')
                    ->label(__('admin.checkout_link.fields.title'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder('TV box - model 209343 checkout'),
                Select::make('application_id')
                    ->label(__('admin.checkout_link.fields.application'))
                    ->options(fn (Get $get) => static::scopedOptions(Application::class, $get, 'name', null, $existingMerchantId))
                    ->required()
                    ->searchable(),
                Select::make('merchant_domain_id')
                    ->label(__('admin.checkout_link.fields.domain'))
                    ->options(fn (Get $get) => static::domainOptions($get, $existingMerchantId))
                    ->searchable()
                    ->placeholder(__('admin.checkout_link.placeholders.platform_domain'))
                    ->helperText(__('admin.checkout_link.help.domain')),
                FileUpload::make('logo_path')
                    ->label(__('admin.checkout_link.fields.logo'))
                    ->image()
                    ->disk(config('checkout.media_disk', 'public'))
                    ->directory(config('checkout.logo_directory'))
                    ->visibility('public')
                    ->maxSize(2048),
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
                    ->maxLength(2000),
                Toggle::make('auto_redirect')
                    ->label(__('admin.checkout_link.fields.auto_redirect'))
                    ->default(true),
                Toggle::make('is_active')
                    ->label(__('admin.checkout_link.fields.is_active'))
                    ->default(true)
                    ->inline(false),
            ])->columns(1)->columnSpan(['default' => 1, 'xl' => 5]),

            Group::make([
                ViewField::make('paymentGroups')
                    ->hiddenLabel()
                    ->view('filament.resources.checkout-link-resource.payment-group-panel')
                    ->viewData(function (Get $get) use ($existingMerchantId): array {
                        $merchantId = $existingMerchantId ?? (filled($get('merchant_id')) ? (int) $get('merchant_id') : null);
                        $actor = auth()->user();
                        $selectedIds = collect(is_array($get('paymentGroups')) ? $get('paymentGroups') : [])
                            ->pluck('id')
                            ->map(fn (mixed $id): int => (int) $id)
                            ->all();

                        return [
                            'merchantId' => $merchantId,
                            'groups' => app(CheckoutLinkGroupSelection::class)->panelGroups(
                                $actor instanceof User ? $actor : null,
                                $merchantId,
                                $selectedIds,
                            ),
                        ];
                    })
                    ->default([])
                    ->rule(function (Get $get) use ($existingMerchantId) {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get, $existingMerchantId): void {
                            $merchantId = $existingMerchantId ?? (int) $get('merchant_id');
                            $actor = auth()->user();
                            $message = app(CheckoutLinkGroupSelection::class)->message(
                                $actor instanceof User ? $actor : null,
                                $merchantId,
                                $value,
                            );

                            if ($message !== null) {
                                $fail($message);
                            }
                        };
                    })
                    ->columnSpanFull(),

                Section::make(__('admin.checkout_link.sections.amount'))->schema([
                    Grid::make(3)->schema([
                        // 币种单值、不可多选（需求 1）。客户不能在落地页上切币种，
                        // 否则同一条链接会产生多币种订单，对账口径全乱。
                        Select::make('currency')
                            ->label(__('admin.checkout_link.fields.currency'))
                            ->options(array_combine($currencies, $currencies))
                            ->required()
                            ->searchable(),

                        Select::make('amount_mode')
                            ->label(__('admin.checkout_link.fields.amount_mode'))
                            ->options([
                                CheckoutLink::MODE_FIXED => __('admin.checkout_link.amount_modes.fixed'),
                                CheckoutLink::MODE_RANGE => __('admin.checkout_link.amount_modes.range'),
                            ])
                            ->default(CheckoutLink::MODE_FIXED)
                            ->required()
                            ->live(),

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
                            ->numeric()->minValue(0)->default(0)->required(),
                    ]),
                ]),

                Section::make(__('admin.checkout_link.sections.items'))
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
                                    ->url()->maxLength(500),
                            ])
                            ->columns(4)
                            ->addActionLabel(__('admin.checkout_link.actions.add_item'))
                            ->reorderable()
                            ->orderColumn('sort_order')
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state) => $state['product_name'] ?? null),
                    ]),
            ])->columns(1)->columnSpan(['default' => 1, 'xl' => 7]),
        ]);
    }

    /**
     * 按表单上已选的商户过滤下拉选项。
     *
     * 平台侧账号（超管、商户级管理员）的 merchant_id 是 NULL，全局 MerchantScope
     * 对他们不生效，必须显式用 forMerchant() 过滤，否则会把别家商户的应用/支付组
     * 列出来；商户用户则已被全局 Scope 过滤，直接查即可。
     */
    private static function scopedOptions(string $modelClass, Get $get, string $labelColumn, ?string $activeColumn = null, ?int $lockedMerchantId = null)
    {
        $query = $modelClass::query();

        if ((bool) auth()->user()?->isPlatformStaff()) {
            $merchantId = $lockedMerchantId ?? $get('merchant_id');

            if (blank($merchantId)) {
                // 还没选商户时不给任何选项，逼操作者先选商户——
                // 否则很容易在没选商户的情况下挑到别家的资源。
                return [];
            }

            $query->forMerchant((int) $merchantId);
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
    private static function domainOptions(Get $get, ?int $lockedMerchantId = null)
    {
        $query = MerchantDomain::query()
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->where('cf_ssl_status', MerchantDomain::CF_STATUS_ACTIVE);

        if ((bool) auth()->user()?->isPlatformStaff()) {
            $merchantId = $lockedMerchantId ?? $get('merchant_id');

            if (blank($merchantId)) {
                return [];
            }

            $query->forMerchant((int) $merchantId);
        }

        return $query->pluck('host', 'id');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make(__('admin.checkout_link.sections.basic_info'))->columns(2)->columnSpanFull()->schema([
                TextEntry::make('merchant.name')->label(__('admin.checkout_link.fields.merchant'))
                    ->visible(fn (): bool => (bool) auth()->user()?->isPlatformStaff()),
                TextEntry::make('link_name')->label(__('admin.checkout_link.fields.link_name')),
                TextEntry::make('title')->label(__('admin.checkout_link.fields.title')),
                TextEntry::make('application.name')->label(__('admin.checkout_link.fields.application')),
                TextEntry::make('url')->label(__('admin.checkout_link.columns.url'))
                    ->state(fn (CheckoutLink $record): string => $record->url())->copyable()->columnSpanFull(),
                TextEntry::make('domain.host')->label(__('admin.checkout_link.fields.domain'))
                    ->placeholder(__('admin.checkout_link.placeholders.platform_domain')),
                TextEntry::make('paymentGroup.group_name')->label(__('admin.checkout_link.fields.payment_group')),
                TextEntry::make('payment_groups')->label(__('admin.checkout_link.fields.payment_groups'))
                    ->state(fn (CheckoutLink $record): array => PaymentGroup::query()->forMerchant((int) $record->merchant_id)
                        ->withTrashed()->whereIn('id', $record->payment_group_ids ?? [])->pluck('group_name')->all()),
                TextEntry::make('supported_countries')->label(__('admin.checkout_link.fields.supported_countries')),
                TextEntry::make('customer_notice')->label(__('admin.checkout_link.fields.customer_notice'))->columnSpanFull(),
                IconEntry::make('auto_redirect')->label(__('admin.checkout_link.fields.auto_redirect'))->boolean(),
                IconEntry::make('is_active')->label(__('admin.checkout_link.fields.is_active'))->boolean(),
            ]),
            Section::make(__('admin.checkout_link.sections.amount'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('currency')->label(__('admin.checkout_link.fields.currency')),
                TextEntry::make('amount_mode')->label(__('admin.checkout_link.fields.amount_mode'))
                    ->formatStateUsing(fn (string $state): string => __('admin.checkout_link.amount_modes.'.$state)),
                TextEntry::make('fixed_amount')->label(__('admin.checkout_link.fields.fixed_amount'))
                    ->visible(fn (CheckoutLink $record): bool => $record->isFixedAmount()),
                TextEntry::make('min_amount')->label(__('admin.checkout_link.fields.min_amount'))
                    ->visible(fn (CheckoutLink $record): bool => ! $record->isFixedAmount()),
                TextEntry::make('max_amount')->label(__('admin.checkout_link.fields.max_amount'))
                    ->visible(fn (CheckoutLink $record): bool => ! $record->isFixedAmount()),
                TextEntry::make('shipping_fee')->label(__('admin.checkout_link.fields.shipping_fee')),
                TextEntry::make('tax')->label(__('admin.checkout_link.fields.tax')),
                TextEntry::make('discount')->label(__('admin.checkout_link.fields.discount')),
            ]),
            RepeatableEntry::make('items')->label(__('admin.checkout_link.sections.items'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('product_name')->label(__('admin.checkout_link.fields.product_name')),
                TextEntry::make('unit_price')->label(__('admin.checkout_link.fields.unit_price')),
                TextEntry::make('quantity')->label(__('admin.checkout_link.fields.quantity')),
                TextEntry::make('product_sku')->label(__('admin.checkout_link.fields.product_sku')),
                TextEntry::make('product_url')->label(__('admin.checkout_link.fields.product_url'))->columnSpan(2),
            ]),
        ]);
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
                // 内部名称排在 title 前面：运营在列表里是靠它认链接的，
                // title 是对客文案，扫列表时优先级更低。
                TextColumn::make('link_name')
                    ->label(__('admin.checkout_link.fields.link_name'))
                    ->searchable()
                    ->wrap(),
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
                ViewAction::make()->authorize(fn (CheckoutLink $record): bool => static::canView($record)),
                EditAction::make()->authorize(fn (CheckoutLink $record): bool => static::canEdit($record)),
                DeleteAction::make()->authorize(fn (CheckoutLink $record): bool => static::canDelete($record)),
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
        $user = auth()->user();

        return $user instanceof User && $user->status
            && ($user->can(Permissions::CHECKOUT_LINKS_VIEW) || $user->can(Permissions::CHECKOUT_LINKS_MANAGE));
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->whereKey($record->id)->exists();
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status && $user->can(Permissions::CHECKOUT_LINKS_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate() && static::getEloquentQuery()->whereKey($record->id)->exists();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }
}
