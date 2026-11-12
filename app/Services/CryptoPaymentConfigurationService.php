<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoPublicKey;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CryptoPaymentConfigurationService
{
    public function __construct(private readonly CryptoAssetRegistry $assets) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data, ?PaymentMethod $record = null): array
    {
        $type = $data['payment_type'] ?? $record?->payment_type ?? 'fiat';
        Validator::make(['payment_type' => $type], ['payment_type' => ['required', Rule::in(['fiat', 'crypto'])]])->validate();
        if ($record && $record->payment_type !== $type) {
            throw ValidationException::withMessages(['payment_type' => __('crypto.validation.type_immutable')]);
        }
        $data['payment_type'] = $type;
        $mapId = $data['config_map_id'] ?? $record?->config_map_id;
        $map = $mapId ? PaymentMethodConfigMap::query()->find($mapId) : null;
        if (($map && ($map->payment_type !== $type || (! $map->is_active && (int) $record?->config_map_id !== (int) $map->id)))
            || ($mapId && ! $map) || ($type === 'crypto' && ! $map)) {
            throw ValidationException::withMessages(['config_map_id' => __('crypto.validation.template_mismatch')]);
        }
        if ($type === 'fiat') {
            return $data;
        }
        $config = $data['config'] ?? $record?->config ?? [];
        if (! is_array($config)) {
            throw ValidationException::withMessages(['config' => __('crypto.validation.public_only')]);
        }
        $data['config'] = $this->validateConfig($config);
        foreach ($map->fields ?? [] as $field) {
            if (($field['type'] ?? 'text') === 'select' && is_array($field['options'] ?? null)) {
                $key = $field['key'];
                Validator::make($data, ['config.'.$key => [Rule::in(array_keys($field['options']))]])->validate();
            }
        }
        foreach (['domain', 'domain_client_id', 'domain_client_sk', 'payment_config_id', 'invoice_prefix', 'virtual_product_prefix'] as $field) {
            $data[$field] = null;
        }
        $data['sync_logistics'] = false;
        $data['allow_returned_source'] = false;

        return $data;
    }

    /** @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function validateConfig(array $config): array
    {
        if (($config['config_version'] ?? null) === 1) {
            $config['config_version'] = '1';
        }
        $allowed = ['config_version', 'network', 'asset', 'extended_public_key', 'derivation_profile', 'recovery_address_0', 'recovery_address_1', 'confirmation_policy', 'contract_address'];
        if (array_diff(array_keys($config), $allowed)) {
            throw ValidationException::withMessages(['config' => __('crypto.validation.public_only')]);
        }
        Validator::make(['config' => $config], [
            'config.config_version' => ['required', 'string', Rule::in(['1'])],
            'config.network' => ['required', 'string', 'max:48'],
            'config.asset' => ['required', 'string', 'max:16'],
            'config.extended_public_key' => ['required', 'string', 'max:111'],
            'config.derivation_profile' => ['required', 'string', 'max:64'],
            'config.recovery_address_0' => ['required', 'string', 'max:128'],
            'config.recovery_address_1' => ['required', 'string', 'max:128', 'different:config.recovery_address_0'],
            'config.confirmation_policy' => ['required', 'string', 'max:64'],
            'config.contract_address' => ['nullable', 'string', 'max:128'],
        ])->validate();
        try {
            $asset = $this->assets->get($config['network'].':'.$config['asset']);
            CryptoPublicKey::payload($config['extended_public_key']);
            $family = explode('-', $asset['network'], 2)[0];
            if ($config['derivation_profile'] !== 'tatum-v3-'.$family.'-v1'
                || $config['confirmation_policy'] !== $family.'-configured-v1') {
                throw new InvalidArgumentException;
            }
            foreach (['recovery_address_0', 'recovery_address_1'] as $field) {
                $this->assets->addressKey($asset['network'], $config[$field]);
            }
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['config.extended_public_key' => __('crypto.validation.invalid_public_config')]);
        }
        if ($asset['asset'] === 'USDT') {
            $expected = match ($asset['network']) {
                'ethereum-mainnet' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
                'tron-mainnet' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                default => config('services.tatum.test_tokens.'.$asset['network']),
            };
            $actual = $config['contract_address'] ?? '';
            $equal = is_string($expected) && $expected !== '' && ($family === 'ethereum' ? strtolower($actual) === strtolower($expected) : $actual === $expected);
            if (! $equal) {
                throw ValidationException::withMessages(['config.contract_address' => __('crypto.validation.contract_mismatch')]);
            }
        } elseif (filled($config['contract_address'] ?? null)) {
            throw ValidationException::withMessages(['config.contract_address' => __('crypto.validation.native_contract')]);
        }

        return $config;
    }
}
