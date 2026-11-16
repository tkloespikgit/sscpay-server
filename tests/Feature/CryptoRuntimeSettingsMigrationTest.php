<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Support\CryptoRuntimeSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CryptoRuntimeSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Schema round trips must run outside transactions, including MySQL's implicit DDL commits. */
    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_fresh_install_has_disabled_flags_empty_credentials_and_all_network_settings(): void
    {
        foreach (CryptoRuntimeSettings::definitions() as $name => $definition) {
            $this->assertDatabaseHas('system_configs', ['config_key' => 'crypto.'.$name,
                'value_type' => $definition['type'], 'config_value' => $definition['default'], 'secret_value' => null]);
        }
        $this->assertFalse(CryptoRuntimeSettings::get('checkout_enabled'));
        $this->assertFalse(CryptoRuntimeSettings::get('history_enabled'));
        $this->assertNull(CryptoRuntimeSettings::get('testnet_key'));
        $this->assertSame('10', CryptoRuntimeSettings::get('timeout'));
    }

    public function test_upgrade_imports_cached_legacy_config_encrypts_keys_and_preserves_false_and_snapshots(): void
    {
        $this->removeRuntimeSettings();
        config(['services.tatum' => ['checkout_enabled' => true, 'history_enabled' => false,
            'api_key' => 'legacy-price-key', 'testnet_key' => 'legacy-test-key',
            'testnet_hmac_secret' => 'legacy-hmac-secret-123456', 'webhook_base_url' => 'https://notify.example.test',
            'utxo_min_confirmations' => ['bitcoin-testnet' => '2'],
            'genesis_hashes' => ['bitcoin-testnet' => str_repeat('a', 64)],
        ]]);
        Cache::put('system_config:crypto.checkout_enabled', 'false');
        Cache::put('system_config_secret:crypto.testnet_key', Crypt::encryptString('cached-old-key'));

        $this->migration()->up();

        $this->assertNull(Cache::get('system_config_secret:crypto.testnet_key'));
        $this->assertNull(Cache::get('system_config:crypto.checkout_enabled'));
        $this->assertTrue(CryptoRuntimeSettings::get('checkout_enabled'));
        $this->assertFalse(CryptoRuntimeSettings::get('history_enabled'));
        $this->assertSame('legacy-test-key', CryptoRuntimeSettings::get('testnet_key'));
        $this->assertSame('legacy-price-key', CryptoRuntimeSettings::get('api_key'));
        $this->assertSame('2', CryptoRuntimeSettings::get('utxo_min_confirmations.bitcoin-testnet'));
        $row = DB::table('system_configs')->where('config_key', 'crypto.testnet_key')->first();
        $this->assertNull($row->config_value);
        $this->assertStringNotContainsString('legacy-test-key', $row->secret_value);
        $this->assertSame('legacy-test-key', Crypt::decryptString($row->secret_value));
    }

    public function test_upgrade_reads_an_explicit_environment_file_without_changing_app_url(): void
    {
        $this->removeRuntimeSettings();
        $directory = sys_get_temp_dir().'/sscpay-env-'.bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory.'/.env.testing', "TATUM_TESTNET_API_KEY=local-file-fixture-key\nCRYPTO_BITCOIN_TESTNET_MIN_CONFIRMATIONS=3\nAPP_URL=https://not-the-runtime-origin.example.test\n");
        $oldPath = app()->environmentPath();
        $oldFile = app()->environmentFile();
        $url = config('app.url');
        try {
            app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env.testing');
            $this->migration()->up();
            $this->assertSame('local-file-fixture-key', CryptoRuntimeSettings::get('testnet_key'));
            $this->assertSame('3', CryptoRuntimeSettings::get('utxo_min_confirmations.bitcoin-testnet'));
            $this->assertSame($url, config('app.url'));
            $this->assertDatabaseMissing('system_configs', ['config_key' => 'app.url']);
        } finally {
            app()->useEnvironmentPath($oldPath)->loadEnvironmentFrom($oldFile);
            unlink($directory.'/.env.testing');
            rmdir($directory);
        }
    }

    public function test_repeated_upgrade_preserves_admin_values_and_encrypts_legacy_plaintext_once(): void
    {
        SystemConfig::set('crypto.checkout_enabled', false);
        SystemConfig::set('crypto.api_key', 'admin-price-key');
        DB::table('system_configs')->where('config_key', 'crypto.testnet_key')->update([
            'config_value' => 'legacy-plaintext-key', 'secret_value' => null, 'value_type' => 'string',
        ]);
        config(['services.tatum.api_key' => 'do-not-overwrite', 'services.tatum.checkout_enabled' => true]);
        $this->migration()->up();
        $before = DB::table('system_configs')->orderBy('id')->get()->toJson();
        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame($before, DB::table('system_configs')->orderBy('id')->get()->toJson());
        $this->assertFalse(CryptoRuntimeSettings::get('checkout_enabled'));
        $this->assertSame('admin-price-key', CryptoRuntimeSettings::get('api_key'));
        $this->assertSame('legacy-plaintext-key', CryptoRuntimeSettings::get('testnet_key'));
        $this->assertDatabaseHas('system_configs', ['config_key' => 'crypto.testnet_key', 'config_value' => null, 'value_type' => 'secret']);
    }

    public function test_schema_rollback_refuses_to_discard_saved_credentials(): void
    {
        SystemConfig::set('crypto.testnet_key', 'keep-this-key');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove encrypted settings');
        $this->schemaMigration()->down();
    }

    public function test_empty_secret_column_can_be_rolled_back_and_recreated(): void
    {
        $this->schemaMigration()->down();
        $this->assertFalse(Schema::hasColumn('system_configs', 'secret_value'));
        $this->schemaMigration()->up();
        $this->assertTrue(Schema::hasColumn('system_configs', 'secret_value'));
    }

    private function removeRuntimeSettings(): void
    {
        foreach (array_keys(CryptoRuntimeSettings::definitions()) as $name) {
            SystemConfig::query()->where('config_key', 'crypto.'.$name)->delete();
            SystemConfig::forget('crypto.'.$name);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_16_163325_move_crypto_runtime_settings_to_system_configs.php');
    }

    private function schemaMigration(): Migration
    {
        return require database_path('migrations/2026_11_16_163325_add_secret_value_to_system_configs_table.php');
    }
}
