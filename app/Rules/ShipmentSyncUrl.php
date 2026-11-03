<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ShipmentSyncUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('admin.application.validation.shipment_sync_url'));
        }
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2048 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($value);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || ! str_contains($host, '.')) {
            return false;
        }

        return ! filter_var($host, FILTER_VALIDATE_IP)
            || (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
