<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use Database\Seeders\CryptoSettingsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CryptoSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_create_typed_crypto_settings_without_running_seeders(): void
    {
        foreach ($this->defaults() as $key => $value) {
            $this->assertDatabaseHas('system_configs', [
                'config_key' => $key,
                'config_value' => $value,
                'value_type' => $key === 'crypto.subscription_ttl_overrides' ? 'json' : 'number',
                'group' => 'crypto',
            ]);
        }
    }

    public function test_migration_recreates_missing_settings_and_invalidates_cached_fallbacks(): void
    {
        SystemConfig::query()->where('config_key', 'like', 'crypto.%')->delete();
        foreach (array_keys($this->defaults()) as $key) {
            $this->assertSame('missing', SystemConfig::get($key, 'missing'));
        }

        $this->migration()->up();

        foreach ($this->defaults() as $key => $value) {
            $this->assertSame($value, SystemConfig::get($key));
        }
    }

    #[DataProvider('emptyValues')]
    public function test_migration_fills_legacy_empty_settings_and_invalidates_cached_values(?string $value): void
    {
        DB::table('system_configs')->where('config_key', 'like', 'crypto.%')->update(['config_value' => $value]);
        foreach (array_keys($this->defaults()) as $key) {
            Cache::put('system_config:'.$key, $value ?? 'missing', 3600);
        }

        $this->migration()->up();

        foreach ($this->defaults() as $key => $expected) {
            $this->assertSame($expected, SystemConfig::get($key));
        }
    }

    /** @return array<string, array{null|string}> */
    public static function emptyValues(): array
    {
        return ['empty string' => [''], 'null' => [null]];
    }

    public function test_repeated_migration_and_seeding_preserve_saved_values_zero_and_metadata(): void
    {
        foreach ([
            'crypto.quote_markup_percent' => '2.1250',
            'crypto.quote_ttl_seconds' => '1200',
            'crypto.max_market_price_age_seconds' => '90',
            'crypto.subscription_ttl_seconds' => '4800',
            'crypto.subscription_ttl_overrides' => '{"ethereum-sepolia:ETH":1200}',
            'crypto.subscription_reopen_max_renewals' => '0',
            'crypto.history_scan_interval_seconds' => '600',
        ] as $key => $value) {
            SystemConfig::set($key, $value, 'custom', '管理员已设置');
        }
        $before = SystemConfig::query()->orderBy('id')->get()->toArray();

        $this->migration()->up();
        $this->migration()->up();
        $this->seed(CryptoSettingsSeeder::class);

        $this->assertSame($before, SystemConfig::query()->orderBy('id')->get()->toArray());
    }

    public function test_rollback_preserves_settings_including_values_edited_after_deployment(): void
    {
        SystemConfig::set('crypto.quote_markup_percent', '4.5000');
        $before = SystemConfig::query()->orderBy('id')->get()->toArray();

        $this->migration()->down();

        $this->assertSame($before, SystemConfig::query()->orderBy('id')->get()->toArray());
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        return [
            'crypto.quote_markup_percent' => '0',
            'crypto.quote_ttl_seconds' => '3600',
            'crypto.max_market_price_age_seconds' => '300',
            'crypto.subscription_ttl_seconds' => '7200',
            'crypto.subscription_ttl_overrides' => '{}',
            'crypto.subscription_reopen_max_renewals' => '3',
            'crypto.history_scan_interval_seconds' => '300',
        ];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_12_163459_add_crypto_defaults_to_system_configs.php');
    }
}
