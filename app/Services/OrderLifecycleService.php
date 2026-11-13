<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderSettlement;
use Illuminate\Support\Facades\DB;

/** 从独立的支付、账务、审核及履约事实推导订单主状态，不直接修改资金。 */
final class OrderLifecycleService
{
    public function __construct(private readonly CombinedPaymentStatusService $combinedPayments) {}

    public function normalStatus(Order $order): string
    {
        if ($order->settlement_mode === null) {
            if ($order->status === 'completed' || $order->normal_status === 'completed') {
                return 'completed';
            }

            return $order->shipping()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->whereNull('order_shippings.deleted_at')->exists()
                ? 'shipped' : 'paid';
        }

        $settlement = $order->settlement()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->first();

        if ($settlement?->status === OrderSettlement::STATUS_SETTLED) {
            return 'completed';
        }

        if ($order->settlement_mode === 'immediate') {
            return Order::STATUS_PENDING_SETTLEMENT;
        }

        return ($settlement?->started_at !== null
            || $order->shipping()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->whereNull('order_shippings.deleted_at')->exists())
                ? 'shipped' : 'paid';
    }

    public function resolve(Order $order, ?string $gatewayStatus = null): string
    {
        $gatewayStatus ??= $order->gateway_status;

        if (BalanceService::hasChargebackLedger($order) || $gatewayStatus === 'chargeback' || $order->status === 'chargeback') {
            return 'chargeback';
        }

        if ($gatewayStatus === 'refunded' || $order->status === 'refunded'
            || (bccomp((string) ($order->amount ?? '0'), '0', 2) > 0
                && bccomp((string) ($order->refunded_amount ?? '0'), (string) $order->amount, 2) >= 0)) {
            return 'refunded';
        }

        if ($order->activeDisputeEvent()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->exists()) {
            return Order::STATUS_DISPUTE_REVIEW;
        }

        if ($gatewayStatus === 'disputing' || ($gatewayStatus === null && $order->status === 'disputing')) {
            return 'disputing';
        }

        if (bccomp((string) ($order->refunded_amount ?? '0'), '0', 2) > 0) {
            return 'partially_refunded';
        }

        if ($gatewayStatus !== null && in_array($gatewayStatus, Order::NEVER_PAID_STATUSES, true)
            && $order->paid_at === null && in_array($order->status, Order::NEVER_PAID_STATUSES, true)) {
            return $gatewayStatus;
        }

        return $this->normalStatus($order);
    }

    /** 调用方持有商户及订单锁；所有副作用在事务提交后派发。 */
    public function sync(Order $order): bool
    {
        if ($order->paid_at === null && in_array($order->status, Order::NEVER_PAID_STATUSES, true)) {
            return false;
        }

        $old = $order->status;
        $order->normal_status = $this->normalStatus($order);
        $order->status = $this->resolve($order);
        $order->save();
        $this->combinedPayments->apply($order, false);

        if ($old === $order->status) {
            return false;
        }

        $snapshot = clone $order;
        DB::afterCommit(fn () => event(new OrderStatusChanged($snapshot, $old, $snapshot->status)));

        return true;
    }

    public function isBlocked(Order $order): bool
    {
        return ($order->combined_order_id !== null
                && ! $order->combinedOrder()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)
                    ->whereNull('deleted_at')->where('status', 'paid')->exists())
            || in_array($order->status, ['disputing', Order::STATUS_DISPUTE_REVIEW], true)
            || $order->gateway_status === 'disputing'
            || (($order->gateway_status === 'refunded' || $order->status === 'refunded') && bccomp((string) $order->refunded_amount, (string) $order->amount, 2) < 0)
            || (($order->gateway_status === 'chargeback' || $order->status === 'chargeback') && ! BalanceService::hasChargebackLedger($order))
            || $order->activeDisputeEvent()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->exists()
            || $order->accountingTasks()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)
                ->unsettledReversals()->exists();
    }
}
