<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ObserverResource\Pages;
use App\Models\Observer;
use App\Models\PaymentMethod;
use App\Rules\UniqueObserverAccountEmail;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
 * 观察者账户管理：只有平台侧账号（超级管理员 / 商户级管理员）可见/可操作，
 * 见 canViewAny()。观察者是完全独立于 users 表的一套账号（App\Models\Observer，
 * 见该类注释），这里只负责建号 + 绑定支付方式，登录后能看到什么见
 * App\Filament\Observer\Resources\OrderResource。
 *
 * 支付方式多选框直接用 ->relationship('paymentMethods', ...)，选项查询会自动套用
 * PaymentMethod 的 BelongsToMerchant 全局 Scope——商户级管理员登录时自动只列出
 * 自己名下商户的支付方式，超级管理员不受限，不需要在这里手动重复一遍范围判断
 * （同理 getEloquentQuery() 的商户级管理员分支）。
 */
class ObserverResource extends Resource
{
    protected static ?string $model = Observer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-eye';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.observer');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.observer.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.observer.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.observer.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $isViewerSuperAdmin = (bool) auth()->user()?->is_super_admin;

        return $schema->components([
            Section::make(__('admin.observer.sections.account_info'))->schema([
                TextInput::make('account')
                    ->label(__('admin.observer.fields.account'))
                    ->helperText(__('admin.observer.help.account'))
                    ->required()
                    ->maxLength(190)
                    ->regex('/^\S+$/')
                    ->rule(fn (?Model $record) => new UniqueObserverAccountEmail($record?->getKey())),
                TextInput::make('password')
                    ->label(__('admin.observer.fields.password'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('admin.observer.help.password_edit') : null)
                    ->minLength(8),
                Toggle::make('status')
                    ->label(__('admin.observer.fields.status'))
                    ->helperText(__('admin.observer.help.status'))
                    ->default(true),
            ])->columns(2),

            Section::make(__('admin.observer.sections.payment_methods'))->schema([
                // relationship() 负责保存时自动同步中间表。多选的 Select 默认就是
                // searchable（Select::isSearchable() 在没显式设置时回退到 isMultiple()，
                // 见 vendor/filament/forms/src/Components/Select.php），searchable
                // 状态下 Filament 不会预加载完整选项列表——不但下拉框看起来是空的，
                // 连带提交时用来做"选的值是否合法"校验的候选集也是空的，导致无论选中
                // 哪个支付方式提交都会报"The selected ... is invalid."（之前在这里
                // 真实踩过，误以为是"绑不了支付方式"）。必须显式加 ->preload()
                // 强制预加载，写法对齐 PaymentGroupResource 里同样是 PaymentMethod
                // 多选的 paymentMethods 字段。列表范围已经通过 PaymentMethod 的
                // MerchantScope 自动按商户级管理员的可管理范围收窄，不需要在这里
                // 手动重复一遍范围判断。
                Select::make('paymentMethods')
                    ->label(__('admin.observer.fields.payment_methods'))
                    ->relationship('paymentMethods', 'method_name')
                    ->getOptionLabelFromRecordUsing(fn (PaymentMethod $record) => "{$record->merchant?->name} - {$record->method_name}")
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText(__('admin.observer.help.payment_methods')),
            ]),

            // 金额显示比例：只有超级管理员能看到/修改，商户级管理员完全不渲染这个字段
            // （不是禁用，是不出现）。服务端兜底见 CreateObserver/EditObserver，
            // 防止绕过前端直接提交篡改值。
            Section::make(__('admin.observer.sections.display_ratio'))
                ->visible($isViewerSuperAdmin)
                ->schema([
                    TextInput::make('amount_display_ratio')
                        ->label(__('admin.observer.fields.amount_display_ratio'))
                        ->helperText(__('admin.observer.help.amount_display_ratio'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(100),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label(__('admin.observer.fields.account'))
                    ->formatStateUsing(fn (string $state): string => Observer::accountFromEmail($state))
                    ->searchable(),
                TextColumn::make('paymentMethods.method_name')
                    ->label(__('admin.observer.fields.payment_methods'))
                    ->badge()
                    ->limitList(3),
                TextColumn::make('amount_display_ratio')
                    ->label(__('admin.observer.fields.amount_display_ratio'))
                    ->suffix('%')
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin),
                IconColumn::make('status')->label(__('admin.observer.fields.status'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.observer.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('status')->label(__('admin.observer.fields.status')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * 商户级管理员只看到"绑定的支付方式里至少有一个在自己管理范围内"的观察者——
     * whereHas 的子查询同样会自动套用 PaymentMethod 的 MerchantScope，不需要
     * 手动传 manageableMerchantIds()。超级管理员不受限，看全部。
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->is_super_admin) {
            return $query;
        }

        return $query->whereHas('paymentMethods');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListObservers::route('/'),
            'create' => Pages\CreateObserver::route('/create'),
            'edit' => Pages\EditObserver::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isPlatformStaff();
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
