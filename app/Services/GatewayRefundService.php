<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Models\GatewayRefund;
use App\Models\Merchant;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\OrderRefund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 已验签的网关退款凭证；接收与扣款分开提交，到账顺序或扣款故障不丢凭证。 */
class GatewayRefundService
{
    public function __construct(private readonly BalanceService $balances) {}

    public function receive(array $payload): GatewayRefund
    {
        $identifier = ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.:-]+$/D'];
        $data = Validator::make($payload, [
            'event' => ['required', Rule::in(['payment_refund'])],
            'status' => ['required', Rule::in(['succeeded'])],
            's_order_id' => $identifier,
            'payment_method' => ['required', Rule::in(['stripe', 'paypal_js', 'paypal_rest', 'airwallex', 'antom'])],
            'livemode' => ['present', static function ($attribute, $value, $fail): void {
                if (! is_bool($value)) {
                    $fail('livemode must be a JSON boolean.');
                }
            }],
            'refund_id' => $identifier,
            'payment_reference' => $identifier,
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'refunded_at' => ['required', 'string', 'date', 'regex:/T.*(?:Z|[+-]\d\d:\d\d)$/D'],
        ])->validate();

        if (bccomp($data['amount'], '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => '退款金额必须为正数。']);
        }

        $order = Order::query()->withoutGlobalScopes()->where('payment_type', 'fiat')
            ->where(fn ($query) => $query->where('gateway_order_no', $data['s_order_id'])
                ->orWhere(fn ($legacy) => $legacy->whereNull('gateway_order_no')->where('order_no', $data['s_order_id'])))
            ->first();
        if (! $order || $order->gatewayOrderReference() !== $data['s_order_id']) {
            throw ValidationException::withMessages(['s_order_id' => '找不到对应网关交易的订单。']);
        }

        $facts = [
            'payment_method' => $this->family($data['payment_method']),
            'livemode' => $data['livemode'], 'refund_id' => $data['refund_id'],
            'gateway_reference' => $data['s_order_id'], 'payment_reference' => $data['payment_reference'],
            'amount' => bcadd($data['amount'], '0', 2), 'currency' => $data['currency'],
        ];
        $identity = hash('sha256', json_encode([$facts['payment_method'], $facts['livemode'], $facts['refund_id']], JSON_THROW_ON_ERROR));
        $orderKey = hash('sha256', $order->id.'|'.$facts['refund_id']);

        $receipt = DB::transaction(function () use ($order, $facts, $data, $identity, $orderKey): GatewayRefund {
            $locked = $this->lockOrder($order->merchant_id, $order->id);
            $keys = fn () => GatewayRefund::query()->withoutGlobalScopes()->where(fn ($q) => $q
                ->where('identity_hash', $identity)->orWhere('order_refund_hash', $orderKey));
            $receipt = $keys()->lockForUpdate()->first();
            if (! $receipt) {
                try {
                    return DB::transaction(fn () => GatewayRefund::create($facts + [
                        'identity_hash' => $identity, 'order_refund_hash' => $orderKey,
                        'merchant_id' => $locked->merchant_id, 'order_id' => $locked->id,
                        'payment_method_id' => $locked->payment_method_id,
                        'refunded_at' => Carbon::parse($data['refunded_at'])->setTimezone(config('app.timezone')),
                        'status' => 'pending',
                    ]));
                } catch (UniqueConstraintViolationException $e) {
                    $receipt = $keys()->lockForUpdate()->first() ?? throw $e;
                }
            }
            if ($receipt->order_id !== $locked->id || $receipt->only(array_keys($facts)) !== $facts) {
                $encoded = json_encode($facts, JSON_THROW_ON_ERROR);
                $fingerprint = hash('sha256', $receipt->id.'|'.$locked->id.'|'.$encoded);
                $seen = DB::table('gateway_refund_conflicts')->where('fingerprint', $fingerprint)->exists();
                DB::table('gateway_refund_conflicts')->upsert([[
                    'gateway_refund_id' => $receipt->id, 'order_id' => $locked->id, 'facts' => $encoded,
                    'fingerprint' => $fingerprint, 'created_at' => now(),
                ]], ['fingerprint'], ['fingerprint']);
                if (! $seen) {
                    Log::warning('同一渠道退款号的交易或金额冲突，需人工核对', [
                        'gateway_refund_id' => $receipt->id, 'order_id' => $locked->id, 'merchant_id' => $locked->merchant_id,
                    ]);
                }
                if ($receipt->status !== 'booked') {
                    $receipt->update(['status' => 'review', 'last_error' => 'conflicting_evidence']);
                }
            }

            return $receipt;
        }, 5);

        $this->process($receipt);

        return $receipt->refresh();
    }

