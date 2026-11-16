<?php

namespace App\Filament\Pages;

use App\Models\Merchant;
use App\Models\MerchantLogisticsTrackingSetting;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use App\Services\LogisticsTracking\LogisticsTrackingManager;
use App\Services\TelegramBindingService;
use App\Services\TelegramBotClient;
use App\Services\TelegramNotificationService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ServiceSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.service-settings';

    public ?array $merchantData = [];

    public ?array $timezoneData = [];

    public ?array $logisticsData = [];

    public ?array $telegramData = [];

    /** @var array{token: string, url: string, expires_at: string, merchant_id: int}|null */
    #[Locked]
    public ?array $telegramBinding = null;

    #[Locked]
    public ?string $telegramRecipient = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.merchant_settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.service_settings.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.service_settings.nav_label');
    }

    public function mount(): void
    {
        $user = auth()->user();

        $this->merchantForm->fill([
            'merchant_id' => $user->isPlatformStaff() ? null : $user->merchant_id,
        ]);
        $this->loadSettings();
    }

    public function merchantForm(Schema $schema): Schema
    {
        $viewer = auth()->user();

        return $schema
            ->components([
                Select::make('merchant_id')
                    ->label(__('admin.service_settings.fields.merchant'))
                    ->options(function () use ($viewer): Collection {
                        $query = Merchant::query()->where('status', true);
                        $merchantIds = $viewer->manageableMerchantIds();

                        if ($merchantIds !== null) {
                            $query->whereIn('id', $merchantIds);
                        }

                        return $query->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required()
                    ->disabled(! $viewer->isPlatformStaff())
                    ->dehydrated()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadSettings()),
            ])
            ->statePath('merchantData');
    }

    public function timezoneForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.merchant.fields.timezone'))
                    ->schema([
                        Select::make('timezone')
                            ->label(__('admin.merchant.fields.timezone'))
                            ->options(fn (): array => collect(\DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $tz): array => [$tz => $tz])->all())
                            ->searchable()
                            ->helperText(__('admin.merchant.help.timezone')),
                    ])
                    ->footerActions([
                        Action::make('saveTimezone')
                            ->label(__('admin.service_settings.actions.save'))
                            ->submit('saveTimezone'),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('timezoneData');
    }

    public function logisticsForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.logistics_tracking.nav_label'))
                    ->key('logisticsTracking')
                    ->schema([
                        Select::make('driver')
                            ->label(__('admin.logistics_tracking.fields.driver'))
                            ->options(fn (): array => app(LogisticsTrackingManager::class)->availableDriverOptions())
                            ->required()
                            ->live()
                            ->helperText(__('admin.logistics_tracking.help.driver')),
                        TextInput::make('base_url')
                            ->label(__('admin.logistics_tracking.fields.base_url'))
                            ->url()
                            ->maxLength(255)
                            ->placeholder(fn (Get $get): ?string => $get('driver') === SeventeenTrackDriver::NAME
                                ? SeventeenTrackDriver::DEFAULT_BASE_URL
                                : null)
                            ->visible(fn (Get $get): bool => filled($get('driver')))
                            ->helperText(fn (Get $get): string => $this->credentialHelp('base_url', $get('driver'))),
                        TextInput::make('api_token')
                            ->label(__('admin.logistics_tracking.fields.api_token'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText(fn (Get $get): string => $this->credentialHelp('api_token', $get('driver'))),
                        TextInput::make('webhook_secret')
                            ->label(__('admin.logistics_tracking.fields.webhook_secret'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText(fn (Get $get): string => $this->credentialHelp('webhook_secret', $get('driver'))),
                        Placeholder::make('webhook_url_display')
                            ->label(__('admin.logistics_tracking.fields.webhook_url'))
                            ->content(function (): HtmlString {
                                $url = (string) ($this->logisticsData['webhook_url'] ?? '');

                                return new HtmlString($url === ''
                                    ? e(__('admin.logistics_tracking.help.webhook_url_empty'))
                                    : '<code class="text-sm break-all">'.e($url).'</code>');
                            })
                            ->helperText(__('admin.logistics_tracking.help.webhook_url'))
                            ->columnSpanFull(),
                    ])
                    ->footerActions([
                        Action::make('saveLogisticsTracking')
                            ->label(__('admin.logistics_tracking.actions.save'))
                            ->submit('saveLogisticsTracking'),
                        Action::make('regenerateWebhookToken')
                            ->label(__('admin.logistics_tracking.actions.regenerate_webhook_token'))
                            ->color('gray')
                            ->requiresConfirmation()
                            ->action(fn () => $this->regenerateWebhookToken()),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('logisticsData');
    }

    public function telegramForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.telegram.nav_label'))
                    ->key('telegramSettings')
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
                                ? __('admin.telegram.help.quick_binding')
                                : __('admin.telegram.help.unavailable'))
                            ->visible(fn (Get $get): bool => $get('mode') === 'platform'),
                        Actions::make([
                            Action::make('bindTelegramPrivate')
                                ->label(__('admin.telegram.actions.bind_private'))
                                ->action(fn (TelegramBindingService $service) => $this->createTelegramBinding('private', $service)),
                            Action::make('bindTelegramGroup')
                                ->label(__('admin.telegram.actions.bind_group'))
                                ->color('gray')
                                ->action(fn (TelegramBindingService $service) => $this->createTelegramBinding('group', $service)),
                        ])
                            ->key('telegramBindingActions')
                            ->visible(fn (Get $get): bool => $get('mode') === 'platform' && TelegramBotClient::platformIsConfigured()),
                        Placeholder::make('binding_pending')
                            ->hiddenLabel()
                            ->content(__('admin.telegram.help.binding_pending'))
                            ->visible(fn (Get $get): bool => $get('mode') === 'platform' && $this->telegramBinding !== null),
                        Actions::make([
                            Action::make('openTelegram')
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
                        Action::make('saveTelegram')
                            ->label(__('admin.telegram.actions.save'))
                            ->submit('saveTelegram'),
                        Action::make('sendTest')
                            ->label(__('admin.telegram.actions.send_test'))
                            ->color('gray')
                            ->action(fn (TelegramNotificationService $service, TelegramBindingService $bindings) => $this->sendTest($service, $bindings)),
                        Action::make('disconnectTelegram')
                            ->label(__('admin.telegram.actions.disconnect'))
                            ->color('danger')
                            ->requiresConfirmation()
                            ->visible(fn (): bool => $this->telegramRecipient !== null)
                            ->action(fn (TelegramBindingService $service) => $this->disconnectTelegram($service)),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('telegramData');
    }

    public function saveTimezone(): void
    {
        $merchantId = $this->selectedMerchantId();
        $timezone = $this->timezoneForm->getState()['timezone'] ?? null;
        $timezone = filled($timezone) ? (string) $timezone : null;

        if ($timezone !== null && ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages([
                'timezoneData.timezone' => __('admin.service_settings.validation.timezone'),
            ]);
        }

        Merchant::query()->whereKey($merchantId)->update(['timezone' => $timezone]);

        Notification::make()->title(__('admin.service_settings.notifications.timezone_saved'))->success()->send();
    }

    public function saveLogisticsTracking(): void
    {
        abort_unless(static::canManageLogisticsTracking(), 403);

        $merchantId = $this->selectedMerchantId();
        $data = $this->logisticsForm->getState();
        $driver = (string) $data['driver'];

        if (! app(LogisticsTrackingManager::class)->isDriverConfigured($driver)) {
            throw ValidationException::withMessages([
                'logisticsData.driver' => __('admin.logistics_tracking.validation.driver_unavailable'),
            ]);
        }

        $attributes = [
            'driver' => $driver,
            'base_url' => filled($data['base_url'] ?? null) ? rtrim((string) $data['base_url'], '/') : null,
            'api_token' => $data['api_token'],
            'webhook_secret' => $data['webhook_secret'],
        ];
        $setting = $this->settingForMerchant($merchantId);

        if ($setting) {
            $setting->update($attributes);
        } else {
            $setting = MerchantLogisticsTrackingSetting::query()->create([
                ...$attributes,
                'merchant_id' => $merchantId,
                'webhook_token' => MerchantLogisticsTrackingSetting::generateWebhookToken(),
            ]);
        }

        $this->fillLogisticsForm($setting->fresh());

        Notification::make()->title(__('admin.logistics_tracking.notifications.saved'))->success()->send();
    }

    public function regenerateWebhookToken(): void
    {
        abort_unless(static::canManageLogisticsTracking(), 403);

        $setting = $this->settingForMerchant($this->selectedMerchantId());

        if (! $setting) {
            Notification::make()->title(__('admin.logistics_tracking.notifications.regenerate_requires_save'))->danger()->send();

            return;
        }

        $setting->update(['webhook_token' => MerchantLogisticsTrackingSetting::generateWebhookToken()]);
        $this->logisticsData['webhook_url'] = $setting->fresh()->webhookUrl();

        Notification::make()->title(__('admin.logistics_tracking.notifications.webhook_token_regenerated'))->success()->send();
    }

    public function saveTelegram(TelegramBindingService $bindings): void
    {
        abort_unless(static::canManageTelegram(), 403);

        $merchantId = $this->selectedMerchantId();
        $data = $this->telegramForm->getState();

        $bindings->save(auth()->user(), $merchantId, $data);
        $this->telegramBinding = null;
        $this->fillTelegramForm(TelegramBot::query()->forMerchant($merchantId)->first());

        Notification::make()->title(__('admin.telegram.notifications.saved'))->success()->send();
    }

    public function sendTest(TelegramNotificationService $service, TelegramBindingService $bindings): void
    {
        $this->saveTelegram($bindings);

        $sent = $service->sendTest($this->selectedMerchantId());
        $notification = Notification::make()->title(__('admin.telegram.notifications.'.($sent ? 'test_sent' : 'test_failed')));
        ($sent ? $notification->success() : $notification->danger())->send();
    }

    public function createTelegramBinding(string $chatType, TelegramBindingService $service): void
    {
        abort_unless(static::canManageTelegram(), 403);
        $merchantId = $this->selectedMerchantId();
        $this->telegramBinding = [
            ...$service->createLink(auth()->user(), $merchantId, $chatType),
            'merchant_id' => $merchantId,
        ];
        $this->telegramData['mode'] = 'platform';
        $this->resetValidation('telegramData.mode');
    }

    public function refreshTelegramBinding(TelegramBindingService $service): void
    {
        abort_unless(static::canManageTelegram(), 403);
        $merchantId = $this->selectedMerchantId();

        if ($this->telegramBinding === null || $this->telegramBinding['merchant_id'] !== $merchantId) {
            $this->telegramBinding = null;

            return;
        }

        $status = $service->status(auth()->user(), $merchantId, $this->telegramBinding['token']);

        if ($status === 'pending') {
            return;
        }

        $this->telegramBinding = null;

        if ($status === 'bound') {
            $this->fillTelegramForm(TelegramBot::query()->forMerchant($merchantId)->first());
            Notification::make()->title(__('admin.telegram.notifications.bound'))->success()->send();
        } else {
            Notification::make()->title(__('admin.telegram.notifications.expired'))->warning()->send();
        }
    }

    public function disconnectTelegram(TelegramBindingService $service): void
    {
        abort_unless(static::canManageTelegram(), 403);
        $service->disconnect(auth()->user(), $this->selectedMerchantId());
        $this->telegramBinding = null;
        $this->fillTelegramForm(null);
        Notification::make()->title(__('admin.telegram.notifications.disconnected'))->success()->send();
    }

    private function loadSettings(): void
    {
        $merchantId = filled($this->merchantData['merchant_id'] ?? null) ? (int) $this->merchantData['merchant_id'] : null;

        if ($merchantId !== null) {
            $this->assertMerchantAccessible($merchantId);
        }

        $this->resetValidation();
        $this->timezoneForm->fill([
            'timezone' => $merchantId === null ? null : Merchant::query()->whereKey($merchantId)->value('timezone'),
        ]);

        if (static::canManageLogisticsTracking()) {
            $this->fillLogisticsForm($merchantId ? $this->settingForMerchant($merchantId) : null);
        }

        if (static::canManageTelegram()) {
            $this->telegramBinding = null;
            $bot = $merchantId ? TelegramBot::query()->where('merchant_id', $merchantId)->first() : null;
            $this->fillTelegramForm($bot);
        }
    }

    private function fillTelegramForm(?TelegramBot $bot): void
    {
        $this->telegramRecipient = $bot ? ($bot->chat_title ? $bot->chat_title.' · ' : '').$bot->chat_id : null;
        $this->telegramForm->fill([
            'mode' => $bot ? ($bot->uses_platform_bot ? 'platform' : 'custom') : (TelegramBotClient::platformIsConfigured() ? 'platform' : 'custom'),
            'bot_token' => $bot?->uses_platform_bot ? null : $bot?->bot_token,
            'chat_id' => $bot?->chat_id,
            'is_enabled' => $bot?->is_enabled ?? true,
        ]);
    }

    private function fillLogisticsForm(?MerchantLogisticsTrackingSetting $setting): void
    {
        $this->logisticsForm->fill([
            'driver' => $setting?->driver,
            'base_url' => $setting?->base_url,
            'api_token' => $setting?->api_token,
            'webhook_secret' => $setting?->webhook_secret,
            'webhook_url' => $setting?->webhookUrl(),
        ]);
    }

    private function settingForMerchant(int $merchantId): ?MerchantLogisticsTrackingSetting
    {
        return MerchantLogisticsTrackingSetting::query()->where('merchant_id', $merchantId)->first();
    }

    private function selectedMerchantId(): int
    {
        $merchantId = (int) ($this->merchantData['merchant_id'] ?? 0);
        $this->assertMerchantAccessible($merchantId);
        $this->merchantForm->getState();

        return $merchantId;
    }

    private function assertMerchantAccessible(int $merchantId): void
    {
        $allowed = auth()->user()->manageableMerchantIds();

        if ($allowed !== null && ! in_array($merchantId, $allowed, true)) {
            throw ValidationException::withMessages([
                'merchantData.merchant_id' => __('admin.service_settings.validation.merchant_forbidden'),
            ]);
        }
    }

    private function credentialHelp(string $field, mixed $driver): string
    {
        return __('admin.logistics_tracking.help.'.$field.($driver === SeventeenTrackDriver::NAME ? '_seventeen_track' : ''));
    }

    public static function canManageLogisticsTracking(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status && $user->can(Permissions::LOGISTICS_TRACKING_MANAGE);
    }

    public static function canManageTelegram(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status && $user->can(Permissions::TELEGRAM_MANAGE);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return static::canManageLogisticsTracking() || static::canManageTelegram();
    }
}
