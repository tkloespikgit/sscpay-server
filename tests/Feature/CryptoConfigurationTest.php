<?php

namespace Tests\Feature;

use App\Models\PaymentMethodConfigMap;
use App\Services\CryptoPaymentConfigurationService;
use App\Support\CryptoPublicKey;
use Database\Seeders\CryptoPaymentTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class CryptoConfigurationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    /** Public example key from Tatum's address API reference, not a production wallet. */
    public const XPUB = 'xpub6EsCk1uU6cJzqvP9CdsTiJwT2rF748YkPnhv5Qo8q44DG7nn2vbyt48YRsNSUYS44jFCW9gwvD9kLQu9AuqXpTpM1c5hgg9PsuBLdeNncid';

    public static function publicConfig(): array
    {
        return [
            'config_version' => '1', 'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'extended_public_key' => self::XPUB,
            'derivation_profile' => 'tatum-v3-ethereum-v1', 'recovery_address_0' => '0x'.str_repeat('a', 40),
            'recovery_address_1' => '0x'.str_repeat('b', 40), 'confirmation_policy' => 'ethereum-configured-v1',
        ];
    }

    public function test_accepts_a_checksum_valid_public_key_and_only_clears_fiat_plugin_fields(): void
    {
        $this->seed(CryptoPaymentTemplatesSeeder::class);
        $map = PaymentMethodConfigMap::query()->where('name', 'Crypto / Ethereum')->sole();

        $data = app(CryptoPaymentConfigurationService::class)->validate([
            'payment_type' => 'crypto', 'config_map_id' => $map->id, 'config' => self::publicConfig(),
            'domain' => 'https://old.example.com', 'domain_client_sk' => 'old-secret', 'sync_logistics' => true,
        ]);

        $this->assertSame(self::publicConfig(), $data['config']);
        $this->assertNull($data['domain']);
        $this->assertNull($data['domain_client_sk']);
        $this->assertFalse($data['sync_logistics']);
        $this->assertSame(78, strlen(CryptoPublicKey::payload(self::XPUB)));
    }

    #[DataProvider('invalidConfigurations')]
    public function test_rejects_secret_fields_bad_assets_and_incompatible_recovery_information(array $changes, string $field): void
    {
        $this->expectException(ValidationException::class);
        try {
            app(CryptoPaymentConfigurationService::class)->validateConfig(array_replace(self::publicConfig(), $changes));
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            throw $exception;
        }
    }

    public static function invalidConfigurations(): array
    {
        return [
            'private field' => [['private_key' => 'must-never-save'], 'config'],
            'mnemonic' => [['mnemonic' => 'must-never-save'], 'config'],
            'provider credential' => [['api_key' => 'must-never-save'], 'config'],
            'unsupported network' => [['network' => 'unsupported'], 'config.extended_public_key'],
            'private extended key' => [['extended_public_key' => 'xprv'.str_repeat('a', 107)], 'config.extended_public_key'],
            'wrong profile' => [['derivation_profile' => 'arbitrary'], 'config.extended_public_key'],
            'same recovery addresses' => [['recovery_address_1' => '0x'.str_repeat('a', 40)], 'config.recovery_address_1'],
            'native contract' => [['contract_address' => '0x'.str_repeat('c', 40)], 'config.contract_address'],
        ];
    }

    public function test_detects_corrupted_base58check_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CryptoPublicKey::payload(substr(self::XPUB, 0, -1).'1');
    }

    public function test_mainnet_usdt_contract_is_fixed_and_test_contract_requires_operator_allowlist(): void
    {
        $service = app(CryptoPaymentConfigurationService::class);
        $config = array_replace(self::publicConfig(), ['network' => 'ethereum-mainnet', 'asset' => 'USDT', 'contract_address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7']);
        $this->assertSame($config, $service->validateConfig($config));
        config(['services.tatum.test_tokens.ethereum-sepolia' => '0x'.str_repeat('c', 40)]);
        $config['network'] = 'ethereum-sepolia';
        $config['contract_address'] = '0x'.str_repeat('c', 40);
        $this->assertSame($config, $service->validateConfig($config));
    }

    public function test_fake_usdt_contract_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(CryptoPaymentConfigurationService::class)->validateConfig(array_replace(self::publicConfig(), ['network' => 'ethereum-mainnet', 'asset' => 'USDT', 'contract_address' => '0x'.str_repeat('d', 40)]));
    }

    public function test_crypto_configuration_cannot_use_a_fiat_template(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('fiat-map');
        $this->expectException(ValidationException::class);
        app(CryptoPaymentConfigurationService::class)->validate(['payment_type' => 'crypto', 'config_map_id' => $method->config_map_id, 'config' => self::publicConfig()]);
    }

    public function test_seed_is_repeatable_and_never_overwrites_saved_template_changes(): void
    {
        $this->seed(CryptoPaymentTemplatesSeeder::class);
        $map = PaymentMethodConfigMap::query()->where('name', 'Crypto / Ethereum')->sole();
        $map->update(['fields' => [['key' => 'custom', 'label' => 'Custom']], 'is_active' => false]);

        $this->seed(CryptoPaymentTemplatesSeeder::class);

        $this->assertDatabaseCount('payment_method_config_maps', 5);
        $this->assertFalse($map->refresh()->is_active);
        $this->assertSame([['key' => 'custom', 'label' => 'Custom']], $map->fields);
    }
}