    public function process(GatewayRefund $receipt, bool $onlyDue = false): bool
    {
        if ($receipt->status !== 'pending') {
            return false;
        }

        try {
            return DB::transaction(function () use ($receipt, $onlyDue): bool {
                $order = $this->lockOrder($receipt->merchant_id, $receipt->order_id);
                $fresh = GatewayRefund::forMerchant($order->merchant_id)->lockForUpdate()->findOrFail($receipt->id);
                if ($fresh->status !== 'pending' || ($onlyDue && $fresh->next_attempt_at?->isFuture())) {
                    return false;
                }

                $method = $order->paymentMethodConfig();
                $mode = strtolower(trim((string) ($method?->config['mode'] ?? $method?->config['env'] ?? '')));
                if ($order->gatewayOrderReference() !== $fresh->gateway_reference || $order->currency !== $fresh->currency
                    || ! $method || ! $order->payment_method_id || $method->id !== $fresh->payment_method_id || $method->id !== $order->payment_method_id
                    || $this->family((string) $method->configMap?->payment_config_tag) !== $fresh->payment_method
                    || ($mode !== '' && ($mode === 'live') !== $fresh->livemode)) {
                    return $this->review($fresh, 'order_channel_or_currency_mismatch');
                }
                if ($order->paid_at === null || empty($order->transaction_id)) {
                    $fresh->update(['last_error' => 'awaiting_payment_reference', 'next_attempt_at' => now()->addMinutes(5)]);

                    return false;
                }
                if ((string) $order->transaction_id !== $fresh->payment_reference) {
                    return $this->review($fresh, 'payment_reference_mismatch');
                }
                if (! $order->balanceTransactions()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)
                    ->where('type', MerchantBalanceTransaction::TYPE_ORDER_PAID)->exists()) {
                    if ($order->settlement_mode === null) {
                        return $this->review($fresh, 'missing_income_ledger');
                    }
                    $fresh->update(['last_error' => 'awaiting_income_ledger', 'next_attempt_at' => now()->addMinutes(5)]);

                    return false;
                }
                if (! $fresh->reviewed_by && $order->refunds()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)
                    ->whereNotIn('id', GatewayRefund::forMerchant($order->merchant_id)->where('order_id', $order->id)
                        ->whereNotNull('order_refund_id')->select('order_refund_id'))->exists()) {
                    return $this->review($fresh, 'unlinked_refunds');
                }

                try {
                    $refund = $this->balances->refund($order, $fresh->amount, null,
                        __('admin.finance.refund.gateway_auto_reason'), 'gateway_refund_receipt:'.$fresh->id);
                } catch (BalanceOperationException $e) {
                    return $this->review($fresh, $e->getMessage());
                }

                $fresh->update(['order_refund_id' => $refund->id, 'status' => 'booked', 'last_error' => null, 'next_attempt_at' => null]);
                $order->refresh();
                if (bccomp($order->refunded_amount, $order->amount, 2) === 0 && $order->gateway_status !== 'chargeback') {
                    $order->update(['gateway_status' => 'refunded', 'gateway_status_version' => $order->gateway_status_version + 1]);
                    $dispute = $order->activeDisputeEvent()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->first();
                    if ($dispute) {
                        $this->balances->releaseForDisputeEvent($dispute, null, OrderDisputeEvent::CLOSE_TYPE_AUTO,
                            '渠道逐笔成功退款已达到订单全额，解除该单审核冻结。');
                    }
                }

                return true;
            }, 3);
        } catch (Throwable $exception) {
            GatewayRefund::forMerchant($receipt->merchant_id)->whereKey($receipt->id)->where('status', 'pending')
                ->update(['next_attempt_at' => now()->addMinute(), 'last_error' => 'processing_failed']);
            throw $exception;
        }
    }

    /** 经财务核验旧流水，关联既有退款或确认这是另一笔独立退款。 */
    public function resolveUnlinked(Order $order, int $receiptId, ?int $existingRefundId, User $operator): void
    {
        DB::transaction(function () use ($order, $receiptId, $existingRefundId, $operator): void {
            $locked = $this->lockOrder($order->merchant_id, $order->id);
            $receipt = GatewayRefund::forMerchant($locked->merchant_id)->where('order_id', $locked->id)
                ->lockForUpdate()->findOrFail($receiptId);
            if ($receipt->status !== 'review' || $receipt->last_error !== 'unlinked_refunds') {
                throw new BalanceOperationException('该退款凭证不在待核销状态。');
            }
            if ($existingRefundId !== null) {
                $refund = OrderRefund::forMerchant($locked->merchant_id)->where('order_id', $locked->id)->lockForUpdate()->findOrFail($existingRefundId);
                if ($refund->amount !== $receipt->amount || $refund->currency !== $receipt->currency
                    || GatewayRefund::forMerchant($locked->merchant_id)->where('order_refund_id', $refund->id)->exists()) {
                    throw new BalanceOperationException('所选退款的金额、币种或核销状态不匹配。');
                }
                $entries = $locked->balanceTransactions()->withoutGlobalScopes()->where('merchant_id', $locked->merchant_id)
                    ->where('order_refund_id', $refund->id)->get();
                $principal = $entries->where('type', MerchantBalanceTransaction::TYPE_REFUND);
                $fees = $entries->where('type', MerchantBalanceTransaction::TYPE_REFUND_FEE);
                $expectedPrincipal = bcsub('0', $refund->principal_deducted_usd ?? $refund->amount_usd, 2);
                if ($principal->count() !== 1 || $principal->first()->amount !== $expectedPrincipal
                    || $fees->count() !== (int) (bccomp($refund->fee, '0', 2) > 0)
                    || ($fees->isNotEmpty() && $fees->first()->amount !== bcsub('0', $refund->fee, 2))) {
                    throw new BalanceOperationException('所选退款单与实际本金或手续费流水不符，请核对原账。');
                }
                $receipt->update(['order_refund_id' => $refund->id, 'status' => 'booked', 'last_error' => null, 'reviewed_by' => $operator->id]);
            } else {
                $receipt->update(['status' => 'pending', 'last_error' => null, 'reviewed_by' => $operator->id]);
                $this->process($receipt);
            }
        }, 3);
    }

    private function review(GatewayRefund $receipt, string $reason): bool
    {
        $receipt->update(['status' => 'review', 'last_error' => $reason, 'next_attempt_at' => null]);

        return false;
    }

    private function lockOrder(int $merchantId, int $orderId): Order
    {
        Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);

        return Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId);
    }

    private function family(string $method): string
    {
        return in_array($method, ['paypal_js', 'paypal_rest'], true) ? 'paypal' : $method;
    }
}
