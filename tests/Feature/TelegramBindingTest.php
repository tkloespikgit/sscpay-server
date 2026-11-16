<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\TelegramBindingRequest;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\TelegramBindingService;
use App\Services\TelegramNotificationService;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config()->set('services.telegram', [
            'bot_token' => '12345:platform-token',
            'bot_username' => 'platform_test_bot',
            'webhook_secret' => str_repeat('s', 40),
        ]);
        Http::preventStrayRequests();
    }

    public function test_private_link_binds_only_its_merchant_without_copying_the_platform_token(): void
    {
        [$merchant, , $link] = $this->createBinding();
        $other = Merchant::query()->create(['name' => 'Other', 'status' => true]);
        $otherBot = TelegramBot::query()->create([
            'merchant_id' => $other->id, 'bot_token' => 'custom-token', 'chat_id' => '555', 'is_enabled' => true,
        ]);

        $this->webhook($link['token'])->assertOk()->assertJson([
            'method' => 'sendMessage',
            'chat_id' => '9876543210',
            'text' => __('admin.telegram.replies.bound'),
        ]);

        $bot = TelegramBot::query()->forMerchant($merchant->id)->sole();
        $this->assertTrue($bot->uses_platform_bot);
        $this->assertSame('', $bot->bot_token);
        $this->assertSame('9876543210', $bot->chat_id);
        $this->assertSame('private', $bot->chat_type);
        $this->assertSame('Test User', $bot->chat_title);
        $this->assertTrue($bot->is_enabled);
        $this->assertArrayNotHasKey('bot_token', $bot->toArray());
        $this->assertSame('555', $otherBot->fresh()->chat_id);
        $this->assertNotNull(TelegramBindingRequest::query()->forMerchant($merchant->id)->sole()->consumed_at);
        $this->assertStringStartsWith('https://t.me/platform_test_bot?start=', $link['url']);
        $this->assertDatabaseMissing('telegram_binding_requests', ['token_hash' => $link['token']]);
        Http::assertNothingSent();
    }

    #[DataProvider('groupTypes')]
    public function test_group_link_requires_an_administrator_and_binds_the_selected_group(string $type, string $status): void
    {
        [$merchant, , $link] = $this->createBinding('group');
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getChatAdministrators' => Http::response([
                'ok' => true, 'result' => [['user' => ['id' => 9876543210], 'status' => $status]],
            ]),
        ]);

        $this->webhook($link['token'], [
            'text' => '/start@platform_test_bot '.$link['token'],
            'chat' => ['id' => -1001234567890, 'type' => $type, 'title' => 'Merchant Operations'],
        ])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.bound'));

        $bot = TelegramBot::query()->forMerchant($merchant->id)->sole();
        $this->assertSame('-1001234567890', $bot->chat_id);
        $this->assertSame('Merchant Operations', $bot->chat_title);
        $this->assertSame($type, $bot->chat_type);
        $this->assertStringStartsWith('https://t.me/platform_test_bot?startgroup=', $link['url']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '-1001234567890');
    }

    public static function groupTypes(): array
    {
        return [
            'group owner' => ['group', 'creator'],
            'supergroup administrator' => ['supergroup', 'administrator'],
        ];
    }

    public function test_regular_group_members_cannot_bind_the_group(): void
    {
        [, , $link] = $this->createBinding('group');
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getChatAdministrators' => Http::response([
                'ok' => true, 'result' => [['user' => ['id' => 123], 'status' => 'creator']],
            ]),
        ]);

        $this->webhook($link['token'], ['chat' => ['id' => -100123, 'type' => 'supergroup']])
            ->assertOk()->assertJsonPath('text', __('admin.telegram.replies.group_admin_required'));

        $this->assertDatabaseCount('telegram_bots', 0);
        $this->assertNull(TelegramBindingRequest::query()->sole()->consumed_at);
        Http::assertSentCount(1);
    }

    public function test_group_lookup_failure_returns_503_and_allows_the_same_update_to_retry(): void
    {
        [, , $link] = $this->createBinding('group');
        Http::fake([
            'api.telegram.org/bot12345:platform-token/getChatAdministrators' => Http::sequence()
                ->push(['ok' => false], 503)
                ->push(['ok' => true, 'result' => [['user' => ['id' => 9876543210], 'status' => 'creator']]]),
        ]);

        $message = ['chat' => ['id' => -100123, 'type' => 'group']];
        $this->webhook($link['token'], $message)->assertServiceUnavailable();
        $this->assertDatabaseCount('telegram_bots', 0);
        $this->assertNull(TelegramBindingRequest::query()->sole()->consumed_at);

        $this->webhook($link['token'], $message)->assertOk()->assertJsonPath('text', __('admin.telegram.replies.bound'));
        $this->assertDatabaseCount('telegram_bots', 1);
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidSecrets')]
    public function test_unsigned_and_forged_webhooks_are_rejected_without_changes(?string $secret): void
    {
        [, , $link] = $this->createBinding();

        $this->postJson(route('webhooks.telegram'), ['message' => $this->message($link['token'])],
            $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret])
            ->assertForbidden();

        $this->assertDatabaseCount('telegram_bots', 0);
        $this->assertNull(TelegramBindingRequest::query()->sole()->consumed_at);
        Http::assertNothingSent();
    }

    public static function invalidSecrets(): array
    {
        return ['missing' => [null], 'forged' => ['wrong-secret']];
    }

    public function test_a_consumed_link_cannot_be_replayed_to_replace_the_recipient(): void
    {
        [$merchant, , $link] = $this->createBinding();
        $this->webhook($link['token'])->assertOk();

        $this->webhook($link['token'], ['chat' => ['id' => 222], 'from' => ['id' => 222]])
            ->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('9876543210', TelegramBot::query()->forMerchant($merchant->id)->sole()->chat_id);
        $this->assertDatabaseCount('telegram_bots', 1);
        Http::assertNothingSent();
    }

    public function test_generating_a_new_link_invalidates_the_previous_link(): void
    {
        [$merchant, $actor, $old] = $this->createBinding();
        $new = app(TelegramBindingService::class)->createLink($actor, $merchant->id, 'private');

        $this->webhook($old['token'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertDatabaseCount('telegram_bots', 0);
        $this->webhook($new['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.bound'));

        $this->assertDatabaseCount('telegram_binding_requests', 1);
        $this->assertDatabaseCount('telegram_bots', 1);
        Http::assertNothingSent();
    }

    public function test_links_expire_after_ten_minutes(): void
    {
        $this->freezeTime();
        [, , $link] = $this->createBinding();
        $this->travel(10)->minutes();

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.expired'));

        $this->assertDatabaseCount('telegram_bots', 0);
        Http::assertNothingSent();
    }

    public function test_changing_the_platform_bot_invalidates_pending_links(): void
    {
        [, , $link] = $this->createBinding();
        config()->set('services.telegram.bot_token', '67890:another-bot');

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.expired'));

        $this->assertDatabaseCount('telegram_bots', 0);
        Http::assertNothingSent();
    }

    public function test_revoked_permissions_are_rechecked_when_the_merchant_binds(): void
    {
        [, $actor, $link] = $this->createBinding();
        $actor->revokePermissionTo(Permissions::TELEGRAM_MANAGE);

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.expired'));

        $this->assertDatabaseCount('telegram_bots', 0);
        $this->assertDatabaseCount('telegram_binding_requests', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_users_cannot_complete_pending_bindings(): void
    {
        [, $actor, $link] = $this->createBinding();
        $actor->update(['status' => false]);

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.expired'));

        $this->assertDatabaseCount('telegram_bots', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_merchants_cannot_complete_pending_bindings(): void
    {
        [$merchant, , $link] = $this->createBinding();
        $merchant->update(['status' => false]);

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.expired'));

        $this->assertDatabaseCount('telegram_bots', 0);
        Http::assertNothingSent();
    }

    public function test_transferring_a_merchant_invalidates_the_previous_managers_binding_link(): void
    {
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->givePermissionTo(Permissions::TELEGRAM_MANAGE);
        $merchant = Merchant::query()->create(['name' => 'Managed', 'owner_id' => $manager->id, 'status' => true]);
        $link = app(TelegramBindingService::class)->createLink($manager, $merchant->id, 'private');
        $merchant->update(['owner_id' => null]);

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.expired'));

        $this->assertDatabaseCount('telegram_bots', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('ignoredMessages')]
    public function test_unrelated_or_invalid_messages_do_not_bind(array $overrides): void
    {
        [, , $link] = $this->createBinding();

        $this->webhook($link['token'], $overrides)->assertOk()->assertExactJson(['ok' => true]);

        $this->assertDatabaseCount('telegram_bots', 0);
        Http::assertNothingSent();
    }

    public static function ignoredMessages(): array
    {
        return [
            'ordinary text' => [['text' => 'hello']],
            'unknown link' => [['text' => '/start '.str_repeat('a', 64)]],
            'another bot' => [['text' => '/start@another_bot '.str_repeat('a', 64)]],
            'channel' => [['chat' => ['type' => 'channel']]],
            'bot sender' => [['from' => ['is_bot' => true]]],
            'malformed id' => [['chat' => ['id' => ['invalid']]]],
        ];
    }

    public function test_a_group_link_cannot_bind_a_private_chat(): void
    {
        [, , $link] = $this->createBinding('group');

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.wrong_type'));

        $this->assertDatabaseCount('telegram_bots', 0);
        Http::assertNothingSent();
    }

    public function test_disconnect_cancels_pending_links_and_keeps_other_merchants_connected(): void
    {
        [$merchant, $actor, $link] = $this->createBinding();
        $this->webhook($link['token'])->assertOk();
        [$other, , $otherLink] = $this->createBinding();
        $this->webhook($otherLink['token'])->assertOk();
        $pending = app(TelegramBindingService::class)->createLink($actor, $merchant->id, 'private');

        app(TelegramBindingService::class)->disconnect($actor, $merchant->id);

        $this->webhook($pending['token'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertFalse(TelegramBot::query()->forMerchant($merchant->id)->exists());
        $this->assertTrue(TelegramBot::query()->forMerchant($other->id)->exists());
        $this->assertFalse(TelegramBindingRequest::query()->forMerchant($merchant->id)->exists());
        Http::assertNothingSent();
    }

    public function test_platform_and_custom_notifications_use_their_own_credentials(): void
    {
        [$merchant, , $link] = $this->createBinding();
        $this->webhook($link['token'])->assertOk();
        $custom = Merchant::query()->create(['name' => 'Custom bot', 'status' => true]);
        TelegramBot::query()->create([
            'merchant_id' => $custom->id, 'bot_token' => 'custom-token', 'chat_id' => '-123', 'is_enabled' => true,
        ]);
        Http::fake([
            'api.telegram.org/bot12345:platform-token/sendMessage' => Http::response(['ok' => true]),
            'api.telegram.org/botcustom-token/sendMessage' => Http::response(['ok' => true]),
        ]);

        $this->assertTrue(app(TelegramNotificationService::class)->send($merchant->id, 'Platform notification'));
        $this->assertTrue(app(TelegramNotificationService::class)->send($custom->id, 'Custom notification'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot12345:platform-token/sendMessage'
            && $request['chat_id'] === '9876543210' && $request['text'] === 'Platform notification');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/botcustom-token/sendMessage'
            && $request['chat_id'] === '-123' && $request['text'] === 'Custom notification');
    }

    public function test_saving_disabled_notifications_cancels_an_unfinished_rebinding_link(): void
    {
        [$merchant, $actor, $link] = $this->createBinding();
        $this->webhook($link['token'])->assertOk();
        $service = app(TelegramBindingService::class);
        $pending = $service->createLink($actor, $merchant->id, 'private');

        $service->save($actor, $merchant->id, ['mode' => 'platform', 'is_enabled' => false]);

        $this->webhook($pending['token'], ['chat' => ['id' => 222], 'from' => ['id' => 222]])
            ->assertOk()->assertExactJson(['ok' => true]);
        $bot = TelegramBot::query()->forMerchant($merchant->id)->sole();
        $this->assertFalse($bot->is_enabled);
        $this->assertSame('9876543210', $bot->chat_id);
        $this->assertDatabaseCount('telegram_binding_requests', 0);
        Http::assertNothingSent();
    }

    public function test_a_merchant_can_reconnect_after_soft_deleting_the_previous_binding(): void
    {
        [$merchant, $actor, $link] = $this->createBinding();
        $this->webhook($link['token'])->assertOk();
        $service = app(TelegramBindingService::class);
        $service->disconnect($actor, $merchant->id);
        $new = $service->createLink($actor, $merchant->id, 'private');

        $this->webhook($new['token'], ['chat' => ['id' => 222], 'from' => ['id' => 222]])
            ->assertOk()->assertJsonPath('text', __('admin.telegram.replies.bound'));

        $this->assertSame('222', TelegramBot::query()->forMerchant($merchant->id)->sole()->chat_id);
        $this->assertSame(2, TelegramBot::withTrashed()->forMerchant($merchant->id)->count());
        Http::assertNothingSent();
    }

    public function test_platform_credentials_do_not_enable_unbound_or_disabled_merchants_or_replace_missing_custom_tokens(): void
    {
        [$merchant, , $link] = $this->createBinding();
        $service = app(TelegramNotificationService::class);
        $this->assertFalse($service->send($merchant->id, 'Not bound'));

        $this->webhook($link['token'])->assertOk();
        $bot = TelegramBot::query()->forMerchant($merchant->id)->sole();
        $bot->update(['is_enabled' => false]);
        $this->assertFalse($service->send($merchant->id, 'Disabled'));

        $bot->update(['is_enabled' => true, 'uses_platform_bot' => false, 'bot_token' => '']);
        $this->assertFalse($service->send($merchant->id, 'No custom token'));
        Http::assertNothingSent();
    }

    private function createBinding(string $chatType = 'private'): array
    {
        $merchant = Merchant::query()->create(['name' => 'Merchant', 'status' => true]);
        $actor = User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);
        $actor->givePermissionTo(Permissions::TELEGRAM_MANAGE);

        return [$merchant, $actor, app(TelegramBindingService::class)->createLink($actor, $merchant->id, $chatType)];
    }

    private function webhook(string $token, array $overrides = []): TestResponse
    {
        return $this->postJson(route('webhooks.telegram'), [
            'update_id' => 1234,
            'message' => array_replace_recursive($this->message($token), $overrides),
        ], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 40)]);
    }

    private function message(string $token): array
    {
        return [
            'text' => '/start '.$token,
            'from' => ['id' => 9876543210, 'is_bot' => false],
            'chat' => ['id' => 9876543210, 'type' => 'private', 'first_name' => 'Test', 'last_name' => 'User'],
        ];
    }
}
