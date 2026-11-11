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

class PaymentMethodMerchantAssignmentService
{
    public static function canManage(?User $actor, PaymentMethod $method): bool
    {
        $actor = $actor === null ? null : User::query()->find($actor->getKey());
        $method = PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)->find($method->getKey());

        return $actor !== null && $actor->status && $actor->isPlatformStaff()
            && $actor->can(Permissions::PAYMENT_METHODS_MANAGE)
            && $method !== null && $method->isSystemLevel()
            && ($actor->is_super_admin || $method->owner_id === $actor->id);
    }

    /** @return Collection<int, Merchant> */
    public function merchants(User $actor, PaymentMethod $method): Collection
    {
        if (! self::canManage($actor, $method)) {
            throw new AuthorizationException;
        }

        $method = PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)->findOrFail($method->getKey());
        $owner = $method->owner;

        return Merchant::query()->where('status', true)
            ->where(function (Builder $query) use ($method, $owner): void {
                if ($method->owner_id === null) {
                    $query->whereNull('owner_id');
                } else {
                    $query->where('owner_id', $method->owner_id)
                        ->when($owner?->is_super_admin, fn (Builder $direct): Builder => $direct->orWhereNull('owner_id'));
                }
            })
            ->withExists(['assignedPaymentMethods as is_assigned' => fn (Builder $assigned): Builder => $assigned
                ->withoutGlobalScope(MerchantScope::class)->where('payment_methods.id', $method->id)])
            ->orderBy('name')->orderBy('id')->get();
    }

    public function setAssignment(?User $actor, PaymentMethod $method, int $merchantId, bool $authorized): void
    {
        DB::transaction(function () use ($actor, $method, $merchantId, $authorized): void {
            $merchant = Merchant::query()->whereKey($merchantId)->lockForUpdate()->first();
            $method = PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)
                ->whereKey($method->getKey())->lockForUpdate()->first();

            if ($merchant === null || ! $merchant->status || $method === null
                || ! self::canManage($actor, $method) || ! $method->canAssignToMerchant($merchant)) {
                throw new AuthorizationException;
            }

            if ($authorized) {
                $method->assignedMerchants()->syncWithoutDetaching([$merchant->id]);

                return;
            }

            $method->assignedMerchants()->detach($merchant->id);
            $groupIds = PaymentGroup::query()->withoutGlobalScope(MerchantScope::class)->withTrashed()
                ->where('merchant_id', $merchant->id)->pluck('id');
            $method->paymentGroups()->detach($groupIds);
            MerchantPaymentSetting::query()->forMerchant($merchant->id)->where('payment_method_id', $method->id)->delete();
        }, 3);
    }
}
