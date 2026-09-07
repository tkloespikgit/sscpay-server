<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MerchantResource;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\User;
use App\Rules\UniqueAccountEmail;
use App\Services\MerchantRoleProvisioningService;
use App\Support\Permissions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * 商户入驻分步向导（跟 MerchantResource 的"创建商户"是并存的两条路径，不是替换）：
 * 一次性把"建商户 + 建该商户第一个管理员账号 + 建应用"三步串起来，提交前不落库、
 * 提交时在一个事务里依次创建，避免半成品数据。
 *
 * 第二步不提供角色选择框——角色是 MerchantObserver::created() 在商户建好之后
 * 才 provisionDefaultRoles() 生成的，一次性提交时商户在最终提交前根本不存在，
 * 没法在第二步现选角色；直接固定赋予"商户管理员"角色（新商户的第一个用户就是它的管理员，
 * 符合常见预期）。Telegram 绑定不在这个向导里，仍走独立的 TelegramSettings 页面。
 */
class OnboardMerchant extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected string $view = 'filament.pages.onboard-merchant';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.onboard_merchant.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.onboard_merchant.nav_label');
    }

    public function mount(): void
    {
        $this->form->fill([
            'merchant_status' => true,
            'merchant_timezone' => (string) config('app.timezone', 'UTC'),
            'app_is_order_email_enabled' => true,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isViewerSuperAdmin = (bool) auth()->user()?->is_super_admin;

        return $schema
            ->components([
                Wizard::make([
                    Step::make('merchant')
                        ->label(__('admin.onboard_merchant.steps.merchant.label'))
                        ->description(__('admin.onboard_merchant.steps.merchant.description'))
                        ->schema([
                            TextInput::make('merchant_name')->label(__('admin.merchant.fields.name'))->required()->maxLength(100),
                            TextInput::make('merchant_contact_person')->label(__('admin.merchant.fields.contact_person'))->required()->maxLength(100),
                            TextInput::make('merchant_contact_phone')->label(__('admin.merchant.fields.contact_phone'))->required()->maxLength(30),
                            TextInput::make('merchant_contact_email')->label(__('admin.merchant.fields.contact_email'))->email()->required()->maxLength(255),
                            Toggle::make('merchant_status')->label(__('admin.merchant.fields.status'))->default(true)->inline(false),
                            Select::make('merchant_owner_id')
                                ->label(__('admin.merchant.fields.owner'))
                                ->helperText(__('admin.merchant.help.owner'))
                                ->visible($isViewerSuperAdmin)
                                ->options(fn () => User::query()->whereNull('merchant_id')->where('is_super_admin', false)->pluck('name', 'id'))
                                ->searchable()
                                ->placeholder(__('admin.merchant.placeholders.owner_platform')),
                            Select::make('merchant_timezone')
                                ->label(__('admin.merchant.fields.timezone'))
                                ->options(fn () => collect(\DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $tz) => [$tz => $tz])->all())
                                ->searchable()
                                ->helperText(__('admin.merchant.help.timezone')),
                            Textarea::make('merchant_remark')->label(__('admin.merchant.fields.remark'))->rows(3)->columnSpanFull(),
                        ])->columns(2),

                    Step::make('admin_account')
                        ->label(__('admin.onboard_merchant.steps.admin_account.label'))
                        ->description(__('admin.onboard_merchant.steps.admin_account.description'))
                        ->schema([
                            TextInput::make('admin_name')->label(__('admin.user.fields.name'))->required()->maxLength(255),
                            TextInput::make('admin_account')
                                ->label(__('admin.user.fields.account'))
                                ->helperText(__('admin.user.help.account'))
                                ->required()
                                ->maxLength(190)
                                ->regex('/^\S+$/')
                                ->rule(new UniqueAccountEmail()),
                            TextInput::make('admin_password')
                                ->label(__('admin.user.fields.password'))
                                ->password()
                                ->revealable()
                                ->required()
                                ->minLength(8)
                                ->columnSpanFull(),
                        ])->columns(2),

                    Step::make('application')
                        ->label(__('admin.onboard_merchant.steps.application.label'))
                        ->description(__('admin.onboard_merchant.steps.application.description'))
                        ->schema([
                            TextInput::make('app_name')->label(__('admin.application.fields.name'))->required()->maxLength(100),
                            TextInput::make('app_website')
                                ->label(__('admin.application.fields.website'))
                                ->maxLength(255)
                                ->required()
                                ->placeholder('hat.com'),
                            Toggle::make('app_is_order_email_enabled')->label(__('admin.application.fields.is_order_email_enabled'))->default(true)->columnSpanFull(),
                            RichEditor::make('app_payment_link_mail_template')
                                ->label(__('admin.application.fields.payment_link_mail_template'))
                                ->helperText(__('admin.application.help.payment_link_mail_template'))
                                ->placeholder(__('admin.application.placeholders.payment_link_mail_template'))
                                ->columnSpanFull(),
                        ])->columns(2),
                ])
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="submit" size="sm">
                            {{ __('admin.onboard_merchant.actions.submit') }}
                        </x-filament::button>
                    BLADE)))
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $data = $this->form->getState();

        $merchant = DB::transaction(function () use ($data) {
            $user = auth()->user();

            $merchant = Merchant::create([
                'owner_id' => $user->isMerchantManager() ? $user->id : ($data['merchant_owner_id'] ?? null),
                'name' => $data['merchant_name'],
                'contact_person' => $data['merchant_contact_person'],
                'contact_phone' => $data['merchant_contact_phone'],
                'contact_email' => $data['merchant_contact_email'],
                'status' => $data['merchant_status'],
                'timezone' => $data['merchant_timezone'],
                'remark' => $data['merchant_remark'],
            ]);

            $roleService = app(MerchantRoleProvisioningService::class);

            $admin = User::create([
                'merchant_id' => $merchant->id,
                'name' => $data['admin_name'],
                'email' => User::emailForAccount($data['admin_account']),
                'email_verified_at' => now(),
                'password' => $data['admin_password'],
            ]);

            if ($adminRole = $roleService->merchantAdminRole($merchant)) {
                $admin->assignRole($adminRole);
            }

            Application::createWithCredentials([
                'merchant_id' => $merchant->id,
                'name' => $data['app_name'],
                'website' => $data['app_website'],
                'is_order_email_enabled' => $data['app_is_order_email_enabled'],
                'payment_link_mail_template' => $data['app_payment_link_mail_template'],
            ]);

            return $merchant;
        });

        Notification::make()->title(__('admin.onboard_merchant.notifications.created'))->success()->send();

        $this->redirect(MerchantResource::getUrl('edit', ['record' => $merchant]));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return (bool) auth()->user()?->can(Permissions::MERCHANTS_MANAGE);
    }
}
