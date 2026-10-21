<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SystemConfigResource\Pages\EditSystemConfig;
use App\Models\Merchant;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\OrderStatusColor;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class OrderStatusColorConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_a_super_admin_can_edit_formatted_json_and_the_change_takes_effect_immediately(): void
    {
        $config = $this->statusColorConfig();
        $this->actingAs($this->superAdmin());
        $this->assertSame('success', OrderStatusColor::get('paid'));

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->assertOk()
            ->assertFormFieldVisible('value_json_object')
            ->assertFormFieldHidden('value_json')
            ->assertFormSet([
                'value_json_object' => "{\n    \"paid\": \"success\",\n    \"pending\": \"warning\"\n}",
            ])
            ->fillForm(['value_json_object' => '{"paid":"info","refunded":"danger"}'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $saved = $config->refresh()->config_value;
        $this->assertInstanceOf(stdClass::class, json_decode($saved));
        $this->assertSame(['paid' => 'info', 'refunded' => 'danger'], json_decode($saved, true));
        $this->assertSame($saved, SystemConfig::get(OrderStatusColor::CONFIG_KEY));
        $this->assertSame('info', OrderStatusColor::get('paid'));
    }

    public function test_an_empty_json_object_is_saved_as_an_object_and_uses_default_colors(): void
    {
        $config = $this->statusColorConfig();
        $this->actingAs($this->superAdmin());

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->fillForm(['value_json_object' => '{}'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('{}', $config->refresh()->config_value);
        $this->assertSame('info', OrderStatusColor::get('paid'));
    }

    #[DataProvider('invalidStatusColorConfigurations')]
    public function test_invalid_status_color_configuration_is_rejected_without_changing_the_saved_value(
        string $value,
        string $messageKey,
        array $messageParameters = [],
    ): void {
        $config = $this->statusColorConfig();
        $originalValue = $config->config_value;
        $this->actingAs($this->superAdmin());

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->fillForm(['value_json_object' => $value])
            ->call('save')
            ->assertHasFormErrors(['value_json_object'])
            ->assertSee(__('admin.system_config.order_status_colors.'.$messageKey, $messageParameters));

        $this->assertSame($originalValue, $config->refresh()->config_value);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2?: array<string, string>}>
     */
    public static function invalidStatusColorConfigurations(): array
    {
        $invalidColorMessage = [
            'status' => 'paid',
            'colors' => implode(', ', OrderStatusColor::ALLOWED_COLORS),
        ];

        return [
            'malformed JSON' => ['{"paid":', 'invalid_object'],
            'JSON list' => ['["success"]', 'invalid_object'],
            'empty JSON list' => ['[]', 'invalid_object'],
            'JSON string' => ['"success"', 'invalid_object'],
            'JSON number' => ['42', 'invalid_object'],
            'JSON null' => ['null', 'invalid_object'],
            'unknown status' => ['{"unknown":"success"}', 'unknown_status', ['status' => 'unknown']],
            'unsupported color' => ['{"paid":"purple"}', 'invalid_color', $invalidColorMessage],
            'color array' => ['{"paid":["success"]}', 'invalid_color', $invalidColorMessage],
            'color object' => ['{"paid":{"color":"success"}}', 'invalid_color', $invalidColorMessage],
        ];
    }

    public function test_a_merchant_cannot_open_the_order_status_color_editor(): void
    {
        $config = $this->statusColorConfig();
        $originalValue = $config->config_value;
        $this->seed(PermissionSeeder::class);
        $merchant = Merchant::create([
            'name' => 'Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);
        $user = User::factory()->create([
            'merchant_id' => $merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        Filament::setCurrentPanel('merchant');

        Livewire::actingAs($user)
            ->test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->assertForbidden();

        $this->assertSame($originalValue, $config->refresh()->config_value);
    }

    public function test_existing_json_lists_keep_the_repeater_and_numeric_value_conversion(): void
    {
        $config = SystemConfig::query()->updateOrCreate([
            'config_key' => 'notify.retry_intervals_seconds',
        ], [
            'config_value' => '[30,300,1800,3600]',
            'value_type' => SystemConfig::TYPE_JSON,
            'group' => 'notify',
            'description' => '失败通知的重试间隔',
        ]);
        $this->actingAs($this->superAdmin());

        Livewire::test(EditSystemConfig::class, ['record' => $config->getRouteKey()])
            ->assertFormFieldVisible('value_json')
            ->assertFormFieldHidden('value_json_object')
            ->fillForm(['value_json' => [
                ['value' => '60'],
                ['value' => '600'],
                ['value' => '3600'],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([60, 600, 3600], json_decode($config->refresh()->config_value, true));
    }

    public function test_seeding_adds_the_missing_configuration_without_overwriting_saved_colors(): void
    {
        SystemConfig::query()->where('config_key', OrderStatusColor::CONFIG_KEY)->delete();

        $this->seed(SystemConfigSeeder::class);

        $config = SystemConfig::query()->where('config_key', OrderStatusColor::CONFIG_KEY)->firstOrFail();
        $this->assertSame(SystemConfig::TYPE_JSON, $config->value_type);
        $this->assertSame('order', $config->group);
        $this->assertInstanceOf(stdClass::class, json_decode($config->config_value));
        $this->assertCount(12, json_decode($config->config_value, true));

        $config->update(['config_value' => '{"paid":"primary"}']);

        $this->seed(SystemConfigSeeder::class);

        $this->assertSame('{"paid":"primary"}', $config->refresh()->config_value);
    }

    private function statusColorConfig(): SystemConfig
    {
        return SystemConfig::query()->updateOrCreate([
            'config_key' => OrderStatusColor::CONFIG_KEY,
        ], [
            'config_value' => '{"paid":"success","pending":"warning"}',
            'value_type' => SystemConfig::TYPE_JSON,
            'group' => 'order',
            'description' => '订单状态颜色',
        ]);
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
