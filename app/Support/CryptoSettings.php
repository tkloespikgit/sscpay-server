<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use stdClass;

final class CryptoSettings
{
    public const SUBSCRIPTION_OVERRIDES = 'crypto.subscription_ttl_overrides';

    public static function isObjectKey(?string $key): bool
    {
        return $key === self::SUBSCRIPTION_OVERRIDES;
    }

    /** @return list<string> */
    public static function numberRules(?string $key): array
    {
        return match ($key) {
            'crypto.quote_markup_percent' => ['numeric', 'min:0', 'max:100', 'regex:/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,4})?$/D'],
            'crypto.quote_ttl_seconds', 'crypto.max_market_price_age_seconds', 'crypto.subscription_ttl_seconds',
            'crypto.history_scan_interval_seconds' => ['integer', 'min:1', 'max:9999999'],
            'crypto.subscription_reopen_max_renewals' => ['integer', 'min:0', 'max:999999'],
            default => [],
        };
    }

    public static function validatedObject(mixed $value, string $statePath): string
    {
        $field = $statePath.'.value_json_object';
        $data = [];
        Arr::set($data, $field, $value);
        Validator::make($data, [$field => ['bail', 'required', 'string', 'json',
            function (string $attribute, mixed $value, Closure $fail): void {
                $decoded = json_decode($value);
                if (! $decoded instanceof stdClass) {
                    $fail(__('crypto.settings.invalid_overrides'));

                    return;
                }
                foreach (get_object_vars($decoded) as $assetKey => $seconds) {
                    try {
                        (new CryptoAssetRegistry)->get($assetKey);
                    } catch (InvalidArgumentException) {
                        $fail(__('crypto.settings.invalid_overrides'));

                        return;
                    }
                    if ((! is_int($seconds) && ! is_string($seconds)) || ! preg_match('/^[1-9][0-9]{0,6}$/D', (string) $seconds)) {
                        $fail(__('crypto.settings.invalid_overrides'));

                        return;
                    }
                }
            },
        ]])->validate();

        return json_encode(json_decode($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
