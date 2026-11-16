<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\SystemConfig;
use App\Models\TelegramBot;
use App\Models\User;
use App\Support\TelegramDelivery;
use App\Support\TelegramNotificationKind;
use App\Support\TelegramRecipient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * 按平台保存的通知模板选择 Telegram 接收人。未保存的模板使用代码里的默认接收人。
 */
class TelegramNotificationRouter
{
    public const CONFIG_KEY = 'telegram.notification_routes';

    /** @var list<int>|null */
    private ?array $superAdminIds = null;

    public function __construct(private readonly TelegramNotificationService $telegram) {}

    /**
     * @return list<TelegramRecipient>
     */
    public function selection(TelegramNotificationKind $kind): array
    {
        $stored = SystemConfig::getArray(self::CONFIG_KEY, []);
        $raw = is_array($stored) ? ($stored[$kind->value] ?? null) : null;

        if (! is_array($raw)) {
            return $kind->defaults();
        }

        $parsed = [];

        foreach ($raw as $value) {
            $recipient = TelegramRecipient::tryFrom((string) $value);

            if ($recipient !== null && in_array($recipient, $kind->allowed(), true)) {
                $parsed[$recipient->value] = $recipient;
            }
        }

        if ($parsed === []) {
            return $kind->defaults();
        }

        if (isset($parsed[TelegramRecipient::None->value])) {
            return [TelegramRecipient::None];
        }

        return array_values($parsed);
    }

