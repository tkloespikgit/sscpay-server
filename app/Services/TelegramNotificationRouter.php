<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\TelegramBot;
use App\Models\User;
use App\Support\TelegramDelivery;
use App\Support\TelegramNotificationKind;
use Illuminate\Support\Facades\Log;
use Throwable;

/** 根据事件所属商户或通道所属人发送；旧配置仅用于继承停发状态。 */
class TelegramNotificationRouter
{
    public const CONFIG_KEY = 'telegram.notification_routes';

    public function __construct(
        private readonly TelegramNotificationService $telegram,
        private readonly TelegramNotificationSettings $settings,
        private readonly TelegramMessageTemplate $templates,
    ) {}

    /** @return array<string, bool|int|string> */
    public function settings(TelegramNotificationKind $kind): array
    {
        return $this->settings->get($kind);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<int, array{order: array<string, string>, shipment?: array<string, string>}>  $rows
     * @return list<array{ids: list<int>, message: string}>
     */
    public function messages(TelegramNotificationKind $kind, array $variables, array $rows): array
    {
        return $this->templates->renderBatches($kind, $variables, $rows);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, string>|null  $orderDetails
     */
    public function sendNotification(
        TelegramNotificationKind $kind,
        array $variables,
        ?int $merchantId = null,
        ?PaymentMethod $method = null,
        ?int $paymentMethodId = null,
        ?array $orderDetails = null,
    ): TelegramDelivery {
        $message = $this->templates->render($kind, $variables, orderDetails: $orderDetails);

        return $this->send($kind, $message, $merchantId, $method, $paymentMethodId);
    }

    public function send(
        TelegramNotificationKind $kind,
        string $message,
        ?int $merchantId = null,
        ?PaymentMethod $method = null,
        ?int $paymentMethodId = null,
    ): TelegramDelivery {
        if (! $this->settings($kind)['enabled']) {
            return TelegramDelivery::suppressed();
        }

        $recipient = $this->recipient($kind, $merchantId, $method?->id ?? $paymentMethodId);

        if ($recipient['id'] === null) {
            if ($recipient['reason'] === 'missing_owner') {
                Log::warning('Telegram 通知已跳过：支付通道缺少归属管理员', [
                    'kind' => $kind->value,
                    'payment_method_id' => $method?->id ?? $paymentMethodId,
                ]);
            }

            return new TelegramDelivery(false, false, false);
        }

        try {
            $sent = $recipient['type'] === 'merchant'
                ? $this->telegram->send($recipient['id'], $message)
                : $this->telegram->sendToUser($recipient['id'], $message);
        } catch (Throwable $error) {
            Log::warning('Telegram 通知发送失败', ['kind' => $kind->value, 'exception' => $error::class]);
            $sent = false;
        }

        $currentRecipient = $this->recipient($kind, $merchantId, $method?->id ?? $paymentMethodId);

        return new TelegramDelivery(false, $currentRecipient['usable'], $sent);
    }

    /**
     * 发送和后台预览共用当前归属解析；不返回凭证或 Chat ID。
     *
     * @return array{type: string, id: int|null, name: string, usable: bool, reason: string}
     */
    public function recipient(TelegramNotificationKind $kind, ?int $merchantId = null, ?int $paymentMethodId = null): array
    {
        $userId = null;

        if ($kind->recipientRule() === 'channel_owner') {
            $method = PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)->withTrashed()->find($paymentMethodId);

            if ($method === null) {
                return $this->unavailable('missing_channel');
            }

            $merchantId = $method->merchant_id !== null ? (int) $method->merchant_id : null;
            $userId = $merchantId === null && $method->owner_id !== null ? (int) $method->owner_id : null;

            if ($merchantId === null && $userId === null) {
                return $this->unavailable('missing_owner');
            }
        }

        $recipient = $merchantId !== null
            ? Merchant::query()->find($merchantId)
            : ($userId !== null ? User::query()->whereNull('merchant_id')->find($userId) : null);

        if ($recipient === null || ! $recipient->status) {
            return $this->unavailable('inactive_recipient');
        }

        $bot = $merchantId !== null
            ? TelegramBot::query()->withoutGlobalScope(MerchantScope::class)->forMerchant($merchantId)->first()
            : TelegramBot::query()->forUser($userId)->first();

        return [
            'type' => $merchantId !== null ? 'merchant' : 'admin',
            'id' => (int) $recipient->id,
            'name' => (string) $recipient->name,
            'usable' => $bot?->isUsable() ?? false,
            'reason' => $bot === null ? 'unbound' : (! $bot->is_enabled ? 'disabled' : ($bot->isUsable() ? 'ready' : 'incomplete')),
        ];
    }

    /** @return array{type: string, id: int|null, name: string, usable: bool, reason: string} */
    private function unavailable(string $reason): array
    {
        return ['type' => '', 'id' => null, 'name' => '', 'usable' => false, 'reason' => $reason];
    }
}
