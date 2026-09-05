<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentGroupResource\Pages;
use App\Filament\Resources\PaymentGroupResource\RelationManagers\PaymentMethodsRelationManager;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Support\Permissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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

        return $schema->components([
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
                    ->disabled(! $canPickMerchant)
                    ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                    ->dehydrated()
                    // 换了商户之后，上一个商户的支付方式选项失效，清掉避免把别家商户的方式挂进来。
                    ->afterStateUpdated(fn (Set $set) => $set('paymentMethods', [])),
                TextInput::make('group_key')
                    ->label(__('admin.payment_group.fields.group_key'))
                    ->required()
                    ->maxLength(50)
                    ->helperText(__('admin.payment_group.help.group_key')),
                TextInput::make('group_name')->label(__('admin.payment_group.fields.group_name'))->required()->maxLength(100),
                Select::make('timezone')
                    ->label(__('admin.payment_group.fields.timezone'))
                    ->options(fn () => collect(\DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $tz) => [$tz => $tz])->all())
                    ->default(fn () => (string) config('app.timezone', 'UTC'))
                    ->searchable()
                    ->helperText(__('admin.payment_group.help.timezone')),
                Toggle::make('is_active')->label(__('admin.payment_group.fields.status'))->default(true)->inline(false),
                Select::make('paymentMethods')
                    ->label(__('admin.payment_group.fields.payment_methods'))
                    ->relationship(
                        'paymentMethods',
                        'method_name',
                        // 搜索走的是 relationship 的动态搜索（绕开下方 options 闭包），
                        // 必须在这里也按所选商户过滤，否则平台侧账号搜索时会搜出别家商户的支付方式。
                        modifyQueryUsing: function ($query, Get $get) use ($canPickMerchant) {
                            if ($canPickMerchant) {
                                if (blank($get('merchant_id'))) {
                                    // 还没选商户时不允许搜出任何支付方式，逼操作者先选商户。
                                    return $query->whereRaw('1 = 0');
                                }

                                $query->where('payment_methods.merchant_id', $get('merchant_id'));
                            }

                            return $query;
                        },
                    )
                    // 平台侧账号（超管、商户级管理员）：按所选商户加载（forMerchant 绕开全局 Scope 显式过滤）；
                    // 商户用户：全局 Scope 已自动过滤到自己商户，不用额外处理。
                    ->options(function (Get $get) use ($canPickMerchant) {
                        if ($canPickMerchant) {
                            $merchantId = $get('merchant_id');

                            if (! $merchantId) {
                                return collect();
                            }

                            return PaymentMethod::query()
                                ->forMerchant((int) $merchantId)
                                ->where('is_active', true)
                                ->pluck('method_name', 'id');
                        }

                        return PaymentMethod::query()
                            ->where('is_active', true)
                            ->pluck('method_name', 'id');
                    })
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->pivotData(['priority' => 100])
                    ->helperText(__('admin.payment_group.help.payment_methods'))
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make(__('admin.payment_group.sections.description'))->schema([
                Textarea::make('description')->label(__('admin.payment_group.fields.description'))->rows(3),
            ]),
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
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }
}
