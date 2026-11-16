<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentMethodConfigMapResource\Pages\CreatePaymentMethodConfigMap;
use App\Filament\Resources\PaymentMethodConfigMapResource\Pages\EditPaymentMethodConfigMap;
use App\Models\PaymentMethodConfigMap;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentMethodConfigMapFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_create_saves_modal_options_only_for_the_selected_row(): void
    {
        $firstField = $this->selectField('environment');
        $secondField = $this->selectField('currency', ['usd' => 'USD']);
        $page = Livewire::actingAs($this->administrator())
            ->test(CreatePaymentMethodConfigMap::class)
            ->fillForm([
                'name' => 'Example Gateway',
                'payment_config_tag' => 'example',
                'fields' => [$firstField, $secondField],
            ]);
        [$firstKey, $secondKey] = array_keys($page->get('data.fields'));
        $options = ['sandbox' => 'Sandbox', 'live' => 'Live'];

        $page->callAction($this->optionsAction($firstKey), ['options' => $options])
            ->assertHasNoActionErrors()
            ->assertSet("data.fields.{$firstKey}.options", $options)
            ->assertSet("data.fields.{$secondKey}.options", ['usd' => 'USD']);

        $this->assertDatabaseCount('payment_method_config_maps', 0);
        $page->call('create')->assertHasNoFormErrors();
        $template = PaymentMethodConfigMap::query()->sole();
        $this->assertSame([
            array_replace($firstField, ['options' => $options]),
            $secondField,
        ], $template->fields);
    }

    public function test_edit_prefills_existing_options_and_saves_only_after_the_main_form_is_saved(): void
    {
        $field = $this->selectField('environment', ['sandbox' => 'Sandbox']);
        $template = $this->template([$field]);
        $page = Livewire::actingAs($this->administrator())
            ->test(EditPaymentMethodConfigMap::class, ['record' => $template->id]);
        $itemKey = array_key_first($page->get('data.fields'));
        $action = $this->optionsAction($itemKey);
        $options = ['sandbox' => 'Testing', 'live' => 'Production'];

        $page->mountAction($action)
            ->assertActionDataSet(['options' => ['sandbox' => 'Sandbox']])
            ->setActionData(['options' => $options])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet("data.fields.{$itemKey}.options", $options);

        $this->assertSame([$field], $template->fresh()->fields);
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame([array_replace($field, ['options' => $options])], $template->fresh()->fields);
    }

    public function test_cancelling_the_modal_keeps_the_original_options(): void
    {
        $field = $this->selectField('environment', ['sandbox' => 'Sandbox']);
        $template = $this->template([$field]);
        $page = Livewire::actingAs($this->administrator())
            ->test(EditPaymentMethodConfigMap::class, ['record' => $template->id]);
        $itemKey = array_key_first($page->get('data.fields'));
        $action = $this->optionsAction($itemKey);

        $page->mountAction($action)
            ->setActionData(['options' => ['live' => 'Production']])
            ->unmountAction()
            ->assertSet("data.fields.{$itemKey}.options", ['sandbox' => 'Sandbox'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$field], $template->fresh()->fields);
    }

    public function test_empty_modal_options_are_rejected_without_replacing_the_current_options(): void
    {
        $field = $this->selectField('environment', ['sandbox' => 'Sandbox']);
        $template = $this->template([$field]);
        $page = Livewire::actingAs($this->administrator())
            ->test(EditPaymentMethodConfigMap::class, ['record' => $template->id]);
        $itemKey = array_key_first($page->get('data.fields'));

        $page->callAction($this->optionsAction($itemKey), ['options' => []])
            ->assertHasActionErrors(['options' => 'required'])
            ->assertSet("data.fields.{$itemKey}.options", ['sandbox' => 'Sandbox']);

        $this->assertSame([$field], $template->fresh()->fields);
    }

    public function test_select_fields_without_options_show_an_error_beside_the_field_type(): void
    {
        $page = Livewire::actingAs($this->administrator())
            ->test(CreatePaymentMethodConfigMap::class)
            ->fillForm([
                'name' => 'Example Gateway',
                'payment_config_tag' => 'example',
                'fields' => [$this->selectField('environment')],
            ]);
        $itemKey = array_key_first($page->get('data.fields'));

        $page->call('create')
            ->assertHasFormErrors(["fields.{$itemKey}.type"])
            ->assertSee(__('admin.payment_method_config_map.validation.options_required'));

        $this->assertDatabaseCount('payment_method_config_maps', 0);
    }

    public function test_text_fields_hide_the_options_button_and_save_without_select_options(): void
    {
        $field = $this->selectField('environment', ['sandbox' => 'Sandbox']);
        $template = $this->template([$field]);
        $page = Livewire::actingAs($this->administrator())
            ->test(EditPaymentMethodConfigMap::class, ['record' => $template->id]);
        $itemKey = array_key_first($page->get('data.fields'));
        $action = $this->optionsAction($itemKey);

        $page->assertActionVisible($action)
            ->set("data.fields.{$itemKey}.type", 'text')
            ->assertDontSee(__('admin.payment_method_config_map.actions.edit_options'))
            ->set("data.fields.{$itemKey}.type", 'select')
            ->assertActionVisible($action)
            ->mountAction($action)
            ->assertActionDataSet(['options' => ['sandbox' => 'Sandbox']])
            ->unmountAction()
            ->set("data.fields.{$itemKey}.type", 'text')
            ->call('save')
            ->assertHasNoFormErrors();

        unset($field['options']);
        $field['type'] = 'text';
        $this->assertSame([$field], $template->fresh()->fields);
    }

    private function administrator(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'status' => true]);
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function template(array $fields): PaymentMethodConfigMap
    {
        return PaymentMethodConfigMap::create([
            'name' => 'Example Gateway',
            'payment_config_tag' => 'example',
            'payment_type' => 'fiat',
            'is_active' => true,
            'fields' => $fields,
        ]);
    }

    /**
     * @param  array<string, string>  $options
     * @return array{key: string, label: string, type: string, options: array<string, string>, default: string, required: bool}
     */
    private function selectField(string $key, array $options = []): array
    {
        return [
            'key' => $key,
            'label' => ucfirst($key),
            'type' => 'select',
            'options' => $options,
            'default' => 'sandbox',
            'required' => true,
        ];
    }

    private function optionsAction(string $itemKey): TestAction
    {
        return TestAction::make('editFieldOptions')->schemaComponent("fields.{$itemKey}.type");
    }
}
