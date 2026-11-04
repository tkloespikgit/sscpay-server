<?php

namespace Tests\Feature;

use App\Filament\Resources\SystemConfigResource\Pages\EditSystemConfig;
use App\Filament\Support\SystemConfigForm;
use App\Models\SystemConfig;
use App\Models\User;
use Database\Seeders\LogisticsAnomalySettingsSeeder;
use Database\Seeders\SystemConfigSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogisticsAnomalySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_migration_and_seeders_supply_missing_defaults_without_overwriting_saved_thresholds(): void
    {
        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'logistics_tracking.stale_days',
            'config_value' => '5',
            'value_type' => 'number',
            'group' => 'logistics',
        ]);
        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'logistics_tracking.no_delivery_days',
            'config_value' => '15',
            'value_type' => 'number',
            'group' => 'logistics',
        ]);
        SystemConfig::set('logistics_tracking.stale_days', '8');
        SystemConfig::query()->where('config_key', 'logistics_tracking.no_delivery_days')->delete();
        $migration = require database_path('migrations/2026_11_04_094301_add_logistics_anomaly_thresholds_to_system_configs.php');

        $migration->up();
        $migration->down();
        $this->seed(LogisticsAnomalySettingsSeeder::class);
        $this->seed(SystemConfigSeeder::class);

        $this->assertSame('8', SystemConfig::get('logistics_tracking.stale_days'));
        $this->assertSame('15', SystemConfig::get('logistics_tracking.no_delivery_days'));
    }

    #[DataProvider('thresholdKeys')]
    public function test_super_admin_can_change_thresholds_and_cached_values_update_immediately(string $key): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $config = SystemConfig::query()->where('config_key', $key)->firstOrFail();
        $original = SystemConfig::get($key);

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->fillForm(['value_number' => '9'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertNotSame('9', $original);
        $this->assertSame('9', $config->refresh()->config_value);
        $this->assertSame('9', SystemConfig::get($key));
    }

    #[DataProvider('invalidThresholds')]
    public function test_edit_page_rejects_invalid_day_thresholds(string $key, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $config = SystemConfig::query()->where('config_key', $key)->firstOrFail();
        $original = $config->config_value;

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->fillForm(['value_number' => $value])
            ->call('save')
            ->assertHasFormErrors(['value_number']);

        $this->assertSame($original, $config->refresh()->config_value);
    }

    public function test_shared_save_validation_uses_the_actual_record_and_modal_state_path(): void
    {
        $config = SystemConfig::query()->where('config_key', 'logistics_tracking.stale_days')->firstOrFail();

        try {
            SystemConfigForm::save([
                'config_key' => 'unrelated.setting',
                'value_number' => '0',
            ], $config, 'mountedActions.0.data');
            $this->fail('Invalid day thresholds must be rejected by the shared save handler.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mountedActions.0.data.value_number', $exception->errors());
        }

        $this->assertSame('5', $config->refresh()->config_value);
    }

    public function test_non_super_admin_cannot_edit_logistics_thresholds(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false, 'merchant_id' => null, 'status' => true]));
        $config = SystemConfig::query()->where('config_key', 'logistics_tracking.stale_days')->firstOrFail();

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->assertForbidden();

        $this->assertSame('5', $config->refresh()->config_value);
    }

    public function test_configuration_help_exists_in_both_languages(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            foreach (['stale_days_helper', 'no_delivery_days_helper'] as $key) {
                $this->assertTrue(Lang::has('admin.system_config.logistics_anomaly.'.$key, $locale, false));
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function thresholdKeys(): array
    {
        return [
            'stale events' => ['logistics_tracking.stale_days'],
            'undelivered shipment' => ['logistics_tracking.no_delivery_days'],
        ];
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidThresholds(): array
    {
        $cases = [];
        foreach (['logistics_tracking.stale_days', 'logistics_tracking.no_delivery_days'] as $key) {
            foreach (['empty' => null, 'zero' => 0, 'negative' => -1, 'decimal' => '1.5', 'text' => 'invalid', 'too large' => 3651] as $name => $value) {
                $cases[$key.' '.$name] = [$key, $value];
            }
        }

        return $cases;
    }
}
