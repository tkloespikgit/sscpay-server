<?php

namespace App\Services\ChargebackAlerts;

use App\Models\ChargebackAlert;
use App\Models\ChargebackChannelBinding;
use App\Models\Order;
use App\Models\OrderChargebackReference;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class ChargebackAlertMatcher
{
    public function __construct(private ChargebackAlertPolicy $policy) {}

    public function attempt(int $alertId): void
    {
        DB::transaction(function () use ($alertId): void {
            $alert = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->lockForUpdate()->findOrFail($alertId);
            if ($alert->order_id !== null || $alert->next_match_at === null || $alert->next_match_at->isFuture()) {
                return;
            }

            $orders = $this->byReference($alert);
            $order = $orders->count() === 1 ? $orders->first() : null;
            if ($order && ! $alert->evidence_conflict && $alert->trade_amount !== null && $alert->trade_currency !== null
                && $this->moneyMatches($alert, $order)) {
                $fresh = $this->orders($alert)->lockForUpdate()->find($order->id);
                $currentCandidates = $this->byReference($alert);
                if ($fresh && $this->moneyMatches($alert, $fresh)
                    && $currentCandidates->count() === 1 && $currentCandidates->first()->is($fresh)
                    && $fresh->gatewayOrderReference() === $order->gatewayOrderReference()
                    && $fresh->transaction_id === $order->transaction_id && $fresh->invoice_number === $order->invoice_number) {
                    $this->link($alert, $fresh);

                    return;
                }
            }

            $state = $alert->evidence_conflict ? 'conflict' : ($orders->count() > 1 ? 'ambiguous' : 'unmatched');
            $alert->update([
                'match_status' => $state,
                'next_match_at' => $state === 'unmatched' && $alert->created_at->gt(now()->subDays(7)) ? now()->addHour() : null,
            ]);
        }, 3);
    }

    /** @return Collection<int, Order> */
    public function candidates(User $actor, ChargebackAlert $alert): Collection
    {
        $actor = User::query()->findOrFail($actor->id);
        $alert = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->findOrFail($alert->id);
        abort_unless($this->policy->view($actor, $alert), 403);

        if ($alert->order_id !== null) {
            return Order::query()->withoutGlobalScope(MerchantScope::class)->withTrashed()
                ->forMerchant($alert->merchant_id)->whereKey($alert->order_id)->get();
        }
        if (! $actor->is_super_admin || $alert->scope_key === null) {
            return collect();
        }

        $orders = $this->byReference($alert);
        if ($alert->trade_amount === null || $alert->trade_currency === null || $alert->trade_at === null) {
            return $orders;
        }

        return $orders->merge($this->orders($alert)
            ->where('amount', $alert->trade_amount)->where('currency', $alert->trade_currency)
            ->whereBetween('paid_at', [$alert->trade_at->copy()->subDay(), $alert->trade_at->copy()->addDay()])
            ->orderByDesc('paid_at')->orderByDesc('id')->limit(10)->get())->unique('id')->take(10)->values();
    }

    public function attach(User $actor, int $alertId, string $orderNo, string $reason, bool $confirmArn): void
    {
        DB::transaction(function () use ($actor, $alertId, $orderNo, $reason, $confirmArn): void {
            $actor = User::query()->findOrFail($actor->id);
            $alert = ChargebackAlert::query()->withoutGlobalScope(MerchantScope::class)->lockForUpdate()->findOrFail($alertId);
            abort_unless($this->policy->matchOrder($actor, $alert), 403);
            Validator::make(['order_no' => $orderNo, 'reason' => $reason], [
                'order_no' => ['required', 'string', 'max:32'],
                'reason' => ['required', 'string', 'max:250', 'not_regex:/(?:\d[ -]?){12,}/'],
            ])->validate();

            $orders = $this->orders($alert)->where('orders.order_no', $orderNo)->lockForUpdate()->limit(2)->get();
            $order = $orders->count() === 1 ? $orders->first() : null;
            if (! $order || $order->order_no !== $orderNo || ! $this->moneyMatches($alert, $order) || $alert->evidence_conflict) {
                throw ValidationException::withMessages(['order_no' => __('chargeback_alerts.order_mismatch')]);
            }

            if ($confirmArn && $alert->arn !== null) {
                OrderChargebackReference::query()->withoutGlobalScope(MerchantScope::class)->firstOrCreate([
                    'order_id' => $order->id, 'merchant_id' => $order->merchant_id,
                    'gateway_reference' => $order->gatewayOrderReference(), 'arn_hash' => hash('sha256', $alert->arn),
                ], ['arn' => $alert->arn, 'operator_id' => $actor->id]);
            }

            $this->link($alert, $order, $actor->id, $reason);
        }, 3);
    }

    /** @return Collection<int, Order> */
    private function byReference(ChargebackAlert $alert): Collection
    {
        $orders = collect();
        if ($alert->scope_key === null) {
            return $orders;
        }

        if ($alert->arn !== null) {
            $orders = $this->orders($alert)->whereExists(function (Query $proof) use ($alert): void {
                $proof->selectRaw('1')->from('order_chargeback_references')
                    ->where('arn_hash', hash('sha256', $alert->arn))
                    ->whereColumn('order_chargeback_references.order_id', 'orders.id')
                    ->whereColumn('order_chargeback_references.merchant_id', 'orders.merchant_id')
                    ->whereRaw('order_chargeback_references.gateway_reference = COALESCE(orders.gateway_order_no, orders.order_no)');
            })->orderBy('id')->limit(2)->get();
        }

        foreach ([
            ChargebackChannelBinding::PSP_TRANSACTION => [$alert->provider_transaction_id, 'transaction_id'],
            ChargebackChannelBinding::GATEWAY_REFERENCE => [$alert->provider_order_reference, null],
            ChargebackChannelBinding::INVOICE => [$alert->provider_order_reference, 'invoice_number'],
        ] as $type => [$reference, $column]) {
            if ($reference === null) {
                continue;
            }

            $query = $this->orders($alert, $type);
            if ($column === null) {
                $query->where(fn (Builder $order) => $order->where('gateway_order_no', $reference)
                    ->orWhere(fn (Builder $legacy) => $legacy->whereNull('gateway_order_no')->where('order_no', $reference)));
            } else {
                $query->where($column, $reference);
            }

            $candidates = $query->orderBy('id')->limit(2)->get();
            $orders = $orders->merge($candidates->count() > 1 ? $candidates
                : $candidates->filter(fn (Order $order): bool => ($column === null ? $order->gatewayOrderReference() : $order->{$column}) === $reference));
        }

        return $orders->unique('id')->values();
    }

    private function orders(ChargebackAlert $alert, ?string $referenceType = null): Builder
    {
        $orders = Order::query()->withoutGlobalScope(MerchantScope::class)->withTrashed()
            ->where('orders.payment_type', 'fiat')->whereNotNull('orders.paid_at')->paidEver();
        if ($alert->scope_key === null) {
            return $orders->whereRaw('1 = 0');
        }

        return $orders->whereExists(function (Query $bindings) use ($alert, $referenceType): void {
            $bindings->selectRaw('1')->from('chargeback_channel_bindings')
                ->whereColumn('chargeback_channel_bindings.payment_method_id', 'orders.payment_method_id')
                ->where('chargeback_provider_account_id', $alert->chargeback_provider_account_id)
                ->where('service', $alert->service)->where('scope_hash', hash('sha256', $alert->scope_key))
                ->when($referenceType !== null, fn (Query $query) => $query->where('reference_type', $referenceType))
                ->whereColumn('valid_from', '<=', 'orders.paid_at')
                ->where(fn (Query $valid) => $valid->whereNull('valid_until')->orWhereColumn('valid_until', '>', 'orders.paid_at'));
        });
    }

    private function moneyMatches(ChargebackAlert $alert, Order $order): bool
    {
        return ($alert->trade_amount === null || bccomp($alert->trade_amount, $order->amount, 2) === 0)
            && ($alert->trade_currency === null || $alert->trade_currency === $order->currency);
    }

    private function link(ChargebackAlert $alert, Order $order, ?int $operatorId = null, ?string $reason = null): void
    {
        $alert->update([
            'merchant_id' => $order->merchant_id, 'order_id' => $order->id, 'match_status' => 'matched',
            'next_match_at' => null, 'next_notice_at' => now(),
        ]);
        $alert->events()->create(['kind' => 'matched', 'source' => $operatorId === null ? 'system' : 'operator',
            'operator_id' => $operatorId, 'details' => ['order_no' => $order->order_no, 'reason' => $reason]]);
    }
}
