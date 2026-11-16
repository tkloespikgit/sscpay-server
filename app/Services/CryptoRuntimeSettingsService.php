<?php

namespace App\Services;

use App\Models\CryptoAddressMonitor;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoRuntimeSettings;
use App\Support\CryptoTronAddress;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CryptoRuntimeSettingsService
{
    /** @param array<string, mixed> $values */
    public function save(User $actor, SystemConfig $record, #[\SensitiveParameter] array $values, string $statePath = 'data'): SystemConfig
    {
        abort_unless(User::query()->whereKey($actor->id)->where('status', true)->where('is_super_admin', true)->exists(), 403);

        return DB::transaction(function () use ($record, $values, $statePath): SystemConfig {
            $name = CryptoRuntimeSettings::name($record->config_key);
            if (CryptoRuntimeSettings::isSecret($record->config_key)) {
                // Lock both environments too: an operator may have used the same API key in both.
                SystemConfig::query()->whereIn('config_key', ['crypto.api_key', 'crypto.testnet_key',
                    'crypto.mainnet_hmac_secret', 'crypto.testnet_hmac_secret'])->orderBy('id')->lockForUpdate()->get();
            }
            $fresh = SystemConfig::query()->lockForUpdate()->findOrFail($record->id);
            $secret = $fresh->value_type === SystemConfig::TYPE_SECRET;
            $attribute = $secret ? 'secret_value' : 'config_value';
            if (! array_key_exists($attribute, $values)) {
                return $fresh;
            }
            $value = $values[$attribute];
            $type = $fresh->value_type;
            $field = $statePath.'.value_'.$type;
            if ($name !== null) {
                $this->validate($name, $value, $field);
            }
            if ($secret && $fresh->secret_value !== $value) {
                $environment = in_array($name, ['api_key', 'mainnet_hmac_secret'], true) ? 'mainnet' : 'testnet';
                $key = CryptoRuntimeSettings::get($environment === 'mainnet' ? 'api_key' : 'testnet_key');
                $inUse = CryptoAddressMonitor::query()->withoutGlobalScopes()->where('status', '!=', 'cold')
                    ->where(function ($query) use ($environment, $key, $name, $value): void {
                        $query->where('environment', $environment);
                        if (filled($key)) {
                            $query->orWhere('credential_fingerprint', hash('sha256', $key));
                        }
                        if (in_array($name, ['api_key', 'testnet_key'], true) && filled($value)) {
                            $query->orWhere('credential_fingerprint', hash('sha256', $value));
                        }
                    })->lockForUpdate()->first(['id']) !== null;
                if ($inUse) {
                    throw ValidationException::withMessages([$field => __('crypto.settings.credentials_in_use')]);
                }
            }
            $fresh->update([$attribute => $value]);

            return $fresh;
        }, 3);
    }

    public function validate(string $name, #[\SensitiveParameter] mixed $value, string $field): void
    {
        $definition = CryptoRuntimeSettings::definitions()[$name];
        $rules = match (true) {
            $definition['type'] === 'boolean' => ['required', 'in:true,false'],
            str_ends_with($name, '_hmac_secret') => ['nullable', 'string', 'min:16', 'max:100', 'regex:/^[^\s\x00-\x1f]+$/D'],
            $definition['type'] === 'secret' => ['nullable', 'string', 'max:4096', 'regex:/^[^\s\x00-\x1f]+$/D'],
            str_contains($name, '_min_confirmations.') => ['nullable', 'integer', 'min:1', 'max:100000'],
            str_starts_with($name, 'genesis_hashes.') => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/D'],
            $name === 'history_max_head_age_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            in_array($name, ['timeout', 'connect_timeout'], true) => ['required', 'integer', 'min:1', 'max:60'],
            default => ['nullable', 'string', 'max:300'],
        };
        $data = [];
        Arr::set($data, $field, $value);
        Validator::make($data, [$field => $rules])->validate();
        if (blank($value)) {
            return;
        }
        if ($name === 'webhook_base_url') {
            $parts = parse_url($value);
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ! in_array($parts['path'] ?? '', ['', '/'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages([$field => __('crypto.settings.https_origin_required')]);
            }
        }
        if (str_starts_with($name, 'test_tokens.')) {
            try {
                if ($name === 'test_tokens.tron-shasta') {
                    CryptoTronAddress::hex($value);
                } else {
                    (new CryptoAssetRegistry)->addressKey('ethereum-sepolia', $value);
                }
            } catch (InvalidArgumentException|DomainException) {
                throw ValidationException::withMessages([$field => __('crypto.settings.invalid_test_contract')]);
            }
        }
    }
}
