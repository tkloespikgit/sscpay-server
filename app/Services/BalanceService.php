<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Models\Merchant;
use App\Models\MerchantBalanceTransaction;
use App\Models\MerchantFundFreeze;
use App\Models\MerchantWithdrawal;
use App\Models\Order;
use App\Models\OrderAccountingTask;
use App\Models\OrderDisputeEvent;
use App\Models\OrderRefund;
use App\Models\OrderSettlement;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 商户余额账务服务：所有会改动商户余额/冻结余额的操作都必须经过这里，
 * 保证「事务 + 行锁 + 幂等 + 统一流水台账」四件事：
 *
 *   - 事务/行锁：每个写操作在 DB::transaction 内先 lockForUpdate 锁住商户行，
 *     避免并发下的余额竞态（两笔退款同时读到旧余额）。
 *   - 幂等：自动入账用 idempotency_key 唯一约束兜底，重复事件不会重复入账。
 *   - 台账：每一次总余额增减都落一条 merchant_balance_transactions。
 *
 * 记账口径统一 USD。金额一律用 bcmath 字符串运算，避免浮点误差。
 * 余额允许为负（退款/拒付可能超过当前余额），但提现不能超过可用余额。
 */
class BalanceService
{
    /**
     * 按净结算金额入账；新单进入待结算，旧单仍直接可提现。同一订单仅入账一次。
     * 与状态事务分开执行；若有外层账务事务，必须先锁商户、再锁订单。
     *
     * @return MerchantBalanceTransaction|null 已入过账则返回 null
     */
    public function creditForPaidOrder(Order $order): ?MerchantBalanceTransaction
    {
        $key = 'order_paid:'.$order->id;

        return $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order, $key) {
            $fresh = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                ->lockForUpdate()->findOrFail($order->id);
            $exists = MerchantBalanceTransaction::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $key)
                ->exists();

            if ($exists) {
                return null;
            }

            $amount = (string) $fresh->settlement_amount;
            $attributes = [
                'order_id' => $fresh->id,
                'idempotency_key' => $key,
            ];

            if ($fresh->settlement_mode !== null) {
                $startedAt = $fresh->settlement_mode === 'immediate' ? ($fresh->paid_at ?? now()) : null;
                $merchant->pending_settlement_balance = bcadd((string) $merchant->pending_settlement_balance, $amount, 2);
                $attributes['pending_settlement_change'] = $amount;
                $settlement = OrderSettlement::query()->create([
                    'merchant_id' => $merchant->id,
                    'order_id' => $fresh->id,
                    'amount' => $amount,
                    'remaining_amount' => $amount,
                    'mode' => $fresh->settlement_mode,
                    'cycle_days' => $fresh->settlement_days,
                    'reserve_percent' => $fresh->reserve_percent ?? '0',
                    'reserve_days' => $fresh->reserve_days ?? 0,
                    'status' => $startedAt ? OrderSettlement::STATUS_PENDING : OrderSettlement::STATUS_WAITING_SHIPMENT,
                    'started_at' => $startedAt,
                    'due_at' => $startedAt?->copy()->addSeconds((int) $fresh->settlement_days * 86400),
                ]);
            }

