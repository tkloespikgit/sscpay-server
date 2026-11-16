<?php

namespace App\Console\Commands;

use App\Services\TelegramBotClient;
use Illuminate\Console\Command;
use RuntimeException;

class RegisterTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:register-webhook {--url= : Public HTTPS webhook URL; defaults to APP_URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '注册平台 Telegram 机器人的快速绑定回调';

    /**
     * Execute the console command.
     */
    public function handle(TelegramBotClient $client): int
    {
        if (! TelegramBotClient::platformIsConfigured()) {
            $this->error(__('admin.telegram.setup.missing_config'));

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: route('webhooks.telegram'));

        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->error(__('admin.telegram.setup.https_required'));

            return self::FAILURE;
        }

        try {
            $token = (string) config('services.telegram.bot_token');
            $bot = $client->call($token, 'getMe')['result'] ?? [];

            if (($bot['is_bot'] ?? false) !== true
                || strcasecmp((string) ($bot['username'] ?? ''), (string) config('services.telegram.bot_username')) !== 0
                || ($bot['can_join_groups'] ?? false) !== true) {
                $this->error(__('admin.telegram.setup.invalid_bot'));

                return self::FAILURE;
            }

            $client->call($token, 'setWebhook', [
                'url' => $url,
                'secret_token' => config('services.telegram.webhook_secret'),
                'allowed_updates' => ['message'],
            ]);
        } catch (RuntimeException) {
            $this->error(__('admin.telegram.setup.failed'));

            return self::FAILURE;
        }

        $this->info(__('admin.telegram.setup.registered', ['url' => $url]));

        return self::SUCCESS;
    }
}
