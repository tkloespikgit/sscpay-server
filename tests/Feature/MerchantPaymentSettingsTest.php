<?php

namespace Tests\Feature;

use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Filament\Resources\MerchantResource\RelationManagers\PaymentSettingsRelationManager;
use App\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\MerchantPaymentSettingsService;
use App\Services\PaymentService;
use App\Support\Permissions;
use App\Support\SignatureCanonicalizer;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class MerchantPaymentSettingsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_overrides_inherit_each_null_field_and_preserve_explicit_zero(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('inherit');
        $method->update(['merchant_id' => null, 'fee_percent' => '3.5000', 'fee_fixed' => '0.30', 'refund_fee' => '5.00', 'chargeback_fee' => '20.00', 'reserve_percent' => '10.0000', 'reserve_days' => 90]);
        $other = $this->otherMerchant();
        $method->assignedMerchants()->sync([$this->merchant->id, $other->id]);
        $actor = $this->administrator();

        app(MerchantPaymentSettingsService::class)->save($actor, $this->merchant, $method->id, [
            'fee_percent' => '2.0000', 'fee_fixed' => '0', 'refund_fee' => null,
            'chargeback_fee' => '12.00', 'reserve_percent' => '0', 'reserve_days' => null,
            'merchant_id' => $other->id,
        ]);

        $this->assertSame([
            'fee_percent' => '2.0000', 'fee_fixed' => '0.00', 'refund_fee' => '5.00',
            'chargeback_fee' => '12.00', 'reserve_percent' => '0.0000', 'reserve_days' => 90,
        ], $method->financialSettingsForMerchant($this->merchant->id));
        $this->assertSame('3.5000', $method->financialSettingsForMerchant($other->id)['fee_percent']);
        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $this->merchant->id, 'payment_method_id' => $method->id]);
        $this->assertDatabaseMissing('merchant_payment_settings', ['merchant_id' => $other->id]);
    }

    public function test_unassigned_system_method_cannot_be_configured(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('unassigned');
        $method->update(['merchant_id' => null]);

        $this->expectException(ValidationException::class);

        try {
            app(MerchantPaymentSettingsService::class)->save($this->administrator(), $this->merchant, $method->id, ['fee_percent' => 1]);
        } finally {
            $this->assertDatabaseCount('merchant_payment_settings', 0);
        }
    }

    public function test_dirty_assignment_does_not_authorize_another_merchants_own_method(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('foreign');
        $method->update(['merchant_id' => $this->otherMerchant()->id]);
        $method->assignedMerchants()->attach($this->merchant->id);
        $this->assertFalse(PaymentMethod::query()->forMerchant($this->merchant->id)->whereKey($method->id)->exists());

        $this->expectException(ValidationException::class);

        try {
            app(MerchantPaymentSettingsService::class)->save($this->administrator(), $this->merchant, $method->id, ['fee_percent' => 1]);
        } finally {
            $this->assertDatabaseCount('merchant_payment_settings', 0);
        }
    }

    public function test_manager_can_configure_only_owned_merchants(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('owned');
        $manager = $this->administrator(false);
        $this->merchant->update(['owner_id' => $manager->id]);
        app(MerchantPaymentSettingsService::class)->save($manager, $this->merchant, $method->id, ['fee_percent' => 2]);
        $other = $this->otherMerchant();

        $this->expectException(AuthorizationException::class);

        try {
            app(MerchantPaymentSettingsService::class)->save($manager, $other, $method->id, ['fee_percent' => 9]);
        } finally {
            $this->assertDatabaseCount('merchant_payment_settings', 1);
            $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $this->merchant->id, 'fee_percent' => 2]);
        }
    }

    public function test_merchant_cannot_change_its_own_financial_overrides(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('self');
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);

        $this->expectException(AuthorizationException::class);

        try {
            app(MerchantPaymentSettingsService::class)->save($user, $this->merchant, $method->id, ['fee_percent' => 0]);
        } finally {
            $this->assertDatabaseCount('merchant_payment_settings', 0);
        }
    }

    public function test_invalid_financial_values_are_rejected_before_writing(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('validation');

        try {
            app(MerchantPaymentSettingsService::class)->save($this->administrator(), $this->merchant, $method->id, [
                'fee_percent' => '100.0000', 'fee_fixed' => '-1.00', 'refund_fee' => '1.001',
                'reserve_percent' => '101', 'reserve_days' => '1.5',
            ]);
            $this->fail('Invalid values should be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(['fee_percent', 'fee_fixed', 'refund_fee', 'reserve_percent', 'reserve_days'], array_keys($e->errors()));
        }

        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_new_order_uses_merchant_settings_and_idempotent_retry_preserves_snapshots(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('snapshot');
        $this->merchant->update(['settlement_mode' => 'shipped', 'settlement_days' => 7]);
        PaymentGroup::create(['merchant_id' => $this->merchant->id, 'group_key' => 'default', 'group_name' => 'Default']);
        $actor = $this->administrator();
        app(MerchantPaymentSettingsService::class)->save($actor, $this->merchant, $method->id, [
            'fee_percent' => '2.5123', 'fee_fixed' => '0.30', 'refund_fee' => '5.00',
            'chargeback_fee' => '15.00', 'reserve_percent' => '10.0000', 'reserve_days' => 90,
        ]);
        Http::preventStrayRequests();
        Http::fake(['*/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/order', 'wp_order_id' => 1]])]);

        $this->createOrderRequest('snapshot', '10000.00')->assertOk();
        app(MerchantPaymentSettingsService::class)->save($actor, $this->merchant, $method->id, [
            'fee_percent' => 99, 'reserve_percent' => 80, 'reserve_days' => 180,
        ]);
        $this->createOrderRequest('snapshot', '10000.00')->assertOk();

        $order = Order::query()->sole();
        $this->assertSame('251.23', $order->fee_percent_amount);
        $this->assertSame('0.30', $order->fee_fixed_amount);
        $this->assertSame('9748.47', $order->settlement_amount);
        $this->assertSame('10.0000', $order->reserve_percent);
        $this->assertSame(90, $order->reserve_days);
        $this->assertSame('5.00', $order->refund_fee_snapshot);
        $this->assertSame('15.00', $order->chargeback_fee_snapshot);
        $this->assertSame('shipped', $order->settlement_mode);
        $this->assertSame(7, $order->settlement_days);
        Http::assertSentCount(1);
    }

    public function test_minimum_amount_rejection_uses_merchant_override(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('minimum');
        PaymentGroup::create(['merchant_id' => $this->merchant->id, 'group_key' => 'default', 'group_name' => 'Default']);
        app(MerchantPaymentSettingsService::class)->save($this->administrator(), $this->merchant, $method->id, [
            'fee_percent' => 50, 'fee_fixed' => 60,
        ]);
        Http::preventStrayRequests();

        $this->createOrderRequest('minimum')->assertUnprocessable()->assertJsonPath('error_code', 'MINIMUM_AMOUNT_NOT_MET');

        $this->assertSame('120.00', $method->minTransactionAmount($this->merchant->id));
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_group_routing_ignores_foreign_and_revoked_channels(): void
    {
        $this->createMerchantAndApplication();
        $foreign = $this->makePaymentMethod('foreign_group');
        $foreign->update(['merchant_id' => $this->otherMerchant()->id]);
        $foreign->assignedMerchants()->attach($this->merchant->id);
        $revoked = $this->makePaymentMethod('revoked_group');
        $revoked->update(['merchant_id' => null]);
        $allowed = $this->makePaymentMethod('allowed_group');
        $allowed->update(['merchant_id' => null]);
        $allowed->assignedMerchants()->attach($this->merchant->id);
        $group = PaymentGroup::create(['merchant_id' => $this->merchant->id, 'group_key' => 'routing', 'group_name' => 'Routing']);
        $group->paymentMethods()->attach([$foreign->id, $revoked->id, $allowed->id]);

        $selected = app(PaymentService::class)->resolvePaymentMethod($group, 100);

        $this->assertSame($allowed->id, $selected->id);
    }

    public function test_relation_manager_can_create_edit_and_reset_settings(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('relation');
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->administrator());

        $component = Livewire::test(PaymentSettingsRelationManager::class, ['ownerRecord' => $this->merchant, 'pageClass' => EditMerchant::class])
            ->assertOk()
            ->callTableAction('create', data: ['payment_method_id' => $method->id, 'fee_percent' => '4.25', 'reserve_percent' => '10', 'reserve_days' => 30])
            ->assertHasNoTableActionErrors();
        $setting = MerchantPaymentSetting::query()->sole();
        $component->callTableAction('edit', $setting, ['fee_percent' => '0', 'reserve_percent' => null, 'reserve_days' => null])
            ->assertHasNoTableActionErrors();
        $this->assertSame('0.0000', $setting->fresh()->fee_percent);
        $this->assertNull($setting->fresh()->reserve_percent);

        $component->callTableAction('delete', $setting)->assertHasNoTableActionErrors();

        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_relation_manager_rejects_foreign_merchant_and_merchant_accounts(): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->administrator(false);
        $manager->givePermissionTo(Permissions::MERCHANTS_MANAGE);
        $this->merchant->update(['owner_id' => $manager->id]);
        $other = $this->otherMerchant();
        Filament::setCurrentPanel('admin');
        $this->actingAs($manager);

        Livewire::test(PaymentSettingsRelationManager::class, ['ownerRecord' => $other, 'pageClass' => EditMerchant::class])
            ->assertForbidden();

        $merchantUser = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $merchantUser->givePermissionTo(Permissions::MERCHANTS_MANAGE);
        $this->actingAs($merchantUser);

        Livewire::test(PaymentSettingsRelationManager::class, ['ownerRecord' => $this->merchant, 'pageClass' => EditMerchant::class])
            ->assertForbidden();
    }

    public function test_relation_manager_rechecks_owner_after_merchant_is_transferred(): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->administrator(false);
        $manager->givePermissionTo(Permissions::MERCHANTS_MANAGE);
        $this->merchant->update(['owner_id' => $manager->id]);
        Filament::setCurrentPanel('admin');
        $this->actingAs($manager);
        $component = Livewire::test(PaymentSettingsRelationManager::class, ['ownerRecord' => $this->merchant, 'pageClass' => EditMerchant::class])->assertOk();
        $this->merchant->update(['owner_id' => null]);

        $component->call('$refresh')->assertForbidden();

        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_merchant_edit_form_cannot_spoof_default_financial_values(): void
    {
        $this->createMerchantAndApplication();
        $other = $this->otherMerchant();
        $method = $this->makePaymentMethod('protected');
        $method->update(['fee_percent' => 4, 'fee_fixed' => 2, 'refund_fee' => 5, 'chargeback_fee' => 15, 'reserve_percent' => 10, 'reserve_days' => 90]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        Filament::setCurrentPanel('merchant');
        $this->actingAs($user);
        Http::preventStrayRequests();
        Http::fake(['*/wp-json/payment-plugin/v1/gateway-config*' => Http::response(['code' => 0, 'data' => ['config_id' => 'test']])]);

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->set('data.merchant_id', $other->id)
            ->set('data.fee_percent', 0)->set('data.fee_fixed', 0)->set('data.refund_fee', 0)
            ->set('data.chargeback_fee', 0)->set('data.reserve_percent', 0)->set('data.reserve_days', 0)
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([
            'fee_percent' => '4.0000', 'fee_fixed' => '2.00', 'refund_fee' => '5.00',
            'chargeback_fee' => '15.00', 'reserve_percent' => '10.0000', 'reserve_days' => 90,
        ], $method->fresh()->financialSettingsForMerchant($this->merchant->id));
        $this->assertSame($this->merchant->id, $method->fresh()->merchant_id);
    }

    public function test_merchant_create_form_cannot_spoof_default_financial_values(): void
    {
        $this->createMerchantAndApplication();
        $other = $this->otherMerchant();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        Filament::setCurrentPanel('merchant');
        $this->actingAs($user);
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm([
            'merchant_id' => $this->merchant->id, 'method_code' => 'new_protected', 'method_name' => 'New Protected',
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test',
            'product_match_mode' => 'MATCH',
        ])->set('data.merchant_id', $other->id)
            ->set('data.fee_percent', 99)->set('data.reserve_percent', 90)->set('data.reserve_days', 365)
            ->call('create')->assertHasNoFormErrors();

        $method = PaymentMethod::query()->sole();
        $this->assertSame('0.0000', $method->fee_percent);
        $this->assertSame('0.0000', $method->reserve_percent);
        $this->assertSame(0, $method->reserve_days);
        $this->assertSame($this->merchant->id, $method->merchant_id);
        Http::assertNothingSent();
    }

    public function test_merchant_channel_list_shows_effective_settings_with_one_scoped_eager_load(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('effective');
        $method->update(['merchant_id' => null, 'fee_percent' => '3.5000', 'fee_fixed' => '0.30', 'refund_fee' => '5.00', 'chargeback_fee' => '20.00', 'reserve_percent' => '10.0000', 'reserve_days' => 90]);
        $other = $this->otherMerchant();
        $method->assignedMerchants()->sync([$this->merchant->id, $other->id]);
        $second = $this->makePaymentMethod('default_effective');
        $actor = $this->administrator();
        app(MerchantPaymentSettingsService::class)->save($actor, $this->merchant, $method->id, [
            'fee_percent' => '2.1234', 'fee_fixed' => '0', 'refund_fee' => null,
            'chargeback_fee' => '12.00', 'reserve_percent' => '5', 'reserve_days' => 30,
        ]);
        app(MerchantPaymentSettingsService::class)->save($actor, $other, $method->id, ['fee_percent' => '9.8765']);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        Filament::setCurrentPanel('merchant');
        $this->actingAs($user);
        $settingsQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$settingsQueries): void {
            $sql = str_replace(['`', '"'], '', strtolower($query->sql));

            if (str_contains($sql, 'from merchant_payment_settings')) {
                $settingsQueries[] = $query->sql;
            }
        });

        $component = Livewire::test(ListPaymentMethods::class)->assertOk();

        $this->assertCount(1, $settingsQueries);
        $records = $component->instance()->getTableRecords();
        $this->assertTrue($records->find($method->id)->relationLoaded('merchantPaymentSettings'));
        $this->assertCount(1, $records->find($method->id)->merchantPaymentSettings);
        $this->assertCount(0, $records->find($second->id)->merchantPaymentSettings);
        $component->assertTableColumnStateSet('fee_percent', '2.1234', $method)
            ->assertTableColumnStateSet('fee_fixed', '0.00', $method)
            ->assertTableColumnStateSet('refund_fee', '5.00', $method)
            ->assertTableColumnStateSet('chargeback_fee', '12.00', $method)
            ->assertTableColumnStateSet('reserve_percent', '5.0000', $method)
            ->assertTableColumnStateSet('reserve_days', 30, $method)
            ->assertTableColumnStateSet('fee_percent', '0.0000', $second);
    }

    private function administrator(bool $superAdmin = true): User
    {
        return User::factory()->create(['merchant_id' => null, 'is_super_admin' => $superAdmin]);
    }

    private function otherMerchant(): Merchant
    {
        return Merchant::create(['name' => 'Other', 'contact_person' => 'Other', 'contact_phone' => '123', 'contact_email' => 'other@example.com']);
    }

    private function createOrderRequest(string $methodCode, string $amount = '100.00'): TestResponse
    {
        $body = [
            'merchant_order_no' => 'FINANCE-001', 'platform' => 'wordpress', 'currency' => 'USD',
            'group_key' => 'default', 'payment_method_key' => $methodCode,
            'subtotal' => $amount, 'shipping_fee' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'amount' => $amount,
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
            'shipping_address' => ['line1' => '123 Main St', 'city' => 'City', 'country' => 'US'],
            'items' => [['product_id' => 'SKU1', 'product_url' => 'https://shop.example.com/product/1', 'product_name' => 'Product', 'unit_price' => $amount, 'quantity' => 1]],
            'notify_url' => 'https://shop.example.com/notify', 'return_url' => 'https://shop.example.com/return', 'cancel_url' => 'https://shop.example.com/cancel',
        ];
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body['sign'] = SignatureCanonicalizer::sign($this->application->app_id, $timestamp, $nonce, $body, $this->application->api_key);

        return $this->withHeaders(['App-ID' => $this->application->app_id, 'Timestamp' => $timestamp, 'X-Nonce' => $nonce])
            ->postJson('/api/order/create', $body);
    }
}
