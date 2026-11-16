<?php

namespace Tests\Feature;

use App\Filament\Auth\EditProfile;
use App\Filament\Resources\AdminResource\Pages\EditAdmin;
use App\Models\Merchant;
use App\Models\TelegramBindingRequest;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\PaymentMethodFailureService;
use App\Services\TelegramBindingService;
use App\Services\TelegramNotificationService;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class AdminTelegramBindingTest extends TestCase
{
    use CreatesTestOrders;
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
        Filament::setCurrentPanel('admin');
    }

    public function test_platform_staff_link_binds_their_own_account_without_storing_the_platform_token(): void
    {
        $manager = $this->merchantManager();
        $link = app(TelegramBindingService::class)->createAdminLink($manager, $manager, 'private');

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.bound'));

        $bot = TelegramBot::query()->forUser($manager->id)->sole();
        $this->assertNull($bot->merchant_id);
        $this->assertTrue($bot->uses_platform_bot);
        $this->assertSame('', $bot->bot_token);
        $this->assertSame('9876543210', $bot->chat_id);
        $this->assertNotNull(TelegramBindingRequest::query()->forAdmin($manager->id)->sole()->consumed_at);
        $this->assertStringStartsWith('https://t.me/platform_test_bot?start=', $link['url']);
        Http::assertNothingSent();
    }

    public function test_a_platform_account_cannot_bind_someone_else(): void
    {
        $actor = $this->superAdmin();
        $manager = $this->merchantManager();
        $service = app(TelegramBindingService::class);

        try {
            $service->createAdminLink($actor, $manager, 'private');
            $this->fail('不能替其他管理员生成绑定链接');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        $service->createAdminLink($manager, $actor, 'private');
    }

    public function test_disabled_platform_account_link_does_not_bind(): void
    {
        $actor = $this->superAdmin();
        $link = app(TelegramBindingService::class)->createAdminLink($actor, $actor, 'private');
        $actor->update(['status' => false]);

        $this->webhook($link['token'])->assertOk()->assertJsonPath('text', __('admin.telegram.replies.expired'));

        $this->assertNull(TelegramBot::query()->forUser($actor->id)->first());
        $this->assertDatabaseCount('telegram_binding_requests', 0);
    }

    public function test_account_settings_explains_when_the_platform_bot_is_not_configured(): void
    {
        config()->set('services.telegram.bot_token', null);
        $this->actingAs($this->superAdmin());

        Livewire::test(EditProfile::class)
            ->fillForm(['mode' => 'platform'], 'ownTelegramForm')
            ->assertSee(__('admin.telegram.help.admin_unavailable'))
            ->assertDontSee(__('admin.telegram.help.unavailable'));
    }

    public function test_account_settings_generates_a_link_for_the_signed_in_admin(): void
    {
        $manager = $this->merchantManager();
        $this->actingAs($manager);

        Livewire::test(EditProfile::class)
            ->assertSee(__('admin.telegram.help.admin_quick_binding'))
            ->call('createOwnTelegramBinding', 'group');

        $request = TelegramBindingRequest::query()->forAdmin($manager->id)->sole();
        $this->assertSame($manager->id, $request->user_id);
        $this->assertSame('group', $request->chat_type);
        $this->assertNull($request->merchant_id);
        $this->assertDatabaseCount('telegram_binding_requests', 1);
    }

    public function test_merchant_owned_channel_disable_does_not_notify_an_admin_bot(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('merchant-channel');
        $method->update(['max_failed_times' => 1]);
        $admin = $this->superAdmin();
        TelegramBot::query()->create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'merchant-token', 'chat_id' => 'merchant-chat', 'is_enabled' => true,
        ]);
        TelegramBot::query()->create([
            'user_id' => $admin->id, 'merchant_id' => null, 'bot_token' => 'admin-token', 'chat_id' => 'admin-chat', 'is_enabled' => true,
        ]);
        $order = $this->makeOrder('FAIL-MERCHANT', 'merchant-fail', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        $messages = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'));
        $this->assertCount(1, $messages);
        $this->assertSame('merchant-chat', $messages->first()[0]['chat_id']);
    }

    public function test_unowned_system_channel_notifies_each_enabled_super_admin_after_one_delivery_fails(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('system-channel');
        $method->forceFill(['merchant_id' => null, 'owner_id' => null, 'max_failed_times' => 1])->save();
        $method->assignedMerchants()->attach($this->merchant->id);
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        TelegramBot::query()->create([
            'user_id' => $first->id, 'merchant_id' => null, 'bot_token' => 'first-token', 'chat_id' => 'first-admin', 'is_enabled' => true,
        ]);
        TelegramBot::query()->create([
            'user_id' => $second->id, 'merchant_id' => null, 'bot_token' => 'second-token', 'chat_id' => 'second-admin', 'is_enabled' => true,
        ]);
        TelegramBot::query()->create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'merchant-token', 'chat_id' => 'merchant-chat', 'is_enabled' => true,
        ]);
        $order = $this->makeOrder('FAIL-SYSTEM', 'system-fail', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        Http::fake([
            'api.telegram.org/botfirst-token/sendMessage' => Http::response(['ok' => false], 500),
            'api.telegram.org/botsecond-token/sendMessage' => Http::response(['ok' => true]),
        ]);

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        $chatIds = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'))
            ->map(fn (array $entry): string => (string) $entry[0]['chat_id'])
            ->all();
        $this->assertSame(['first-admin', 'second-admin'], $chatIds);
        $this->assertFalse($method->refresh()->is_active);
    }

    public function test_a_merchant_user_or_disabled_admin_cannot_be_the_binding_target(): void
    {
        $actor = $this->superAdmin();
        $merchant = Merchant::query()->create(['name' => 'Attached merchant']);
        $merchantUser = User::factory()->create([
            'merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true,
        ]);
        $disabled = $this->merchantManager();
        $disabled->update(['status' => false]);
        $service = app(TelegramBindingService::class);

        try {
            $service->createAdminLink($actor, $merchantUser, 'private');
            $this->fail('商户用户不应成为管理员绑定对象');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        $service->createAdminLink($actor, $disabled, 'private');
    }

    public function test_super_admin_can_disable_and_disconnect_the_bound_admin(): void
    {
        $manager = $this->merchantManager();
        $link = app(TelegramBindingService::class)->createAdminLink($manager, $manager, 'private');
        $this->webhook($link['token'])->assertOk();
        $service = app(TelegramBindingService::class);

        $service->saveAdmin($manager, $manager, ['mode' => 'platform', 'is_enabled' => false]);

        $bot = TelegramBot::query()->forUser($manager->id)->sole();
        $this->assertFalse($bot->is_enabled);
        $this->assertTrue($bot->uses_platform_bot);
        $this->assertSame('9876543210', $bot->chat_id);

        $service->disconnectAdmin($manager, $manager);

        $this->assertNull(TelegramBot::query()->forUser($manager->id)->first());
        $this->assertDatabaseCount('telegram_binding_requests', 0);
    }

    public function test_custom_bot_replaces_only_that_admins_platform_binding(): void
    {
        $manager = $this->merchantManager();
        $other = $this->superAdmin();
        $link = app(TelegramBindingService::class)->createAdminLink($manager, $manager, 'private');
        $this->webhook($link['token'])->assertOk();
        TelegramBot::query()->create([
            'user_id' => $other->id, 'merchant_id' => null, 'bot_token' => 'other-token', 'chat_id' => 'other-chat', 'is_enabled' => true,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramBindingService::class)->saveAdmin($manager, $manager, [
            'mode' => 'custom', 'bot_token' => '999:custom-admin-token', 'chat_id' => '-1001', 'is_enabled' => true,
        ]);

        $bot = TelegramBot::query()->forUser($manager->id)->sole();
        $this->assertNull($bot->merchant_id);
        $this->assertFalse($bot->uses_platform_bot);
        $this->assertSame('999:custom-admin-token', $bot->bot_token);
        $this->assertSame('-1001', $bot->chat_id);
        $this->assertNull($bot->chat_title);
        $this->assertSame('other-token', TelegramBot::query()->forUser($other->id)->sole()->bot_token);
        $this->assertDatabaseCount('telegram_binding_requests', 0);
        $this->assertTrue(app(TelegramNotificationService::class)->sendToUser($manager->id, 'custom'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'bot999:custom-admin-token/sendMessage')
            && $request['chat_id'] === '-1001');
    }

    public function test_platform_mode_cannot_be_saved_before_binding_and_merchant_users_cannot_save(): void
    {
        $manager = $this->merchantManager();
        $merchant = Merchant::query()->create(['name' => 'Attached merchant']);
        $merchantUser = User::factory()->create([
            'merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true,
        ]);
        $service = app(TelegramBindingService::class);

        try {
            $service->saveAdmin($manager, $manager, ['mode' => 'platform', 'is_enabled' => true]);
            $this->fail('未完成快速绑定不应保存平台模式');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('telegramData.mode', $exception->errors());
        }

        $this->expectException(AuthorizationException::class);
        $service->saveAdmin($merchantUser, $merchantUser, [
            'mode' => 'custom', 'bot_token' => '999:custom-admin-token', 'chat_id' => '42', 'is_enabled' => true,
        ]);
    }

    public function test_account_settings_saves_a_custom_bot_separately_from_the_account(): void
    {
        $manager = $this->merchantManager();
        $this->actingAs($manager);

        Livewire::test(EditProfile::class)
            ->assertSee(__('admin.telegram.modes.custom'))
            ->fillForm([
                'mode' => 'custom',
                'bot_token' => '12345:test',
                'chat_id' => '-100123',
                'is_enabled' => true,
            ], 'ownTelegramForm')
            ->call('saveOwnTelegram')
            ->assertHasNoErrors()
            ->assertNotified(__('admin.telegram.notifications.saved'))
            ->fillForm(['name' => 'Renamed Admin'])
            ->call('save')
            ->assertHasNoErrors();

        $bot = TelegramBot::query()->forUser($manager->id)->sole();
        $this->assertSame('12345:test', $bot->bot_token);
        $this->assertSame('-100123', $bot->chat_id);
        $this->assertFalse($bot->uses_platform_bot);
        $this->assertSame('Renamed Admin', $manager->refresh()->name);
        $this->assertNull($bot->merchant_id);
    }

    public function test_admin_edit_page_and_merchant_account_settings_do_not_show_personal_telegram(): void
    {
        $actor = $this->superAdmin();
        $manager = $this->merchantManager();
        $this->actingAs($actor);

        Livewire::test(EditAdmin::class, ['record' => $manager->id])
            ->assertDontSee(__('admin.telegram.help.admin_quick_binding'));

        $merchant = Merchant::query()->create(['name' => 'Merchant account']);
        $merchantUser = User::factory()->create([
            'merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true,
        ]);
        Filament::setCurrentPanel('merchant');
        $this->actingAs($merchantUser);

        Livewire::test(EditProfile::class)
            ->assertDontSee(__('admin.telegram.nav_label'));
    }

    public function test_disabled_admin_bot_is_skipped(): void
    {
        $admin = $this->superAdmin();
        TelegramBot::query()->create([
            'user_id' => $admin->id, 'merchant_id' => null, 'bot_token' => 'admin-token',
            'chat_id' => 'admin-chat', 'is_enabled' => false, 'uses_platform_bot' => true,
        ]);

        $this->assertFalse(app(TelegramNotificationService::class)->sendToUser($admin->id, 'disabled'));
        Http::assertNothingSent();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
    }

    private function merchantManager(): User
    {
        return User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
    }

    private function webhook(string $token): TestResponse
    {
        return $this->postJson(route('webhooks.telegram'), [
            'update_id' => 1234,
            'message' => [
                'text' => '/start '.$token,
                'from' => ['id' => 9876543210, 'is_bot' => false],
                'chat' => ['id' => 9876543210, 'type' => 'private', 'first_name' => 'Test', 'last_name' => 'User'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 40)]);
    }
}
