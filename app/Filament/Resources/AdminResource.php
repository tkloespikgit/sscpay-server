<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminResource\Pages;
use App\Models\User;
use App\Rules\UniqueAccountEmail;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 平台管理员名单：只展示不挂靠任何商户的平台侧账号——超级管理员和商户级管理员
 * （merchant_id 均为 NULL，见 User::isMerchantManager()），不展示商户自己的
 * 普通管理员/操作员账号（那些在"商户配置 > 用户管理"，见 UserResource）。
 *
 * 仅真超级管理员可见/可操作，商户级管理员看不到这份名单（含自己）——
 * 和 SystemConfigResource 一样的"平台专属敏感资源"权限模式：canViewAny()
 * 直接判 is_super_admin，不走 Permissions 权限位（Gate::before 对超管的
 * 短路本来就已经保证了这一点，这里的直接判断是为了让商户级管理员也被挡住）。
 */
class AdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.admin.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.admin.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.admin.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.admin.sections.account_info'))->schema([
                TextInput::make('name')->label(__('admin.admin.fields.name'))->required()->maxLength(255),
                TextInput::make('account')
                    ->label(__('admin.admin.fields.account'))
                    ->helperText(__('admin.admin.help.account'))
                    ->required()
                    ->maxLength(190)
                    ->regex('/^\S+$/')
                    ->rule(fn (?Model $record) => new UniqueAccountEmail($record?->getKey())),
                TextInput::make('password')
                    ->label(__('admin.admin.fields.password'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('admin.admin.help.password_edit') : null)
                    ->minLength(8),
                Toggle::make('status')
                    ->label(__('admin.admin.fields.status'))
                    ->helperText(__('admin.admin.help.status'))
                    ->default(true),
            ])->columns(2),

            Section::make(__('admin.admin.sections.account_type'))->schema([
                Toggle::make('is_super_admin')
                    ->label(__('admin.admin.fields.is_super_admin'))
                    ->helperText(__('admin.admin.help.is_super_admin'))
                    ->default(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.admin.fields.name'))->searchable(),
                TextColumn::make('email')
                    ->label(__('admin.admin.fields.account'))
                    ->formatStateUsing(fn (string $state): string => User::accountFromEmail($state))
                    ->searchable(),
                TextColumn::make('is_super_admin')
                    ->label(__('admin.admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('admin.admin.types.super_admin')
                        : __('admin.admin.types.merchant_manager'))
                    ->color(fn (bool $state): string => $state ? 'danger' : 'warning'),
                IconColumn::make('status')->label(__('admin.admin.fields.status'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('status')->label(__('admin.admin.fields.status')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * 只看平台侧账号：merchant_id 为 NULL 就是超级管理员或商户级管理员
     * （二者互斥地覆盖了这个条件，见 User::isMerchantManager()），
     * 挂靠具体商户的普通用户/管理员一律不在这份名单里。
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('merchant_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdmins::route('/'),
            'create' => Pages\CreateAdmin::route('/create'),
            'edit' => Pages\EditAdmin::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
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
