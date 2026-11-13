<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationResource\Pages;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\User;
use App\Rules\ShipmentSyncUrl;
use App\Services\ShipmentSyncProbeService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->dehydrated()
                    ->columnSpanFull(),

                TextInput::make('name')->label(__('admin.application.fields.name'))->required()->maxLength(100),
                TextInput::make('website')
                    ->label(__('admin.application.fields.website'))
                    ->maxLength(255)
                    ->required()
                    ->placeholder('hat.com'),

                Toggle::make('is_combine_payment_enabled')
                    ->label(__('combined.enable'))
                    ->helperText(__('combined.enable_help'))
                    ->default(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set, bool $state): void {
                        if ($state) {
                            $set('webhook_version', 2);
                        }
                    })
                    ->inline(false),

                Select::make('webhook_version')
                    ->label(__('combined.webhook_version'))
                    ->options([1 => __('combined.webhook_v1'), 2 => __('combined.webhook_v2')])
                    ->default(1)
                    ->required()
                    ->disabled(fn (Get $get): bool => (bool) $get('is_combine_payment_enabled'))
                    ->helperText(__('combined.webhook_help')),

                Toggle::make('is_auto_discount_enabled')
                    ->label(__('admin.application.fields.is_auto_discount_enabled'))
                    ->helperText(__('admin.application.help.auto_discount'))
                    ->default(false)
                    ->inline(false),

                Toggle::make('is_logistics_tracking_enabled')
                    ->label(__('admin.application.fields.is_logistics_tracking_enabled'))
                    ->helperText(__('admin.application.help.logistics_tracking'))
                    ->default(false)
                    ->inline(false),

                Toggle::make('status')->label(__('admin.application.fields.status'))->default(true)->inline(false),
                Toggle::make('is_shipment_sync_enabled')
                    ->label(__('admin.application.fields.is_shipment_sync_enabled'))
                    ->default(false)
                    ->live()
                    ->inline(false),
                TextInput::make('shipment_sync_url')
                    ->label(__('admin.application.fields.shipment_sync_url'))
                    ->helperText(__('admin.application.help.shipment_sync_url'))
                    ->required(fn (Get $get): bool => (bool) $get('is_shipment_sync_enabled'))
                    ->rules([new ShipmentSyncUrl])
                    ->maxLength(2048)
                    ->suffixAction(static::probeShipmentSyncUrlAction()),

            ])->columns(2)->headerActions([static::apiDocumentationAction()]),

            Section::make(__('admin.application.sections.mail_settings'))
                ->description(__('admin.application.help.mail_settings'))
                ->schema([
                    Toggle::make('is_order_email_enabled')->label(__('admin.application.fields.is_order_email_enabled'))->default(true)->columnSpanFull(),
                    RichEditor::make('payment_link_mail_template')
                        ->label(__('admin.application.fields.payment_link_mail_template'))
                        ->helperText(__('admin.application.help.payment_link_mail_template'))
                        ->placeholder(__('admin.application.placeholders.payment_link_mail_template'))
                        ->extraInputAttributes(['style' => 'min-height: 20rem;'])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function apiDocumentationAction(): Action
    {
        return Action::make('apiDocumentation')
            ->label(__('admin.application.actions.api_documentation'))
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->url(fn (): string => route('api.documentation', [
                'locale' => app()->getLocale() === 'en' ? 'en' : 'zh_CN',
            ]))
            ->openUrlInNewTab();
    }

    private static function probeShipmentSyncUrlAction(): Action
    {
        return Action::make('probeShipmentSyncUrl')
            ->label(__('admin.application.shipment_probe.label'))
            ->icon('heroicon-o-signal')
            ->button()
            ->color('gray')
            ->authorize(fn (?Application $record): bool => static::canProbeShipmentSyncUrl($record))
            ->action(function (Get $get, ?Application $record, ShipmentSyncProbeService $probe): void {
                abort_unless(static::canProbeShipmentSyncUrl($record), 403);

                $result = $probe->probe((string) $get('shipment_sync_url'));
                $outcome = $result['outcome'];
                $notification = Notification::make()
                    ->title(__('admin.application.shipment_probe.titles.'.$outcome))
                    ->body(__('admin.application.shipment_probe.messages.'.$outcome, ['status' => $result['status']]));

                match ($outcome) {
                    'reachable' => $notification->success(),
                    'requires_post', 'redirect', 'rejected' => $notification->warning(),
                    default => $notification->danger(),
                };

                $notification->send();
            });
    }

    private static function canProbeShipmentSyncUrl(?Application $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->status || ! $user->can(Permissions::APPLICATIONS_MANAGE)) {
            return false;
        }

        $merchantIds = $user->manageableMerchantIds();

        return ! $record || ($merchantIds === null || in_array((int) $record->merchant_id, $merchantIds, true));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([static::apiDocumentationAction()])
            ->columns([
                TextColumn::make('name')->label(__('admin.application.fields.name'))->searchable(),
                // 商户用户自己看到的应用本来就只属于自己商户，这一列只对平台侧账号
                // （超管、商户级管理员）有意义，见 isPlatformStaff()。
                TextColumn::make('merchant.name')->label(__('admin.application.fields.merchant'))
                    ->visible(fn () => (bool) auth()->user()?->isPlatformStaff())
                    ->searchable(),
                TextColumn::make('app_id')->label(__('admin.application.fields.app_id'))->copyable()->searchable(),
                TextColumn::make('website')->label(__('admin.application.fields.website'))->searchable(),
                IconColumn::make('is_order_email_enabled')->label(__('admin.application.fields.is_order_email_enabled'))->boolean(),
                IconColumn::make('is_shipment_sync_enabled')->label(__('admin.application.fields.is_shipment_sync_enabled'))->boolean(),
                TextColumn::make('sender_email')->label(__('admin.mail_credentials.fields.sender_email'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mail_driver')->label(__('admin.mail_credentials.fields.mail_driver'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('status')->label(__('admin.application.fields.status'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.application.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                // Application 自带 MerchantScope，商户用户只会看到自己名下的应用，
                // 这个筛选只对能看到多个商户的平台侧账号（超管、商户级管理员）有意义。
                SelectFilter::make('merchant_id')
                    ->label(__('admin.application.filters.merchant'))
                    ->visible(fn () => (bool) auth()->user()?->isPlatformStaff())
                    ->options(function () {
                        $user = auth()->user();

                        if ($user->isMerchantManager()) {
                            return $user->ownedMerchants()->orderBy('name')->pluck('name', 'id');
                        }

                        return Merchant::query()->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable(),

                Filter::make('app_id')
                    ->label(__('admin.application.filters.app_id'))
                    ->schema([
                        TextInput::make('app_id')->label(__('admin.application.filters.app_id')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['app_id'] ?? null,
                        fn ($q, $appId) => $q->where('app_id', 'like', '%'.$appId.'%')
                    )),

                Filter::make('website')
                    ->label(__('admin.application.filters.website'))
                    ->schema([
                        TextInput::make('website')->label(__('admin.application.filters.website')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['website'] ?? null,
                        fn ($q, $website) => $q->where('website', 'like', '%'.$website.'%')
                    )),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
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
