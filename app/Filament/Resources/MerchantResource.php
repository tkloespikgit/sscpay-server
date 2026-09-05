<?php

namespace App\Filament\Resources;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\MerchantResource\Pages;
use App\Filament\Support\FinanceSecurity;
use App\Models\Merchant;
use App\Models\User;
use App\Services\BalanceService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 平台超级管理员和商户级管理员能看到 / 操作这个 Resource（canViewAny 里做判断），
 * 普通商户用户看不到。商户级管理员只能看到/编辑自己 owner_id 名下的商户
 * （getEloquentQuery() 限定），不能删除商户、不能把商户重新指派给别人。
 *
 * 商户是财务/权限的根节点（orders.merchant_id 是 restrictOnDelete），
 * 只要还有订单/用户挂在这个商户下，数据库层面就无法物理删除——
 * 这里不需要额外处理，Filament 默认的 DeleteAction 走的是模型的
 * SoftDeletes（软删除），不会触碰 forceDelete()。
 *
 * 国际化说明：所有面向用户的文案都走 __('admin.xxx') 读 lang/{locale}/admin.php，
 * 代码注释保持中文不翻译（这是给维护这套代码的开发者看的，不是终端用户界面）。
 */
class MerchantResource extends Resource
{
    protected static ?string $model = Merchant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('admin.merchant.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.merchant.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $isViewerSuperAdmin = (bool) auth()->user()?->is_super_admin;

        return $schema->components([
            Section::make(__('admin.merchant.sections.basic_info'))->schema([
                TextInput::make('name')->label(__('admin.merchant.fields.name'))->required()->maxLength(100),
                TextInput::make('contact_person')->label(__('admin.merchant.fields.contact_person'))->required()->maxLength(100),
                TextInput::make('contact_phone')->label(__('admin.merchant.fields.contact_phone'))->required()->maxLength(30),
                TextInput::make('contact_email')->label(__('admin.merchant.fields.contact_email'))->email()->required()->maxLength(255),
                Toggle::make('status')->label(__('admin.merchant.fields.status'))->default(true),
                // 商户归属哪个商户级管理员，只有真超管能看/能改（用来重新指派归属）；
                // 商户级管理员自己建的商户，归属在 CreateMerchant 页面里自动落成自己，不需要也不允许在这里选。
                Select::make('owner_id')
                    ->label(__('admin.merchant.fields.owner'))
                    ->helperText(__('admin.merchant.help.owner'))
                    ->visible($isViewerSuperAdmin)
                    ->options(fn () => User::query()->whereNull('merchant_id')->where('is_super_admin', false)->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder(__('admin.merchant.placeholders.owner_platform')),
            ])->columns(2),

            Section::make(__('admin.merchant.sections.remark'))->schema([
                Textarea::make('remark')->label(__('admin.merchant.fields.remark'))->rows(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.merchant.fields.name'))->searchable(),
                TextColumn::make('contact_person')->label(__('admin.merchant.fields.contact_person')),
                TextColumn::make('contact_email')->label(__('admin.merchant.fields.contact_email')),
                IconColumn::make('status')->label(__('admin.merchant.fields.status'))->boolean(),
                // 归属的商户级管理员，只有真超管需要看这一列（商户级管理员自己看到的
                // 列表本来就已经被 getEloquentQuery() 限定成自己名下的商户）。
                TextColumn::make('owner.name')->label(__('admin.merchant.fields.owner'))
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin)
                    ->placeholder(__('admin.merchant.placeholders.owner_platform')),
                TextColumn::make('balance')->label(__('admin.finance.fields.balance'))->money('usd')->sortable(),
                TextColumn::make('frozen_balance')->label(__('admin.finance.fields.frozen_balance'))->money('usd')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applications_count')->counts('applications')->label(__('admin.merchant.fields.applications_count')),
                TextColumn::make('created_at')->label(__('admin.merchant.fields.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                static::adjustBalanceAction(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * 人工调整某商户余额（增/减）。需要 balance.adjust 权限 + 资金操作强制 2FA。
     * 平台超级管理员可对任意商户操作；商户侧自助入口见余额流水页的同名动作。
     */
    public static function adjustBalanceAction(): Action
    {
        return Action::make('adjustBalance')
            ->label(__('admin.finance.adjust.action'))
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->visible(fn () => auth()->user()->can(Permissions::BALANCE_ADJUST))
            ->modalHeading(__('admin.finance.adjust.action'))
            ->modalDescription(fn (Merchant $record) => __('admin.finance.adjust.current_balance', ['amount' => number_format((float) $record->balance, 2)]))
            ->schema([
                TextInput::make('amount')
                    ->label(__('admin.finance.adjust.amount'))
                    ->helperText(__('admin.finance.adjust.amount_help'))
                    ->numeric()
                    ->required()
                    ->prefix('$'),
                Textarea::make('reason')
                    ->label(__('admin.finance.adjust.reason'))
                    ->required()
                    ->rows(2)
                    ->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (Merchant $record, array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    app(BalanceService::class)->manualAdjust($record, $data['amount'], $user, $data['reason']);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.adjust.success'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchants::route('/'),
            'create' => Pages\CreateMerchant::route('/create'),
            'edit' => Pages\EditMerchant::route('/{record}/edit'),
        ];
    }

    /**
     * 商户级管理员只能看到/管理自己 owner_id 名下的商户，超管不受限制。
     * 普通商户用户没有 merchants.manage 权限，getEloquentQuery() 走不到这里
     * （canViewAny() 已经把整个 Resource 挡掉）。
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()?->is_super_admin) {
            $query->where('owner_id', auth()->id());
        }

        return $query;
    }

    /**
     * 超级管理员和商户级管理员都能访问这个 Resource（普通商户用户看不到
     * "商户管理"这个菜单）；商户级管理员看到的行已经被 getEloquentQuery() 限定。
     */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::MERCHANTS_MANAGE);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    /**
     * 显式声明，不依赖 Filament 在"没有注册 Policy"时的默认行为——
     * 这套系统里所有 Model 都没有配 Laravel Policy 类，全靠 Resource 里
     * 显式的 canX() 方法 + Gate::before 的超管短路来控制权限，
     * 漏写任何一个都可能导致按钮该出现的没出现、或者不该出现的出现了。
     */
    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    /**
     * 商户级管理员只能编辑自己名下的商户；getEloquentQuery() 已经把别人的商户
     * 排除在查询之外，$record 能传进来这里就说明本来就属于自己（或调用方是超管），
     * 这里不用再重复判 owner_id，直接复用 canViewAny() 即可。
     */
    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    /**
     * 删除商户是破坏性操作（关联订单/用户级联影响大），保持超级管理员专属，
     * 不下放给商户级管理员——即便是自己名下的商户也不能自己删。
     */
    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }
}
