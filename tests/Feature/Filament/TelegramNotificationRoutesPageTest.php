<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TelegramNotificationRoutes;
use App\Filament\Resources\SystemConfigResource;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\PlatformRoleProvisioningService;
use App\Services\TelegramNotificationRouter;
use App\Support\TelegramNotificationKind;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramNotificationRoutesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_only_enabled_super_admins_in_the_platform_panel_can_open_and_save(): void
    {
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->assignRole(app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole());
        $disabledSuper = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => false]);

        foreach ([$manager, $disabledSuper] as $user) {
            $this->actingAs($user);
            $this->assertFalse(TelegramNotificationRoutes::shouldRegisterNavigation());
            Livewire::test(TelegramNotificationRoutes::class)->assertForbidden();
        }

        $super = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        $this->actingAs($super);
        Filament::setCurrentPanel('merchant');
        Livewire::test(TelegramNotificationRoutes::class)->assertForbidden();

        Filament::setCurrentPanel('admin');
        $page = Livewire::test(TelegramNotificationRoutes::class)
            ->assertOk()
            ->assertSee(__('admin.telegram_routes.title'))
            ->assertSee(__('admin.telegram_routes.recipients.channel_owner'))
            ->assertSee(rtrim(__('admin.telegram_routes.kinds.payment_method_forbidden.description'), '。.'))
            ->assertDontSee(__('admin.telegram_routes.kinds.payment_method_forbidden.description'));
        $super->update(['is_super_admin' => false]);
        $this->actingAs($super->fresh());
        $page->call('save')->assertForbidden();
    }

    public function test_super_admin_can_save_recipients_and_the_raw_config_stays_out_of_system_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $data = app(TelegramNotificationRouter::class)->current();
        $data[TelegramNotificationKind::NoAvailablePaymentMethod->value] = ['super_admins'];

        Livewire::test(TelegramNotificationRoutes::class)
            ->fillForm($data)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $stored = json_decode((string) SystemConfig::query()->where('config_key', TelegramNotificationRouter::CONFIG_KEY)->value('config_value'), true);
        $this->assertSame(['super_admins'], $stored[TelegramNotificationKind::NoAvailablePaymentMethod->value]);
        $this->assertNull(SystemConfigResource::getEloquentQuery()->where('config_key', TelegramNotificationRouter::CONFIG_KEY)->first());
    }

    public function test_checking_do_not_send_clears_the_other_recipients_on_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $field = 'data.'.TelegramNotificationKind::OrderPaid->value;

        Livewire::test(TelegramNotificationRoutes::class)
            ->set($field, ['merchant', 'none'])
            ->assertSet($field, ['none']);
    }

    public function test_checking_another_recipient_clears_do_not_send_on_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $field = 'data.'.TelegramNotificationKind::OrderPaid->value;

        Livewire::test(TelegramNotificationRoutes::class)
            ->set($field, ['none'])
            ->set($field, ['none', 'merchant'])
            ->assertSet($field, ['merchant']);
    }

    public function test_a_combined_do_not_send_selection_is_reduced_before_it_is_saved(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $data = app(TelegramNotificationRouter::class)->current();
        $data[TelegramNotificationKind::OrderPaid->value] = ['merchant', 'none'];

        Livewire::test(TelegramNotificationRoutes::class)
            ->fillForm($data)
            ->assertSet('data.'.TelegramNotificationKind::OrderPaid->value, ['none'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = json_decode((string) SystemConfig::query()->where('config_key', TelegramNotificationRouter::CONFIG_KEY)->value('config_value'), true);
        $this->assertSame(['none'], $stored[TelegramNotificationKind::OrderPaid->value]);
    }
}