    /**
     * @return array<string, list<string>>
     */
    public function current(): array
    {
        $state = [];

        foreach (TelegramNotificationKind::cases() as $kind) {
            $state[$kind->value] = array_map(
                fn (TelegramRecipient $recipient): string => $recipient->value,
                $this->selection($kind),
            );
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function save(array $selection): void
    {
        $keys = array_map(fn (TelegramNotificationKind $kind): string => $kind->value, TelegramNotificationKind::cases());
        $rules = [];

        foreach (TelegramNotificationKind::cases() as $kind) {
            $allowed = array_map(fn (TelegramRecipient $recipient): string => $recipient->value, $kind->allowed());
            $rules[$kind->value] = ['required', 'array', 'min:1'];
            $rules[$kind->value.'.*'] = ['string', 'distinct', Rule::in($allowed)];
        }

        $validator = Validator::make($selection, $rules);
        $validator->after(function ($validator) use ($selection, $keys): void {
            foreach (array_keys($selection) as $key) {
                if (! in_array((string) $key, $keys, true)) {
                    $validator->errors()->add((string) $key, __('admin.telegram_routes.invalid_recipient'));
                }
            }

            foreach (TelegramNotificationKind::cases() as $kind) {
                $values = $selection[$kind->value] ?? null;

                if (! is_array($values)) {
                    continue;
                }

                if (in_array(TelegramRecipient::None->value, $values, true) && count($values) > 1) {
                    $validator->errors()->add($kind->value, __('admin.telegram_routes.none_exclusive'));
                }
            }
        });
        $validator->validate();

        $normalized = [];

        foreach (TelegramNotificationKind::cases() as $kind) {
            $values = array_values($selection[$kind->value]);
            $normalized[$kind->value] = in_array(TelegramRecipient::None->value, $values, true)
                ? [TelegramRecipient::None->value]
                : $values;
        }

        SystemConfig::query()->updateOrCreate(
            ['config_key' => self::CONFIG_KEY],
            [
                'config_value' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'value_type' => SystemConfig::TYPE_JSON,
                'group' => 'telegram',
                'description' => 'Telegram 通知接收人（由通知接收人页面维护）',
            ],
        );
        Cache::forget('system_config:'.self::CONFIG_KEY);
    }

    public function send(
        TelegramNotificationKind $kind,
        string $message,
        ?int $merchantId = null,
        ?PaymentMethod $method = null,
        ?int $paymentMethodId = null,
    ): TelegramDelivery {
        $recipients = $this->selection($kind);

        if ($recipients === [TelegramRecipient::None]) {
            return TelegramDelivery::suppressed();
        }

        $method ??= $this->findMethod($paymentMethodId);
        $merchantIds = [];
        $userIds = [];
        $routedToAdmins = false;

        foreach ($recipients as $recipient) {
            match ($recipient) {
                TelegramRecipient::Merchant => $merchantId !== null ? $merchantIds[] = $merchantId : null,
                TelegramRecipient::ChannelOwner => $this->addChannelOwner($method, $merchantIds, $userIds, $routedToAdmins),
                TelegramRecipient::ChannelMerchants => $method !== null
                    ? $merchantIds = array_merge($merchantIds, $method->notifiableMerchantIds())
                    : null,
                TelegramRecipient::SuperAdmins => $this->addSuperAdmins($userIds, $routedToAdmins),
                TelegramRecipient::None => null,
            };
        }

        $merchantIds = array_values(array_unique(array_map(intval(...), $merchantIds)));
        $userIds = array_values(array_unique(array_map(intval(...), $userIds)));
        sort($merchantIds);
        sort($userIds);

        if ($routedToAdmins && ! $this->hasUsableAdminBot($userIds)) {
            Log::warning(
                in_array($kind, [
                    TelegramNotificationKind::PaymentMethodForbidden,
                    TelegramNotificationKind::PaymentMethodCreationFailedDisabled,
                ], true)
                    ? '支付通道禁用提醒已跳过：管理员未绑定可用的 Telegram'
                    : 'Telegram 通知已跳过：管理员未绑定可用的机器人',
                [
                    'kind' => $kind->value,
                    'payment_method_id' => $method?->id,
                    'owner_id' => $method?->owner_id,
                    'recipient_ids' => $userIds,
                ],
            );
        }

        $anyUsable = false;
        $sent = false;

        foreach ($merchantIds as $id) {
            $sent = $this->attempt(
                fn (): bool => $this->telegram->send($id, $message),
                $kind,
                $id,
                null,
            ) || $sent;
            $bot = TelegramBot::query()->forMerchant($id)->first();
            $anyUsable = ($bot?->isUsable() ?? false) || $anyUsable;
        }

        foreach ($userIds as $id) {
            $sent = $this->attempt(
                fn (): bool => $this->telegram->sendToUser($id, $message),
                $kind,
                null,
                $id,
            ) || $sent;
            $bot = TelegramBot::query()->forUser($id)->first();
            $anyUsable = ($bot?->isUsable() ?? false) || $anyUsable;
        }

        return new TelegramDelivery(false, $anyUsable, $sent);
    }

    private function findMethod(?int $paymentMethodId): ?PaymentMethod
    {
        if ($paymentMethodId === null || $paymentMethodId <= 0) {
            return null;
        }

        return PaymentMethod::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->withTrashed()
            ->find($paymentMethodId);
    }

    /**
     * @param  list<int>  $merchantIds
     * @param  list<int>  $userIds
     */
    private function addChannelOwner(?PaymentMethod $method, array &$merchantIds, array &$userIds, bool &$routedToAdmins): void
    {
        if ($method === null) {
            return;
        }

        if ($method->merchant_id !== null) {
            $merchantIds[] = (int) $method->merchant_id;

            return;
        }

        $routedToAdmins = true;

        if ($method->owner_id !== null) {
            $userIds[] = (int) $method->owner_id;

            return;
        }

        $this->addSuperAdmins($userIds, $routedToAdmins);
    }

    /**
     * @param  list<int>  $userIds
     */
    private function addSuperAdmins(array &$userIds, bool &$routedToAdmins): void
    {
        $routedToAdmins = true;
        array_push($userIds, ...$this->activeSuperAdminIds());
    }

    /**
     * @return list<int>
     */
    private function activeSuperAdminIds(): array
    {
        return $this->superAdminIds ??= User::query()
            ->where('is_super_admin', true)
            ->where('status', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function hasUsableAdminBot(array $userIds): bool
    {
        if ($userIds === []) {
            return false;
        }

        return TelegramBot::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->whereIn('user_id', $userIds)
            ->whereNull('merchant_id')
            ->get()
            ->contains(fn (TelegramBot $bot): bool => $bot->isUsable());
    }

    private function attempt(callable $send, TelegramNotificationKind $kind, ?int $merchantId, ?int $userId): bool
    {
        try {
            return $send() === true;
        } catch (Throwable $error) {
            Log::warning('Telegram 通知发送失败', [
                'kind' => $kind->value,
                'merchant_id' => $merchantId,
                'user_id' => $userId,
                'exception' => $error::class,
            ]);

            return false;
        }
    }
}
