<?php

namespace App\Filament\Pages;

use App\Models\Merchant;
use App\Models\MerchantLogisticsTrackingSetting;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use App\Services\LogisticsTracking\LogisticsTrackingManager;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * 商户物流跟踪对接配置页（对标 TelegramSettings）。
 *
 * 每商户一条：从平台 available_drivers 选用驱动、可选 base_url、API Token / Webhook Secret；
 * 保存后展示含 webhook_token 的回调 URL。无独立启用开关；可用性由平台白名单 + 凭证 + 订单快照决定。
 */
class LogisticsTrackingSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected string $view = 'filament.pages.logistics-tracking-settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.merchant_settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.logistics_tracking.nav_label');
    }

    public function mount(): void
    {
        $user = auth()->user();
        $canPickMerchant = (bool) $user?->isPlatformStaff();
        $merchantId = $canPickMerchant ? null : $user->merchant_id;
        $setting = $merchantId ? $this->settingForMerchant($merchantId) : null;

        $this->form->fill($this->formStateFromSetting($merchantId, $setting));
    }

    public function form(Schema $schema): Schema
    {
        $viewer = auth()->user();
        $canPickMerchant = (bool) $viewer?->isPlatformStaff();
        $isViewerMerchantManager = (bool) $viewer?->isMerchantManager();

        return $schema
            ->components([
                Select::make('merchant_id')
                    ->label(__('admin.logistics_tracking.fields.merchant'))
                    ->options(function () use ($isViewerMerchantManager, $viewer) {
                        if ($isViewerMerchantManager) {
                            return $viewer->ownedMerchants()->where('status', true)->orderBy('name')->pluck('name', 'id');
                        }

                        return Merchant::query()->where('status', true)->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required()
                    ->disabled(! $canPickMerchant)
                    ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                    ->dehydrated()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $setting = filled($state) ? $this->settingForMerchant((int) $state) : null;
                        foreach ($this->formStateFromSetting(filled($state) ? (int) $state : null, $setting) as $key => $value) {
                            $set($key, $value);
                        }
                    }),
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
                        $url = (string) ($this->data['webhook_url'] ?? '');

                        if ($url === '') {
                            return new HtmlString(e(__('admin.logistics_tracking.help.webhook_url_empty')));
                        }

                        return new HtmlString('<code class="text-sm break-all">'.e($url).'</code>');
                    })
                    ->helperText(__('admin.logistics_tracking.help.webhook_url')),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('admin.logistics_tracking.actions.save'))
                ->action('save'),
            Action::make('regenerateWebhookToken')
                ->label(__('admin.logistics_tracking.actions.regenerate_webhook_token'))
                ->color('gray')
                ->requiresConfirmation()
                ->action('regenerateWebhookToken'),
        ];
    }

    /**
     * 保存或创建商户设置；驱动须在平台 available_drivers 配置中（可含未实现预填名）。
     * 新建时生成 webhook_token；不覆盖已有令牌除非点「重新生成」。
     */
    public function save(): void
    {
        $data = $this->form->getState();
        $merchantId = (int) $data['merchant_id'];
        $this->assertMerchantAccessible($merchantId);
        $driver = (string) ($data['driver'] ?? '');

        if (! app(LogisticsTrackingManager::class)->isDriverConfigured($driver)) {
            throw ValidationException::withMessages([
                'data.driver' => __('admin.logistics_tracking.validation.driver_unavailable'),
            ]);
        }

        unset($data['merchant_id'], $data['webhook_url']);

        $existing = $this->settingForMerchant($merchantId);

        $attributes = [
            'driver' => $driver,
            'base_url' => filled($data['base_url'] ?? null) ? rtrim((string) $data['base_url'], '/') : null,
            'api_token' => $data['api_token'],
            'webhook_secret' => $data['webhook_secret'],
        ];

        if ($existing) {
            $existing->update($attributes);
            $setting = $existing->fresh();
        } else {
            $setting = MerchantLogisticsTrackingSetting::query()->create(array_merge($attributes, [
                'merchant_id' => $merchantId,
                'webhook_token' => MerchantLogisticsTrackingSetting::generateWebhookToken(),
            ]));
        }

        $this->form->fill($this->formStateFromSetting($merchantId, $setting));

        Notification::make()->title(__('admin.logistics_tracking.notifications.saved'))->success()->send();
    }

    /** 轮换 webhook_token；旧回调 URL 立即失效，需同步更新 Provider 控制台。 */
    public function regenerateWebhookToken(): void
    {
        $merchantId = (int) ($this->form->getState()['merchant_id'] ?? 0);

        if ($merchantId > 0) {
            $this->assertMerchantAccessible($merchantId);
        }

        $setting = $merchantId ? $this->settingForMerchant($merchantId) : null;

        if (! $setting) {
            Notification::make()
                ->title(__('admin.logistics_tracking.notifications.regenerate_requires_save'))
                ->danger()
                ->send();

            return;
        }

        $setting->update([
            'webhook_token' => MerchantLogisticsTrackingSetting::generateWebhookToken(),
        ]);

        $this->data['webhook_url'] = $setting->fresh()->webhookUrl();

        Notification::make()
            ->title(__('admin.logistics_tracking.notifications.webhook_token_regenerated'))
            ->success()
            ->send();
    }

    private function settingForMerchant(int $merchantId): ?MerchantLogisticsTrackingSetting
    {
        return MerchantLogisticsTrackingSetting::query()->where('merchant_id', $merchantId)->first();
    }

    /**
     * 服务端再校验商户归属：超管不限；商户经理限 ownedMerchants；商户用户限自身 merchant_id。
     */
    private function assertMerchantAccessible(int $merchantId): void
    {
        $allowed = auth()->user()?->manageableMerchantIds();

        if ($allowed === null) {
            return;
        }

        if (! in_array($merchantId, $allowed, true)) {
            throw ValidationException::withMessages([
                'data.merchant_id' => __('admin.logistics_tracking.validation.merchant_forbidden'),
            ]);
        }
    }

    /**
     * 将设置模型映射为表单 state（含派生 webhook_url）。
     *
     * @return array<string, mixed>
     */
    private function formStateFromSetting(?int $merchantId, ?MerchantLogisticsTrackingSetting $setting): array
    {
        return [
            'merchant_id' => $merchantId,
            'driver' => $setting?->driver,
            'base_url' => $setting?->base_url,
            'api_token' => $setting?->api_token,
            'webhook_secret' => $setting?->webhook_secret,
            'webhook_url' => $setting?->webhookUrl(),
        ];
    }

    /**
     * 凭证/Base URL 帮助文案：已知驱动用专用 key（如 *_seventeen_track），否则用通用 help。
     */
    private function credentialHelp(string $field, mixed $driver): string
    {
        if ($driver === SeventeenTrackDriver::NAME) {
            return __('admin.logistics_tracking.help.'.$field.'_seventeen_track');
        }

        return __('admin.logistics_tracking.help.'.$field);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->can(Permissions::LOGISTICS_TRACKING_MANAGE);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return static::shouldRegisterNavigation();
    }
}
