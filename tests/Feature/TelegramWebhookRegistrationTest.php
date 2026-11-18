<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramWebhookRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram', [
            'bot_token' => '12345:platform-token',
            'bot_username' => 'platform_test_bot',
            'webhook_secret' => str_repeat('s', 40),
            'webhook_url' => null,
        ]);
        Http::preventStrayRequests();
    }

    public function test_registration_verifies_the_bot_and_registers_the_authenticated_message_webhook(): void
    {
        $url = 'https://pay.example.test/api/webhooks/telegram';
        config()->set('services.telegram.webhook_url', 'https://api.example.test/api/webhooks/telegram');
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::response([
                'ok' => true, 'result' => ['is_bot' => true, 'username' => 'platform_test_bot', 'can_join_groups' => true],
            ]),
            'api.telegram.org/bot12345:platform-token/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => $url])
            ->expectsOutput(__('admin.telegram.setup.registered', ['url' => $url]))
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot12345:platform-token/setWebhook'
            && $request['url'] === $url
            && $request['secret_token'] === str_repeat('s', 40)
            && $request['allowed_updates'] === ['message']
            && ! isset($request['drop_pending_updates']));
    }

    public function test_deployment_skips_registration_when_the_platform_bot_is_not_configured(): void
    {
        config()->set('services.telegram', []);

        $this->artisan('telegram:register-webhook', ['--if-configured' => true])
            ->expectsOutput(__('admin.telegram.setup.skipped'))
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_deployment_uses_the_persistent_webhook_url_override(): void
    {
        $url = 'https://api.example.test/api/webhooks/telegram';
        config()->set('services.telegram.webhook_url', $url);
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::response([
                'ok' => true, 'result' => ['is_bot' => true, 'username' => 'platform_test_bot', 'can_join_groups' => true],
            ]),
            'api.telegram.org/bot12345:platform-token/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:register-webhook', ['--if-configured' => true])
            ->expectsOutput(__('admin.telegram.setup.registered', ['url' => $url]))
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook') && $request['url'] === $url);
    }

    #[DataProvider('missingConfiguration')]
    public function test_invalid_configuration_cannot_register_a_webhook(string $key, string $value): void
    {
        config()->set('services.telegram.'.$key, $value);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram', '--if-configured' => true])
            ->expectsOutput(__('admin.telegram.setup.missing_config'))
            ->assertFailed();

        Http::assertNothingSent();
    }

    public static function missingConfiguration(): array
    {
        return [
            'missing token' => ['bot_token', ''],
            'invalid username' => ['bot_username', '@invalid'],
            'weak secret' => ['webhook_secret', 'short'],
        ];
    }

    public function test_registration_refuses_http_callback_urls(): void
    {
        $this->artisan('telegram:register-webhook', ['--url' => 'http://pay.example.test/api/webhooks/telegram'])
            ->expectsOutput(__('admin.telegram.setup.https_required'))
            ->assertFailed();

        Http::assertNothingSent();
    }

    #[DataProvider('invalidBotIdentities')]
    public function test_registration_rejects_mismatched_or_group_disabled_bots(string $username, bool $canJoinGroups): void
    {
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::response([
                'ok' => true, 'result' => ['is_bot' => true, 'username' => $username, 'can_join_groups' => $canJoinGroups],
            ]),
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram'])
            ->expectsOutput(__('admin.telegram.setup.invalid_bot'))
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public static function invalidBotIdentities(): array
    {
        return [
            'different bot' => ['another_bot', true],
            'groups disabled' => ['platform_test_bot', false],
        ];
    }

    public function test_connection_errors_return_a_safe_failure_message(): void
    {
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::failedConnection(),
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram'])
            ->expectsOutput(__('admin.telegram.setup.failed'))
            ->assertFailed();

        Http::assertSentCount(1);
    }

    #[DataProvider('rejectedRegistrations')]
    public function test_rejected_registration_is_not_reported_as_success(array $response, int $status): void
    {
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::response([
                'ok' => true, 'result' => ['is_bot' => true, 'username' => 'platform_test_bot', 'can_join_groups' => true],
            ]),
            'api.telegram.org/bot12345:platform-token/setWebhook' => Http::response($response, $status),
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram'])
            ->expectsOutput(__('admin.telegram.setup.failed'))
            ->assertFailed();

        Http::assertSentCount(2);
    }

    public static function rejectedRegistrations(): array
    {
        return [
            'API rejection' => [['ok' => false], 400],
            'unsuccessful result' => [['ok' => true, 'result' => false], 200],
        ];
    }

    #[DataProvider('messageSubscriptions')]
    public function test_check_accepts_explicit_and_default_message_subscriptions_without_registering(array $subscription): void
    {
        $url = 'https://pay.example.test/api/webhooks/telegram';
        $this->fakeWebhookInfo(['url' => $url, 'pending_update_count' => 0, ...$subscription]);

        $this->artisan('telegram:register-webhook', ['--url' => $url, '--check' => true])
            ->expectsOutput(__('admin.telegram.setup.pending_updates', ['count' => 0]))
            ->expectsOutput(__('admin.telegram.setup.check_passed'))
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook'));
    }

    public static function messageSubscriptions(): array
    {
        return [
            'explicit' => [['allowed_updates' => ['message']]],
            'default empty list' => [['allowed_updates' => []]],
            'default omitted' => [[]],
        ];
    }

    #[DataProvider('unusableWebhooks')]
    public function test_check_identifies_registration_problems_without_changing_the_webhook(array $webhook, string $message): void
    {
        $this->fakeWebhookInfo($webhook + ['pending_update_count' => 0]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram', '--check' => true])
            ->expectsOutput(__('admin.telegram.setup.'.$message))
            ->assertFailed();

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook'));
    }

    public static function unusableWebhooks(): array
    {
        return [
            'not registered' => [['url' => ''], 'not_registered'],
            'other application' => [['url' => 'https://old.example.test/webhook'], 'url_mismatch'],
            'messages excluded' => [[
                'url' => 'https://pay.example.test/api/webhooks/telegram', 'allowed_updates' => ['my_chat_member'],
            ], 'messages_disabled'],
        ];
    }

    public function test_check_reports_a_delivery_failure_and_pending_messages(): void
    {
        $this->fakeWebhookInfo([
            'url' => 'https://pay.example.test/api/webhooks/telegram',
            'pending_update_count' => 3,
            'last_error_date' => 1794960000,
            'last_error_message' => 'Wrong response from the webhook: 403 Forbidden',
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram', '--check' => true])
            ->expectsOutput(__('admin.telegram.setup.pending_updates', ['count' => 3]))
            ->expectsOutputToContain('403 Forbidden')
            ->expectsOutput(__('admin.telegram.setup.delivery_failed'))
            ->assertFailed();

        Http::assertSentCount(2);
    }

    public function test_check_distinguishes_a_historical_error_from_pending_delivery_and_redacts_secrets(): void
    {
        $this->fakeWebhookInfo([
            'url' => 'https://pay.example.test/api/webhooks/telegram',
            'pending_update_count' => 0,
            'last_error_message' => 'Failure 12345:platform-token '.str_repeat('s', 40),
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram', '--check' => true])
            ->expectsOutputToContain('Failure [redacted] [redacted]')
            ->doesntExpectOutputToContain('12345:platform-token')
            ->doesntExpectOutputToContain(str_repeat('s', 40))
            ->expectsOutput(__('admin.telegram.setup.check_passed'))
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_check_does_not_expose_credentials_from_an_old_webhook_url(): void
    {
        $this->fakeWebhookInfo([
            'url' => 'https://user:old-password@old.example.test/webhook?secret=old-secret&key=old-key',
            'pending_update_count' => 0,
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram', '--check' => true])
            ->expectsOutputToContain('https://[redacted]@old.example.test/webhook?secret=[redacted]&key=[redacted]')
            ->doesntExpectOutputToContain('old-password')
            ->doesntExpectOutputToContain('old-secret')
            ->doesntExpectOutputToContain('old-key')
            ->assertFailed();

        Http::assertSentCount(2);
    }

    public function test_check_returns_a_safe_failure_when_telegram_is_unavailable(): void
    {
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::response([
                'ok' => true, 'result' => ['is_bot' => true, 'username' => 'platform_test_bot', 'can_join_groups' => true],
            ]),
            'api.telegram.org/bot12345:platform-token/getWebhookInfo' => Http::failedConnection(),
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram', '--check' => true])
            ->expectsOutput(__('admin.telegram.setup.check_failed'))
            ->assertFailed();

        Http::assertSentCount(2);
    }

    public function test_check_does_not_report_an_incomplete_status_response_as_success(): void
    {
        $this->fakeWebhookInfo(['url' => 'https://pay.example.test/api/webhooks/telegram']);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram', '--check' => true])
            ->expectsOutput(__('admin.telegram.setup.check_failed'))
            ->assertFailed();

        Http::assertSentCount(2);
    }

    private function fakeWebhookInfo(array $webhook): void
    {
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::response([
                'ok' => true, 'result' => ['is_bot' => true, 'username' => 'platform_test_bot', 'can_join_groups' => true],
            ]),
            'api.telegram.org/bot12345:platform-token/getWebhookInfo' => Http::response(['ok' => true, 'result' => $webhook]),
        ]);
    }
}
