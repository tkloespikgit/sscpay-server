<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentGroupResource\Pages;
use App\Filament\Resources\PaymentGroupResource\RelationManagers\PaymentMethodsRelationManager;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Services\PaymentGroupMethodService;
use App\Support\Permissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 支付组（3.6 节）。group_key 是商户下单接口里传的 group_key，
 * 修改要谨慎——商户端代码里硬编码了这个值，改了这里也要通知商户同步改。
 *
 * 创建和编辑均按左侧基本信息 5/12、右侧组内支付方式 7/12 排列。
 * Logo、统计时区和描述保留在基本信息内，窄屏改为上下排列。
 */
class PaymentGroupResource extends Resource
{
    protected static ?string $model = PaymentGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.payment_settings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.payment_group.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payment_group.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $isViewerSuperAdmin = (bool) $viewer?->is_super_admin;
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();
        $canPickMerchant = $isViewerSuperAdmin || $isViewerMerchantManager;
        $isEdit = $schema->getOperation() === 'edit';

        return $schema->columns($isEdit ? 1 : ['default' => 1, 'lg' => 1, 'xl' => 12])->components([
            Section::make(__('admin.payment_group.sections.basic_info'))->schema([
                // 商户用户锁定成自己所在商户；超管/商户级管理员的 merchant_id 为 NULL，
                // 见 BelongsToMerchant：自动回填只对绑定单一商户的普通用户生效，两者都必须显式选。
                Select::make('merchant_id')
                    ->label(__('admin.payment_group.fields.merchant'))
                    ->options(function () use ($isViewerMerchantManager, $viewer) {
                        if ($isViewerMerchantManager) {
                            return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                        }

                        return Merchant::query()->where('status', true)->pluck('name', 'id');
                    })
                    ->required()
                    ->searchable()
                    ->live()
                    ->disabled(fn (string $operation): bool => $operation === 'edit' || ! $canPickMerchant)
                    ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                    ->dehydrated()
                    ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('admin.payment_group.help.merchant_immutable') : null)
                    // 换了商户之后，上一个商户的支付方式选项失效，清掉避免把别家商户的方式挂进来。
                    ->afterStateUpdated(fn (Set $set) => $set('paymentMethods', [])),
                TextInput::make('group_key')
                    ->label(__('admin.payment_group.fields.group_key'))
                    ->required()
                    ->maxLength(50)
                    ->helperText(__('admin.payment_group.help.group_key')),
                TextInput::make('group_name')->label(__('admin.payment_group.fields.group_name'))->required()->maxLength(100),
                Toggle::make('is_active')->label(__('admin.payment_group.fields.status'))->default(true)->inline(false),
                Toggle::make('is_combine_payment_enabled')
                    ->label(__('combined.enable'))
                    ->helperText(__('combined.enable_help'))
                    ->default(false)
                    ->live()
                    ->inline(false),
                TextInput::make('max_combined_amount')
                    ->label(__('combined.max_amount'))
                    ->helperText(__('combined.max_amount_help'))
                    ->numeric()
                    ->suffix('USD')
                    ->rules(['decimal:0,2', 'min:0.01', 'max:9999999999999.99'])
                    ->required(fn (Get $get): bool => (bool) $get('is_combine_payment_enabled'))
                    ->visible(fn (Get $get): bool => (bool) $get('is_combine_payment_enabled')),
                FileUpload::make('logo_path')
                    ->label(__('admin.payment_group.fields.logo'))
                    ->image()
                    // 落地页访问者是未登录客户，必须走公开可读的盘（同收款链接 Logo）。
                    ->disk(config('checkout.media_disk', 'public'))
                    ->directory(config('checkout.payment_group_logo_directory', 'payment-groups/logos'))
                    ->visibility('public')
                    ->maxSize(2048)
                    // 落地页按固定尺寸渲染组卡片，这里把上传图统一压到 320×120
                    // （cover：先等比缩放再居中裁切），避免商户传不同比例的图把版面顶乱。
                    // ⚠️ Filament v5 的缩放是**浏览器端** FilePond 插件做的（参数序列化后交给前端，
                    //    见 FileUpload 的 automaticallyResizeImages*），不是服务端处理：
                    //    SVG 不会被缩放，绕过前端直传也会跳过。落地页另有 CSS 兜底
                    //    （h-12 / max-w-[160px] / object-contain），所以 320×120 是引导值而非常量约束。
                    ->automaticallyResizeImagesMode('cover')
                    ->automaticallyResizeImagesToWidth('320')
                    ->automaticallyResizeImagesToHeight('120')
                    ->helperText(__('admin.payment_group.help.logo'))
                    ->columnSpanFull(),
                Select::make('timezone')
                    ->label(__('admin.payment_group.fields.timezone'))
                    ->options(fn () => collect(\DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $tz) => [$tz => $tz])->all())
                    ->default(fn () => (string) config('app.timezone', 'UTC'))
                    ->searchable()
                    ->helperText(__('admin.payment_group.help.timezone'))
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('admin.payment_group.fields.description'))
                    ->rows(2)
                    ->columnSpanFull(),
            ])->columns(2)->columnSpan($isEdit ? 'full' : ['default' => 1, 'xl' => 5]),

            ...($isEdit ? [] : [ViewField::make('paymentMethods')
                ->label(__('admin.payment_group.fields.payment_methods'))
                ->hiddenLabel()
                ->view('filament.resources.payment-group-resource.create-payment-method-panel')
                ->viewData(function (Get $get, PaymentGroupMethodService $service): array {
                    $merchantId = filled($get('merchant_id')) ? (int) $get('merchant_id') : null;

                    return [
                        'merchantId' => $merchantId,
                        'methods' => $service->availableForMerchant(auth()->user(), $merchantId),
                    ];
                })
                ->default([])
                ->rules(['array', 'list'])
                ->columnSpan(['default' => 1, 'xl' => 7])]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $columns = [];

        // 商户用户在全局 Scope 下只能看到自己的支付组，商户列没有意义；
        // 平台侧账号（超管、商户级管理员）看到多个商户的数据时才需要展示归属商户。
        if ((bool) auth()->user()?->isPlatformStaff()) {
            $columns[] = TextColumn::make('merchant.name')->label(__('admin.payment_group.fields.merchant'))->searchable()->sortable();
        }

        return $table
            ->columns(array_merge($columns, [
                TextColumn::make('group_name')->label(__('admin.payment_group.fields.group_name'))->searchable(),
                TextColumn::make('group_key')->label(__('admin.payment_group.fields.group_key'))->badge()->copyable(),
                TextColumn::make('timezone')->label(__('admin.payment_group.fields.timezone'))->default(config('app.timezone', 'UTC')),
                TextColumn::make('payment_methods_count')->counts('paymentMethods')->label(__('admin.payment_group.columns.payment_methods_count')),
                IconColumn::make('is_active')->label(__('admin.payment_group.fields.is_active'))->boolean(),
                IconColumn::make('is_combine_payment_enabled')->label(__('combined.enable'))->boolean(),
                TextColumn::make('max_combined_amount')->label(__('combined.max_amount'))->money('USD'),
            ]))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PaymentMethodsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentGroups::route('/'),
            'create' => Pages\CreatePaymentGroup::route('/create'),
            'edit' => Pages\EditPaymentGroup::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::PAYMENT_GROUPS_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return $record instanceof PaymentGroup && PaymentGroupMethodService::canManage(auth()->user(), $record);
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }
}
