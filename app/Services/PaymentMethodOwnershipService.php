<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodOwnershipService
{
    public function changeOwner(PaymentMethod $paymentMethod, User $operator, int $ownerId): PaymentMethod
    {
        $operator = User::query()->find($operator->getKey());

        if (! $operator?->is_super_admin || ! $operator->status) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($paymentMethod, $ownerId): PaymentMethod {
            $record = PaymentMethod::query()
                ->withoutGlobalScope(MerchantScope::class)
                ->lockForUpdate()
                ->findOrFail($paymentMethod->getKey());

            if (! $record->isSystemLevel()) {
                throw new AuthorizationException;
            }

            $owner = User::query()
                ->whereNull('merchant_id')
                ->where('status', true)
                ->find($ownerId);

            if (! $owner) {
                throw ValidationException::withMessages([
                    'owner_id' => __('admin.payment_method.validation.owner_invalid'),
                ]);
            }

            $record->owner()->associate($owner);

            foreach ($record->assignedMerchants()->get() as $merchant) {
                if (! $record->canAssignToMerchant($merchant)) {
                    throw new AuthorizationException;
                }
            }

            $record->save();

            return $record;
        });
    }
}
