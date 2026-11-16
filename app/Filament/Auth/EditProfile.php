<?php

namespace App\Filament\Auth;

use App\Models\TelegramBot;
use App\Models\User;
use App\Services\TelegramBindingService;
use App\Services\TelegramBotClient;
use App\Services\TelegramNotificationService;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;

/**
 * 后台已经改成"账号登录"（见 App\Filament\Auth\Login / User::emailForAccount()），
 * 账号设置页不应该再让用户自己改登录用的邮箱：把邮箱输入框换成只读展示的账号，
 * disabled() + dehydrated(false) 双重保证——即使前端被绕过，save() 时这个字段
 * 也不会出现在提交的 $data 里，不可能被改掉。
 */
class EditProfile extends BaseEditProfile
{
    protected static bool $shouldRegisterNavigation = false;

    protected Width|string|null $maxWidth = Width::ThreeExtraLarge;

    public ?array $telegramData = [];

    /** @var array{token: string, url: string, expires_at: string, user_id: int}|null */
    #[Locked]
    public ?array $telegramBinding = null;

    #[Locked]
    public ?string $telegramRecipient = null;

    public function mount(): void
    {
        parent::mount();

        if ($this->showsOwnTelegram()) {
            $this->fillOwnTelegram(TelegramBot::query()->forUser($this->currentUser()->id)->first());
        }
    }

    public function content(Schema $schema): Schema
    {
        $components = [$this->getFormContentComponent()];

        if ($this->showsOwnTelegram()) {
            $components[] = Form::make([EmbeddedSchema::make('ownTelegramForm')])
                ->id('own-telegram-form')
                ->livewireSubmitHandler('saveOwnTelegram')
                ->extraAttributes(fn (): array => $this->telegramBinding === null ? [] : ['wire:poll.3s' => 'refreshOwnTelegramBinding']);
        }

        $authentication = $this->getMultiFactorAuthenticationContentComponent();

        if ($authentication !== null) {
            $components[] = $authentication;
        }

        return $schema->components($components);
    }

