<?php

namespace App\Services\Checkout;

use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * 收款链接后台的支付组点选。
 *
 * 面板里每一行是 {id, is_default}。落库仍用原来的两列：
 * 默认组写入 payment_group_id；其余组写入 payment_group_ids。
 * 只选中默认组时 payment_group_ids 为空，落地页不展示选择区。
 */
class CheckoutLinkGroupSelection
{
    /**
     * @param  list<int>  $selectedIds
     * @return Collection<int, PaymentGroup>
     */
    public function panelGroups(?User $actor, ?int $merchantId, array $selectedIds): Collection
    {
        if ($this->merchantMessage($actor, (int) $merchantId) !== null) {
            return new Collection;
        }

        $selectedIds = array_values(array_filter(array_map(intval(...), $selectedIds)));

        return PaymentGroup::query()
            ->withTrashed()
            ->forMerchant((int) $merchantId)
            ->where(function (Builder $query) use ($selectedIds): void {
                $query->where(function (Builder $available): void {
                    $available->where('is_active', true)->whereNull('deleted_at');
                });

                if ($selectedIds !== []) {
                    $query->orWhereIn('id', $selectedIds);
                }
            })
            ->orderBy('group_name')
            ->orderBy('id')
            ->get(['id', 'group_name', 'group_key', 'is_active', 'deleted_at']);
    }

    /**
     * @return list<array{id: int, is_default: bool}>
     */
    public function fromStored(mixed $defaultId, mixed $extraIds): array
    {
        $defaultId = (int) $defaultId;
        $ids = [];

        if ($defaultId > 0) {
            $ids[] = $defaultId;
        }

        foreach (array_map(intval(...), (array) $extraIds) as $id) {
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return array_map(fn (int $id): array => [
            'id' => $id,
            'is_default' => $id === $defaultId,
        ], $ids);
    }

    /**
     * @param  list<array<string, mixed>>  $selection
     * @return array{payment_group_id: int, payment_group_ids: list<int>|null}
     */
    public function columns(array $selection): array
    {
        $defaultId = 0;
        $extras = [];

        foreach ($selection as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($this->isDefault($row['is_default'] ?? false)) {
                $defaultId = $id;
            } elseif ($id > 0) {
                $extras[] = $id;
            }
        }

        return [
            'payment_group_id' => $defaultId,
            'payment_group_ids' => $extras === [] ? null : array_values($extras),
        ];
    }

    public function message(?User $actor, ?int $merchantId, mixed $selection): ?string
    {
        $merchantMessage = $this->merchantMessage($actor, (int) $merchantId);
        if ($merchantMessage !== null) {
            return $merchantMessage;
        }

        if (! is_array($selection) || $selection === []) {
            return __('admin.checkout_link.validation.groups_required');
        }

        $validator = Validator::make(['paymentGroups' => $selection], [
            'paymentGroups' => ['array', 'list'],
            'paymentGroups.*' => ['required', 'array'],
            'paymentGroups.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'paymentGroups.*.is_default' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return __('admin.checkout_link.validation.groups_invalid');
        }

        /** @var list<array{id: int, is_default: bool|int|string}> $rows */
        $rows = $validator->validated()['paymentGroups'];
        $defaultRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->isDefault($row['is_default']),
        ));

        if (count($defaultRows) !== 1) {
            return __('admin.checkout_link.validation.groups_default');
        }

        $ids = array_map(fn (array $row): int => (int) $row['id'], $rows);
        $groups = PaymentGroup::query()
            ->withTrashed()
            ->forMerchant((int) $merchantId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($groups->count() !== count($ids)) {
            return __('admin.checkout_link.validation.groups_invalid');
        }

        if ($groups->contains(fn (PaymentGroup $group): bool => $group->trashed())) {
            return __('admin.checkout_link.validation.groups_deleted');
        }

        $default = $groups->get((int) $defaultRows[0]['id']);

        if ($default === null || ! $default->is_active) {
            return __('admin.checkout_link.validation.groups_default_inactive');
        }

        return null;
    }

    public function merchantMessage(?User $actor, int $merchantId): ?string
    {
        if (! $actor instanceof User) {
            return __('admin.checkout_link.validation.merchant_forbidden');
        }

        if ($merchantId < 1) {
            return __('admin.checkout_link.group_panel.choose_merchant');
        }

        $merchant = Merchant::query()->find($merchantId);

        if ($merchant === null || ! $this->canAccess($actor, $merchant)) {
            return __('admin.checkout_link.validation.merchant_forbidden');
        }

        return null;
    }

    private function canAccess(User $actor, Merchant $merchant): bool
    {
        if ($actor->is_super_admin) {
            return true;
        }

        if ($actor->isMerchantManager()) {
            return (int) $merchant->owner_id === (int) $actor->id;
        }

        return (int) $actor->merchant_id === (int) $merchant->id;
    }

    private function isDefault(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
