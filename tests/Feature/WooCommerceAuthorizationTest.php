<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Models\MerchantPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\User;
use App\Services\WooCommerceAuthorizationService;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class WooCommerceAuthorizationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const DOMAIN = 'https://shop.example.com';

    private const KEY = 'ck_0123456789abcdef0123456789abcdef01234567';

    private const SECRET = 'cs_abcdef0123456789abcdef0123456789abcdef01';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://pay.example.com']);
        Filament::setCurrentPanel('admin');
    }

    public function test_create_form_receives_anonymous_callback_preserves_draft_and_saves_normally(): void
    {
        $admin = $this->administrator();
        $map = PaymentMethodConfigMap::create(['name' => 'Test Gateway', 'payment_config_tag' => 'stripe', 'fields' => []]);
        Http::preventStrayRequests();
        $page = Livewire::actingAs($admin)->test(CreatePaymentMethod::class)->fillForm([
            'merchant_id' => $this->merchant->id, 'method_code' => 'connected-channel',
            'method_name' => 'Draft channel name', 'domain' => self::DOMAIN, 'config_map_id' => $map->id,
        ])->mountAction(TestAction::make('authorizeWooCommerce')->schemaComponent('domain'))
            ->assertActionMounted(TestAction::make('authorizeWooCommerce')->schemaComponent('domain'));
        $modal = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString(__('admin.payment_method.woocommerce.open'), $modal);
        $this->assertStringContainsString(e($page->get('wooCommerceAuthorizationUrl')), $modal);
        $parameters = $this->parameters($page->get('wooCommerceAuthorizationUrl'));
        $this->assertSame('read_write', $parameters['scope']);
        $this->assertStringStartsWith('https://pay.example.com/integrations/woocommerce/authorization/', $parameters['callback_url']);
        $this->assertNotSame((string) $admin->id, $parameters['user_id']);

        Auth::logout();
        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertOk()->assertExactJson(['success' => true]);

        $this->assertDatabaseCount('payment_methods', 0);
        $ciphertext = Cache::get('woocommerce-authorization:'.hash('sha256', $page->get('wooCommerceAuthorizationToken')));
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString(self::KEY, $ciphertext);
        $this->assertStringNotContainsString(self::SECRET, $ciphertext);
        $this->actingAs($admin);
        $page->call('refreshWooCommerceAuthorization')
            ->assertSet('data.domain_client_id', self::KEY)->assertSet('data.domain_client_sk', self::SECRET)
            ->assertSet('data.method_name', 'Draft channel name')->assertSet('data.config_map_id', $map->id)
            ->assertSet('wooCommerceAuthorizationToken', null)->assertActionNotMounted()
            ->assertNotified(__('admin.payment_method.woocommerce.ready'));
        $this->assertDatabaseCount('payment_methods', 0);
        Http::assertNothingSent();

        Http::fake([self::DOMAIN.'/wp-json/payment-plugin/v1/gateway-config?'.http_build_query(['consumer_key' => self::KEY, 'consumer_secret' => self::SECRET]) => Http::response(['code' => 0, 'data' => ['config_id' => 'authorized-config']])]);
        $page->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas('payment_methods', [
            'method_code' => 'connected-channel', 'merchant_id' => $this->merchant->id,
            'domain_client_id' => self::KEY, 'domain_client_sk' => self::SECRET, 'payment_config_id' => 'authorized-config',
        ]);
        Http::assertSentCount(1);
    }

    public function test_edit_form_replaces_keys_only_in_the_draft_until_save(): void
    {
        $admin = $this->administrator();
        $method = $this->makePaymentMethod('existing-channel');
        $page = Livewire::actingAs($admin)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['method_name' => 'Unsaved name'])
            ->mountAction(TestAction::make('authorizeWooCommerce')->schemaComponent('domain'));
        $parameters = $this->parameters($page->get('wooCommerceAuthorizationUrl'));

        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertOk();
        $page->call('refreshWooCommerceAuthorization')->assertSet('data.domain_client_sk', self::SECRET)
            ->assertSet('data.method_name', 'Unsaved name');

        $this->assertSame('cs_test', $method->fresh()->domain_client_sk);
        $this->assertSame('EXISTING-CHANNEL', $method->fresh()->method_name);
    }

    public function test_wordpress_subdirectory_is_preserved_in_the_normalized_authorization_url(): void
    {
        $admin = $this->administrator();

        $flow = app(WooCommerceAuthorizationService::class)->begin($admin, ' HTTPS://SHOP.EXAMPLE.COM:443/wordpress/ ');

        $this->assertStringStartsWith(self::DOMAIN.'/wordpress/wc-auth/v1/authorize?', $flow['url']);
    }

    #[TestWith([''])]
    #[TestWith(['not-a-url'])]
    #[TestWith(['http://shop.example.com'])]
    #[TestWith(['https://user:password@shop.example.com'])]
    #[TestWith(['https://shop.example.com?redirect=elsewhere'])]
    #[TestWith(['https://shop.example.com#fragment'])]
    public function test_invalid_domain_prevents_authorization_without_validating_unrelated_fields(string $domain): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test(CreatePaymentMethod::class)->fillForm(['domain' => $domain])
            ->mountAction(TestAction::make('authorizeWooCommerce')->schemaComponent('domain'))
            ->assertHasErrors(['data.domain'])->assertSet('wooCommerceAuthorizationToken', null)
            ->assertSee(__('admin.payment_method.validation.domain_format'));
    }

    public function test_http_system_url_explains_why_automatic_authorization_is_unavailable(): void
    {
        $admin = $this->administrator();
        config(['app.url' => 'http://pay.example.com']);

        Livewire::actingAs($admin)->test(CreatePaymentMethod::class)->fillForm(['domain' => self::DOMAIN])
            ->mountAction(TestAction::make('authorizeWooCommerce')->schemaComponent('domain'))
            ->assertHasErrors(['data.domain'])->assertSee(__('admin.payment_method.woocommerce.https_required'));
    }

    #[TestWith(['key_id', 0])]
    #[TestWith(['user_id', ''])]
    #[TestWith(['consumer_key', 'ck_invalid'])]
    #[TestWith(['consumer_secret', 'cs_invalid'])]
    #[TestWith(['key_permissions', 'read'])]
    #[TestWith(['consumer_key', ['unexpected' => 'array']])]
    public function test_invalid_callback_returns_422_without_flashing_or_echoing_credentials(string $field, mixed $value): void
    {
        $admin = $this->administrator();
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($admin, self::DOMAIN);
        $parameters = $this->parameters($flow['url']);
        $payload = array_replace($this->payload($parameters), [$field => $value]);

        $this->call('POST', $parameters['callback_url'], server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR))
            ->assertUnprocessable()->assertExactJson(['success' => false])
            ->assertDontSee(self::SECRET)->assertSessionMissing('_old_input');

        $this->assertSame(['status' => 'pending'], $service->consume($admin, $flow['token'], self::DOMAIN));
    }

    public function test_callback_cannot_complete_a_different_authorization_reference(): void
    {
        $admin = $this->administrator();
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($admin, self::DOMAIN);
        $parameters = $this->parameters($flow['url']);

        $this->postJson($parameters['callback_url'], array_replace($this->payload($parameters), ['user_id' => str_repeat('0', 32)]))->assertForbidden();

        $this->assertSame(['status' => 'pending'], $service->consume($admin, $flow['token'], self::DOMAIN));
    }

    public function test_duplicate_callback_cannot_replace_keys_and_keys_are_consumed_once(): void
    {
        $admin = $this->administrator();
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($admin, self::DOMAIN);
        $parameters = $this->parameters($flow['url']);
        $payload = $this->payload($parameters);
        $this->postJson($parameters['callback_url'], $payload)->assertOk();
        $this->postJson($parameters['callback_url'], $payload)->assertOk();

        $this->postJson($parameters['callback_url'], array_replace($payload, ['consumer_secret' => 'cs_'.str_repeat('a', 40)]))->assertConflict();

        $this->assertSame(['status' => 'ready', 'credentials' => ['consumer_key' => self::KEY, 'consumer_secret' => self::SECRET]], $service->consume($admin, $flow['token'], self::DOMAIN));
        $this->assertSame(['status' => 'expired'], $service->consume($admin, $flow['token'], self::DOMAIN));
    }

    public function test_expired_and_unknown_callbacks_return_404(): void
    {
        $this->freezeTime();
        $admin = $this->administrator();
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($admin, self::DOMAIN);
        $parameters = $this->parameters($flow['url']);
        $this->travel(11)->minutes();

        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertNotFound();
        $this->postJson(route('woocommerce.authorization.callback', ['token' => str_repeat('f', 64)]), $this->payload($parameters))->assertNotFound();

        $this->assertSame(['status' => 'expired'], $service->consume($admin, $flow['token'], self::DOMAIN));
    }

    public function test_return_url_success_flag_cannot_complete_authorization(): void
    {
        $admin = $this->administrator();
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($admin, self::DOMAIN);
        $parameters = $this->parameters($flow['url']);

        $this->get($parameters['return_url'].'&success=1&user_id='.$parameters['user_id'])
            ->assertOk()->assertSee(__('admin.payment_method.woocommerce.return_title'))
            ->assertDontSee(self::KEY)->assertDontSee(self::SECRET);

        $this->assertSame(['status' => 'pending'], $service->consume($admin, $flow['token'], self::DOMAIN));
    }

    public function test_restarting_authorization_invalidates_the_previous_link(): void
    {
        $admin = $this->administrator();
        $page = Livewire::actingAs($admin)->test(CreatePaymentMethod::class)->fillForm(['domain' => self::DOMAIN])
            ->call('startWooCommerceAuthorization');
        $previous = $this->parameters($page->get('wooCommerceAuthorizationUrl'));

        $page->call('startWooCommerceAuthorization');
        $current = $this->parameters($page->get('wooCommerceAuthorizationUrl'));

        $this->assertNotSame($previous['callback_url'], $current['callback_url']);
        $this->postJson($previous['callback_url'], $this->payload($previous))->assertNotFound();
        $this->postJson($current['callback_url'], $this->payload($current))->assertOk();
        $page->call('refreshWooCommerceAuthorization')->assertSet('data.domain_client_sk', self::SECRET);
    }

    #[TestWith(['domain', 'https://different.example.com'])]
    #[TestWith(['domain_client_id', 'ck_manually_changed'])]
    #[TestWith(['domain_client_sk', 'cs_manually_changed'])]
    #[TestWith(['payment_type', 'crypto'])]
    public function test_changing_connection_settings_cancels_pending_autofill(string $field, string $value): void
    {
        $admin = $this->administrator();
        $page = Livewire::actingAs($admin)->test(CreatePaymentMethod::class)->fillForm(['domain' => self::DOMAIN])
            ->call('startWooCommerceAuthorization');
        $parameters = $this->parameters($page->get('wooCommerceAuthorizationUrl'));
        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertOk();

        $page->fillForm([$field => $value])->call('refreshWooCommerceAuthorization')
            ->assertSet('data.'.$field, $value)->assertSet('wooCommerceAuthorizationToken', null)
            ->assertNotified(__('admin.payment_method.woocommerce.changed'));

        $this->assertNotSame(self::SECRET, $page->get('data.domain_client_sk'));
        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertNotFound();
    }

    public function test_foreign_user_cannot_consume_another_users_authorization(): void
    {
        $admin = $this->administrator();
        $foreign = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($admin, self::DOMAIN);
        $parameters = $this->parameters($flow['url']);
        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertOk();

        try {
            $service->consume($foreign, $flow['token'], self::DOMAIN);
            $this->fail('A different user must not receive the keys.');
        } catch (AuthorizationException) {
            $this->assertSame('ready', $service->consume($admin, $flow['token'], self::DOMAIN)['status']);
        }
    }

    public function test_authorization_cannot_be_moved_to_a_different_payment_method(): void
    {
        $admin = $this->administrator();
        $first = $this->makePaymentMethod('first');
        $second = $this->makePaymentMethod('second');
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($admin, self::DOMAIN, $first);

        $this->expectException(AuthorizationException::class);

        $service->consume($admin, $flow['token'], self::DOMAIN, $second);
    }

    #[TestWith(['disabled'])]
    #[TestWith(['deleted'])]
    #[TestWith(['permission_revoked'])]
    #[TestWith(['merchant_disabled'])]
    public function test_callback_rechecks_initiating_users_current_access(string $change): void
    {
        $this->administrator();
        $actor = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $actor->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $flow = app(WooCommerceAuthorizationService::class)->begin($actor, self::DOMAIN);
        $parameters = $this->parameters($flow['url']);
        match ($change) {
            'disabled' => $actor->update(['status' => false]),
            'deleted' => $actor->delete(),
            'permission_revoked' => $actor->revokePermissionTo(Permissions::PAYMENT_METHODS_MANAGE),
            'merchant_disabled' => $this->merchant->update(['status' => false]),
        };

        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertForbidden();
        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_autofill_rechecks_ownership_after_receiving_the_keys(): void
    {
        $admin = $this->administrator();
        $owner = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $owner->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $method = $this->makePaymentMethod('owned');
        $method->update(['merchant_id' => null]);
        $method->owner_id = $owner->id;
        $method->save();
        $service = app(WooCommerceAuthorizationService::class);
        $flow = $service->begin($owner, self::DOMAIN, $method);
        $parameters = $this->parameters($flow['url']);
        $this->postJson($parameters['callback_url'], $this->payload($parameters))->assertOk();
        PaymentMethod::query()->whereKey($method)->update(['owner_id' => $admin->id]);

        $this->expectException(AuthorizationException::class);

        $service->consume($owner, $flow['token'], self::DOMAIN, $method);
    }

    public function test_assigned_shared_channel_cannot_be_connected_by_a_merchant(): void
    {
        $admin = $this->administrator();
        $method = $this->makePaymentMethod('shared');
        $method->update(['merchant_id' => null]);
        $method->owner_id = $admin->id;
        $method->save();
        MerchantPaymentMethod::withoutEvents(fn () => $method->assignedMerchants()->attach($this->merchant));
        $merchantUser = User::factory()->create(['merchant_id' => $this->merchant->id, 'is_super_admin' => false, 'status' => true]);
        $merchantUser->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);

        $this->expectException(AuthorizationException::class);

        app(WooCommerceAuthorizationService::class)->begin($merchantUser, self::DOMAIN, $method);
    }

    public function test_crypto_form_hides_authorization_and_rejects_direct_calls(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test(CreatePaymentMethod::class)->fillForm(['payment_type' => 'crypto'])
            ->assertActionDoesNotExist(TestAction::make('authorizeWooCommerce')->schemaComponent('domain'))
            ->call('startWooCommerceAuthorization')->assertForbidden();
    }

    public function test_authorization_token_cannot_be_replaced_from_livewire_input(): void
    {
        $admin = $this->administrator();
        $page = Livewire::actingAs($admin)->test(CreatePaymentMethod::class);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $page->set('wooCommerceAuthorizationToken', str_repeat('f', 64));
    }

    public function test_frequent_authorization_attempts_are_rejected_per_user(): void
    {
        $admin = $this->administrator();
        $service = app(WooCommerceAuthorizationService::class);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $service->begin($admin, self::DOMAIN);
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('admin.payment_method.woocommerce.too_many_attempts'));

        $service->begin($admin, self::DOMAIN);
    }

    private function administrator(): User
    {
        $this->createMerchantAndApplication();

        return User::factory()->create(['is_super_admin' => true, 'status' => true]);
    }

    /** @return array<string, string> */
    private function parameters(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array{key_id: int, user_id: string, consumer_key: string, consumer_secret: string, key_permissions: string}
     */
    private function payload(array $parameters): array
    {
        return ['key_id' => 1, 'user_id' => $parameters['user_id'], 'consumer_key' => self::KEY,
            'consumer_secret' => self::SECRET, 'key_permissions' => 'read_write'];
    }
}
