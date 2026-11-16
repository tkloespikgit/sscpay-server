<?php

namespace App\Services;

use App\Models\TelegramBot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 通知服务。
 *
 * 已快速绑定的商户显式使用平台机器人，自建机器人沿用商户凭证。
 * 没有绑定或未启用通知时直接跳过。
 */
class TelegramNotificationService
{
    /**
     * 沿用每商户每分钟 30 条的本地发送预算，供应商拒绝仍按发送失败处理。
     */
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(private TelegramBotClient $client) {}

    public function send(int $merchantId, string $message, array $options = []): bool
    {
        $bot = TelegramBot::query()
            ->forMerchant($merchantId)
            ->first();

        if (! $bot || ! $bot->isUsable()) {
            // 商户未配置或未启用：直接跳过，不发起任何请求，也不算失败。
            return false;
        }

        if (! $this->withinRateLimit($merchantId)) {
            Log::warning('Telegram notification skipped: rate limit exceeded', ['merchant_id' => $merchantId]);

            return false;
        }

        $sent = $this->sendMessage($bot->effectiveToken(), $bot->chat_id, $message, $options);

        if ($sent) {
            $bot->forceFill(['last_sent_at' => now()])->save();
        }

        return $sent;
    }

    public function sendTest(int $merchantId): bool
    {
        $sent = $this->send($merchantId, '✅ 这是一条测试消息，用于验证 Telegram 通知配置是否正确。');

        if ($sent) {
            TelegramBot::query()->forMerchant($merchantId)->first()?->forceFill(['test_sent_at' => now()])->save();
        }

        return $sent;
    }

    private function sendMessage(string $token, string $chatId, string $message, array $options = []): bool
    {
        try {
            $this->client->call($token, 'sendMessage', array_merge([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ], $options));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram send failed', ['exception' => $e::class]);

            return false;
        }
    }

    private function withinRateLimit(int $merchantId): bool
    {
        $key = "telegram_rate_limit:{$merchantId}:".now()->format('YmdHi');
        $count = Cache::increment($key);

        if ($count === 1) {
            Cache::put($key, 1, 65); // 略大于 60 秒，避免边界丢计数
        }

        return $count <= self::RATE_LIMIT_PER_MINUTE;
    }
}
