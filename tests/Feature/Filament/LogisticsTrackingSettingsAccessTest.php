<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LogisticsTrackingSettings;
use App\Models\Merchant;
use App\Models\MerchantLogisticsTrackingSetting;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use App\Services\LogisticsTracking\LogisticsTrackingManager;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class LogisticsTrackingSettingsAccessTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->createMerchantAndApplication();
        SystemConfig::set('logistics_tracking.available_drivers', [SeventeenTrackDriver::NAME], 'logistics');
        Filament::setCurrentPanel('admin');
    }

    public function test_merchant_user_cannot_save_another_merchants_settings(): void
    {
        $other = Merchant::query()->create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other',
            'contact_phone' => '123456',
            'contact_email' => 'other@example.com',
            'status' => true,
        ]);

        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::LOGISTICS_TRACKING_MANAGE);
        $this->actingAs($user);

        Livewire::test(LogisticsTrackingSettings::class)
            ->fillForm([
                'merchant_id' => $other->id,
                'driver' => SeventeenTrackDriver::NAME,
                'api_token' => 'token-a',
                'webhook_secret' => 'secret-a',
            ])
            ->call('save')
            ->assertHasFormErrors(['merchant_id']);
    }

    public function test_merchant_user_can_save_own_merchant_settings(): void
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::LOGISTICS_TRACKING_MANAGE);
        $this->actingAs($user);

        Livewire::test(LogisticsTrackingSettings::class)
            ->fillForm([
                'merchant_id' => $this->merchant->id,
                'driver' => SeventeenTrackDriver::NAME,
                'api_token' => 'token-own',
                'webhook_secret' => 'secret-own',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('merchant_logistics_tracking_settings', [
            'merchant_id' => $this->merchant->id,
            'driver' => SeventeenTrackDriver::NAME,
        ]);
    }

    public function test_available_driver_options_include_unimplemented_configured_names(): void
    {
        SystemConfig::set(
            'logistics_tracking.available_drivers',
            [SeventeenTrackDriver::NAME, 'logistics_track', 'future_carrier'],
            'logistics'
        );

        $manager = app(LogisticsTrackingManager::class);

        $this->assertSame(
            [SeventeenTrackDriver::NAME, 'logistics_track', 'future_carrier'],
            $manager->configuredDriverNames()
        );
        $this->assertArrayHasKey('logistics_track', $manager->availableDriverOptions());
        $this->assertArrayHasKey('future_carrier', $manager->availableDriverOptions());
        $this->assertTrue($manager->isDriverConfigured('logistics_track'));
        $this->assertFalse($manager->isDriverImplemented('logistics_track'));
        $this->assertFalse($manager->isDriverAvailable('logistics_track'));
        $this->assertTrue($manager->isDriverAvailable(SeventeenTrackDriver::NAME));
    }

    public function test_merchant_can_save_unimplemented_configured_driver(): void
    {
        SystemConfig::set(
            'logistics_tracking.available_drivers',
            [SeventeenTrackDriver::NAME, 'logistics_track'],
            'logistics'
        );

        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::LOGISTICS_TRACKING_MANAGE);
        $this->actingAs($user);

        Livewire::test(LogisticsTrackingSettings::class)
            ->fillForm([
                'merchant_id' => $this->merchant->id,
                'driver' => 'logistics_track',
                'api_token' => 'token-future',
                'webhook_secret' => 'secret-future',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('merchant_logistics_tracking_settings', [
            'merchant_id' => $this->merchant->id,
            'driver' => 'logistics_track',
        ]);
        $this->assertNotNull(
            MerchantLogisticsTrackingSetting::query()->where('merchant_id', $this->merchant->id)->value('webhook_token')
        );
    }
}
