<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * 商户用户管理。两种使用场景：
 *   - 超级管理员：可以给任意商户建用户、可以勾"设为超级管理员"、
 *     可以看到所有商户下的用户列表。
 *   - 商户管理员（拥有 users.manage 权限）：只能管理自己商户下的用户，
 *     merchant_id 被锁定成自己所在商户，看不到"设为超级管理员"这个开关，
 *     角色下拉框也只会列出自己商户下的角色。
 *
 * 角色赋值没有用 Filament 的 ->relationship() 快捷方式，是因为
 * spatie 的 roles 名称只在"同一商户+guard"下唯一（不是全局唯一），
 * 直接用角色名字做 Select 的 label 会在多商户之间产生歧义；这里改成
 * 用角色 ID 做 value，在 Create/Edit 页面里手动 syncRoles()，
 * 确保赋的是"这个商户下"名字叫这个的角色，不会跟别的商户的同名角色搞混。
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.merchant_settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.user.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.user.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.user.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $isViewerSuperAdmin = (bool) auth()->user()?->is_super_admin;

        return $schema->components([
            Section::make(__('admin.user.sections.account_info'))->schema([
                TextInput::make('name')->label(__('admin.user.fields.name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('admin.user.fields.email'))->email()->required()->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label(__('admin.user.fields.password'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('admin.user.help.password_edit') : null)
                    ->minLength(8),
            ])->columns(2),

            Section::make(__('admin.user.sections.platform_permissions'))
                ->visible($isViewerSuperAdmin)
                ->schema([
                    Toggle::make('is_super_admin')
                        ->label(__('admin.user.fields.is_super_admin'))
                        ->helperText(__('admin.user.help.is_super_admin'))
                        ->live()
                        ->default(false),
                ]),

            Section::make(__('admin.user.sections.merchant_and_roles'))
                ->visible(fn (Get $get) => ! $get('is_super_admin'))
                ->schema([
                    Select::make('merchant_id')
                        ->label(__('admin.user.fields.merchant'))
                        ->options(fn () => Merchant::query()->where('status', true)->pluck('name', 'id'))
                        ->required(fn (Get $get) => ! $get('is_super_admin'))
                        ->live()
                        ->searchable()
                        // 非超管操作者：锁定成自己所在商户，不能选别的商户
                        ->disabled(! $isViewerSuperAdmin)
                        ->default(fn () => $isViewerSuperAdmin ? null : auth()->user()->merchant_id)
                        ->dehydrated(),

                    Select::make('roles')
                        ->label(__('admin.user.fields.roles'))
                        ->multiple()
                        ->options(function (Get $get) {
                            $merchantId = $get('merchant_id');

                            if (! $merchantId) {
                                return [];
                            }

                            return Role::query()
                                ->where('merchant_id', $merchantId)
                                ->pluck('name', 'id');
                        })
                        ->helperText(__('admin.user.help.roles')),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.user.fields.name'))->searchable(),
                TextColumn::make('email')->label(__('admin.user.fields.email'))->searchable(),
                TextColumn::make('merchant.name')->label(__('admin.user.fields.merchant'))->placeholder(__('admin.user.placeholders.platform')),
                TextColumn::make('roles.name')->label(__('admin.user.fields.roles'))->badge(),
                IconColumn::make('is_super_admin')->label(__('admin.user.fields.is_super_admin'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.user.fields.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * User 模型没有走 BelongsToMerchant 那套全局 Scope（它是认证模型，
     * 处理方式特殊），这里手动补一层：非超管只能看到自己商户下的用户。
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()?->is_super_admin) {
            $query->where('merchant_id', auth()->user()->merchant_id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::USERS_MANAGE);
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
        // 不允许在这里删自己，避免误操作把自己权限删没了还进不去后台改回来
        return static::canViewAny() && $record->id !== auth()->id();
    }
}
