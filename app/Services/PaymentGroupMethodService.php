<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Support\Permissions;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaymentGroupMethodService
{
    public static function canManage(?User $actor, PaymentGroup $group): bool
    {
        return self::authorizedGroup($actor, $group) !== null;
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function availableForMerchant(?User $actor, ?int $merchantId): Collection
    {
        $actor = self::authorizedActor($actor);
        $merchant = $merchantId === null ? null : Merchant::query()->find($merchantId);

        if ($actor === null || $merchant === null || ! self::canAccessMerchant($actor, $merchant)) {
            return new Collection;
        }

        $methods = PaymentMethod::query()
            ->forMerchant((int) $merchant->id)
            ->select(['id', 'method_name', 'method_code', 'is_active'])
            ->orderBy('method_name')
            ->orderBy('payment_methods.id')
            ->get();

        foreach ($methods as $method) {
            $method->setAttribute('is_in_group', false);
            $method->setAttribute('group_weight', 100);
        }

        return $methods;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{id: int|string, priority: int|string}>  $members
     */
    public function createGroup(User $actor, array $data, array $members): PaymentGroup
    {
        if (self::authorizedActor($actor) === null) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(['merchant_id' => $data['merchant_id'] ?? null, 'paymentMethods' => $members], [
            'merchant_id' => ['required', 'integer', 'min:1'],
            'paymentMethods' => ['array', 'list'],
            'paymentMethods.*' => ['required', 'array:id,priority'],
            'paymentMethods.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'paymentMethods.*.priority' => ['required', 'integer', 'between:1,2147483647'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $validated): PaymentGroup {
            $merchant = Merchant::query()->whereKey($validated['merchant_id'])->lockForUpdate()->first();
            $actor = self::authorizedActor($actor) ?? throw new AuthorizationException;

            if ($merchant === null) {
                throw ValidationException::withMessages([
                    'merchant_id' => __('validation.exists', ['attribute' => __('admin.payment_group.fields.merchant')]),
                ]);
            }

            if (! self::canAccessMerchant($actor, $merchant)) {
                throw new AuthorizationException;
            }

            $members = $validated['paymentMethods'];
            $methodIds = array_map(fn (array $member): int => (int) $member['id'], $members);
            $availableIds = PaymentMethod::query()
                ->forMerchant((int) $merchant->id)
                ->whereKey($methodIds)
                ->orderBy('payment_methods.id')
                ->lockForUpdate()
                ->pluck('payment_methods.id');

            if ($availableIds->count() !== count($methodIds)) {
                throw ValidationException::withMessages([
                    'paymentMethods' => __('validation.exists', ['attribute' => __('admin.payment_group.fields.payment_methods')]),
                ]);
            }

            $attributes = Arr::only($data, ['group_key', 'group_name', 'logo_path', 'description', 'timezone', 'is_active']);
            $attributes['merchant_id'] = $merchant->id;
            $group = PaymentGroup::query()->create($attributes);
            $weights = [];

            foreach ($members as $member) {
                $weights[(int) $member['id']] = ['priority' => (int) $member['priority']];
            }

            $group->paymentMethods()->attach($weights);

            return $group;
        }, 3);
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function methods(User $actor, PaymentGroup $group): Collection
    {
        $group = self::authorizedGroup($actor, $group) ?? throw new AuthorizationException;
        $weights = $group->paymentMethods()
            ->forMerchant((int) $group->merchant_id)
            ->withTrashed()
            ->pluck('payment_group_methods.priority', 'payment_methods.id');

        $methods = PaymentMethod::query()
            ->forMerchant((int) $group->merchant_id)
            ->withTrashed()
            ->select(['id', 'method_name', 'method_code', 'is_active', 'deleted_at'])
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('payment_methods.deleted_at')
                ->orWhereIn('payment_methods.id', $weights->keys()))
            ->orderBy('method_name')
            ->orderBy('payment_methods.id')
            ->get();

        foreach ($methods as $method) {
            $method->setAttribute('is_in_group', $weights->has($method->id));
            $method->setAttribute('group_weight', (int) $weights->get($method->id, 100));
        }

        return $methods;
    }

    public function setMembership(User $actor, PaymentGroup $group, int $methodId, bool $included): void
    {
        $this->withLockedGroup($actor, $group, function (PaymentGroup $record, Merchant $merchant) use ($methodId, $included): void {
            $method = PaymentMethod::query()
                ->forMerchant((int) $merchant->id)
                ->when(! $included, fn (Builder $query): Builder => $query->withTrashed())
                ->lockForUpdate()
                ->find($methodId);

            if ($method === null) {
                throw new AuthorizationException;
            }

            if (! $included) {
                $record->paymentMethods()->detach($method->id);

                return;
            }

            $alreadyIncluded = $record->paymentMethods()
                ->withoutGlobalScope(MerchantScope::class)
                ->whereKey($method->id)
                ->exists();

            if (! $alreadyIncluded) {
                $record->paymentMethods()->attach($method->id, ['priority' => 100]);
            }
        });
    }

    public function saveWeight(User $actor, PaymentGroup $group, int $methodId, mixed $weight): void
    {
        $this->withLockedGroup($actor, $group, function (PaymentGroup $record, Merchant $merchant) use ($methodId, $weight): void {
            $method = $record->paymentMethods()
                ->forMerchant((int) $merchant->id)
                ->lockForUpdate()
                ->find($methodId);

            if ($method === null) {
                throw new AuthorizationException;
            }

            $data = Validator::make(['priority' => $weight], [
                'priority' => ['required', 'integer', 'between:1,2147483647'],
            ])->validate();

            $record->paymentMethods()->updateExistingPivot($method->id, ['priority' => (int) $data['priority']]);
        });
    }

    /**
     * @param  Closure(PaymentGroup, Merchant): void  $operation
     */
    private function withLockedGroup(User $actor, PaymentGroup $group, Closure $operation): void
    {
        $merchantId = PaymentGroup::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->whereKey($group->getKey())
            ->value('merchant_id');

        if ($merchantId === null) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $group, $merchantId, $operation): void {
            $merchant = Merchant::query()->whereKey($merchantId)->lockForUpdate()->first();
            $record = PaymentGroup::query()
                ->withoutGlobalScope(MerchantScope::class)
                ->whereKey($group->getKey())
                ->lockForUpdate()
                ->first();
            $actor = self::authorizedActor($actor);

            if ($merchant === null || $record === null || (int) $record->merchant_id !== (int) $merchantId
                || $actor === null || ! self::canAccessMerchant($actor, $merchant)) {
                throw new AuthorizationException;
            }

            $operation($record, $merchant);
        }, 3);
    }

    private static function authorizedGroup(?User $actor, PaymentGroup $group): ?PaymentGroup
    {
        $actor = self::authorizedActor($actor);

        if ($actor === null) {
            return null;
        }

        $record = PaymentGroup::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->find($group->getKey());
        $merchant = $record === null ? null : Merchant::query()->find($record->merchant_id);

        return $merchant !== null && self::canAccessMerchant($actor, $merchant) ? $record : null;
    }

    private static function authorizedActor(?User $actor): ?User
    {
        $actor = $actor === null ? null : User::query()->find($actor->getKey());

        return $actor !== null && $actor->status && $actor->can(Permissions::PAYMENT_GROUPS_MANAGE) ? $actor : null;
    }

    private static function canAccessMerchant(User $actor, Merchant $merchant): bool
    {
        return $actor->is_super_admin
            || ($actor->isMerchantManager() && (int) $merchant->owner_id === (int) $actor->id)
            || (! $actor->isPlatformStaff() && (int) $actor->merchant_id === (int) $merchant->id);
    }
}
