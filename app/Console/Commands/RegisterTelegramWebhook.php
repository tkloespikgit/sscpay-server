<?php

namespace App\Console\Commands;

use App\Services\TelegramBotClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class RegisterTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:register-webhook
        {--url= : Public HTTPS webhook URL; defaults to TELEGRAM_WEBHOOK_URL or APP_URL}
        {--if-configured : Skip only when all platform Telegram settings are empty}
        {--check : Inspect the registered webhook without changing it}';

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
        if ($this->option('if-configured') && collect([
            config('services.telegram.bot_token'),
            config('services.telegram.bot_username'),
            config('services.telegram.webhook_secret'),
            config('services.telegram.webhook_url'),
        ])->every(fn (mixed $value): bool => blank($value))) {
            $this->info(__('admin.telegram.setup.skipped'));

            return self::SUCCESS;
        }

        if (! TelegramBotClient::platformIsConfigured()) {
            $this->error(__('admin.telegram.setup.missing_config'));

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: config('services.telegram.webhook_url') ?: route('webhooks.telegram'));

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

            if ($this->option('check')) {
                $webhook = $client->call($token, 'getWebhookInfo')['result'] ?? null;

                if (! is_array($webhook) || ! is_string($webhook['url'] ?? null)
                    || ! is_int($webhook['pending_update_count'] ?? null)
                    || ! is_array($webhook['allowed_updates'] ?? [])) {
                    throw new RuntimeException('Invalid Telegram webhook status response.');
                }

                return $this->checkWebhook($webhook, $url);
            }

            $registered = $client->call($token, 'setWebhook', [
                'url' => $url,
                'secret_token' => config('services.telegram.webhook_secret'),
                'allowed_updates' => ['message'],
            ]);

            if (($registered['result'] ?? false) !== true) {
                throw new RuntimeException('Telegram webhook registration failed.');
            }
        } catch (RuntimeException) {
            $this->error(__($this->option('check') ? 'admin.telegram.setup.check_failed' : 'admin.telegram.setup.failed'));

            return self::FAILURE;
        }

        $this->info(__('admin.telegram.setup.registered', ['url' => $this->safeOutput($url)]));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $webhook
     */
    private function checkWebhook(array $webhook, string $expectedUrl): int
    {
        $url = (string) ($webhook['url'] ?? '');
        $pending = (int) ($webhook['pending_update_count'] ?? 0);
        $lastError = (string) ($webhook['last_error_message'] ?? '');
        $allowedUpdates = $webhook['allowed_updates'] ?? [];

        $this->line(__('admin.telegram.setup.expected_url', ['url' => $this->safeOutput($expectedUrl)]));
        $this->line(__('admin.telegram.setup.current_url', ['url' => $this->safeOutput($url) ?: '-']));
        $this->line(__('admin.telegram.setup.pending_updates', ['count' => $pending]));

        if ($lastError !== '') {
            $this->warn(__('admin.telegram.setup.last_error', [
                'time' => isset($webhook['last_error_date']) ? gmdate('Y-m-d H:i:s', (int) $webhook['last_error_date']).' UTC' : '-',
                'error' => $this->safeOutput($lastError),
            ]));
        }

        if ($url === '' || $url !== $expectedUrl) {
            $this->error(__($url === '' ? 'admin.telegram.setup.not_registered' : 'admin.telegram.setup.url_mismatch'));

            return self::FAILURE;
        }

        if ($allowedUpdates !== [] && ! in_array('message', $allowedUpdates, true)) {
            $this->error(__('admin.telegram.setup.messages_disabled'));

            return self::FAILURE;
        }

        if ($pending > 0 && $lastError !== '') {
            $this->error(__('admin.telegram.setup.delivery_failed'));

            return self::FAILURE;
        }

        $this->info(__('admin.telegram.setup.check_passed'));

        return self::SUCCESS;
    }

    private function safeOutput(string $value): string
    {
        foreach (['bot_token', 'webhook_secret'] as $key) {
            $secret = (string) config('services.telegram.'.$key);
            if ($secret !== '') {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }

        $value = preg_replace('~(https?://)[^/\s]+@~i', '$1[redacted]@', $value) ?? '';
        $value = preg_replace('/([?&][^=\s&]+)=([^&\s]*)/', '$1=[redacted]', $value) ?? '';
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';

        return OutputFormatter::escape(Str::limit($value, 500));
    }
}
