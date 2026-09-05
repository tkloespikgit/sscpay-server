<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationResource\Pages;
use App\Models\Application;
use App\Models\Merchant;
use App\Support\Permissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 应用管理（4.2 节）。app_id / api_key 只读展示（生成逻辑见
 * Application::createWithCredentials() / generateCredentials()，
 * 表单里不出现这两个字段的可编辑输入框，避免商户手滑改坏凭证）。
 */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.merchant_settings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.application.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.application.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $isViewerSuperAdmin = (bool) $viewer?->is_super_admin;
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();
        $canPickMerchant = $isViewerSuperAdmin || $isViewerMerchantManager;

        return $schema->components([
            Section::make(__('admin.application.sections.basic_info'))->schema([

                Select::make('merchant_id')
                    ->label(__('admin.application.fields.merchant'))
                    ->options(function () use ($isViewerMerchantManager, $viewer) {
                        if ($isViewerMerchantManager) {
                            return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                        }
                        return Merchant::query()->where('status', true)->pluck('name', 'id');
                    })
                    ->required()
                    ->searchable()
                    // 商户级管理员和超管一样可以在自己的商户范围内自由选择，
                    // 只有绑定单一商户的普通商户用户才锁死成自己那一个。
                    ->disabled(! $canPickMerchant)
                    ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                    ->dehydrated(),

                TextInput::make('name')->label(__('admin.application.fields.name'))->required()->maxLength(100),
                TextInput::make('website')
                    ->label(__('admin.application.fields.website'))
                    ->maxLength(255)
                    ->required()
                    ->placeholder('hat.com'),

                Toggle::make('status')->label(__('admin.application.fields.status'))->default(true)->inline(false),
                // 商户用户锁定成自己所在商户；超级管理员的 merchant_id 本来就是 NULL，
                // 不选就直接建，会导致 merchant_id 外键列为空。见 BelongsToMerchant：
                // 自动回填 merchant_id 只对非超管用户生效，超管必须在这里显式选。

            ])->columns(2),

            Section::make(__('admin.application.sections.mail_settings'))->schema([
                Toggle::make('is_order_email_enabled')->label(__('admin.application.fields.is_order_email_enabled'))->default(true),
                TextInput::make('sender_email')->label(__('admin.application.fields.sender_email'))->email()->maxLength(255)->placeholder('notify@hat.com'),
                TextInput::make('sender_name')->label(__('admin.application.fields.sender_name'))->maxLength(255)->placeholder('Hat Shop Support'),
            ])->columns(3),

            Section::make(__('admin.application.sections.remark'))->schema([
                Textarea::make('remark')->label(__('admin.application.fields.remark'))->rows(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.application.fields.name'))->searchable(),
                TextColumn::make('app_id')->label(__('admin.application.fields.app_id'))->copyable()->searchable(),
                TextColumn::make('website')->label(__('admin.application.fields.website')),
                IconColumn::make('is_order_email_enabled')->label(__('admin.application.fields.is_order_email_enabled'))->boolean(),
                IconColumn::make('status')->label(__('admin.application.fields.status'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.application.fields.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApplications::route('/'),
            'create' => Pages\CreateApplication::route('/create'),
            'view' => Pages\ViewApplication::route('/{record}'),
            'edit' => Pages\EditApplication::route('/{record}/edit'),
        ];
    }

    /**
     * 只有拥有 applications.manage 权限的角色（默认是"商户管理员"和
     * "网站应用管理员"，见 MerchantRoleProvisioningService）才能访问。
     * 超级管理员通过 AppServiceProvider 里的 Gate::before 自动放行。
     */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::APPLICATIONS_MANAGE);
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

    public static function canView($record): bool
    {
        return static::canViewAny();
    }
}
