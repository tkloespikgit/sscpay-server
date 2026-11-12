<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class CryptoFinanceAccess
{
    /** Read current account attributes and role assignments, never the hydrated session copy. */
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? User::query()->where('status', true)->find($user->id) : null;
    }

    public static function allowed(?User $user, string $permission, ?Merchant $merchant = null, bool $platformOnly = false): bool
    {
        if (! $user?->status || ! $user->can(Permissions::CRYPTO_FINANCE_VIEW) || ! $user->can($permission)
            || ($platformOnly && ! $user->isPlatformStaff())) {
            return false;
        }
        if ($merchant === null) {
            return $user->isPlatformStaff() || $user->merchant()->where('status', true)->exists();
        }

        return $user->is_super_admin
            || ($user->isMerchantManager() ? (int) $merchant->owner_id === (int) $user->id
                : (int) $user->merchant_id === (int) $merchant->id && $merchant->status);
    }

    public static function authorize(User $user, Merchant $merchant, string $permission, bool $platformOnly = false): void
    {
        if (! self::allowed($user, $permission, $merchant, $platformOnly)) {
            throw new AuthorizationException;
        }
    }

    /** All crypto resources explicitly deny anonymous/observer access and use current ownership. */
    public static function scope(Builder $query): Builder
    {
        $query->withoutGlobalScope(MerchantScope::class);
        $user = self::user();
        if (! self::allowed($user, Permissions::CRYPTO_FINANCE_VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->is_super_admin) {
            return $query;
        }
        $merchants = Merchant::query()->select('id');
        if ($user->isMerchantManager()) {
            $merchants->where('owner_id', $user->id);
        } else {
            $merchants->whereKey($user->merchant_id)->where('status', true);
        }

        return $query->whereIn($query->getModel()->qualifyColumn('merchant_id'), $merchants);
    }
}
