<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LogisticsTrackingSettings;
use App\Filament\Pages\ServiceSettings;
use App\Filament\Pages\TelegramSettings;
use App\Models\Merchant;
use App\Models\MerchantLogisticsTrackingSetting;
use App\Models\SystemConfig;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        SystemConfig::set('logistics_tracking.available_drivers', [SeventeenTrackDriver::NAME], 'logistics');
        Filament::setCurrentPanel('admin');
    }

    public function test_page_displays_both_services_under_one_navigation_entry(): void
    {
        $merchant = $this->createMerchant();
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test(ServiceSettings::class)
            ->assertSuccessful()
            ->assertSee(__('admin.logistics_tracking.nav_label'))
            ->assertSee(__('admin.telegram.nav_label'));

        $this->assertTrue(ServiceSettings::shouldRegisterNavigation());
        $this->assertFalse(LogisticsTrackingSettings::shouldRegisterNavigation());
        $this->assertFalse(TelegramSettings::shouldRegisterNavigation());
    }

    public function test_logistics_can_be_saved_while_telegram_is_unconfigured(): void
    {
        $merchant = $this->createMerchant();
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test(ServiceSettings::class)
            ->fillForm($this->logisticsInput(), 'logisticsForm')
            ->call('saveLogisticsTracking')
            ->assertHasNoErrors()
            ->assertNotified(__('admin.logistics_tracking.notifications.saved'));

        $setting = MerchantLogisticsTrackingSetting::query()->sole();
        $this->assertSame($merchant->id, $setting->merchant_id);
        $this->assertSame('tracking-token', $setting->api_token);
        $this->assertNotEmpty($setting->webhook_token);
        $this->assertDatabaseCount('telegram_bots', 0);
    }

    public function test_telegram_can_be_saved_while_logistics_is_unconfigured(): void
    {
        $merchant = $this->createMerchant();
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test(ServiceSettings::class)
            ->fillForm($this->telegramInput(), 'telegramForm')
            ->call('saveTelegram')
            ->assertHasNoErrors()
            ->assertNotified(__('admin.telegram.notifications.saved'));

        $bot = TelegramBot::query()->sole();
        $this->assertSame($merchant->id, $bot->merchant_id);
        $this->assertSame('12345:test', $bot->bot_token);
        $this->assertSame('-100123', $bot->chat_id);
        $this->assertDatabaseCount('merchant_logistics_tracking_settings', 0);
    }

    public function test_saving_logistics_preserves_unsaved_telegram_changes(): void
    {
        $merchant = $this->createMerchant();
        $bot = $this->createBot($merchant);
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test(ServiceSettings::class)
            ->fillForm(['bot_token' => 'unsaved-bot-token', 'chat_id' => 'invalid'], 'telegramForm')
            ->fillForm($this->logisticsInput(), 'logisticsForm')
            ->call('saveLogisticsTracking')
            ->assertHasNoErrors()
            ->assertSet('telegramData.bot_token', 'unsaved-bot-token')
            ->assertSet('telegramData.chat_id', 'invalid');

        $this->assertSame('original-bot-token', $bot->fresh()->bot_token);
        $this->assertSame('-100999', $bot->fresh()->chat_id);
        $this->assertDatabaseCount('merchant_logistics_tracking_settings', 1);
    }

    public function test_saving_telegram_preserves_unsaved_logistics_changes(): void
    {
        $merchant = $this->createMerchant();
        $setting = $this->createLogisticsSetting($merchant);
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test(ServiceSettings::class)
            ->fillForm(['api_token' => 'unsaved-tracking-token', 'webhook_secret' => null], 'logisticsForm')
            ->fillForm($this->telegramInput(), 'telegramForm')
            ->call('saveTelegram')
            ->assertHasNoErrors()
            ->assertSet('logisticsData.api_token', 'unsaved-tracking-token')
            ->assertSet('logisticsData.webhook_secret', null);

        $this->assertSame('original-tracking-token', $setting->fresh()->api_token);
        $this->assertSame('original-webhook-secret', $setting->fresh()->webhook_secret);
        $this->assertDatabaseCount('telegram_bots', 1);
    }

    #[DataProvider('restrictedServiceActions')]
    public function test_service_permissions_hide_credentials_and_forbid_ungranted_actions(string $permission, string $action, string $hiddenCredential): void
    {
        $merchant = $this->createMerchant();
        $bot = $this->createBot($merchant);
        $setting = $this->createLogisticsSetting($merchant);
        $this->actingAs($this->merchantUser($merchant, [$permission]));
        Http::fake();
        Http::preventStrayRequests();

        Livewire::test(ServiceSettings::class)
            ->assertSuccessful()
            ->assertDontSee($hiddenCredential)
            ->call($action)
            ->assertForbidden();

        $this->assertSame('original-bot-token', $bot->fresh()->bot_token);
        $this->assertSame('original-tracking-token', $setting->fresh()->api_token);
        $this->assertSame($setting->webhook_token, $setting->fresh()->webhook_token);
        Http::assertNothingSent();
    }

    public static function restrictedServiceActions(): array
    {
        return [
            'logistics cannot save Telegram' => [Permissions::LOGISTICS_TRACKING_MANAGE, 'saveTelegram', 'original-bot-token'],
            'logistics cannot test Telegram' => [Permissions::LOGISTICS_TRACKING_MANAGE, 'sendTest', 'original-bot-token'],
            'Telegram cannot save logistics' => [Permissions::TELEGRAM_MANAGE, 'saveLogisticsTracking', 'original-tracking-token'],
            'Telegram cannot rotate webhook token' => [Permissions::TELEGRAM_MANAGE, 'regenerateWebhookToken', 'original-webhook-secret'],
        ];
    }

    public function test_page_forbids_users_without_either_service_permission(): void
    {
        $merchant = $this->createMerchant();
        $this->actingAs($this->merchantUser($merchant, []));

        Livewire::test(ServiceSettings::class)->assertForbidden();

        $this->assertFalse(ServiceSettings::canAccess());
    }

    public function test_telegram_cannot_be_saved_for_another_merchant(): void
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant('Other Merchant');
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test(ServiceSettings::class)
            ->set('merchantData', ['merchant_id' => $other->id])
            ->fillForm($this->telegramInput(), 'telegramForm')
            ->call('saveTelegram')
            ->assertHasFormErrors(['merchant_id'], 'merchantForm');

        $this->assertDatabaseCount('telegram_bots', 0);
    }

    public function test_merchant_selector_only_loads_the_users_own_merchant(): void
    {
        $merchant = $this->createMerchant();
        $this->createMerchant('Other Merchant');
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test(ServiceSettings::class)
            ->assertSuccessful()
            ->assertFormFieldExists('merchant_id', 'merchantForm', fn (Select $field): bool => $field->getOptions() === [
                $merchant->id => $merchant->name,
            ]);
    }

    public function test_switching_merchant_reloads_both_services_and_clears_missing_configuration(): void
    {
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->givePermissionTo([Permissions::TELEGRAM_MANAGE, Permissions::LOGISTICS_TRACKING_MANAGE]);
        $configured = $this->createMerchant('Configured', $manager);
        $empty = $this->createMerchant('Unconfigured', $manager);
        $this->createMerchant('Other Merchant');
        $this->createBot($configured);
        $this->createLogisticsSetting($configured);
        $this->actingAs($manager);

        Livewire::test(ServiceSettings::class)
            ->assertFormFieldExists('merchant_id', 'merchantForm', fn (Select $field): bool => $field->getOptions() === [
                $configured->id => $configured->name,
                $empty->id => $empty->name,
            ])
            ->set('merchantData.merchant_id', $configured->id)
            ->assertSet('telegramData.bot_token', 'original-bot-token')
            ->assertSet('telegramData.chat_id', '-100999')
            ->assertSet('logisticsData.api_token', 'original-tracking-token')
            ->assertSet('logisticsData.webhook_secret', 'original-webhook-secret')
            ->set('merchantData.merchant_id', $empty->id)
            ->assertSet('telegramData.bot_token', null)
            ->assertSet('telegramData.chat_id', null)
            ->assertSet('logisticsData.api_token', null)
            ->assertSet('logisticsData.webhook_secret', null)
            ->assertSet('logisticsData.webhook_url', null);
    }

    public function test_sending_a_test_saves_only_telegram_and_uses_the_new_configuration(): void
    {
        $merchant = $this->createMerchant();
        $setting = $this->createLogisticsSetting($merchant);
        $this->actingAs($this->merchantUser($merchant));
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test/sendMessage' => Http::response(['ok' => true]),
        ]);

        Livewire::test(ServiceSettings::class)
            ->fillForm(['api_token' => 'unsaved-tracking-token', 'webhook_secret' => null], 'logisticsForm')
            ->fillForm($this->telegramInput(), 'telegramForm')
            ->call('sendTest')
            ->assertHasNoErrors()
            ->assertNotified(__('admin.telegram.notifications.test_sent'))
            ->assertSet('logisticsData.api_token', 'unsaved-tracking-token');

        $bot = TelegramBot::query()->sole();
        $this->assertSame('12345:test', $bot->bot_token);
        $this->assertNotNull($bot->test_sent_at);
        $this->assertSame('original-tracking-token', $setting->fresh()->api_token);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '-100123');
    }

    public function test_rotating_the_webhook_token_requires_confirmation_and_does_not_submit_telegram(): void
    {
        $merchant = $this->createMerchant();
        $setting = $this->createLogisticsSetting($merchant);
        $this->actingAs($this->merchantUser($merchant));

        $action = TestAction::make('regenerateWebhookToken')->schemaComponent('logisticsTracking', 'logisticsForm');

        $page = Livewire::test(ServiceSettings::class)
            ->fillForm(['bot_token' => 'unsaved-bot-token'], 'telegramForm')
            ->mountAction($action)
            ->assertActionMounted($action);

        $this->assertSame($setting->webhook_token, $setting->fresh()->webhook_token);

        $page->callMountedAction()
            ->assertHasNoErrors()
            ->assertActionNotMounted($action)
            ->assertNotified(__('admin.logistics_tracking.notifications.webhook_token_regenerated'))
            ->assertSet('telegramData.bot_token', 'unsaved-bot-token');

        $this->assertNotSame($setting->webhook_token, $setting->fresh()->webhook_token);
        $this->assertDatabaseCount('telegram_bots', 0);
    }

    #[DataProvider('legacySettingsPages')]
    public function test_legacy_settings_pages_redirect_to_service_settings(string $page): void
    {
        $merchant = $this->createMerchant();
        $this->actingAs($this->merchantUser($merchant));

        Livewire::test($page)->assertRedirect(ServiceSettings::getUrl());
    }

    public static function legacySettingsPages(): array
    {
        return [
            'logistics' => [LogisticsTrackingSettings::class],
            'Telegram' => [TelegramSettings::class],
        ];
    }

    private function createMerchant(string $name = 'Test Merchant', ?User $owner = null): Merchant
    {
        return Merchant::query()->create([
            'name' => $name,
            'owner_id' => $owner?->id,
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => fake()->unique()->safeEmail(),
            'status' => true,
        ]);
    }

    private function merchantUser(Merchant $merchant, array $permissions = [Permissions::TELEGRAM_MANAGE, Permissions::LOGISTICS_TRACKING_MANAGE]): User
    {
        $user = User::factory()->create([
            'merchant_id' => $merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function createBot(Merchant $merchant): TelegramBot
    {
        return TelegramBot::query()->create([
            'merchant_id' => $merchant->id,
            'bot_token' => 'original-bot-token',
            'chat_id' => '-100999',
            'is_enabled' => true,
        ]);
    }

    private function createLogisticsSetting(Merchant $merchant): MerchantLogisticsTrackingSetting
    {
        return MerchantLogisticsTrackingSetting::query()->create([
            'merchant_id' => $merchant->id,
            'driver' => SeventeenTrackDriver::NAME,
            'api_token' => 'original-tracking-token',
            'webhook_secret' => 'original-webhook-secret',
            'webhook_token' => MerchantLogisticsTrackingSetting::generateWebhookToken(),
        ]);
    }

    private function logisticsInput(): array
    {
        return [
            'driver' => SeventeenTrackDriver::NAME,
            'api_token' => 'tracking-token',
            'webhook_secret' => 'webhook-secret',
        ];
    }

    private function telegramInput(): array
    {
        return [
            'bot_token' => '12345:test',
            'chat_id' => '-100123',
            'is_enabled' => true,
        ];
    }
}
