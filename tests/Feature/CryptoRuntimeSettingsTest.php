<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Models\User;
use App\Services\CryptoAddressMonitorService;
use App\Services\CryptoRuntimeSettingsService;
use App\Services\TatumReadClient;
use App\Support\CryptoCheckoutGuard;
use App\Support\CryptoRuntimeSettings;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class CryptoRuntimeSettingsTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    public function test_database_flags_override_legacy_config_and_false_does_not_turn_into_truthy_text(): void
    {
        config(['services.tatum.checkout_enabled' => true, 'services.tatum.subscriptions_enabled' => true]);
        $this->assertFalse(CryptoCheckoutGuard::enabled());
        $this->setCryptoSettings(['crypto.checkout_enabled' => true, 'crypto.subscriptions_enabled' => true,
            'crypto.verification_enabled' => true, 'crypto.history_enabled' => true, 'crypto.settlement_enabled' => true]);
        $this->assertTrue(CryptoCheckoutGuard::enabled());
        SystemConfig::set('crypto.checkout_enabled', 'false');
        $this->assertFalse(CryptoCheckoutGuard::enabled());
        $this->assertTrue(CryptoCheckoutGuard::operational());
        SystemConfig::query()->where('config_key', 'crypto.checkout_enabled')->delete();
        SystemConfig::forget('crypto.checkout_enabled');
        $this->assertFalse(CryptoCheckoutGuard::enabled());
    }

    public function test_existing_client_uses_updated_database_key_without_restarting_or_reading_old_config(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/data/rate/symbol*' => Http::response([
            'value' => '100000', 'basePair' => 'USD', 'symbol' => 'BTC', 'timestamp' => now()->getTimestampMs(),
        ])]);
        config(['services.tatum.api_key' => 'ignored-old-config-key']);
        SystemConfig::set('crypto.api_key', 'db-first-key');
        $client = app(TatumReadClient::class);
        $client->usdPrice('bitcoin-testnet:BTC');
        SystemConfig::set('crypto.api_key', 'db-second-key');
        $client->usdPrice('bitcoin-testnet:BTC');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'db-first-key'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'db-second-key'));
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'ignored-old-config-key'));
    }

    #[DataProvider('invalidSettings')]
    public function test_runtime_settings_validate_at_the_save_boundary(string $name, mixed $value): void
    {
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $record = SystemConfig::where('config_key', 'crypto.'.$name)->sole();
        $this->expectException(ValidationException::class);
        app(CryptoRuntimeSettingsService::class)->save($actor, $record, [
            $record->value_type === 'secret' ? 'secret_value' : 'config_value' => $value,
        ]);
    }

    public static function invalidSettings(): array
    {
        return [
            'zero confirmations' => ['utxo_min_confirmations.bitcoin-testnet', '0'],
            'fractional confirmations' => ['utxo_min_confirmations.bitcoin-testnet', '1.5'],
            'too many confirmations' => ['evm_min_confirmations.ethereum-mainnet', '100001'],
            'invalid genesis' => ['genesis_hashes.bitcoin-testnet', 'wrong-chain'],
            'stale threshold' => ['history_max_head_age_seconds', '86401'],
            'unbounded timeout' => ['timeout', '0'],
            'empty timeout' => ['connect_timeout', null],
            'invalid flag' => ['history_enabled', 'maybe'],
            'short hmac' => ['testnet_hmac_secret', 'short'],
            'key header injection' => ['testnet_key', "key\r\nx-other: value"],
            'http callback' => ['webhook_base_url', 'http://notify.example.test'],
            'callback path' => ['webhook_base_url', 'https://notify.example.test/api'],
            'callback credentials' => ['webhook_base_url', 'https://user:pass@notify.example.test'],
            'callback query' => ['webhook_base_url', 'https://notify.example.test?key=value'],
            'invalid erc20' => ['test_tokens.ethereum-sepolia', 'not-a-contract'],
            'invalid trc20' => ['test_tokens.tron-shasta', 'not-a-contract'],
        ];
    }

    #[DataProvider('credentialChanges')]
    public function test_credentials_cannot_be_replaced_or_cleared_while_subscriptions_use_them(string $name, ?string $replacement): void
    {
        $monitor = $this->createCryptoMonitor();
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $record = SystemConfig::where('config_key', 'crypto.'.$name)->sole();
        $before = $record->getRawOriginal('secret_value');
        Http::preventStrayRequests();
        Http::fake();
        try {
            app(CryptoRuntimeSettingsService::class)->save($actor, $record, ['secret_value' => $replacement]);
            $this->fail('In-use subscription credentials were changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.value_secret', $exception->errors());
            $this->assertSame($before, $record->fresh()->getRawOriginal('secret_value'));
            $this->assertSame('preparing', $monitor->fresh()->status);
            Http::assertNothingSent();
        }
    }

    public static function credentialChanges(): array
    {
        return [
            'replace API key' => ['testnet_key', 'replacement-api-key'],
            'clear API key' => ['testnet_key', null],
            'replace HMAC' => ['testnet_hmac_secret', 'replacement-hmac-secret-12345'],
            'clear HMAC' => ['testnet_hmac_secret', null],
        ];
    }

    public function test_mainnet_credentials_can_change_when_only_a_different_testnet_key_is_in_use(): void
    {
        $this->createCryptoMonitor();
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $record = SystemConfig::where('config_key', 'crypto.api_key')->sole();
        app(CryptoRuntimeSettingsService::class)->save($actor, $record, ['secret_value' => 'replacement-price-key']);
        $this->assertSame('replacement-price-key', CryptoRuntimeSettings::get('api_key'));
        $this->assertSame('test-testnet-credential', CryptoRuntimeSettings::get('testnet_key'));
    }

    public function test_a_key_shared_across_environments_protects_the_other_environments_hmac(): void
    {
        $monitor = $this->createCryptoMonitor();
        SystemConfig::set('crypto.api_key', 'test-testnet-credential');
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->assertSame(hash('sha256', 'test-testnet-credential'), $monitor->credential_fingerprint);
        $this->expectException(ValidationException::class);
        app(CryptoRuntimeSettingsService::class)->save($actor,
            SystemConfig::where('config_key', 'crypto.mainnet_hmac_secret')->sole(), ['secret_value' => 'new-mainnet-hmac-secret']);
    }

    public function test_rotated_credentials_are_used_to_enable_hmac_and_create_the_next_subscription_after_safe_cancellation(): void
    {
        $monitor = $this->createCryptoMonitor();
        $monitor->update(['status' => 'cold', 'cancelled_at' => now(), 'backfill_completed_at' => now()]);
        SystemConfig::set('crypto.subscription_reopen_max_renewals', '3');
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $settings = app(CryptoRuntimeSettingsService::class);
        $settings->save($actor, SystemConfig::where('config_key', 'crypto.testnet_key')->sole(), ['secret_value' => 'rotated-api-key']);
        $settings->save($actor, SystemConfig::where('config_key', 'crypto.testnet_hmac_secret')->sole(), ['secret_value' => 'rotated-hmac-secret-123456']);
        $service = app(CryptoAddressMonitorService::class);
        $next = $service->prepare($monitor->merchant_id, $monitor->crypto_payment_request_id);
        $this->assertSame(2, $next->generation);
        $this->assertSame(hash('sha256', 'rotated-api-key'), $next->credential_fingerprint);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.tatum.io/v4/subscription/alert-new' => Http::response($this->alertData($next, 'alert-new')),
            'https://api.tatum.io/v4/subscription*' => fn (Request $request) => match ($request->method()) {
                'PUT' => Http::response('', 204), 'GET' => Http::response([]), 'POST' => Http::response(['id' => 'alert-new'], 201),
            },
        ]);
        $this->assertTrue($service->synchronize($next->merchant_id, $next->id));
        $this->assertSame('active', $next->fresh()->status);
        $this->assertSame('cold', $monitor->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['hmacSecret'] === 'rotated-hmac-secret-123456' && $request->hasHeader('x-api-key', 'rotated-api-key'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->hasHeader('x-api-key', 'rotated-api-key'));
        Http::assertSentCount(4);
    }

    public function test_save_rechecks_admin_status_instead_of_trusting_a_stale_authenticated_user(): void
    {
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        DB::table('users')->where('id', $actor->id)->update(['is_super_admin' => false]);
        $this->expectException(HttpException::class);
        app(CryptoRuntimeSettingsService::class)->save($actor,
            SystemConfig::where('config_key', 'crypto.testnet_key')->sole(), ['secret_value' => 'forbidden-change']);
    }

    #[DataProvider('missingCredentials')]
    public function test_missing_credentials_do_not_create_a_monitor_that_blocks_configuration_repair(string $name): void
    {
        $monitor = $this->createCryptoMonitor();
        $monitor->update(['status' => 'cold', 'cancelled_at' => now(), 'backfill_completed_at' => now()]);
        SystemConfig::set('crypto.subscription_reopen_max_renewals', '3');
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $record = SystemConfig::where('config_key', 'crypto.'.$name)->sole();
        $previous = CryptoRuntimeSettings::get($name);
        $settings = app(CryptoRuntimeSettingsService::class);
        $settings->save($actor, $record, ['secret_value' => null]);
        Http::preventStrayRequests();
        Http::fake();
        try {
            app(CryptoAddressMonitorService::class)->prepare($monitor->merchant_id, $monitor->crypto_payment_request_id);
            $this->fail('Incomplete credentials created a monitor.');
        } catch (DomainException) {
            $this->assertDatabaseCount('crypto_address_monitors', 1);
            $this->assertSame('cold', $monitor->fresh()->status);
        }
        $settings->save($actor, $record, ['secret_value' => $previous]);
        $next = app(CryptoAddressMonitorService::class)->prepare($monitor->merchant_id, $monitor->crypto_payment_request_id);
        $this->assertSame(2, $next->generation);
        Http::assertNothingSent();
    }

    public static function missingCredentials(): array
    {
        return [['testnet_key'], ['testnet_hmac_secret']];
    }
}
