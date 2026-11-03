<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MerchantPaymentSettingsService
{
    public function initializeForAssignment(int $merchantId, int $paymentMethodId): ?MerchantPaymentSetting
    {
        return DB::transaction(function () use ($merchantId, $paymentMethodId): ?MerchantPaymentSetting {
            $merchant = Merchant::query()->whereKey($merchantId)->lockForUpdate()->first();

            if ($merchant === null || ! PaymentMethod::query()->forMerchant($merchantId)
                ->whereKey($paymentMethodId)->whereNull('payment_methods.merchant_id')->exists()) {
                return null;
            }

            $defaults = [];

            foreach (MerchantPaymentSetting::FINANCIAL_FIELDS as $field) {
                $defaults[$field] = $merchant->getAttribute('default_'.$field);
            }

            $defaults['settlement_mode'] = $merchant->settlement_mode ?? 'immediate';
            $defaults['settlement_days'] = (int) $merchant->settlement_days;

            return MerchantPaymentSetting::query()->forMerchant($merchantId)->firstOrCreate(
                ['merchant_id' => $merchantId, 'payment_method_id' => $paymentMethodId],
                $defaults,
            );
        });
    }

    public static function canManage(?User $actor, Merchant $merchant): bool
    {
        return $actor !== null && ($actor->is_super_admin || ($actor->isMerchantManager()
            && Merchant::query()->whereKey($merchant->id)->where('owner_id', $actor->id)->exists()));
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, Merchant $merchant, int $paymentMethodId, array $data): MerchantPaymentSetting
    {
        if (! self::canManage($actor, $merchant)) {
            throw new AuthorizationException;
        }

        $values = Arr::only($data, MerchantPaymentSetting::CONFIGURATION_FIELDS);
        $values = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $values);
        $values = Validator::make($values, [
            'fee_percent' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,99.9999'],
            'fee_fixed' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,9999999999999.99'],
            'refund_fee' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,9999999999999.99'],
            'chargeback_fee' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,9999999999999.99'],
            'reserve_percent' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,100'],
            'reserve_days' => ['nullable', 'integer', 'between:0,36500'],
            'settlement_mode' => ['nullable', 'in:immediate,shipped'],
            'settlement_days' => ['nullable', 'integer', 'between:0,36500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $merchant, $paymentMethodId, $values): MerchantPaymentSetting {
            $lockedMerchant = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            if (! self::canManage($actor, $lockedMerchant)) {
                throw new AuthorizationException;
            }

            if (! PaymentMethod::query()->forMerchant($merchant->id)->whereKey($paymentMethodId)->exists()) {
                throw ValidationException::withMessages(['payment_method_id' => __('payment_finance.unavailable_method')]);
            }

            return MerchantPaymentSetting::query()->forMerchant($merchant->id)->updateOrCreate(
                ['merchant_id' => $merchant->id, 'payment_method_id' => $paymentMethodId],
                $values,
            );
        });
    }

    public function delete(User $actor, Merchant $merchant, MerchantPaymentSetting $setting): bool
    {
        if (! self::canManage($actor, $merchant) || (int) $setting->merchant_id !== (int) $merchant->id) {
            throw new AuthorizationException;
        }

        return (bool) $setting->delete();
    }
}
