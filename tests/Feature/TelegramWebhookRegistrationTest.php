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
        ]);
        Http::preventStrayRequests();
    }

    public function test_registration_verifies_the_bot_and_registers_the_authenticated_message_webhook(): void
    {
        $url = 'https://pay.example.test/api/webhooks/telegram';
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

    #[DataProvider('missingConfiguration')]
    public function test_invalid_configuration_cannot_register_a_webhook(string $key, string $value): void
    {
        config()->set('services.telegram.'.$key, $value);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram'])
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

    public function test_rejected_registration_is_not_reported_as_success(): void
    {
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getMe' => Http::response([
                'ok' => true, 'result' => ['is_bot' => true, 'username' => 'platform_test_bot', 'can_join_groups' => true],
            ]),
            'api.telegram.org/bot12345:platform-token/setWebhook' => Http::response(['ok' => false], 400),
        ]);

        $this->artisan('telegram:register-webhook', ['--url' => 'https://pay.example.test/api/webhooks/telegram'])
            ->expectsOutput(__('admin.telegram.setup.failed'))
            ->assertFailed();

        Http::assertSentCount(2);
    }
}
