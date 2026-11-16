<?php

namespace Tests\Feature;

use App\Filament\Resources\SystemConfigResource\Pages\EditSystemConfig;
use App\Filament\Resources\SystemConfigResource\Pages\ListSystemConfigs;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\CryptoRuntimeSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CryptoSecretSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** These cache tests require committed writes; rebuild the isolated test schema between tests. */
    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_secret_storage_cache_and_serialization_never_contain_plaintext_and_updates_invalidate_cache(): void
    {
        $bindings = [];
        DB::listen(function ($query) use (&$bindings): void {
            $bindings = [...$bindings, ...$query->bindings];
        });
        SystemConfig::set('crypto.testnet_key', 'sensitive-test-key-first');
        $record = SystemConfig::where('config_key', 'crypto.testnet_key')->sole();
        $this->assertNull($record->config_value);
        $this->assertStringNotContainsString('sensitive-test-key-first', json_encode($bindings));
        $this->assertStringNotContainsString('sensitive-test-key-first', $record->toJson());
        $this->assertArrayNotHasKey('secret_value', $record->toArray());
        $this->assertNull(SystemConfig::get('crypto.testnet_key'));
        $this->assertSame('sensitive-test-key-first', CryptoRuntimeSettings::get('testnet_key'));
        $cached = Cache::get('system_config_secret:crypto.testnet_key');
        $this->assertIsString($cached);
        $this->assertNotSame('sensitive-test-key-first', $cached);
        $this->assertSame('sensitive-test-key-first', Crypt::decryptString($cached));
        SystemConfig::set('crypto.testnet_key', 'sensitive-test-key-second');
        $this->assertNull(Cache::get('system_config_secret:crypto.testnet_key'));
        $this->assertSame('sensitive-test-key-second', CryptoRuntimeSettings::get('testnet_key'));
        SystemConfig::set('crypto.testnet_key', null);
        $this->assertNull(CryptoRuntimeSettings::get('testnet_key'));
    }

    public function test_secret_edit_modal_is_blank_preserves_existing_value_and_saves_encrypted_replacement(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => true]));
        Filament::setCurrentPanel('admin');
        SystemConfig::set('crypto.testnet_key', 'original-private-setting');
        $record = SystemConfig::where('config_key', 'crypto.testnet_key')->sole();
        $ciphertext = $record->getRawOriginal('secret_value');
        $component = Livewire::test(ListSystemConfigs::class)->assertDontSee('original-private-setting')->assertDontSee($ciphertext)
            ->mountAction(TestAction::make('edit')->table($record));
        $state = json_encode($component->get('mountedActions'));
        $this->assertStringNotContainsString('original-private-setting', $state);
        $this->assertStringNotContainsString($ciphertext, $state);
        $component->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($ciphertext, $record->fresh()->getRawOriginal('secret_value'));
        Livewire::test(ListSystemConfigs::class)->callAction(TestAction::make('edit')->table($record), [
            'value_secret' => 'replacement-private-setting',
        ])->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('replacement-private-setting', CryptoRuntimeSettings::get('testnet_key'));
        $this->assertNull($record->fresh()->config_value);
    }

    public function test_edit_page_does_not_prefill_secret_and_clears_input_after_save_and_allows_explicit_removal(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => true]));
        Filament::setCurrentPanel('admin');
        SystemConfig::set('crypto.mainnet_hmac_secret', 'original-mainnet-hmac-secret');
        $record = SystemConfig::where('config_key', 'crypto.mainnet_hmac_secret')->sole();
        $page = Livewire::test(EditSystemConfig::class, ['record' => $record->id])->assertDontSee('original-mainnet-hmac-secret')
            ->assertSet('data.value_secret', null);
        $page->fillForm(['value_secret' => 'new-mainnet-hmac-secret-value'])->call('save')->assertHasNoFormErrors()
            ->assertSet('data.value_secret', null);
        $this->assertSame('new-mainnet-hmac-secret-value', CryptoRuntimeSettings::get('mainnet_hmac_secret'));
        $page->fillForm(['value_secret' => null, 'clear_secret' => true])->call('save')->assertHasNoFormErrors();
        $this->assertNull(CryptoRuntimeSettings::get('mainnet_hmac_secret'));
    }

    public function test_non_super_admin_cannot_read_or_edit_system_secrets(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false, 'status' => true]));
        Filament::setCurrentPanel('admin');
        $record = SystemConfig::where('config_key', 'crypto.testnet_key')->sole();
        Livewire::test(ListSystemConfigs::class)->assertForbidden();
        Livewire::test(EditSystemConfig::class, ['record' => $record->id])->assertForbidden();
    }

    public function test_rolled_back_secret_update_does_not_leave_uncommitted_plaintext_or_ciphertext_in_cache(): void
    {
        SystemConfig::set('crypto.testnet_key', 'committed-key');
        $this->assertSame('committed-key', CryptoRuntimeSettings::get('testnet_key'));
        DB::beginTransaction();
        SystemConfig::set('crypto.testnet_key', 'uncommitted-key');
        $this->assertSame('uncommitted-key', CryptoRuntimeSettings::get('testnet_key'));
        $this->assertNull(Cache::get('system_config_secret:crypto.testnet_key'));
        DB::rollBack();
        $this->assertSame('committed-key', CryptoRuntimeSettings::get('testnet_key'));
    }
}
