<?php

namespace App\Filament\Pages;

use App\Models\Merchant;
use App\Models\TelegramBot;
use App\Services\TelegramNotificationService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Telegram 通知配置（4.11 节）。每个商户最多绑定一个机器人
 * （telegram_bots.merchant_id 唯一），所以做成单例设置页而不是
 * 列表型 Resource——商户后台不需要"新建/列表"这些多余的操作。
 *
 * 商户用户看不到商户选择器，始终锁定自己所在商户（同 PaymentMethodResource
 * 的约定）；超级管理员没有 merchant_id，必须显式选择要配置哪个商户的机器人。
 */
class TelegramSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected string $view = 'filament.pages.telegram-settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.merchant_settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.telegram.nav_label');
    }

    public function mount(): void
    {
        $isSuperAdmin = (bool) auth()->user()?->is_super_admin;
        $merchantId = $isSuperAdmin ? null : auth()->user()->merchant_id;
        $bot = $merchantId ? $this->botForMerchant($merchantId) : null;

        $this->form->fill([
            'merchant_id' => $merchantId,
            ...($bot ? $bot->only(['bot_token', 'chat_id', 'is_enabled']) : []),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isSuperAdmin = (bool) auth()->user()?->is_super_admin;

        return $schema
            ->components([
                Select::make('merchant_id')
                    ->label(__('admin.telegram.fields.merchant'))
                    ->options(fn () => Merchant::query()->where('status', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    // 商户用户锁定成自己所在商户；超级管理员的 merchant_id 本来就是 NULL，
                    // 不选就直接保存会导致 telegram_bots.merchant_id 外键列为空，必须在这里显式选。
                    ->disabled(! $isSuperAdmin)
                    ->default(fn () => $isSuperAdmin ? null : auth()->user()->merchant_id)
                    ->dehydrated()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $bot = filled($state) ? $this->botForMerchant((int) $state) : null;

                        $set('bot_token', $bot?->bot_token);
                        $set('chat_id', $bot?->chat_id);
                        $set('is_enabled', $bot?->is_enabled ?? true);
                    }),
                TextInput::make('bot_token')->label(__('admin.telegram.fields.bot_token'))->password()->revealable()->required(),
                TextInput::make('chat_id')->label(__('admin.telegram.fields.chat_id'))->required()->rule('regex:/^-?\d+$/')->helperText(__('admin.telegram.help.chat_id')),
                Toggle::make('is_enabled')->label(__('admin.telegram.fields.is_enabled'))->default(true),
                TextEntry::make('divider')
                    ->hiddenLabel() // 🎯 正确隐藏标签的方法
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('admin.telegram.actions.save'))
                ->action('save'),
            Action::make('sendTest')
                ->label(__('admin.telegram.actions.send_test'))
                ->color('gray')
                ->action('sendTest'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $merchantId = (int) $data['merchant_id'];
        unset($data['merchant_id']);

        TelegramBot::query()->updateOrCreate(['merchant_id' => $merchantId], $data);

        Notification::make()->title(__('admin.telegram.notifications.saved'))->success()->send();
    }

    public function sendTest(TelegramNotificationService $service): void
    {
        $this->save(); // 先保存，确保测试用的是最新填写的配置

        $merchantId = (int) $this->form->getState()['merchant_id'];

        $sent = $service->sendTest($merchantId);

        if ($sent) {
            Notification::make()->title(__('admin.telegram.notifications.test_sent'))->success()->send();
        } else {
            Notification::make()->title(__('admin.telegram.notifications.test_failed'))->danger()->send();
        }
    }

    private function botForMerchant(int $merchantId): ?TelegramBot
    {
        return TelegramBot::query()->where('merchant_id', $merchantId)->first();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->can(Permissions::TELEGRAM_MANAGE);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return static::shouldRegisterNavigation();
    }
}