            $transaction = $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_ORDER_PAID, $amount, $attributes);

            if (isset($settlement)) {
                $this->startShipmentCycle($fresh, $settlement);
                $this->settleLockedOrder($merchant, $fresh, $settlement);
            }

            return $transaction;
        });
    }

    public function startSettlementForShippedOrder(Order $order): void
    {
        $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order): void {
            $fresh = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                ->lockForUpdate()->findOrFail($order->id);
            $settlement = OrderSettlement::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                ->where('order_id', $fresh->id)->lockForUpdate()->first();

            if (! $settlement) {
                return;
            }

            $this->startShipmentCycle($fresh, $settlement);
            $this->settleLockedOrder($merchant, $fresh, $settlement);
        });
    }

    public function settleOrder(OrderSettlement $settlement): bool
    {
        return $this->mutate($settlement->merchant_id, function (Merchant $merchant) use ($settlement): bool {
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                ->lockForUpdate()->findOrFail($settlement->order_id);
            $fresh = OrderSettlement::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                ->where('order_id', $order->id)->lockForUpdate()->findOrFail($settlement->id);

            return $this->settleLockedOrder($merchant, $order, $fresh);
        });
    }

    private function startShipmentCycle(Order $order, OrderSettlement $settlement): void
    {
        if ($settlement->status !== OrderSettlement::STATUS_WAITING_SHIPMENT
            || ! in_array($order->status, ['paid', 'shipped', 'completed', 'partially_refunded', 'disputing', Order::STATUS_DISPUTE_REVIEW], true)) {
            return;
        }

        $shipping = $order->shipping()->withoutGlobalScopes()->whereNull('order_shippings.deleted_at')
            ->oldest('created_at')->first();

        if (! $shipping) {
            return;
        }

        $startedAt = $shipping->created_at->copy();

        if ($order->paid_at && $order->paid_at->greaterThan($startedAt)) {
            $startedAt = $order->paid_at->copy();
        }

        $settlement->update([
            'status' => OrderSettlement::STATUS_PENDING,
            'started_at' => $startedAt,
            'due_at' => $startedAt->copy()->addSeconds($settlement->cycle_days * 86400),
        ]);
    }

    /** 商户、订单、结算记录已按顺序锁定；只做本地账务，禁止外部副作用。 */
    private function settleLockedOrder(Merchant $merchant, Order $order, OrderSettlement $settlement): bool
    {
        if ($settlement->status !== OrderSettlement::STATUS_PENDING
            || ! $settlement->due_at || $settlement->due_at->isFuture()
            || ! in_array($order->status, ['paid', 'shipped', 'completed', 'partially_refunded'], true)) {
            return false;
        }

        $amount = (string) $settlement->remaining_amount;
        $reserve = $settlement->reserve_days > 0
            ? bcmul($amount, bcdiv((string) $settlement->reserve_percent, '100', 8), 2)
            : '0.00';
        $settledAt = now();
        $freeze = null;

        if (bccomp($reserve, '0', 2) > 0) {
            $freeze = MerchantFundFreeze::query()->create([
                'merchant_id' => $merchant->id,
                'order_id' => $order->id,
                'amount' => $reserve,
                'remaining_amount' => $reserve,
                'status' => MerchantFundFreeze::STATUS_FROZEN,
                'reason' => __('settlement.reserve.auto_reason'),
                'frozen_at' => $settledAt,
                'release_at' => $settledAt->copy()->addSeconds($settlement->reserve_days * 86400),
            ]);
        }

        $merchant->pending_settlement_balance = bcsub((string) $merchant->pending_settlement_balance, $amount, 2);
        $merchant->frozen_balance = bcadd((string) $merchant->frozen_balance, $reserve, 2);
        $settlement->update([
            'status' => OrderSettlement::STATUS_SETTLED,
            'remaining_amount' => '0.00',
            'reserve_amount' => $reserve,
            'available_amount' => bcsub($amount, $reserve, 2),
            'settled_at' => $settledAt,
        ]);
        $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_ORDER_SETTLEMENT, '0.00', [
            'order_id' => $order->id,
            'fund_freeze_id' => $freeze?->id,
            'pending_settlement_change' => '-'.$amount,
            'frozen_change' => $reserve,
            'idempotency_key' => 'order_settlement:'.$order->id,
        ]);

        return true;
    }

    /**
     * 可以执行退款的订单状态。
     *
     * refunded 也在列内，是为了支持「补录」：网关推送 refunded 时本系统只改了
     * 订单状态、钱还挂在商户余额上（历史行为，见 OrderPaymentStatusService），
     * 这些订单必须还能把账扣平。真正防重复扣款的是 refunded_amount < amount
     * 这个条件（见 assertRefundable()），不是状态本身。
     */
    private const REFUNDABLE_STATUSES = ['paid', 'shipped', 'completed', 'partially_refunded', 'refunded'];

    /** 可以执行拒付的订单状态。chargeback 在列内的理由同 REFUNDABLE_STATUSES。 */
    private const CHARGEBACKABLE_STATUSES = ['paid', 'shipped', 'completed', 'chargeback'];

    /**
     * 退款（支持部分退款）。amountOriginal 为订单原币种金额，
     * 换算成 USD 记账，并按支付方式收取固定退款手续费（USD）。
     * 余额扣减 = 退款 USD + 手续费。
     *
     * @param  ?User  $operator  操作人；网关回调自动入账时为 null（系统行为）
     * @param  ?string  $idempotencyKey  幂等键前缀，自动入账时必传：余额流水表对该列
     *                                   有唯一索引，是防重复扣款的数据库层兜底。
     *                                   人工退款留空——同一订单本来就允许多次部分退款。
     */
    public function refund(Order $order, string|float $amountOriginal, ?User $operator, ?string $reason = null, ?string $idempotencyKey = null): OrderRefund
    {
        $amountOriginal = $this->normalize($amountOriginal);

        if (bccomp($amountOriginal, '0', 2) <= 0) {
            throw new BalanceOperationException('退款金额必须大于 0。');
        }

        $this->assertRefundable($order);

        // 换算成 USD：按已收款总额（converted_amount）对退款占订单金额的比例分摊，
        // 避免依赖汇率乘除方向；订单金额为 0 的异常单直接拒绝。
        if (bccomp((string) $order->amount, '0', 2) <= 0) {
            throw new BalanceOperationException('订单金额异常（为 0），无法计算退款。');
        }

        $method = $order->paymentMethodConfig();
        $fee = (string) ($order->refund_fee_snapshot ?? $method?->refund_fee ?? '0');

        return $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order, $amountOriginal, $operator, $reason, $fee, $idempotencyKey) {
            // 在锁内重新读取订单最新状态，做并发安全的校验：不能只信外层（加锁前）
            // 读到的旧状态——等锁期间订单可能已被另一笔并发的拒付/退款改变状态。
            $fresh = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            $this->assertRefundable($fresh);

            if ((int) $fresh->merchant_id !== (int) $merchant->id) {
                throw new BalanceOperationException(__('settlement.errors.order_merchant_mismatch'));
            }

            if ($fresh->settlement_mode !== null) {
                $this->creditForPaidOrder($fresh);
                $merchant->refresh();
            }

            $remaining = bcsub((string) $fresh->amount, (string) $fresh->refunded_amount, 2);

            if (bccomp($amountOriginal, $remaining, 2) > 0) {
                throw new BalanceOperationException("退款金额超过剩余可退金额（剩余 {$remaining} {$fresh->currency}）。");
            }

            $amountUsd = bcdiv(bcmul((string) $fresh->converted_amount, $amountOriginal, 6), (string) $fresh->amount, 2);
            $bucketChanges = $this->consumeOrderFunds($merchant, $fresh, $amountUsd);

            $refund = OrderRefund::query()->create([
                'merchant_id' => $fresh->merchant_id,
                'order_id' => $fresh->id,
                'currency' => $fresh->currency,
                'amount' => $amountOriginal,
                'exchange_rate' => $fresh->exchange_rate,
                'amount_usd' => $amountUsd,
                'fee' => $fee,
                'operator_id' => $operator?->id,
                'reason' => $reason,
            ]);

            // 扣退款本金
            $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_REFUND, '-'.$amountUsd, array_merge($bucketChanges, [
                'order_id' => $fresh->id,
                'order_refund_id' => $refund->id,
                'operator_id' => $operator?->id,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey ? $idempotencyKey.':principal' : null,
            ]));

            // 扣退款手续费（>0 才落）
            if (bccomp($fee, '0', 2) > 0) {
                $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_REFUND_FEE, '-'.$fee, [
                    'order_id' => $fresh->id,
                    'order_refund_id' => $refund->id,
                    'operator_id' => $operator?->id,
                    'idempotency_key' => $idempotencyKey ? $idempotencyKey.':fee' : null,
                ]);
            }

            // 累计退款额 + 订单状态
            $newRefunded = bcadd((string) $fresh->refunded_amount, $amountOriginal, 2);
            $fresh->refunded_amount = $newRefunded;
            $fresh->status = bccomp($newRefunded, (string) $fresh->amount, 2) >= 0 ? 'refunded' : 'partially_refunded';
            $fresh->save();

            return $refund;
        });
    }

    /**
     * 拒付（只能全额）。扣减 = 订单全额（USD） + 固定拒付手续费（USD）。
     * 为避免与部分退款重复扣款，已有退款记录的订单不允许再拒付。
     *
     * @param  ?User  $operator  操作人；网关回调自动入账时为 null（系统行为）
     * @param  ?string  $idempotencyKey  幂等键前缀，说明见 refund()
     */
    public function chargeback(Order $order, ?User $operator, ?string $reason = null, ?string $idempotencyKey = null): void
    {
        $this->assertChargebackable($order);

        $amountUsd = (string) $order->converted_amount;
        $method = $order->paymentMethodConfig();
        $fee = (string) ($order->chargeback_fee_snapshot ?? $method?->chargeback_fee ?? '0');

        $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order, $amountUsd, $fee, $operator, $reason, $idempotencyKey) {
            // 在锁内重新读取订单最新状态/退款额：等锁期间订单可能已被另一笔并发的
            // 退款/拒付改变状态，不能只信外层（加锁前）读到的旧值，否则会对同一笔
            // 订单重复扣款（如退款已发生后又被拒付扣了全额）。
            $fresh = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            $this->assertChargebackable($fresh);

            if ((int) $fresh->merchant_id !== (int) $merchant->id) {
                throw new BalanceOperationException(__('settlement.errors.order_merchant_mismatch'));
            }

            if ($fresh->settlement_mode !== null) {
                $this->creditForPaidOrder($fresh);
                $merchant->refresh();
            }
            $amountUsd = (string) $fresh->converted_amount;
            $bucketChanges = $this->consumeOrderFunds($merchant, $fresh, $amountUsd);

            $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_CHARGEBACK, '-'.$amountUsd, array_merge($bucketChanges, [
                'order_id' => $fresh->id,
                'operator_id' => $operator?->id,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey ? $idempotencyKey.':principal' : null,
            ]));

            if (bccomp($fee, '0', 2) > 0) {
                $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_CHARGEBACK_FEE, '-'.$fee, [
                    'order_id' => $fresh->id,
                    'operator_id' => $operator?->id,
                    'idempotency_key' => $idempotencyKey ? $idempotencyKey.':fee' : null,
                ]);
            }

            $fresh->status = 'chargeback';
            $fresh->save();
        });
    }

    /**
     * 订单当前是否还能退款。外层（加锁前）与锁内各调一次，规则必须完全一致。
     *
     * refunded 状态的订单只有在「还有钱没扣」时才放行，这就是补录网关退款的入口：
     * 人工退完的订单 refunded_amount == amount，会被这里挡住，不会重复扣款。
     *
     * @throws BalanceOperationException
     */
    private function assertRefundable(Order $order): void
    {
        if (! in_array($order->status, self::REFUNDABLE_STATUSES, true)) {
            throw new BalanceOperationException("当前订单状态「{$order->status}」不可退款，只有已收款的订单才能退款。");
        }

        if (self::hasChargebackLedger($order)) {
            throw new BalanceOperationException('该订单已完成拒付扣款，不能再退款。');
        }

        if ($order->status === 'refunded'
            && bccomp((string) $order->refunded_amount, (string) $order->amount, 2) >= 0) {
            throw new BalanceOperationException('该订单已全额退款并完成扣款，不能重复退款。');
        }
    }

    /**
     * 订单当前是否还能拒付。只有还没落过拒付流水（即钱没扣）时才放行，
     * 防止查询将状态纠正为 paid 后重复扣款，同时支持补录网关拒付。
     *
     * @throws BalanceOperationException
     */
    private function assertChargebackable(Order $order): void
    {
        if (! in_array($order->status, self::CHARGEBACKABLE_STATUSES, true)) {
            throw new BalanceOperationException("当前订单状态「{$order->status}」不可拒付。");
        }

        if (bccomp((string) $order->refunded_amount, '0', 2) > 0) {
            throw new BalanceOperationException('该订单已发生退款，不能再做拒付，请人工处理。');
        }

        if (self::hasChargebackLedger($order)) {
            throw new BalanceOperationException('该订单已完成拒付扣款，不能重复拒付。');
        }
    }

    /**
     * 该订单是否已经落过拒付本金流水（= 钱已经扣了）。
     *
     * 订单状态是 chargeback 但没有这条流水，说明是网关推送过来只改了状态、
     * 没有扣款的历史数据——那种订单需要补录，见 assertChargebackable()。
     */
    public static function hasChargebackLedger(Order $order): bool
    {
        return MerchantBalanceTransaction::query()
            ->withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', MerchantBalanceTransaction::TYPE_CHARGEBACK)
            ->exists();
    }

    /**
     * 本金先消耗本单待结算及循环保证金，其余由可提现余额承担。
     * 固定保证金、其他订单及待出款均不挪用；额外手续费不走此方法。
     *
     * @return array{pending_settlement_change: string, frozen_change: string, fund_freeze_id?: int}
     */
    private function consumeOrderFunds(Merchant $merchant, Order $order, string $amount): array
    {
        $changes = ['pending_settlement_change' => '0.00', 'frozen_change' => '0.00'];

        if ($order->settlement_mode === null) {
            return $changes;
        }

        $settlement = OrderSettlement::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
            ->where('order_id', $order->id)->lockForUpdate()->first();

        if ($settlement && in_array($settlement->status, [OrderSettlement::STATUS_WAITING_SHIPMENT, OrderSettlement::STATUS_PENDING], true)) {
            $pending = (string) $settlement->remaining_amount;
            $consumed = bccomp($pending, $amount, 2) < 0 ? $pending : $amount;
            $remaining = bcsub($pending, $consumed, 2);
            $merchant->pending_settlement_balance = bcsub((string) $merchant->pending_settlement_balance, $consumed, 2);
            $changes['pending_settlement_change'] = '-'.$consumed;
            $amount = bcsub($amount, $consumed, 2);
            $settlement->remaining_amount = $remaining;

            if (bccomp($remaining, '0', 2) === 0) {
                $settlement->status = OrderSettlement::STATUS_CANCELLED;
                $settlement->cancelled_at = now();
            }

            $settlement->save();
        }

        if (bccomp($amount, '0', 2) <= 0) {
            return $changes;
        }

        $freeze = MerchantFundFreeze::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
            ->where('order_id', $order->id)->where('status', MerchantFundFreeze::STATUS_FROZEN)->lockForUpdate()->first();

        if ($freeze) {
            $reserve = $freeze->remainingAmount();
            $consumed = bccomp($reserve, $amount, 2) < 0 ? $reserve : $amount;
            $remaining = bcsub($reserve, $consumed, 2);
            $merchant->frozen_balance = bcsub((string) $merchant->frozen_balance, $consumed, 2);
            $freeze->remaining_amount = $remaining;

            if (bccomp($remaining, '0', 2) === 0) {
                $freeze->status = MerchantFundFreeze::STATUS_CONSUMED;
            }

            $freeze->save();
            $changes['frozen_change'] = '-'.$consumed;
            $changes['fund_freeze_id'] = $freeze->id;
        }

        return $changes;
    }

    /**
     * 人工调整余额（���/减）。amount 带符号，reason 必填，operator 记录操作人。
     */
    public function manualAdjust(Merchant $merchant, string|float $amount, User $operator, string $reason): MerchantBalanceTransaction
    {
        $amount = $this->normalize($amount);

        if (bccomp($amount, '0', 2) === 0) {
            throw new BalanceOperationException('调整金额不能为 0。');
        }

        if (trim($reason) === '') {
            throw new BalanceOperationException('人工调整必须填写理由。');
        }

        return $this->mutate($merchant->id, function (Merchant $locked) use ($amount, $operator, $reason) {
            return $this->writeLedger($locked, MerchantBalanceTransaction::TYPE_MANUAL_ADJUST, $amount, [
                'operator_id' => $operator->id,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * 申请提现：可提现资金转入待出款，总余额不变。
     */
    public function requestWithdrawal(Merchant $merchant, string|float $amount, User $requester, ?string $payoutAccount = null, ?string $remark = null): MerchantWithdrawal
    {
        $amount = $this->normalize($amount);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new BalanceOperationException('提现金额必须大于 0。');
        }

        return $this->mutate($merchant->id, function (Merchant $locked) use ($amount, $requester, $payoutAccount, $remark) {
            $this->assertReversalsAccounted($locked);
            $available = $locked->availableBalance();

            if (bccomp($amount, $available, 2) > 0) {
                throw new BalanceOperationException("提现金额超过可提现余额（可提现 {$available} USD）。");
            }

            $locked->pending_withdrawal_balance = bcadd((string) $locked->pending_withdrawal_balance, $amount, 2);

            $withdrawal = MerchantWithdrawal::query()->create([
                'merchant_id' => $locked->id,
                'amount' => $amount,
                'status' => MerchantWithdrawal::STATUS_PENDING,
                'payout_account' => $payoutAccount,
                'remark' => $remark,
                'requested_by' => $requester->id,
                'uses_pending_balance' => true,
            ]);
            $this->writeLedger($locked, MerchantBalanceTransaction::TYPE_WITHDRAWAL_REQUEST, '0.00', [
                'withdrawal_id' => $withdrawal->id,
                'operator_id' => $requester->id,
                'pending_withdrawal_change' => $amount,
                'reason' => $remark,
            ]);

            return $withdrawal;
        });
    }

    /**
     * 后台确认放款：扣除总余额和提现占用额；兼容旧冻结方式。
     */
    public function approveWithdrawal(MerchantWithdrawal $withdrawal, User $reviewer, ?string $remark = null): void
    {
        $this->mutate($withdrawal->merchant_id, function (Merchant $locked) use ($withdrawal, $reviewer, $remark) {
            $fresh = MerchantWithdrawal::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $fresh->isPending()) {
                throw new BalanceOperationException('该提现单已被处理，无法重复操作。');
            }

            $this->assertReversalsAccounted($locked);
            $amount = (string) $fresh->amount;

            if (bccomp($locked->availableBalance(), '0', 2) < 0) {
                throw new BalanceOperationException(__('settlement.errors.negative_available'));
            }

            $bucket = $fresh->uses_pending_balance ? 'pending_withdrawal_balance' : 'frozen_balance';

            if (bccomp((string) $locked->$bucket, $amount, 2) < 0) {
                throw new BalanceOperationException(__('settlement.errors.invalid_withdrawal_hold'));
            }

            $locked->$bucket = bcsub((string) $locked->$bucket, $amount, 2);
            $this->writeLedger($locked, MerchantBalanceTransaction::TYPE_WITHDRAWAL, '-'.$amount, [
                'withdrawal_id' => $fresh->id,
                'operator_id' => $reviewer->id,
                'reason' => $remark,
                $fresh->uses_pending_balance ? 'pending_withdrawal_change' : 'frozen_change' => '-'.$amount,
            ]);

            $fresh->update([
                'status' => MerchantWithdrawal::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_remark' => $remark,
            ]);
        });
    }

    /**
     * 驳回提现：释放占用额，可提现恢复；总余额不变，记录分类划转流水。
     */
    public function rejectWithdrawal(MerchantWithdrawal $withdrawal, User $reviewer, ?string $remark = null): void
    {
        $this->mutate($withdrawal->merchant_id, function (Merchant $locked) use ($withdrawal, $reviewer, $remark) {
            $fresh = MerchantWithdrawal::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $fresh->isPending()) {
                throw new BalanceOperationException('该提现单已被处理，无法重复操作。');
            }

            $bucket = $fresh->uses_pending_balance ? 'pending_withdrawal_balance' : 'frozen_balance';
            $locked->$bucket = bcsub((string) $locked->$bucket, (string) $fresh->amount, 2);
            $this->writeLedger($locked, MerchantBalanceTransaction::TYPE_WITHDRAWAL_REJECTED, '0.00', [
                'withdrawal_id' => $fresh->id,
                'operator_id' => $reviewer->id,
                'reason' => $remark,
                $fresh->uses_pending_balance ? 'pending_withdrawal_change' : 'frozen_change' => '-'.$fresh->amount,
            ]);

            $fresh->update([
                'status' => MerchantWithdrawal::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_remark' => $remark,
            ]);
        });
    }

    /**
     * 开启争议审核事件：冻结订单金额（converted_amount，USD），订单状态改为
     * Order::STATUS_DISPUTE_REVIEW。加锁顺序与 refund()/chargeback() 一致——
     * 先锁 Merchant（mutate()），闭包内再锁 Order，避免引入新的死锁风险。
     * 新单扣除本单已被待结算或循环保证金占用的部分，避免重复冻结；记录分类流水。
     *
     * 允许的起始状态：paid（正常已付款订单）、disputing（网关已推送争议中，
     * 商户需要在这个窗口期提交申诉材料）。两者共用同一套冻结/回复/结束流程，
     * 结束时统一回退为 paid（见 releaseForDisputeEvent()）——disputing 只是
     * 多了一个允许进入的起点，不代表网关那边的争议已经有结果，真正的输赢
     * 仍然要等网关后续状态或人工核实，本方法不对此做任何推断。
     *
     * $attributes 需已完成校验/XSS 过滤/图片转存（由 OrderDisputeService 负责），
     * 这里只管钱和落库，期望包含：event_no, reason, images, final_action,
     * deadline_value, deadline_unit, deadline_hours。
     *
     * @throws BalanceOperationException 订单状态不是 paid/disputing，或该订单已存在处理中的事件
     */
    public function freezeForDisputeEvent(Order $order, User $operator, array $attributes): OrderDisputeEvent
    {
        return $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order, $operator, $attributes) {
            $fresh = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($fresh->status, ['paid', 'disputing'], true)) {
                throw new BalanceOperationException("当前订单状态「{$fresh->status}」不可开立争议审核事件，只有已收款或争议中的订单才能开立。");
            }

            $hasProcessing = OrderDisputeEvent::query()
                ->withoutGlobalScopes()
                ->where('order_id', $fresh->id)
                ->where('status', OrderDisputeEvent::STATUS_PROCESSING)
                ->exists();

            if ($hasProcessing) {
                throw new BalanceOperationException('该订单已存在处理中的争议审核事件，不能重复开立。');
            }

            $amount = (string) $fresh->converted_amount;

            if ($fresh->settlement_mode !== null) {
                $this->creditForPaidOrder($fresh);
                $merchant->refresh();
                $settlement = OrderSettlement::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                    ->where('order_id', $fresh->id)->lockForUpdate()->first();
                $held = $settlement && in_array($settlement->status, [OrderSettlement::STATUS_WAITING_SHIPMENT, OrderSettlement::STATUS_PENDING], true)
                    ? (string) $settlement->remaining_amount : '0.00';
                $freeze = MerchantFundFreeze::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)
                    ->where('order_id', $fresh->id)->where('status', MerchantFundFreeze::STATUS_FROZEN)->lockForUpdate()->first();
                $held = bcadd($held, $freeze?->remainingAmount() ?? '0.00', 2);
                $amount = bccomp($held, $amount, 2) >= 0 ? '0.00' : bcsub($amount, $held, 2);
            }

            $openedAt = now();

            $event = OrderDisputeEvent::query()->create(array_merge($attributes, [
                'merchant_id' => $fresh->merchant_id,
                'order_id' => $fresh->id,
                'order_no' => $fresh->order_no,
                'payment_method' => $fresh->payment_method,
                'status' => OrderDisputeEvent::STATUS_PROCESSING,
                'frozen_amount' => $amount,
                'opened_by' => $operator->id,
                'opened_at' => $openedAt,
                'due_at' => (clone $openedAt)->addHours((int) $attributes['deadline_hours']),
            ]));

            $merchant->frozen_balance = bcadd((string) $merchant->frozen_balance, $amount, 2);
            $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_DISPUTE_FREEZE, '0.00', [
                'order_id' => $fresh->id,
                'operator_id' => $operator->id,
                'frozen_change' => $amount,
            ]);

            $fresh->status = Order::STATUS_DISPUTE_REVIEW;
            $fresh->save();

            return $event;
        });
    }

    /**
     * 关闭争议审核事件（人工手动或系统到期自动均走这里）：按事件自身的
     * frozen_amount 快照释放冻结（不重新读取订单当前 converted_amount，
     * 避免订单金额在此期间发生变化导致释放金额与当初冻结的不一致），订单
     * 状态回退为 paid，事件状态置为 closed。
     *
     * 幂等：事件已不是 processing 时静默返回 false，不抛异常——手动关闭
     * 与到期自动关闭的定时任务可能并发碰到同一个事件，不应该让后到达的
     * 一方看到报错。
     *
     * $operator 为 null 表示系统自动关闭（closed_by 落 NULL）。
     */
    public function releaseForDisputeEvent(
        OrderDisputeEvent $event,
        ?User $operator,
        string $closeType,
        ?string $remark = null,
    ): bool {
        $released = $this->mutate($event->merchant_id, function (Merchant $merchant) use ($event, $operator, $closeType, $remark) {
            $fresh = OrderDisputeEvent::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($event->id);

            if (! $fresh->isProcessing()) {
                return false;
            }

            $merchant->frozen_balance = bcsub((string) $merchant->frozen_balance, (string) $fresh->frozen_amount, 2);
            $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_DISPUTE_RELEASE, '0.00', [
                'order_id' => $fresh->order_id,
                'operator_id' => $operator?->id,
                'frozen_change' => '-'.$fresh->frozen_amount,
                'reason' => $remark,
            ]);

            $fresh->update([
                'status' => OrderDisputeEvent::STATUS_CLOSED,
                'closed_by' => $operator?->id,
                'closed_at' => now(),
                'close_type' => $closeType,
                'close_remark' => $remark,
            ]);

            $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->find($fresh->order_id);

            if ($order && $order->status === Order::STATUS_DISPUTE_REVIEW) {
                $order->status = 'paid';
                $order->save();
            } elseif ($order) {
                Log::warning('order_dispute: 关闭事件时订单状态已不是 dispute_review，跳过状态回退', [
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                    'dispute_event_id' => $fresh->id,
                ]);
            }

            return true;
        });

        if ($released) {
            $event->refresh();
        }

        return $released;
    }

    /**
     * 通用人工冻结（与订单/提现无关）：amount 冻结进 merchants.frozen_balance，
     * reason 必填，operator 记录操作人。releaseAt 为 null 表示只能人工解冻，
     * 否则由 fund-freezes:release-due 命令到期自动释放（见 releaseFundFreeze()）。
     * 只允许冻结可提现余额，不得挪用待结算、待出款或其他保证金。
     */
    public function freezeFunds(Merchant $merchant, string|float $amount, User $operator, string $reason, \DateTimeInterface|string|null $releaseAt = null): MerchantFundFreeze
    {
        $amount = $this->normalize($amount);

        // $releaseAt 允许传字符串：Filament 的 DateTimePicker 提交上来的表单值
        // 就是 'Y-m-d H:i:s' 字符串，直接传给 ?DateTimeInterface 会 TypeError
        // （Argument #5 must be of type ?DateTimeInterface, string given）。
        // 这里统一规范化，调用方不必各自记得转换。
        $releaseAt = $this->normalizeDateTime($releaseAt);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new BalanceOperationException('冻结金额必须大于 0。');
        }

        if (trim($reason) === '') {
            throw new BalanceOperationException('冻结必须填写理由。');
        }

        return $this->mutate($merchant->id, function (Merchant $locked) use ($amount, $operator, $reason, $releaseAt) {
            if (bccomp($amount, $locked->availableBalance(), 2) > 0) {
                throw new BalanceOperationException(__('settlement.errors.freeze_exceeds_available'));
            }

            $freeze = MerchantFundFreeze::query()->create([
                'merchant_id' => $locked->id,
                'amount' => $amount,
                'remaining_amount' => $amount,
                'status' => MerchantFundFreeze::STATUS_FROZEN,
                'reason' => $reason,
                'release_at' => $releaseAt,
                'frozen_by' => $operator->id,
                'frozen_at' => now(),
            ]);

            $locked->frozen_balance = bcadd((string) $locked->frozen_balance, $amount, 2);
            $this->writeLedger($locked, MerchantBalanceTransaction::TYPE_RESERVE_FREEZE, '0.00', [
                'fund_freeze_id' => $freeze->id,
                'operator_id' => $operator->id,
                'frozen_change' => $amount,
                'reason' => $reason,
            ]);

            return $freeze;
        });
    }

    /**
     * 释放一笔人工资金冻结（人工手动或到期自动均走这里）：释放 frozen_balance，
     * 事件状态置为 released。$operator 为 null 表示系统自动解冻（released_by 落 NULL）。
     *
     * 幂等：记录已不是 frozen 时静默返回 false，不抛异常——手动解冻与到期自动解冻
     * 的定时任务可能并发碰到同一条记录，不应该让后到达的一方看到报错
     * （同 releaseForDisputeEvent() 的既有约定）。
     */
    public function releaseFundFreeze(MerchantFundFreeze $freeze, ?User $operator, string $releaseType, ?string $remark = null): bool
    {
        $released = $this->mutate($freeze->merchant_id, function (Merchant $merchant) use ($freeze, $operator, $releaseType, $remark) {
            $candidate = MerchantFundFreeze::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->findOrFail($freeze->id);
            $order = $candidate->order_id
                ? Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->lockForUpdate()->findOrFail($candidate->order_id)
                : null;
            $fresh = MerchantFundFreeze::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->lockForUpdate()->findOrFail($freeze->id);

            if (! $fresh->isFrozen()) {
                return false;
            }

            if ($order && $releaseType !== MerchantFundFreeze::RELEASE_TYPE_AUTO) {
                throw new BalanceOperationException(__('settlement.errors.rolling_reserve_auto_only'));
            }

            if ($releaseType === MerchantFundFreeze::RELEASE_TYPE_AUTO
                && (! $fresh->release_at || $fresh->release_at->isFuture())) {
                return false;
            }

            if ($order && in_array($order->status, ['disputing', Order::STATUS_DISPUTE_REVIEW], true)) {
                return false;
            }

            if ($order && $this->hasUnsettledReversals($merchant->id, $order->id)) {
                return false;
            }

            $remaining = $fresh->remainingAmount();
            $merchant->frozen_balance = bcsub((string) $merchant->frozen_balance, $remaining, 2);
            $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_RESERVE_RELEASE, '0.00', [
                'order_id' => $order?->id,
                'fund_freeze_id' => $fresh->id,
                'operator_id' => $operator?->id,
                'reason' => $remark,
                'frozen_change' => '-'.$remaining,
            ]);

            $fresh->update([
                'status' => MerchantFundFreeze::STATUS_RELEASED,
                'remaining_amount' => '0.00',
                'released_by' => $operator?->id,
                'released_at' => now(),
                'release_type' => $releaseType,
                'release_remark' => $remark,
            ]);

            return true;
        });

        if ($released) {
            $freeze->refresh();
        }

        return $released;
    }

    // ------------------------------------------------------------------
    // 内部：事务 + 行锁 + 写台账
    // ------------------------------------------------------------------

    /** 反向状态已提交但账务尚未完成时，不能按旧余额继续出款。 */
    private function assertReversalsAccounted(Merchant $merchant): void
    {
        if ($this->hasUnsettledReversals($merchant->id)) {
            throw new BalanceOperationException(__('settlement.errors.unsettled_reversal'));
        }
    }

    private function hasUnsettledReversals(int $merchantId, ?int $orderId = null): bool
    {
        return OrderAccountingTask::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)
            ->when($orderId !== null, fn (Builder $query) => $query->where('order_id', $orderId))
            ->unsettledReversals()->exists();
    }

    /**
     * 在事务内锁住商户行后执行回调，回调拿到的是加锁后的最新 Merchant 实例。
     */
    private function mutate(int $merchantId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($merchantId, $callback) {
            $merchant = Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);

            return $callback($merchant);
        }, 3);
    }

    /**
     * 落一条流水并同步更新商户总余额。必须在 mutate() 的锁内调用。
     * $amount 为带符号的 USD 字符串（正增负减）。
     */
    private function writeLedger(Merchant $merchant, string $type, string $amount, array $attributes = []): MerchantBalanceTransaction
    {
        $before = (string) $merchant->balance;
        $after = bcadd($before, $amount, 2);

        $merchant->balance = $after;
        $merchant->save();

        return MerchantBalanceTransaction::query()->create(array_merge([
            'merchant_id' => $merchant->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
        ], $attributes));
    }

    private function normalize(string|float $amount): string
    {
        return bcadd((string) $amount, '0', 2);
    }

    /**
     * 把可能来自表单的时间值统一成 DateTimeInterface。
     *
     * 背景：Filament 的 DateTimePicker 提交的是 'Y-m-d H:i:s' 字符串，
     * 而 freezeFunds() 的 $releaseAt 声明为 ?DateTimeInterface —— 直接传字符串
     * 会抛 TypeError。这里兼容字符串入参，统一用 Carbon 解析成系统时区的时间。
     *
     * @param  \DateTimeInterface|string|null  $value
     */
    private function normalizeDateTime(\DateTimeInterface|string|null $value): ?\DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            // 空字符串（表单里字段填了又清空）按"不设解冻时间"处理
            if ($value === '') {
                return null;
            }

            return Carbon::parse($value);
        }

        return $value;
    }
}
