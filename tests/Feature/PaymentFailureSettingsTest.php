<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Filament\Resources\SystemConfigResource\Pages\EditSystemConfig;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Models\User;
use Database\Seeders\PaymentFailureSettingsSeeder;
use Database\Seeders\SystemConfigSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentFailureSettingsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_new_methods_inherit_the_system_default_and_start_with_no_failures(): void
    {
        $this->createMerchantAndApplication();

        $method = $this->makePaymentMethod('failure-default')->refresh();

        $this->assertNull($method->max_failed_times);
        $this->assertSame(0, $method->consecutive_failed_times);
        $this->assertSame(5, $method->maxFailedTimes());
        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'payment.max_failed_times',
            'config_value' => '5',
            'value_type' => 'number',
            'group' => 'payment',
        ]);
    }

    public function test_an_unset_override_follows_system_changes_while_an_override_stays_fixed(): void
    {
        $this->createMerchantAndApplication();
        $inherited = $this->makePaymentMethod('failure-inherited');
        $overridden = $this->makePaymentMethod('failure-overridden');
        $overridden->update(['max_failed_times' => 3]);
        $this->assertSame(5, $inherited->maxFailedTimes());

        SystemConfig::set('payment.max_failed_times', '8');

        $this->assertSame(8, $inherited->maxFailedTimes());
        $this->assertSame(3, $overridden->maxFailedTimes());

        $overridden->update(['max_failed_times' => null]);

        $this->assertSame(8, $overridden->maxFailedTimes());
    }

    public function test_upgrade_preserves_existing_configuration_and_existing_methods_inherit_it(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Schema upgrade isolation is verified with SQLite.');
        }

        $this->createMerchantAndApplication();
        $migration = require database_path('migrations/2026_11_03_223806_add_failure_limits_to_payment_methods_table.php');
        $migration->down();
        $method = $this->makePaymentMethod('failure-existing');
        SystemConfig::set('payment.max_failed_times', '11');

        $migration->up();

        $this->assertNull($method->refresh()->max_failed_times);
        $this->assertSame(0, $method->consecutive_failed_times);
        $this->assertSame(11, $method->maxFailedTimes());
        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'payment.max_failed_times',
            'config_value' => '11',
        ]);
    }

    #[DataProvider('invalidThresholds')]
    public function test_invalid_legacy_system_values_fall_back_to_five(mixed $value): void
    {
        SystemConfig::set('payment.max_failed_times', $value);

        $this->assertSame(5, (new PaymentMethod)->maxFailedTimes());
    }

    public function test_seeders_insert_missing_configuration_without_overwriting_existing_settings(): void
    {
        SystemConfig::query()->where('config_key', 'payment.max_failed_times')->delete();

        $this->seed(PaymentFailureSettingsSeeder::class);

        $this->assertSame(5, PaymentMethod::defaultMaxFailedTimes());

        SystemConfig::set('payment.max_failed_times', '11', 'payment', 'Custom description');

        $this->seed(SystemConfigSeeder::class);
        $this->seed(PaymentFailureSettingsSeeder::class);

        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'payment.max_failed_times',
            'config_value' => '11',
            'description' => 'Custom description',
        ]);
        $this->assertSame(11, PaymentMethod::defaultMaxFailedTimes());
    }

    public function test_a_super_admin_can_change_the_system_default_and_inherited_methods_update_immediately(): void
    {
        $config = SystemConfig::query()->where('config_key', 'payment.max_failed_times')->firstOrFail();
        $this->actingAs($this->superAdmin());
        $inherited = new PaymentMethod;
        $this->assertSame(5, $inherited->maxFailedTimes());

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->fillForm(['value_number' => '7'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame('7', $config->refresh()->config_value);
        $this->assertSame(7, $inherited->maxFailedTimes());
    }

    #[DataProvider('invalidThresholds')]
    public function test_system_configuration_rejects_non_positive_or_non_integer_thresholds(mixed $value): void
    {
        $config = SystemConfig::query()->where('config_key', 'payment.max_failed_times')->firstOrFail();
        $this->actingAs($this->superAdmin());

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->fillForm(['value_number' => $value])
            ->call('save')
            ->assertHasFormErrors(['value_number']);

        $this->assertSame('5', $config->refresh()->config_value);
    }

    public function test_payment_method_override_can_be_set_and_cleared_to_inherit(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('failure-edit');
        $this->actingAs($this->superAdmin());
        SystemConfig::set('payment.max_failed_times', '9');

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->fillForm(['max_failed_times' => '2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $method->refresh()->maxFailedTimes());

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->fillForm(['max_failed_times' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($method->refresh()->max_failed_times);
        $this->assertSame(9, $method->maxFailedTimes());
    }

    public function test_an_admin_reenabling_a_payment_method_resets_its_failure_count(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('failure-reenable');
        $method->forceFill(['is_active' => false, 'consecutive_failed_times' => 5])->saveQuietly();
        $this->actingAs($this->superAdmin());

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($method->refresh()->is_active);
        $this->assertSame(0, $method->consecutive_failed_times);
    }

    #[DataProvider('editsWithoutReenabling')]
    public function test_edits_that_do_not_reenable_a_payment_method_preserve_its_failure_count(bool $wasActive, ?bool $submittedActive): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('failure-preserve');
        $method->forceFill(['is_active' => $wasActive, 'consecutive_failed_times' => 5])->saveQuietly();
        $this->actingAs($this->superAdmin());
        $data = ['method_name' => 'Updated failure method'];
        if ($submittedActive !== null) {
            $data['is_active'] = $submittedActive;
        }

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->fillForm($data)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated failure method', $method->refresh()->method_name);
        $this->assertSame($submittedActive ?? $wasActive, $method->is_active);
        $this->assertSame(5, $method->consecutive_failed_times);
    }

    /** @return array<string, array{bool, ?bool}> */
    public static function editsWithoutReenabling(): array
    {
        return [
            'ordinary edit' => [true, null],
            'remains enabled' => [true, true],
            'remains disabled' => [false, false],
            'manually disabled' => [true, false],
        ];
    }

    public function test_saving_an_old_edit_page_does_not_reenable_a_channel_disabled_since_the_page_was_opened(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('failure-stale-edit');
        $this->actingAs($this->superAdmin());
        $page = Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()]);
        PaymentMethod::query()->whereKey($method->id)->update(['is_active' => false, 'consecutive_failed_times' => 5]);

        $page->fillForm(['method_name' => 'Updated after automatic disable'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated after automatic disable', $method->refresh()->method_name);
        $this->assertFalse($method->is_active);
        $this->assertSame(5, $method->consecutive_failed_times);

        $page->assertSet('data.is_active', false)
            ->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($method->refresh()->is_active);
        $this->assertSame(0, $method->consecutive_failed_times);
    }

    public function test_a_second_old_edit_page_cannot_clear_new_failures_after_another_admin_has_reenabled_the_channel(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('failure-double-edit');
        $method->forceFill(['is_active' => false, 'consecutive_failed_times' => 5])->saveQuietly();
        $this->actingAs($this->superAdmin());
        $firstPage = Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()]);
        $secondPage = Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()]);

        $firstPage->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($method->refresh()->is_active);
        $this->assertSame(0, $method->consecutive_failed_times);
        PaymentMethod::query()->whereKey($method->id)->update(['consecutive_failed_times' => 1]);

        $secondPage->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($method->refresh()->is_active);
        $this->assertSame(1, $method->consecutive_failed_times);
    }

    #[DataProvider('invalidThresholds')]
    public function test_payment_method_override_rejects_invalid_values(mixed $value): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('failure-invalid');
        $this->actingAs($this->superAdmin());

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->fillForm(['max_failed_times' => $value])
            ->call('save')
            ->assertHasFormErrors(['max_failed_times']);

        $this->assertNull($method->refresh()->max_failed_times);
    }

    public function test_configuration_and_notification_translations_exist_in_both_languages(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            foreach ([
                'admin.payment_method.fields.max_failed_times',
                'admin.payment_method.fields.consecutive_failed_times',
                'admin.payment_method.help.max_failed_times',
                'admin.payment_method.help.max_failed_times_inherited',
                'admin.system_config.max_failed_times.helper',
                'admin.telegram_notification.payment_method_creation_failed_disabled',
            ] as $key) {
                $this->assertTrue(Lang::has($key, $locale, false), "Missing {$locale} translation: {$key}");
            }
        }
    }

    public function test_copying_a_payment_method_resets_its_failure_count(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('failure-copy');
        $method->update(['max_failed_times' => 8]);
        $method->forceFill(['consecutive_failed_times' => 4])->save();
        $this->actingAs($this->superAdmin());

        Livewire::test(ListPaymentMethods::class)
            ->callAction(TestAction::make('duplicate')->table($method))
            ->assertNotified();

        $copy = PaymentMethod::query()->where('method_code', 'failure-copy_copy')->firstOrFail();
        $this->assertSame(0, $copy->consecutive_failed_times);
        $this->assertSame(8, $copy->max_failed_times);
        $this->assertFalse($copy->is_active);
        $this->assertSame(4, $method->refresh()->consecutive_failed_times);
    }

    public static function invalidThresholds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'decimal' => ['1.5'],
            'text' => ['invalid'],
            'overflow' => ['4294967296'],
        ];
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'is_super_admin' => true,
            'merchant_id' => null,
            'status' => true,
        ]);
    }
}
