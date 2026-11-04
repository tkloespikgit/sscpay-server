<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MerchantPaymentMethodAssignmentService
{
    public static function canManage(?User $actor, Merchant $merchant): bool
    {
        return self::authorizedActor($actor, $merchant) !== null;
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function methods(User $actor, Merchant $merchant): Collection
    {
        $merchant = Merchant::query()->findOrFail($merchant->getKey());
        $actor = self::authorizedActor($actor, $merchant) ?? throw new AuthorizationException;

        $methods = PaymentMethod::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->select(['id', 'owner_id', 'merchant_id', 'method_name', 'method_code', 'is_active'])
            ->where(fn (Builder $query): Builder => $query
                ->where('payment_methods.merchant_id', $merchant->id)
                ->orWhere(fn (Builder $shared): Builder => $shared
                    ->whereNull('payment_methods.merchant_id')
                    ->where('payment_methods.owner_id', $merchant->owner_id ?? $actor->id)))
            ->withExists([
                'assignedMerchants as is_assigned' => fn (Builder $assigned): Builder => $assigned
                    ->where('merchants.id', $merchant->id),
            ])
            ->orderBy('method_name')
            ->orderBy('payment_methods.id')
            ->get();

        foreach ($methods as $method) {
            if (! $method->isSystemLevel()) {
                $method->setAttribute('is_assigned', true);
            }
        }

        return $methods;
    }

    public function setAssignment(User $actor, Merchant $merchant, int $paymentMethodId, bool $authorized): void
    {
        DB::transaction(function () use ($actor, $merchant, $paymentMethodId, $authorized): void {
            $merchant = Merchant::query()->whereKey($merchant->getKey())->lockForUpdate()->first();

            if ($merchant === null) {
                throw new AuthorizationException;
            }

            $actor = self::authorizedActor($actor, $merchant) ?? throw new AuthorizationException;
            $method = PaymentMethod::query()
                ->withoutGlobalScope(MerchantScope::class)
                ->lockForUpdate()
                ->find($paymentMethodId);

            if ($method === null || ! $method->isSystemLevel()
                || $method->owner_id !== ($merchant->owner_id ?? $actor->id)) {
                throw new AuthorizationException;
            }

            if ($authorized) {
                $merchant->assignedPaymentMethods()->syncWithoutDetaching([$method->id]);

                return;
            }

            $merchant->assignedPaymentMethods()->detach($method->id);

            $groupIds = PaymentGroup::query()
                ->withoutGlobalScope(MerchantScope::class)
                ->withTrashed()
                ->where('merchant_id', $merchant->id)
                ->pluck('id');

            if ($groupIds->isNotEmpty()) {
                $method->paymentGroups()->detach($groupIds);
            }

            MerchantPaymentSetting::query()
                ->forMerchant($merchant->id)
                ->where('payment_method_id', $method->id)
                ->delete();
        }, 3);
    }

    private static function authorizedActor(?User $actor, Merchant $merchant): ?User
    {
        $actor = $actor === null ? null : User::query()->find($actor->getKey());

        if ($actor === null || ! $actor->status || ! $actor->isPlatformStaff()
            || ! $actor->can(Permissions::MERCHANTS_MANAGE)
            || ! $actor->can(Permissions::PAYMENT_METHODS_MANAGE)) {
            return null;
        }

        $canManageMerchant = Merchant::query()->whereKey($merchant->getKey())
            ->when(! $actor->is_super_admin, fn (Builder $query): Builder => $query->where('owner_id', $actor->id))
            ->exists();

        return $canManageMerchant ? $actor : null;
    }
}
