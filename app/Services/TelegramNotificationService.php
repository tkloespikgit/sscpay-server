<?php

namespace App\Services;

use App\Models\TelegramBot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 通知服务。
 *
 * 按最新业务决定：商户没有配置（或配置未启用）Telegram 机器人时，
 * 直接跳过，不再回退系统默认 Bot Token —— 这意味着 4.11 节提到的
 * "系统默认 Bot Token 兜底" 已经不再使用，system_configs 里对应的
 * telegram.bot_token 配置项也已在 Seeder 里移除。
 */
class TelegramNotificationService
{
    private const TELEGRAM_API_BASE = 'https://api.telegram.org/bot';

    /**
     * 限流：Telegram 官方限制大致是每分钟 30 条消息（8.12 节）。
     * 用 Redis 计数器做一个简单的令牌桶式限流，避免被 Telegram 封禁。
     */
    private const RATE_LIMIT_PER_MINUTE = 30;

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

        $sent = $this->sendMessage($bot->bot_token, $bot->chat_id, $message, $options);

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
            $response = Http::timeout(10)->post(self::TELEGRAM_API_BASE.$token.'/sendMessage', array_merge([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ], $options));

            return $response->successful() && $response->json('ok') === true;
        } catch (\Throwable $e) {
            Log::warning('Telegram send failed', ['error' => $e->getMessage()]);

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