    public function ownTelegramForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.telegram.nav_label'))
                    ->schema([
                        Select::make('mode')
                            ->label(__('admin.telegram.fields.mode'))
                            ->options([
                                'platform' => __('admin.telegram.modes.platform'),
                                'custom' => __('admin.telegram.modes.custom'),
                            ])
                            ->required()
                            ->live(),
                        Placeholder::make('recipient')
                            ->label(__('admin.telegram.fields.recipient'))
                            ->content(fn (): string => $this->telegramRecipient ?? __('admin.telegram.help.not_bound')),
                        Placeholder::make('quick_binding_help')
                            ->hiddenLabel()
                            ->content(fn (): string => TelegramBotClient::platformIsConfigured()
                                ? __('admin.telegram.help.admin_quick_binding')
                                : __('admin.telegram.help.admin_unavailable'))
                            ->visible(fn (Get $get): bool => $get('mode') === 'platform'),
                        Actions::make([
                            Action::make('bindOwnTelegramPrivate')
                                ->label(__('admin.telegram.actions.bind_private'))
                                ->action(fn (TelegramBindingService $service) => $this->createOwnTelegramBinding('private', $service)),
                            Action::make('bindOwnTelegramGroup')
                                ->label(__('admin.telegram.actions.bind_group'))
                                ->color('gray')
                                ->action(fn (TelegramBindingService $service) => $this->createOwnTelegramBinding('group', $service)),
                        ])
                            ->visible(fn (Get $get): bool => $get('mode') === 'platform' && TelegramBotClient::platformIsConfigured()),
                        Placeholder::make('binding_pending')
                            ->hiddenLabel()
                            ->content(__('admin.telegram.help.binding_pending'))
                            ->visible(fn (Get $get): bool => $get('mode') === 'platform' && $this->telegramBinding !== null),
                        Actions::make([
                            Action::make('openOwnTelegram')
                                ->label(__('admin.telegram.actions.open_telegram'))
                                ->url(fn (): ?string => $this->telegramBinding['url'] ?? null, shouldOpenInNewTab: true),
                        ])
                            ->visible(fn (Get $get): bool => $get('mode') === 'platform' && $this->telegramBinding !== null),
                        TextInput::make('bot_token')
                            ->label(__('admin.telegram.fields.bot_token'))
                            ->password()->revealable()->required()->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('mode') === 'custom'),
                        TextInput::make('chat_id')
                            ->label(__('admin.telegram.fields.chat_id'))
                            ->required()->maxLength(100)->rule('regex:/^-?\d+$/')
                            ->visible(fn (Get $get): bool => $get('mode') === 'custom')
                            ->helperText(__('admin.telegram.help.chat_id')),
                        Toggle::make('is_enabled')
                            ->label(__('admin.telegram.fields.is_enabled'))
                            ->default(true),
                    ])
                    ->footerActions([
                        Action::make('saveOwnTelegram')
                            ->label(__('admin.telegram.actions.save'))
                            ->submit('saveOwnTelegram'),
                        Action::make('sendOwnTelegramTest')
                            ->label(__('admin.telegram.actions.send_test'))
                            ->color('gray')
                            ->action(fn (TelegramNotificationService $service, TelegramBindingService $bindings) => $this->sendOwnTelegramTest($service, $bindings)),
                        Action::make('disconnectOwnTelegram')
                            ->label(__('admin.telegram.actions.disconnect'))
                            ->color('danger')
                            ->requiresConfirmation()
                            ->visible(fn (): bool => $this->telegramRecipient !== null)
                            ->action(fn (TelegramBindingService $service) => $this->disconnectOwnTelegram($service)),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('telegramData');
    }

    public function saveOwnTelegram(TelegramBindingService $bindings): void
    {
        $user = $this->authorizedUser();
        $bindings->saveAdmin($user, $user, $this->ownTelegramForm->getState());
        $this->telegramBinding = null;
        $this->fillOwnTelegram(TelegramBot::query()->forUser($user->id)->first());

        Notification::make()->title(__('admin.telegram.notifications.saved'))->success()->send();
    }

    public function createOwnTelegramBinding(string $chatType, TelegramBindingService $service): void
    {
        $user = $this->authorizedUser();
        $this->telegramBinding = [
            ...$service->createAdminLink($user, $user, $chatType),
            'user_id' => $user->id,
        ];
        $this->telegramData['mode'] = 'platform';
        $this->resetValidation('telegramData.mode');
    }

    public function refreshOwnTelegramBinding(TelegramBindingService $service): void
    {
        $user = $this->authorizedUser();

        if ($this->telegramBinding === null || $this->telegramBinding['user_id'] !== $user->id) {
            $this->telegramBinding = null;

            return;
        }

        $status = $service->adminStatus($user, $user, $this->telegramBinding['token']);

        if ($status === 'pending') {
            return;
        }

        $this->telegramBinding = null;
        $this->fillOwnTelegram(TelegramBot::query()->forUser($user->id)->first());
        $notification = Notification::make()->title(__('admin.telegram.notifications.'.($status === 'bound' ? 'bound' : 'expired')));
        ($status === 'bound' ? $notification->success() : $notification->warning())->send();
    }

    public function sendOwnTelegramTest(TelegramNotificationService $service, TelegramBindingService $bindings): void
    {
        $this->saveOwnTelegram($bindings);

        $sent = $service->sendTestToUser($this->authorizedUser()->id);
        $notification = Notification::make()->title(__('admin.telegram.notifications.'.($sent ? 'test_sent' : 'test_failed')));
        ($sent ? $notification->success() : $notification->danger())->send();
    }

    public function disconnectOwnTelegram(TelegramBindingService $service): void
    {
        $user = $this->authorizedUser();
        $service->disconnectAdmin($user, $user);
        $this->telegramBinding = null;
        $this->fillOwnTelegram(null);
        Notification::make()->title(__('admin.telegram.notifications.disconnected'))->success()->send();
    }

    public static function getLabel(): string
    {
        return __('admin.account_settings.label');
    }

    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)->inlineLabel(false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.account_settings.sections.profile'))
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                    ]),
                Section::make(__('admin.account_settings.sections.password'))
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCurrentPasswordFormComponent(),
                    ]),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()
            ->label(__('admin.user.fields.name'));
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->belowContent(__('admin.user.help.password_edit'));
    }

    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        $component = parent::getMultiFactorAuthenticationContentComponent();

        if ($component instanceof Section) {
            $component->label(__('admin.account_settings.sections.two_factor'));
        }

        return $component;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['account'] = User::accountFromEmail($data['email'] ?? null);

        return $data;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('account')
            ->label(__('admin.user.fields.account'))
            ->disabled()
            ->dehydrated(false);
    }

    /**
     * 父类原本的 visible() 还判断"邮箱有没有改过"（$get('email') !== 当前邮箱）
     * 来决定要不要露出这个字段——但邮箱字段已经被上面换成叫 'account' 且不参与
     * dehydrate，$get('email') 在这里永远拿不到值，那个判断会失真，所以只保留
     * "改了密码才需要验证当前密码"这一条。
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label(__('filament-panels::auth/pages/edit-profile.form.current_password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.current_password.validation_attribute'))
            ->belowContent(__('filament-panels::auth/pages/edit-profile.form.current_password.below_content'))
            ->password()
            ->autocomplete('current-password')
            ->currentPassword(guard: Filament::getAuthGuard())
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }

    private function showsOwnTelegram(): bool
    {
        $user = $this->currentUser();

        return $user->status && $user->merchant_id === null && $user->isPlatformStaff();
    }

    private function authorizedUser(): User
    {
        $user = $this->currentUser();

        if (! $this->showsOwnTelegram()) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function currentUser(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function fillOwnTelegram(?TelegramBot $bot): void
    {
        $this->telegramRecipient = $bot ? ($bot->chat_title ? $bot->chat_title.' · ' : '').$bot->chat_id : null;
        $this->ownTelegramForm->fill([
            'mode' => $bot ? ($bot->uses_platform_bot ? 'platform' : 'custom') : (TelegramBotClient::platformIsConfigured() ? 'platform' : 'custom'),
            'bot_token' => $bot?->uses_platform_bot ? null : $bot?->bot_token,
            'chat_id' => $bot?->chat_id,
            'is_enabled' => $bot?->is_enabled ?? true,
        ]);
    }
}
