<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentMethodResource;
use App\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\CryptoPaymentTemplatesSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\Feature\CryptoConfigurationTest;
use Tests\TestCase;

class CryptoPaymentMethodTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_creates_crypto_method_without_wordpress_fields_or_sync_requests(): void
    {
        $this->arrangeAdmin();
        $map = PaymentMethodConfigMap::query()->where('name', 'Crypto / Ethereum')->sole();
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm([
            'merchant_id' => $this->merchant->id, 'method_code' => 'crypto-create', 'method_name' => 'ETH payments',
            'payment_type' => 'crypto', 'config_map_id' => $map->id, 'config' => CryptoConfigurationTest::publicConfig(),
        ])->call('create')->assertHasNoFormErrors();

        $method = PaymentMethod::query()->where('method_code', 'crypto-create')->sole();
        $this->assertSame('crypto', $method->payment_type);
        $this->assertSame(CryptoConfigurationTest::publicConfig(), $method->config);
        $this->assertNull($method->domain_client_sk);
        $this->assertFalse($method->sync_logistics);
        Http::assertNothingSent();
    }

    public function test_edit_saves_json_and_hides_plugin_sync_action(): void
    {
        $this->arrangeAdmin();
        $method = $this->cryptoMethod();
        Http::preventStrayRequests();

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertDontSee(__('admin.payment_method.actions.sync_gateway_config'))->fillForm(['method_name' => 'Updated ETH'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('Updated ETH', $method->refresh()->method_name);
        $this->assertSame(CryptoConfigurationTest::publicConfig(), $method->config);
        Http::assertNothingSent();
    }

    public function test_cannot_forge_a_crypto_template_into_fiat_sync_payload(): void
    {
        $this->arrangeAdmin();
        $crypto = $this->cryptoMethod();
        $fiat = $this->makePaymentMethod('fiat-sync');
        Http::preventStrayRequests();

        $result = PaymentMethodResource::syncGatewayConfigFromData($fiat, [
            'config_map_id' => $crypto->config_map_id, 'config' => ['extended_public_key' => CryptoConfigurationTest::XPUB],
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck', 'domain_client_sk' => 'cs',
        ]);

        $this->assertSame(['success' => false, 'reason' => 'not_applicable'], $result);
        Http::assertNothingSent();
    }

    public function test_ui_rejects_template_of_the_wrong_payment_type(): void
    {
        $this->arrangeAdmin();
        $fiat = $this->makePaymentMethod('fiat-template');
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm([
            'merchant_id' => $this->merchant->id, 'method_code' => 'bad-map', 'method_name' => 'Bad',
            'payment_type' => 'crypto', 'config_map_id' => $fiat->config_map_id,
        ])->call('create')->assertHasFormErrors(['config_map_id']);

        $this->assertDatabaseMissing('payment_methods', ['method_code' => 'bad-map']);
        Http::assertNothingSent();
    }

    public function test_foreign_merchant_cannot_edit_public_wallet_configuration(): void
    {
        $this->arrangeAdmin();
        $method = $this->cryptoMethod();
        $foreign = User::factory()->create(['is_super_admin' => false, 'merchant_id' => null, 'status' => true]);
        $foreign->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);

        Livewire::actingAs($foreign)->test(EditPaymentMethod::class, ['record' => $method->id])->assertNotFound();
    }

    public function test_secret_field_cannot_be_saved_through_a_forged_form_payload(): void
    {
        $this->arrangeAdmin();
        $method = $this->cryptoMethod();
        Http::preventStrayRequests();

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->set('data.config.private_key', 'must-not-save')->call('save')->assertHasNoFormErrors();

        $this->assertArrayNotHasKey('private_key', $method->refresh()->config);
        Http::assertNothingSent();
    }

    private function arrangeAdmin(): void
    {
        $this->createMerchantAndApplication();
        $this->seed(CryptoPaymentTemplatesSeeder::class);
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => true]));
        Filament::setCurrentPanel('admin');
    }

    private function cryptoMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'merchant_id' => $this->merchant->id, 'method_code' => 'crypto-edit', 'method_name' => 'ETH', 'payment_type' => 'crypto',
            'config_map_id' => PaymentMethodConfigMap::query()->where('name', 'Crypto / Ethereum')->sole()->id,
            'config' => CryptoConfigurationTest::publicConfig(), 'is_active' => true,
        ]);
    }
}
