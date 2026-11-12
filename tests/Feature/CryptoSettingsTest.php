<?php

namespace Tests\Feature;

use App\Filament\Resources\SystemConfigResource\Pages\EditSystemConfig;
use App\Filament\Resources\SystemConfigResource\Pages\ListSystemConfigs;
use App\Filament\Support\SystemConfigForm;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\CryptoSettings;
use Database\Seeders\CryptoSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CryptoSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_supplies_missing_defaults_and_preserves_existing_values(): void
    {
        SystemConfig::query()->where('config_key', 'like', 'crypto.%')->delete();
        SystemConfig::set('crypto.subscription_ttl_seconds', '1800');
        $this->seed(CryptoSettingsSeeder::class);
        $this->seed(CryptoSettingsSeeder::class);

        $this->assertSame('1800', SystemConfig::get('crypto.subscription_ttl_seconds'));
        $this->assertSame('0', SystemConfig::get('crypto.quote_markup_percent'));
        $this->assertSame('3600', SystemConfig::get('crypto.quote_ttl_seconds'));
        $this->assertSame('300', SystemConfig::get('crypto.max_market_price_age_seconds'));
        $this->assertSame('300', SystemConfig::get('crypto.history_scan_interval_seconds'));
        $this->assertSame('3', SystemConfig::get('crypto.subscription_reopen_max_renewals'));
        $this->assertSame('{}', SystemConfig::get(CryptoSettings::SUBSCRIPTION_OVERRIDES));
        $this->assertSame(7, SystemConfig::query()->where('config_key', 'like', 'crypto.%')->count());
        $this->assertNull(SystemConfig::query()->where('config_key', 'crypto.subscription_ttl_seconds')->sole()->group);
    }

    public function test_admin_can_save_an_override_object_without_converting_it_to_a_list(): void
    {
        $this->seed(CryptoSettingsSeeder::class);
        $record = SystemConfig::query()->where('config_key', CryptoSettings::SUBSCRIPTION_OVERRIDES)->sole();
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => true]));
        Filament::setCurrentPanel('admin');
        $this->assertSame('{}', SystemConfig::get(CryptoSettings::SUBSCRIPTION_OVERRIDES));

        Livewire::test(ListSystemConfigs::class)->callAction(TestAction::make('edit')->table($record), [
            'value_json_object' => '{"ethereum-sepolia:ETH":900}',
        ])->assertHasNoActionErrors()->assertNotified();

        $this->assertSame(['ethereum-sepolia:ETH' => 900], json_decode($record->refresh()->config_value, true));
        $this->assertSame(['ethereum-sepolia:ETH' => 900], SystemConfig::getArray(CryptoSettings::SUBSCRIPTION_OVERRIDES));
    }

    public function test_admin_can_set_quote_markup_with_exact_decimal_text(): void
    {
        $this->seed(CryptoSettingsSeeder::class);
        $record = SystemConfig::query()->where('config_key', 'crypto.quote_markup_percent')->sole();
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => true]));
        Filament::setCurrentPanel('admin');

        Livewire::test(EditSystemConfig::class, ['record' => $record->id])->fillForm(['value_number' => '2.0000'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('2.0000', $record->refresh()->config_value);
    }

    #[DataProvider('invalidObjects')]
    public function test_override_validator_rejects_lists_unknown_assets_nonpositive_and_fractional_seconds(string $input): void
    {
        $this->expectException(ValidationException::class);
        CryptoSettings::validatedObject($input, 'data');
    }

    public static function invalidObjects(): array
    {
        return [
            'list' => ['[]'], 'unknown asset' => ['{"ethereum-sepolia:FAKE":60}'],
            'zero' => ['{"ethereum-sepolia:ETH":0}'], 'fraction' => ['{"ethereum-sepolia:ETH":1.5}'],
            'negative' => ['{"ethereum-sepolia:ETH":-1}'], 'large' => ['{"ethereum-sepolia:ETH":10000000}'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_number_validation_is_enforced_in_the_shared_save_handler(string $key, string $value): void
    {
        $record = new SystemConfig(['config_key' => $key, 'value_type' => SystemConfig::TYPE_NUMBER]);
        $this->expectException(ValidationException::class);

        SystemConfigForm::save(['value_number' => $value], $record);
    }

    public static function invalidNumbers(): array
    {
        return [
            'zero TTL' => ['crypto.subscription_ttl_seconds', '0'],
            'fractional TTL' => ['crypto.subscription_ttl_seconds', '1.5'],
            'negative renewals' => ['crypto.subscription_reopen_max_renewals', '-1'],
            'markup over 100' => ['crypto.quote_markup_percent', '100.0001'],
            'markup exponent' => ['crypto.quote_markup_percent', '1e1'],
            'too many markup decimals' => ['crypto.quote_markup_percent', '2.00001'],
        ];
    }
}
