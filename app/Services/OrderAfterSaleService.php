<?php

namespace App\Services;

use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderGatewayStatusEvent;
use App\Models\OrderRefund;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** 只读售后查询；展示日期不回写订单、资金流水或统计。 */
final class OrderAfterSaleService
{
    public function refunds(Builder $query): Builder
    {
        return $query
            ->where('orders.status', '!=', 'chargeback')
            ->where(fn (Builder $query): Builder => $query->whereNull('orders.gateway_status')->orWhere('orders.gateway_status', '!=', 'chargeback'))
            ->whereNotExists($this->ledger(MerchantBalanceTransaction::TYPE_CHARGEBACK)->selectRaw('1'))
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('orders.status', ['refunded', 'partially_refunded'])
                ->orWhere('orders.gateway_status', 'refunded')
                ->orWhere('orders.refunded_amount', '>', '0')
                ->orWhereExists($this->refundRecords()->selectRaw('1')));
    }

    public function chargebacks(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->where('orders.status', 'chargeback')
            ->orWhere('orders.gateway_status', 'chargeback')
            ->orWhereExists($this->ledger(MerchantBalanceTransaction::TYPE_CHARGEBACK)->selectRaw('1')));
    }

    public function withSummary(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('orders.*');
        }

        return $query->addSelect([
            'after_sale_refunded_at' => $this->refundTime(),
            'after_sale_chargeback_at' => $this->chargebackTime(),
            'after_sale_chargeback_id' => $this->ledger(MerchantBalanceTransaction::TYPE_CHARGEBACK)->selectRaw('MIN(id)'),
            'after_sale_chargeback_fee_paid' => $this->ledger(MerchantBalanceTransaction::TYPE_CHARGEBACK_FEE)->selectRaw('SUM(-amount)'),
            'after_sale_chargeback_fee_expected' => $this->expectedChargebackFee(),
            'after_sale_refunded_total' => $this->refundRecords()->selectRaw('SUM(amount)'),
            'after_sale_refund_fee_total' => $this->refundRecords()->selectRaw('SUM(fee)'),
        ])->withCasts([
            'after_sale_refunded_at' => 'datetime',
            'after_sale_chargeback_at' => 'datetime',
            'after_sale_chargeback_id' => 'integer',
            'after_sale_chargeback_fee_paid' => 'decimal:2',
            'after_sale_chargeback_fee_expected' => 'decimal:2',
            'after_sale_refunded_total' => 'decimal:2',
            'after_sale_refund_fee_total' => 'decimal:2',
        ]);
    }

    public function timeQuery(bool $chargebacks): QueryBuilder
    {
        return $chargebacks ? $this->chargebackTime() : $this->refundTime();
    }

    public static function isChargeback(Order $order): bool
    {
        return $order->status === 'chargeback' || $order->gateway_status === 'chargeback'
            || $order->after_sale_chargeback_id !== null;
    }

    public static function hasRefund(Order $order): bool
    {
        return in_array($order->status, ['refunded', 'partially_refunded'], true)
            || $order->gateway_status === 'refunded'
            || self::hasRefundedPrincipal($order);
    }

    public static function isRefundConversion(Order $order): bool
    {
        return self::isChargeback($order) && self::hasRefundedPrincipal($order);
    }

    public static function chargebackFeePending(Order $order): bool
    {
        return $order->after_sale_chargeback_id === null && $order->after_sale_chargeback_fee_paid === null;
    }

    public static function chargebackFee(Order $order): string
    {
        if ($order->after_sale_chargeback_fee_paid !== null) {
            return (string) $order->after_sale_chargeback_fee_paid;
        }

        // 零手续费没有 fee 流水，但拒付本金凭证（包括零本金）证明当次记账已完成。
        return self::chargebackFeePending($order) ? (string) $order->after_sale_chargeback_fee_expected : '0.00';
    }

    private static function hasRefundedPrincipal(Order $order): bool
    {
        return bccomp((string) ($order->refunded_amount ?? '0'), '0', 2) > 0
            || bccomp((string) ($order->after_sale_refunded_total ?? '0'), '0', 2) > 0;
    }

    private function refundTime(): QueryBuilder
    {
        return $this->coalesce([
            $this->refundRecords()->selectRaw('MAX(created_at)'),
            $this->gatewayTime('refunded'),
        ]);
    }

    private function chargebackTime(): QueryBuilder
    {
        $refunds = $this->refundRecords()->selectRaw('1');
        $refundTime = $this->refundTime();
        $chargebackTime = $this->coalesce([
            $this->ledger(MerchantBalanceTransaction::TYPE_CHARGEBACK)->selectRaw('MIN(created_at)'),
            $this->gatewayTime('chargeback'),
        ]);

        return DB::query()->selectRaw(
            'CASE WHEN orders.refunded_amount > 0 OR EXISTS ('.$refunds->toSql().') THEN ('.$refundTime->toSql().') ELSE ('.$chargebackTime->toSql().') END',
            [...$refunds->getBindings(), ...$refundTime->getBindings(), ...$chargebackTime->getBindings()],
        );
    }

    private function refundRecords(): QueryBuilder
    {
        return OrderRefund::query()
            ->whereColumn('order_refunds.order_id', 'orders.id')
            ->whereColumn('order_refunds.merchant_id', 'orders.merchant_id')->toBase();
    }

    private function ledger(string $type): QueryBuilder
    {
        return MerchantBalanceTransaction::query()
            ->whereColumn('merchant_balance_transactions.order_id', 'orders.id')
            ->whereColumn('merchant_balance_transactions.merchant_id', 'orders.merchant_id')
            ->where('type', $type)->toBase();
    }

    private function gatewayTime(string $status): QueryBuilder
    {
        return OrderGatewayStatusEvent::query()
            ->whereColumn('order_gateway_status_events.order_id', 'orders.id')
            ->whereColumn('order_gateway_status_events.merchant_id', 'orders.merchant_id')
            ->whereRaw("gateway_reference = COALESCE(NULLIF(orders.gateway_order_no, ''), orders.order_no)")
            ->where('status', $status)->where('outcome', 'accepted')
            ->selectRaw('MAX(COALESCE(gateway_updated_at, created_at))')->toBase();
    }

    private function expectedChargebackFee(): QueryBuilder
    {
        // 仅取该订单锁定的历史渠道费率；与 paymentMethodConfig() 一样兼容软删除和旧渠道代码。
        $method = PaymentMethod::query()->withoutGlobalScopes()->withTrashed()
            ->where(fn (Builder $query): Builder => $query
                ->whereColumn('payment_methods.id', 'orders.payment_method_id')
                ->orWhere(fn (Builder $query): Builder => $query->whereNull('orders.payment_method_id')
                    ->whereColumn('payment_methods.method_code', 'orders.payment_method')))
            ->select('chargeback_fee')->limit(1)->toBase();

        return DB::query()->selectRaw('COALESCE(orders.chargeback_fee_snapshot, ('.$method->toSql().'), 0)', $method->getBindings());
    }

    /** @param array<QueryBuilder> $queries */
    private function coalesce(array $queries): QueryBuilder
    {
        $expressions = [];
        $bindings = [];

        foreach ($queries as $query) {
            $expressions[] = '('.$query->toSql().')';
            $bindings = [...$bindings, ...$query->getBindings()];
        }

        return DB::query()->selectRaw('COALESCE('.implode(', ', $expressions).')', $bindings);
    }
}
