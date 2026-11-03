<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Models\Merchant;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\MerchantPaymentSettingsService;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class MerchantPaymentDefaultsAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_assignment_copies_the_latest_merchant_defaults_including_explicit_zero(): void
    {
        $merchant = $this->merchant([
            'default_fee_percent' => '2.5123', 'default_fee_fixed' => '0',
            'default_refund_fee' => '5.00', 'default_chargeback_fee' => '12.00',
            'default_reserve_percent' => '0', 'default_reserve_days' => 0,
            'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ]);
        $method = $this->systemMethod('snapshot', ['fee_percent' => 9, 'fee_fixed' => 5, 'reserve_percent' => 20, 'reserve_days' => 90]);

        $method->assignedMerchants()->attach($merchant);

        $setting = MerchantPaymentSetting::query()->sole();
        $this->assertSame($merchant->id, $setting->merchant_id);
        $this->assertSame($method->id, $setting->payment_method_id);
        $this->assertSame([
            'fee_percent' => '2.5123', 'fee_fixed' => '0.00', 'refund_fee' => '5.00',
            'chargeback_fee' => '12.00', 'reserve_percent' => '0.0000', 'reserve_days' => 0,
            'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ], $setting->only(MerchantPaymentSetting::CONFIGURATION_FIELDS));
        $this->assertSame('9.0000', $method->fresh()->fee_percent);
    }

    public function test_reverse_assignment_initializes_each_selected_payment_method(): void
    {
        $merchant = $this->merchant(['default_fee_percent' => '3.25', 'default_reserve_percent' => '10', 'default_reserve_days' => 180]);
        $first = $this->systemMethod('reverse-first');
        $second = $this->systemMethod('reverse-second');

        $merchant->assignedPaymentMethods()->sync([$first->id, $second->id]);

        $this->assertDatabaseCount('merchant_payment_settings', 2);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $first->id,
            'fee_percent' => '3.25', 'reserve_percent' => '10', 'reserve_days' => 180,
        ]);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $second->id,
            'fee_percent' => '3.25', 'reserve_percent' => '10', 'reserve_days' => 180,
        ]);
    }

    public function test_repeated_initialization_and_reassignment_preserve_independent_values(): void
    {
        $merchant = $this->merchant(['default_fee_percent' => '3', 'settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $method = $this->systemMethod('reassign');
        $method->assignedMerchants()->attach($merchant);
        $setting = app(MerchantPaymentSettingsService::class)->save($this->administrator(), $merchant, $method->id, [
            'fee_percent' => '0', 'settlement_mode' => 'immediate', 'settlement_days' => 0,
        ]);
        $merchant->update(['default_fee_percent' => '9', 'settlement_days' => 30]);

        $method->assignedMerchants()->syncWithoutDetaching([$merchant->id]);
        app(MerchantPaymentSettingsService::class)->initializeForAssignment($merchant->id, $method->id);
        $method->assignedMerchants()->detach($merchant);
        $merchant->assignedPaymentMethods()->attach($method);

        $this->assertDatabaseCount('merchant_payment_settings', 1);
        $this->assertSame('0.0000', $setting->fresh()->fee_percent);
        $this->assertSame('immediate', $setting->fresh()->settlement_mode);
        $this->assertSame(0, $setting->fresh()->settlement_days);
    }

    public function test_merchant_default_changes_only_affect_new_assignments(): void
    {
        $merchant = $this->merchant(['default_fee_percent' => '1.25', 'settlement_days' => 2]);
        $first = $this->systemMethod('old-default');
        $second = $this->systemMethod('new-default');
        $first->assignedMerchants()->attach($merchant);

        $merchant->update(['default_fee_percent' => '2.5', 'settlement_days' => 7]);
        $second->assignedMerchants()->attach($merchant);

        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $first->id,
            'fee_percent' => '1.25', 'settlement_days' => 2,
        ]);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $second->id,
            'fee_percent' => '2.5', 'settlement_days' => 7,
        ]);
    }

    public function test_shared_method_gets_each_merchants_own_defaults(): void
    {
        $first = $this->merchant(['default_fee_percent' => '1', 'settlement_mode' => 'immediate', 'settlement_days' => 0]);
        $second = $this->merchant(['default_fee_percent' => '6', 'settlement_mode' => 'shipped', 'settlement_days' => 30]);
        $method = $this->systemMethod('shared');

        $method->assignedMerchants()->sync([$first->id, $second->id]);

        $this->assertDatabaseCount('merchant_payment_settings', 2);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $first->id, 'payment_method_id' => $method->id,
            'fee_percent' => '1', 'settlement_mode' => 'immediate', 'settlement_days' => 0,
        ]);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $second->id, 'payment_method_id' => $method->id,
            'fee_percent' => '6', 'settlement_mode' => 'shipped', 'settlement_days' => 30,
        ]);
    }

    public function test_existing_assignments_without_configuration_are_initialized_from_latest_defaults_once(): void
    {
        $merchant = $this->merchant();
        $first = $this->systemMethod('legacy-first');
        $second = $this->systemMethod('legacy-second');
        $this->assignWithoutConfiguration($merchant, $first);
        $this->assignWithoutConfiguration($merchant, $second);
        $merchant->update([
            'default_fee_percent' => '14', 'default_fee_fixed' => '0.30',
            'default_reserve_percent' => '10', 'default_reserve_days' => 7,
            'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ]);

        $initialized = app(MerchantPaymentSettingsService::class)->initializeMissingForMerchant($merchant->id);

        $this->assertSame(2, $initialized);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $first->id,
            'fee_percent' => '14', 'fee_fixed' => '0.30', 'reserve_percent' => '10', 'reserve_days' => 7,
            'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ]);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $second->id,
            'fee_percent' => '14', 'fee_fixed' => '0.30', 'reserve_percent' => '10', 'reserve_days' => 7,
            'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ]);
        $this->assertSame(0, app(MerchantPaymentSettingsService::class)->initializeMissingForMerchant($merchant->id));
        $this->assertDatabaseCount('merchant_payment_settings', 2);
    }

    public function test_bulk_initialization_keeps_existing_zero_and_explicit_null_configuration(): void
    {
        $merchant = $this->merchant();
        $zeroMethod = $this->systemMethod('existing-zero');
        $nullMethod = $this->systemMethod('existing-null');
        $missingMethod = $this->systemMethod('missing-config');
        $zeroMethod->assignedMerchants()->attach($merchant);
        $nullMethod->assignedMerchants()->attach($merchant);
        $this->assignWithoutConfiguration($merchant, $missingMethod);
        $zeroSetting = MerchantPaymentSetting::query()->where('payment_method_id', $zeroMethod->id)->sole();
        $nullSetting = MerchantPaymentSetting::query()->where('payment_method_id', $nullMethod->id)->sole();
        $nullSetting->update([
            'fee_percent' => null, 'fee_fixed' => null, 'reserve_percent' => null,
            'reserve_days' => null, 'settlement_mode' => null, 'settlement_days' => null,
        ]);
        $zeroConfiguration = $zeroSetting->only(MerchantPaymentSetting::CONFIGURATION_FIELDS);
        $nullConfiguration = $nullSetting->only(MerchantPaymentSetting::CONFIGURATION_FIELDS);
        $merchant->update(['default_fee_percent' => '14', 'default_fee_fixed' => '0.30']);

        $initialized = app(MerchantPaymentSettingsService::class)->initializeMissingForMerchant($merchant->id);

        $this->assertSame(1, $initialized);
        $this->assertSame($zeroConfiguration, $zeroSetting->fresh()->only(MerchantPaymentSetting::CONFIGURATION_FIELDS));
        $this->assertSame($nullConfiguration, $nullSetting->fresh()->only(MerchantPaymentSetting::CONFIGURATION_FIELDS));
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $missingMethod->id,
            'fee_percent' => '14', 'fee_fixed' => '0.30',
        ]);
        $this->assertDatabaseCount('merchant_payment_settings', 3);
    }

    public function test_bulk_initialization_only_changes_valid_shared_assignments_for_the_selected_merchant(): void
    {
        $merchant = $this->merchant(['default_fee_percent' => '14']);
        $other = $this->merchant(['default_fee_percent' => '3']);
        $valid = $this->systemMethod('bulk-valid');
        $own = $this->systemMethod('bulk-own', ['merchant_id' => $merchant->id]);
        $foreign = $this->systemMethod('bulk-foreign', ['merchant_id' => $other->id]);
        $deleted = $this->systemMethod('bulk-deleted');
        $otherShared = $this->systemMethod('bulk-other-merchant');
        $this->systemMethod('bulk-unassigned');
        $this->assignWithoutConfiguration($merchant, $valid);
        $this->assignWithoutConfiguration($merchant, $own);
        $this->assignWithoutConfiguration($merchant, $foreign);
        $this->assignWithoutConfiguration($merchant, $deleted);
        $this->assignWithoutConfiguration($other, $otherShared);
        $deleted->delete();

        $initialized = app(MerchantPaymentSettingsService::class)->initializeMissingForMerchant($merchant->id);

        $this->assertSame(1, $initialized);
        $this->assertDatabaseCount('merchant_payment_settings', 1);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $valid->id, 'fee_percent' => '14',
        ]);
    }

    public function test_bulk_initialization_skips_a_deleted_merchant(): void
    {
        $merchant = $this->merchant();
        $method = $this->systemMethod('bulk-deleted-merchant');
        $this->assignWithoutConfiguration($merchant, $method);
        $merchant->delete();

        $initialized = app(MerchantPaymentSettingsService::class)->initializeMissingForMerchant($merchant->id);

        $this->assertSame(0, $initialized);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_unassigned_or_foreign_merchant_owned_channels_are_not_initialized(): void
    {
        $merchant = $this->merchant();
        $other = $this->merchant();
        $unassigned = $this->systemMethod('unassigned');
        $foreign = $this->systemMethod('foreign', ['merchant_id' => $other->id]);

        $foreign->assignedMerchants()->attach($merchant);
        $setting = app(MerchantPaymentSettingsService::class)->initializeForAssignment($merchant->id, $unassigned->id);

        $this->assertNull($setting);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    #[TestWith([true], 'deleted merchant')]
    #[TestWith([false], 'deleted payment method')]
    public function test_deleted_merchant_or_payment_method_assignments_are_not_initialized(bool $deleteMerchant): void
    {
        $merchant = $this->merchant();
        $method = $this->systemMethod('deleted-assignment');
        ($deleteMerchant ? $merchant : $method)->delete();

        $method->assignedMerchants()->attach($merchant);

        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_initialization_failure_rolls_back_the_assignment(): void
    {
        $merchant = $this->merchant();
        $method = $this->systemMethod('atomic');
        Event::listen('eloquent.creating: '.MerchantPaymentSetting::class, static function (): void {
            throw new RuntimeException('Cannot persist settings.');
        });

        try {
            $method->assignedMerchants()->attach($merchant);
            $this->fail('The initialization failure must abort assignment.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot persist settings.', $exception->getMessage());
        }

        $this->assertDatabaseCount('merchant_payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_configuration_save_keeps_other_merchant_settings_and_accepts_nullable_settlement(): void
    {
        $merchant = $this->merchant(['settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $other = $this->merchant(['default_fee_percent' => '6', 'settlement_days' => 30]);
        $method = $this->systemMethod('save-config');
        $method->assignedMerchants()->sync([$merchant->id, $other->id]);

        $setting = app(MerchantPaymentSettingsService::class)->save($this->administrator(), $merchant, $method->id, [
            'merchant_id' => $other->id, 'fee_percent' => '0', 'settlement_mode' => '', 'settlement_days' => null,
        ]);

        $this->assertSame($merchant->id, $setting->merchant_id);
        $this->assertSame('0.0000', $setting->fee_percent);
        $this->assertNull($setting->settlement_mode);
        $this->assertNull($setting->settlement_days);
        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $other->id, 'fee_percent' => '6', 'settlement_days' => 30]);
    }

    #[TestWith(['settlement_mode', 'unknown'])]
    #[TestWith(['settlement_days', -1])]
    #[TestWith(['settlement_days', '1.5'])]
    #[TestWith(['settlement_days', 36501])]
    public function test_invalid_settlement_configuration_is_rejected(string $field, mixed $value): void
    {
        $merchant = $this->merchant();
        $method = $this->systemMethod('invalid-settlement', ['merchant_id' => $merchant->id]);

        try {
            app(MerchantPaymentSettingsService::class)->save($this->administrator(), $merchant, $method->id, [$field => $value]);
            $this->fail('Invalid settlement configuration must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    #[TestWith([false], 'other manager')]
    #[TestWith([true], 'merchant account')]
    public function test_users_outside_the_merchants_management_scope_cannot_save_configuration(bool $merchantAccount): void
    {
        $merchant = $this->merchant();
        $method = $this->systemMethod('unauthorized', ['merchant_id' => $merchant->id]);
        $actor = User::factory()->create([
            'merchant_id' => $merchantAccount ? $merchant->id : null, 'is_super_admin' => false, 'status' => true,
        ]);

        try {
            app(MerchantPaymentSettingsService::class)->save($actor, $merchant, $method->id, ['settlement_mode' => 'shipped', 'settlement_days' => 7]);
            $this->fail('An actor outside the merchant management scope must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('merchant_payment_settings', 0);
        }
    }

    public function test_create_payment_method_page_initializes_assigned_merchants(): void
    {
        $merchant = $this->merchant(['default_fee_percent' => '2.5', 'settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $this->actingAs($this->administrator());
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm([
            'merchant_id' => null, 'assignedMerchants' => [$merchant->id],
            'method_code' => 'filament-create', 'method_name' => 'Filament Create',
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test',
            'product_match_mode' => 'MATCH', 'fee_percent' => '9',
        ])->call('create')->assertHasNoFormErrors()->assertRedirect();

        $method = PaymentMethod::query()->sole();
        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $method->id]);
        $this->assertDatabaseHas('merchant_payment_settings', [
            'merchant_id' => $merchant->id, 'payment_method_id' => $method->id,
            'fee_percent' => '2.5', 'settlement_mode' => 'shipped', 'settlement_days' => 7,
        ]);
        Http::assertNothingSent();
    }

    public function test_edit_payment_method_page_initializes_only_new_assignments_and_preserves_existing_configuration(): void
    {
        $first = $this->merchant(['default_fee_percent' => '1']);
        $second = $this->merchant(['default_fee_percent' => '2', 'settlement_days' => 14]);
        $method = $this->systemMethod('filament-edit');
        $method->assignedMerchants()->attach($first);
        $actor = $this->administrator();
        app(MerchantPaymentSettingsService::class)->save($actor, $first, $method->id, ['fee_percent' => '5']);
        $first->update(['default_fee_percent' => '9']);
        $this->actingAs($actor);
        Http::preventStrayRequests();

        $page = Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['assignedMerchants' => [$first->id, $second->id]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $first->id, 'fee_percent' => '5']);
        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $second->id, 'fee_percent' => '2', 'settlement_days' => 14]);
        $group = PaymentGroup::create(['merchant_id' => $first->id, 'group_key' => 'detach', 'group_name' => 'Detach']);
        $group->paymentMethods()->attach($method);

        $page->fillForm(['assignedMerchants' => [$second->id]])->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseMissing('merchant_payment_methods', ['merchant_id' => $first->id, 'payment_method_id' => $method->id]);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $first->id, 'fee_percent' => '5']);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $attributes */
    private function merchant(array $attributes = []): Merchant
    {
        return Merchant::create(['name' => 'Test Merchant', 'status' => true, ...$attributes]);
    }

    private function assignWithoutConfiguration(Merchant $merchant, PaymentMethod $method): void
    {
        MerchantPaymentMethod::withoutEvents(fn () => $method->assignedMerchants()->attach($merchant));
    }

    /** @param array<string, mixed> $attributes */
    private function systemMethod(string $code, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create([
            'merchant_id' => null, 'method_code' => $code, 'method_name' => $code,
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test',
            'product_match_mode' => 'MATCH', ...$attributes,
        ]);
    }

    private function administrator(): User
    {
        return User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
    }
}
