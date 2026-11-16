<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Scopes\MerchantScope;
use App\Models\TelegramBindingRequest;
use App\Models\TelegramBot;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TelegramBindingService
{
    public function __construct(private TelegramBotClient $client) {}

    /**
     * @return array{token: string, url: string, expires_at: string}
     */
    public function createLink(User $actor, int $merchantId, string $chatType): array
    {
        Validator::make(['chat_type' => $chatType], ['chat_type' => ['required', 'in:private,group']])->validate();

        return DB::transaction(function () use ($actor, $merchantId, $chatType): array {
            $this->authorizedMerchant($actor, $merchantId, lock: true);

            if (! TelegramBotClient::platformIsConfigured()) {
                throw ValidationException::withMessages(['telegramData.mode' => __('admin.telegram.help.unavailable')]);
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = now()->addMinutes(10);

            TelegramBindingRequest::query()->forMerchant($merchantId)->updateOrCreate(
                ['merchant_id' => $merchantId],
                [
                    'user_id' => $actor->id,
                    'token_hash' => hash('sha256', $token),
                    'bot_fingerprint' => $this->botFingerprint(),
                    'chat_type' => $chatType,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                ],
            );

            return [
                'token' => $token,
                'url' => 'https://t.me/'.config('services.telegram.bot_username').'?'.($chatType === 'group' ? 'startgroup' : 'start').'='.$token,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    public function status(User $actor, int $merchantId, string $token): string
    {
        $this->authorizedMerchant($actor, $merchantId);
        $binding = TelegramBindingRequest::query()->forMerchant($merchantId)
            ->where('token_hash', hash('sha256', $token))->first();

        if ($binding?->consumed_at !== null) {
            return 'bound';
        }

        return $binding && $this->isCurrent($binding) ? 'pending' : 'expired';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, int $merchantId, array $data): void
    {
        $data = Validator::make(['telegramData' => $data], [
            'telegramData.mode' => ['required', 'in:platform,custom'],
            'telegramData.is_enabled' => ['required', 'boolean'],
            'telegramData.bot_token' => ['exclude_unless:telegramData.mode,custom', 'required', 'string', 'max:255'],
            'telegramData.chat_id' => ['exclude_unless:telegramData.mode,custom', 'required', 'string', 'regex:/^-?\d+$/', 'max:100'],
        ])->validate()['telegramData'];

        DB::transaction(function () use ($actor, $merchantId, $data): void {
            $this->authorizedMerchant($actor, $merchantId, lock: true);

            if ($data['mode'] === 'platform') {
                $bot = TelegramBot::query()->forMerchant($merchantId)->first();

                if (! $bot?->uses_platform_bot) {
                    throw ValidationException::withMessages(['telegramData.mode' => __('admin.telegram.validation.bind_first')]);
                }

                $bot->update(['is_enabled' => $data['is_enabled']]);
                TelegramBindingRequest::query()->forMerchant($merchantId)->delete();

                return;
            }

            TelegramBot::query()->forMerchant($merchantId)->updateOrCreate(['merchant_id' => $merchantId], [
                'bot_token' => $data['bot_token'],
                'chat_id' => $data['chat_id'],
                'is_enabled' => $data['is_enabled'],
                'uses_platform_bot' => false,
                'chat_title' => null,
                'chat_type' => null,
            ]);
            TelegramBindingRequest::query()->forMerchant($merchantId)->delete();
        });
    }

    /**
     * @return array{token: string, url: string, expires_at: string}
     */
    public function createAdminLink(User $actor, User $target, string $chatType): array
    {
        Validator::make(['chat_type' => $chatType], ['chat_type' => ['required', 'in:private,group']])->validate();

        return DB::transaction(function () use ($actor, $target, $chatType): array {
            $target = User::query()->lockForUpdate()->find($target->id);
            $this->assertCanBindAdmin($actor, $target);

            if (! TelegramBotClient::platformIsConfigured()) {
                throw ValidationException::withMessages(['telegram' => __('admin.telegram.help.admin_unavailable')]);
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = now()->addMinutes(10);

            TelegramBindingRequest::query()->forAdmin($target->id)->updateOrCreate(
                ['target_user_id' => $target->id],
                [
                    'merchant_id' => null,
                    'user_id' => $actor->id,
                    'token_hash' => hash('sha256', $token),
                    'bot_fingerprint' => $this->botFingerprint(),
                    'chat_type' => $chatType,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                ],
            );

            return [
                'token' => $token,
                'url' => 'https://t.me/'.config('services.telegram.bot_username').'?'.($chatType === 'group' ? 'startgroup' : 'start').'='.$token,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    public function adminStatus(User $actor, User $target, string $token): string
    {
        $this->assertCanBindAdmin($actor, $target->fresh());
        $binding = TelegramBindingRequest::query()->forAdmin($target->id)
            ->where('token_hash', hash('sha256', $token))->first();

        if ($binding?->consumed_at !== null) {
            return 'bound';
        }

        return $binding && $this->isCurrent($binding) ? 'pending' : 'expired';
    }

    public function disconnectAdmin(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $target = User::query()->lockForUpdate()->find($target->id);
            $this->assertCanBindAdmin($actor, $target);
            TelegramBot::query()->forUser($target->id)->delete();
            TelegramBindingRequest::query()->forAdmin($target->id)->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAdmin(User $actor, User $target, array $data): void
    {
        $data = Validator::make(['telegramData' => $data], [
            'telegramData.mode' => ['required', 'in:platform,custom'],
            'telegramData.is_enabled' => ['required', 'boolean'],
            'telegramData.bot_token' => ['exclude_unless:telegramData.mode,custom', 'required', 'string', 'max:255'],
            'telegramData.chat_id' => ['exclude_unless:telegramData.mode,custom', 'required', 'string', 'regex:/^-?\d+$/', 'max:100'],
        ])->validate()['telegramData'];

        DB::transaction(function () use ($actor, $target, $data): void {
            $target = User::query()->lockForUpdate()->find($target->id);
            $this->assertCanBindAdmin($actor, $target);

            if ($data['mode'] === 'platform') {
                $bot = TelegramBot::query()->forUser($target->id)->first();

                if (! $bot?->uses_platform_bot) {
                    throw ValidationException::withMessages(['telegramData.mode' => __('admin.telegram.validation.bind_first')]);
                }

                $bot->update(['is_enabled' => $data['is_enabled']]);
                TelegramBindingRequest::query()->forAdmin($target->id)->delete();

                return;
            }

            TelegramBot::query()->forUser($target->id)->updateOrCreate(['user_id' => $target->id], [
                'merchant_id' => null,
                'bot_token' => $data['bot_token'],
                'chat_id' => $data['chat_id'],
                'is_enabled' => $data['is_enabled'],
                'uses_platform_bot' => false,
                'chat_title' => null,
                'chat_type' => null,
            ]);
            TelegramBindingRequest::query()->forAdmin($target->id)->delete();
        });
    }

    public function disconnect(User $actor, int $merchantId): void
    {
        DB::transaction(function () use ($actor, $merchantId): void {
            $this->authorizedMerchant($actor, $merchantId, lock: true);
            TelegramBot::query()->forMerchant($merchantId)->delete();
            TelegramBindingRequest::query()->forMerchant($merchantId)->delete();
        });
    }

    /**
     * Called only after the webhook secret and message shape have been verified.
     *
     * @param  array{chat: array{id: int|string, type: string, title?: string, first_name?: string, last_name?: string, username?: string}, from: array{id: int|string}, text: string}  $message
     */
    public function handle(array $message): ?string
    {
        if (! preg_match('/\A\/start(?:@([A-Za-z0-9_]+))? ([a-f0-9]{64})\z/', $message['text'], $matches)
            || (filled($matches[1]) && strcasecmp($matches[1], (string) config('services.telegram.bot_username')) !== 0)) {
            return null;
        }

        $tokenHash = hash('sha256', $matches[2]);
        $binding = TelegramBindingRequest::query()->withoutGlobalScope(MerchantScope::class)
            ->where('token_hash', $tokenHash)->first();

        if (! $binding || $binding->consumed_at !== null) {
            return null;
        }

        if (! $this->isCurrent($binding)) {
            return __('admin.telegram.replies.expired');
        }

        $chat = $message['chat'];
        $isPrivate = $chat['type'] === 'private';

        if (($binding->chat_type === 'private') !== $isPrivate
            || ($isPrivate && (string) $chat['id'] !== (string) $message['from']['id'])) {
            return __('admin.telegram.replies.wrong_type');
        }

        if (! $isPrivate && ! $this->isGroupAdministrator((string) $chat['id'], (string) $message['from']['id'])) {
            return __('admin.telegram.replies.group_admin_required');
        }

        if ($binding->target_user_id !== null) {
            return $this->completeAdminBinding($binding, $tokenHash, $chat, $isPrivate);
        }

        return DB::transaction(function () use ($binding, $tokenHash, $chat, $isPrivate): ?string {
            $merchant = Merchant::query()->lockForUpdate()->find($binding->merchant_id);
            $current = TelegramBindingRequest::query()->forMerchant($binding->merchant_id)
                ->where('token_hash', $tokenHash)->lockForUpdate()->first();

            if (! $current || $current->consumed_at !== null) {
                return null;
            }

            if (! $this->isCurrent($current) || ! $this->canManage($current->user_id, $merchant)) {
                $current->delete();

                return __('admin.telegram.replies.expired');
            }

            $title = $isPrivate
                ? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''))
                : ($chat['title'] ?? '');

            TelegramBot::query()->forMerchant($merchant->id)->updateOrCreate(['merchant_id' => $merchant->id], [
                'uses_platform_bot' => true,
                'bot_token' => '',
                'chat_id' => (string) $chat['id'],
                'chat_type' => $chat['type'],
                'chat_title' => Str::limit($title ?: ($chat['username'] ?? (string) $chat['id']), 255, ''),
                'is_enabled' => true,
                'last_sent_at' => null,
                'test_sent_at' => null,
            ]);
            $current->update(['consumed_at' => now()]);

            return __('admin.telegram.replies.bound');
        });
    }

    /**
     * @param  array{id: int|string, type: string, title?: string, first_name?: string, last_name?: string, username?: string}  $chat
     */
    private function completeAdminBinding(TelegramBindingRequest $binding, string $tokenHash, array $chat, bool $isPrivate): ?string
    {
        return DB::transaction(function () use ($binding, $tokenHash, $chat, $isPrivate): ?string {
            $target = User::query()->lockForUpdate()->find($binding->target_user_id);
            $current = TelegramBindingRequest::query()->forAdmin((int) $binding->target_user_id)
                ->where('token_hash', $tokenHash)->lockForUpdate()->first();

            if (! $current || $current->consumed_at !== null) {
                return null;
            }

            $actor = User::query()->find($current->user_id);
            if (! $this->isCurrent($current) || $target === null || $actor?->id !== $target->id || ! $this->canBindOwnAdminBot($actor)) {
                $current->delete();

                return __('admin.telegram.replies.expired');
            }

            $title = $isPrivate
                ? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''))
                : ($chat['title'] ?? '');

            TelegramBot::query()->forUser($target->id)->updateOrCreate(['user_id' => $target->id], [
                'merchant_id' => null,
                'uses_platform_bot' => true,
                'bot_token' => '',
                'chat_id' => (string) $chat['id'],
                'chat_type' => $chat['type'],
                'chat_title' => Str::limit($title ?: ($chat['username'] ?? (string) $chat['id']), 255, ''),
                'is_enabled' => true,
                'last_sent_at' => null,
                'test_sent_at' => null,
            ]);
            $current->update(['consumed_at' => now()]);

            return __('admin.telegram.replies.bound');
        });
    }

    private function assertCanBindAdmin(User $actor, ?User $target): void
    {
        if ($target === null || $actor->id !== $target->id || ! $this->canBindOwnAdminBot($actor)) {
            throw new AuthorizationException;
        }
    }

    private function canBindOwnAdminBot(?User $user): bool
    {
        return $user !== null && $user->status && $user->merchant_id === null && $user->isPlatformStaff();
    }

    private function isCurrent(TelegramBindingRequest $binding): bool
    {
        return $binding->expires_at->isFuture()
            && hash_equals($binding->bot_fingerprint, $this->botFingerprint())
            && TelegramBotClient::platformIsConfigured();
    }

    private function botFingerprint(): string
    {
        return hash('sha256', (string) config('services.telegram.bot_token'));
    }

    private function isGroupAdministrator(string $chatId, string $userId): bool
    {
        $administrators = $this->client->call((string) config('services.telegram.bot_token'), 'getChatAdministrators', [
            'chat_id' => $chatId,
        ])['result'] ?? null;

        if (! is_array($administrators)) {
            throw new RuntimeException('Invalid Telegram administrator response.');
        }

        foreach ($administrators as $administrator) {
            if ((string) ($administrator['user']['id'] ?? '') === $userId
                && in_array($administrator['status'] ?? '', ['creator', 'administrator'], true)) {
                return true;
            }
        }

        return false;
    }

    private function authorizedMerchant(User $actor, int $merchantId, bool $lock = false): Merchant
    {
        $merchant = Merchant::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($merchantId);

        if (! $this->canManage($actor->id, $merchant)) {
            throw new AuthorizationException;
        }

        return $merchant;
    }

    private function canManage(int $actorId, ?Merchant $merchant): bool
    {
        $actor = User::query()->find($actorId);

        return $merchant?->status && $actor?->status && $actor->can(Permissions::TELEGRAM_MANAGE)
            && ($actor->is_super_admin
                || ($actor->isMerchantManager() && (int) $merchant->owner_id === $actor->id)
                || (! $actor->isPlatformStaff() && (int) $actor->merchant_id === $merchant->id));
    }
}
