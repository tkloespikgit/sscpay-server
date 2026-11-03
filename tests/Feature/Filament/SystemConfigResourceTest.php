<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SystemConfigResource\Pages\ListSystemConfigs;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\MerchantRoleProvisioningService;
use App\Support\OrderStatusColor;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SystemConfigResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_group_tabs_filter_existing_and_new_groups_without_hiding_their_configuration(): void
    {
        $exchange = $this->config('test.exchange', 'exchange', SystemConfig::TYPE_NUMBER, '2');
        $custom = $this->config('test.custom', 'custom_group', SystemConfig::TYPE_STRING, 'custom');
        $ungrouped = $this->config('test.ungrouped', null, SystemConfig::TYPE_STRING, 'ungrouped');
        $reservedGroup = $this->config('test.reserved_group', 'all', SystemConfig::TYPE_STRING, 'reserved group');
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(ListSystemConfigs::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$exchange, $custom, $ungrouped, $reservedGroup])
            ->set('activeTab', 'group:exchange')
            ->assertCanSeeTableRecords([$exchange])
            ->assertCanNotSeeTableRecords([$custom])
            ->set('activeTab', 'group:custom_group')
            ->assertCanSeeTableRecords([$custom])
            ->assertCanNotSeeTableRecords([$exchange, $ungrouped])
            ->set('activeTab', 'ungrouped')
            ->assertCanSeeTableRecords([$ungrouped])
            ->assertCanNotSeeTableRecords([$exchange, $custom])
            ->set('activeTab', 'group:all')
            ->assertCanSeeTableRecords([$reservedGroup])
            ->assertCanNotSeeTableRecords([$exchange, $custom, $ungrouped])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$exchange, $custom, $ungrouped, $reservedGroup]);

        $this->assertSame(1, (int) $component->instance()->getTabs()['group:custom_group']->getBadge());
    }

    public function test_the_list_can_search_configuration_keys_and_descriptions(): void
    {
        $keyMatch = $this->config('test.searchable_key', 'order', SystemConfig::TYPE_STRING, 'one');
        $descriptionMatch = $this->config('test.description', 'order', SystemConfig::TYPE_STRING, 'two', '订单重试策略');
        $this->actingAs($this->superAdmin());

        Livewire::test(ListSystemConfigs::class)
            ->searchTable('searchable_key')
            ->assertCanSeeTableRecords([$keyMatch])
            ->assertCanNotSeeTableRecords([$descriptionMatch])
            ->searchTable('订单重试策略')
            ->assertCanSeeTableRecords([$descriptionMatch])
            ->assertCanNotSeeTableRecords([$keyMatch]);
    }

    #[DataProvider('editableConfigurationTypes')]
    public function test_modal_edits_preserve_value_types_and_refresh_cached_values(
        string $type,
        string $originalValue,
        array $formData,
        string $savedValue,
    ): void {
        $config = $this->config('test.edit.'.$type, 'order', $type, $originalValue);
        $this->actingAs($this->superAdmin());
        $this->assertSame($originalValue, SystemConfig::get($config->config_key));

        Livewire::test(ListSystemConfigs::class)
            ->callAction(TestAction::make('edit')->table($config), $formData)
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame($savedValue, $config->refresh()->config_value);
        $this->assertSame($type, $config->value_type);
        $this->assertSame($savedValue, SystemConfig::get($config->config_key));
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function editableConfigurationTypes(): array
    {
        return [
            'string' => [SystemConfig::TYPE_STRING, 'old', ['value_string' => 'updated'], 'updated'],
            'number' => [SystemConfig::TYPE_NUMBER, '1.25', ['value_number' => '2.75'], '2.75'],
            'boolean' => [SystemConfig::TYPE_BOOLEAN, 'true', ['value_boolean' => false], 'false'],
            'numeric JSON list' => [SystemConfig::TYPE_JSON, '[30,300]', ['value_json' => [
                ['value' => '60'],
                ['value' => '600'],
            ]], '[60,600]'],
        ];
    }

    public function test_opening_the_edit_action_fills_a_modal_and_cancelling_keeps_the_saved_value(): void
    {
        $config = $this->config('test.cancel', 'order', SystemConfig::TYPE_STRING, 'original', '配置使用说明');
        $this->actingAs($this->superAdmin());
        $action = TestAction::make('edit')->table($config);

        $component = Livewire::test(ListSystemConfigs::class);
        $table = $component->instance()->getTable();
        $this->assertNull($table->getRecordUrl($config));
        $this->assertNull($table->getAction('edit')->record($config)->getUrl());

        $component
            ->mountAction($action)
            ->assertActionMounted($action)
            ->assertActionDataSet(['value_string' => 'original'])
            ->assertSee('配置使用说明')
            ->fillForm(['value_string' => 'unsaved'])
            ->unmountAction()
            ->assertActionNotMounted($action);

        $this->assertSame('original', $config->refresh()->config_value);
    }

    public function test_modal_edits_cannot_change_read_only_configuration_metadata(): void
    {
        $config = $this->config('test.metadata', 'order', SystemConfig::TYPE_STRING, 'original', 'Original description');
        $this->actingAs($this->superAdmin());

        Livewire::test(ListSystemConfigs::class)
            ->callAction(TestAction::make('edit')->table($config), [
                'config_key' => 'test.forged',
                'group' => 'security',
                'description' => 'Forged description',
                'value_string' => 'updated',
                'config_value' => 'forged',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('system_configs', [
            'id' => $config->id,
            'config_key' => 'test.metadata',
            'group' => 'order',
            'value_type' => SystemConfig::TYPE_STRING,
            'description' => 'Original description',
            'config_value' => 'updated',
        ]);
    }

    public function test_modal_rejects_a_non_positive_payment_failure_limit(): void
    {
        $config = $this->config(PaymentMethod::MAX_FAILED_TIMES_CONFIG_KEY, 'payment', SystemConfig::TYPE_NUMBER, '5');
        $this->actingAs($this->superAdmin());

        Livewire::test(ListSystemConfigs::class)
            ->callAction(TestAction::make('edit')->table($config), ['value_number' => '0'])
            ->assertHasActionErrors(['value_number' => 'min']);

        $this->assertSame('5', $config->refresh()->config_value);
    }

    public function test_modal_order_status_colors_keep_object_validation_and_can_save_an_empty_object(): void
    {
        $config = $this->config(OrderStatusColor::CONFIG_KEY, 'order', SystemConfig::TYPE_JSON, '{"paid":"success"}');
        $this->actingAs($this->superAdmin());
        $action = TestAction::make('edit')->table($config);

        $component = Livewire::test(ListSystemConfigs::class)
            ->callAction($action, ['value_json_object' => '["success"]'])
            ->assertHasActionErrors(['value_json_object']);

        $this->assertSame(
            [__('admin.system_config.order_status_colors.invalid_object')],
            $component->errors()->get('mountedActions.0.data.value_json_object'),
        );
        $this->assertSame('{"paid":"success"}', $config->refresh()->config_value);

        $component->fillForm(['value_json_object' => '{}'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame('{}', $config->refresh()->config_value);
    }

    public function test_role_templates_stay_hidden_from_the_generic_configuration_list(): void
    {
        $template = $this->config(MerchantRoleProvisioningService::TEMPLATE_CONFIG_KEY, 'security', SystemConfig::TYPE_JSON, '{}');
        $ordinary = $this->config('test.visible', 'security', SystemConfig::TYPE_STRING, 'visible');
        $this->actingAs($this->superAdmin());

        Livewire::test(ListSystemConfigs::class)
            ->assertCanSeeTableRecords([$ordinary])
            ->assertCanNotSeeTableRecords([$template]);
    }

    public function test_non_super_admins_cannot_open_the_configuration_list(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false, 'status' => true]));

        Livewire::test(ListSystemConfigs::class)->assertForbidden();
    }

    public function test_new_configuration_labels_exist_in_both_languages_without_fallback(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            foreach ([
                'list_description',
                'all_groups',
                'ungrouped',
                'not_set',
                'search_placeholder',
                'sections.details',
                'boolean_values.true',
                'boolean_values.false',
                'fields.updated_at',
                'actions.edit',
            ] as $key) {
                $this->assertTrue(Lang::has('admin.system_config.'.$key, $locale, false));
            }
        }
    }

    private function config(string $key, ?string $group, string $type, string $value, string $description = 'Configuration description'): SystemConfig
    {
        return SystemConfig::query()->updateOrCreate(['config_key' => $key], [
            'group' => $group,
            'value_type' => $type,
            'config_value' => $value,
            'description' => $description,
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
