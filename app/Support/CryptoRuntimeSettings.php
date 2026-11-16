<?php

namespace App\Support;

use App\Models\SystemConfig;
use InvalidArgumentException;

/** Database-backed runtime settings; never loads credentials from Laravel's config cache. */
final class CryptoRuntimeSettings
{
    /** @return array<string, array{type: string, default: ?string}> */
    public static function definitions(): array
    {
        $settings = [];
        foreach (['checkout_enabled', 'subscriptions_enabled', 'verification_enabled', 'history_enabled', 'settlement_enabled'] as $name) {
            $settings[$name] = ['type' => 'boolean', 'default' => 'false'];
        }
        foreach (['api_key', 'testnet_key', 'mainnet_hmac_secret', 'testnet_hmac_secret'] as $name) {
            $settings[$name] = ['type' => 'secret', 'default' => null];
        }
        $settings['webhook_base_url'] = ['type' => 'string', 'default' => null];
        $settings['history_max_head_age_seconds'] = ['type' => 'number', 'default' => null];
        $settings['timeout'] = ['type' => 'number', 'default' => '10'];
        $settings['connect_timeout'] = ['type' => 'number', 'default' => '3'];
        foreach ([
            'evm' => ['ethereum-mainnet', 'ethereum-sepolia'],
            'utxo' => ['bitcoin-mainnet', 'bitcoin-testnet', 'litecoin-mainnet', 'litecoin-testnet'],
            'tron' => ['tron-mainnet', 'tron-shasta'],
        ] as $family => $networks) {
            foreach ($networks as $network) {
                $settings[$family.'_min_confirmations.'.$network] = ['type' => 'number', 'default' => null];
                if ($family !== 'evm') {
                    $settings['genesis_hashes.'.$network] = ['type' => 'string', 'default' => null];
                }
            }
        }
        foreach (['ethereum-sepolia', 'tron-shasta'] as $network) {
            $settings['test_tokens.'.$network] = ['type' => 'string', 'default' => null];
        }

        return $settings;
    }

    public static function name(?string $key): ?string
    {
        $name = str_starts_with($key ?? '', 'crypto.') ? substr($key, 7) : '';

        return isset(self::definitions()[$name]) ? $name : null;
    }

    public static function isSecret(?string $key): bool
    {
        return (self::definitions()[self::name($key) ?? '']['type'] ?? null) === 'secret';
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        $definition = self::definitions()[$name] ?? throw new InvalidArgumentException('Unknown crypto runtime setting.');
        $default ??= $definition['default'];
        if ($definition['type'] === 'secret') {
            return SystemConfig::secret('crypto.'.$name, $default);
        }
        $value = SystemConfig::get('crypto.'.$name, $default);

        return $definition['type'] === 'boolean' ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value;
    }

    /** Serialize subscription allocation against credential edits in the same database transaction. */
    public static function lockCredentials(string $environment): void
    {
        SystemConfig::query()->whereIn('config_key', [
            'crypto.'.($environment === 'mainnet' ? 'api_key' : 'testnet_key'),
            'crypto.'.$environment.'_hmac_secret',
        ])->orderBy('id')->lockForUpdate()->get();
    }
}
