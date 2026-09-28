<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MerchantDomainResource\Pages;
use App\Models\Merchant;
use App\Models\MerchantDomain;
use App\Services\Checkout\CloudflareSaasService;
use App\Services\Checkout\MerchantDomainService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 商户自有域名。收款链接落地页跑在这些域名上。
 *
 * 上线流程（三步，商户在这个页面上完成前两步）：
 *   1. 录入域名 → 系统给出一条 TXT 记录，商户加到自己的 DNS 上；
 *   2. 点「验证」→ 系统查 TXT 确认归属，然后调 Cloudflare API 注册
 *      自定义主机名，CF 返回一条 DCV 记录，商户同样加到 DNS 上；
 *   3. 商户把域名 CNAME 到平台的回退源站主机名，CF 证书生效后即可访问。
 *
 * 证书签发是异步的，checkout:sync-domains 每 15 分钟轮询一次状态。
 */
class MerchantDomainResource extends Resource
{
    protected static ?string $model = MerchantDomain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.payment_settings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.merchant_domain.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.merchant_domain.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $canPickMerchant = (bool) $viewer?->isPlatformStaff();
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();

        return $schema->components([
            Section::make(__('admin.merchant_domain.sections.basic_info'))->schema([
                Select::make('merchant_id')
                    ->label(__('admin.merchant_domain.fields.merchant'))
                    ->options(function () use ($isViewerMerchantManager, $viewer) {
                        if ($isViewerMerchantManager) {
                            return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                        }

                        return Merchant::query()->where('status', true)->pluck('name', 'id');
                    })
                    ->required()
                    ->searchable()
                    ->disabled(! $canPickMerchant)
                    ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                    ->dehydrated(),

                TextInput::make('host')
                    ->label(__('admin.merchant_domain.fields.host'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder('checkout.example.com')
                    ->helperText(__('admin.merchant_domain.help.host'))
                    // 归一化在 Model 的 creating/updating 钩子里统一做（去协议、去端口、
                    // 转小写），这里只做一次前置处理让商户马上看到最终值。
                    ->dehydrateStateUsing(fn (?string $state) => MerchantDomain::normalizeHost($state))
                    ->rules([
                        fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            $host = MerchantDomain::normalizeHost($value);

                            // 根域名不能加 CNAME（DNS 协议限制，除非 DNS 商支持
                            // ALIAS/ANAME），而 CF for SaaS 的接入方式就是 CNAME。
                            // 这里只做提示级别的判断：少于 3 段的基本都是根域名。
                            if (substr_count($host, '.') < 2) {
                                $fail(__('admin.merchant_domain.errors.use_subdomain'));

                                return;
                            }

                            if (! filter_var('https://'.$host, FILTER_VALIDATE_URL)) {
                                $fail(__('admin.merchant_domain.errors.invalid_host'));
                            }
                        },
                    ]),

                Toggle::make('is_active')
                    ->label(__('admin.merchant_domain.fields.is_active'))
                    ->default(true)
                    ->inline(false),
            ])->columns(2),

            // 新建时还没有 verify_token，这一段等保存后才有内容可显示。
            Section::make(__('admin.merchant_domain.sections.dns_setup'))
                ->visible(fn (?MerchantDomain $record) => $record !== null)
                ->schema(function (?MerchantDomain $record): array {
                    $components = [
                        Text::make(fn (MerchantDomain $record) => __('admin.merchant_domain.help.txt_record', [
                            'name' => $record->verifyRecordName(),
                            'value' => $record->verify_token,
                        ])),
                    ];

                    foreach ($record?->cf_dcv_records ?? [] as $dcv) {
                        $components[] = Text::make(__('admin.merchant_domain.help.dcv_record', [
                            'name' => $dcv['name'] ?? '',
                            'value' => $dcv['value'] ?? '',
                        ]));
                    }

                    $components[] = Text::make(fn () => __('admin.merchant_domain.help.cname_record', [
                        'target' => app(CloudflareSaasService::class)->fallbackOrigin() ?: '—',
                    ]));

                    return $components;
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        $columns = [];

        if ((bool) auth()->user()?->isPlatformStaff()) {
            $columns[] = TextColumn::make('merchant.name')
                ->label(__('admin.merchant_domain.fields.merchant'))
                ->searchable()
                ->sortable();
        }

        return $table
            ->columns(array_merge($columns, [
                TextColumn::make('host')->label(__('admin.merchant_domain.fields.host'))->searchable()->copyable(),
                IconColumn::make('verified_at')
                    ->label(__('admin.merchant_domain.columns.ownership'))
                    ->boolean()
                    ->getStateUsing(fn (MerchantDomain $record) => $record->isVerified()),
                TextColumn::make('cf_ssl_status')
                    ->label(__('admin.merchant_domain.columns.certificate'))
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state) => $state === MerchantDomain::CF_STATUS_ACTIVE ? 'success' : 'warning'),
                IconColumn::make('is_active')->label(__('admin.merchant_domain.fields.is_active'))->boolean(),
                TextColumn::make('cf_synced_at')
                    ->label(__('admin.merchant_domain.columns.synced_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ]))
            ->recordActions([
                Action::make('verify')
                    ->label(__('admin.merchant_domain.actions.verify'))
                    ->icon('heroicon-o-shield-check')
                    ->action(function (MerchantDomain $record) {
                        $ready = app(MerchantDomainService::class)->verify($record);
                        $record->refresh();

                        if ($ready) {
                            Notification::make()
                                ->success()
                                ->title(__('admin.merchant_domain.notifications.ready'))
                                ->send();

                            return;
                        }

                        // 区分三种"还没好"：归属没验过、证书还在签发、调用出错。
                        // 笼统地说一句"失败"会让商户完全不知道下一步该做什么。
                        $body = match (true) {
                            ! $record->isVerified() => $record->last_verify_error
                                ?: __('admin.merchant_domain.errors.txt_not_found'),
                            filled($record->cf_last_error) => $record->cf_last_error,
                            default => __('admin.merchant_domain.notifications.certificate_pending'),
                        };

                        Notification::make()
                            ->warning()
                            ->title(__('admin.merchant_domain.notifications.not_ready'))
                            ->body($body)
                            ->persistent()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make()
                    // 本地记录删掉的同时清理 CF 侧的自定义主机名，
                    // 否则会在 CF 那边留下孤儿记录持续计费。
                    ->before(fn (MerchantDomain $record) => app(CloudflareSaasService::class)->delete($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchantDomains::route('/'),
            'create' => Pages\CreateMerchantDomain::route('/create'),
            'edit' => Pages\EditMerchantDomain::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::MERCHANT_DOMAINS_MANAGE);
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
