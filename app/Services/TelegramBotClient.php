<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    public static function platformIsConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && preg_match('/\A[A-Za-z0-9_]{5,32}\z/', (string) config('services.telegram.bot_username')) === 1
            && preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', (string) config('services.telegram.webhook_secret')) === 1;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function call(string $token, string $method, array $parameters = []): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(10)
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $parameters);
        } catch (ConnectionException) {
            throw new RuntimeException('Telegram connection failed.');
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram API request failed.');
        }

        return $data;
    }
}
