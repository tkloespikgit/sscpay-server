<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Models\Merchant;
use App\Models\MerchantBalanceTransaction;
use App\Models\MerchantFundFreeze;
use App\Models\MerchantWithdrawal;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\OrderRefund;
use App\Models\User;
use Closure;
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
     * 支付成功后给商户入账（订单金额的 USD 口径）。幂等：同一订单只入账一次。
     * 订单状态流转（-> paid）由调用方负责，本方法只管钱，建议放在同一外层事务里。
     *
     * @return MerchantBalanceTransaction|null 已入过账则返回 null
     */
    public function creditForPaidOrder(Order $order): ?MerchantBalanceTransaction
    {
        // 按扣除支付方式手续费后的实际到账金额（settlement_amount）入账，
        // 不是订单折算 USD 全额（converted_amount）——手续费快照在下单时已经算好。
        $amount = (string) $order->settlement_amount;
        $key = 'order_paid:'.$order->id;

        return $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order, $amount, $key) {
            $exists = MerchantBalanceTransaction::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $key)
                ->exists();

            if ($exists) {
                return null;
            }

            return $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_ORDER_PAID, $amount, [
                'order_id' => $order->id,
                'idempotency_key' => $key,
            ]);
        });
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
        $fee = $method ? (string) $method->refund_fee : '0';

        return $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order, $amountOriginal, $operator, $reason, $fee, $idempotencyKey) {
            // 在锁内重新读取订单最新状态，做并发安全的校验：不能只信外层（加锁前）
            // 读到的旧状态——等锁期间订单可能已被另一笔并发的拒付/退款改变状态。
            $fresh = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            $this->assertRefundable($fresh);

            $remaining = bcsub((string) $fresh->amount, (string) $fresh->refunded_amount, 2);

            if (bccomp($amountOriginal, $remaining, 2) > 0) {
                throw new BalanceOperationException("退款金额超过剩余可退金额（剩余 {$remaining} {$fresh->currency}）。");
            }

            $amountUsd = bcdiv(bcmul((string) $fresh->converted_amount, $amountOriginal, 6), (string) $fresh->amount, 2);

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
            $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_REFUND, '-'.$amountUsd, [
                'order_id' => $fresh->id,
                'order_refund_id' => $refund->id,
                'operator_id' => $operator?->id,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey ? $idempotencyKey.':principal' : null,
            ]);

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
        $fee = $method ? (string) $method->chargeback_fee : '0';

        $this->mutate($order->merchant_id, function (Merchant $merchant) use ($order, $amountUsd, $fee, $operator, $reason, $idempotencyKey) {
            // 在锁内重新读取订单最新状态/退款额：等锁期间订单可能已被另一笔并发的
            // 退款/拒付改变状态，不能只信外层（加锁前）读到的旧值，否则会对同一笔
            // 订单重复扣款（如退款已发生后又被拒付扣了全额）。
            $fresh = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            $this->assertChargebackable($fresh);

            $this->writeLedger($merchant, MerchantBalanceTransaction::TYPE_CHARGEBACK, '-'.$amountUsd, [
                'order_id' => $fresh->id,
                'operator_id' => $operator?->id,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey ? $idempotencyKey.':principal' : null,
            ]);

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

        if ($order->status === 'refunded'
            && bccomp((string) $order->refunded_amount, (string) $order->amount, 2) >= 0) {
            throw new BalanceOperationException('该订单已全额退款并完成扣款，不能重复退款。');
        }
    }

    /**
     * 订单当前是否还能拒付。规则同 assertRefundable()：chargeback 状态的订单
     * 只有在还没落过拒付流水（即钱没扣）时才放行，用于补录网关拒付。
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

        if ($order->status === 'chargeback' && self::hasChargebackLedger($order)) {
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
     * 申请提现：校验不超过可用余额，冻结相应金额（balance 不变，frozen 增加）。
     */
    public function requestWithdrawal(Merchant $merchant, string|float $amount, User $requester, ?string $payoutAccount = null, ?string $remark = null): MerchantWithdrawal
    {
        $amount = $this->normalize($amount);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new BalanceOperationException('提现金额必须大于 0。');
        }

        return $this->mutate($merchant->id, function (Merchant $locked) use ($amount, $requester, $payoutAccount, $remark) {
            $available = bcsub((string) $locked->balance, (string) $locked->frozen_balance, 2);

            if (bccomp($amount, $available, 2) > 0) {
                throw new BalanceOperationException("提现金额超过可提现余额（可提现 {$available} USD）。");
            }

            $locked->frozen_balance = bcadd((string) $locked->frozen_balance, $amount, 2);
            $locked->save();

            return MerchantWithdrawal::query()->create([
                'merchant_id' => $locked->id,
                'amount' => $amount,
                'status' => MerchantWithdrawal::STATUS_PENDING,
                'payout_account' => $payoutAccount,
                'remark' => $remark,
                'requested_by' => $requester->id,
            ]);
        });
    }

    /**
     * 审核通过并放款：冻结转出（balance 与 frozen 同时减少），落一条 withdrawal 流水。
     */
    public function approveWithdrawal(MerchantWithdrawal $withdrawal, User $reviewer, ?string $remark = null): void
    {
        $this->mutate($withdrawal->merchant_id, function (Merchant $locked) use ($withdrawal, $reviewer, $remark) {
            $fresh = MerchantWithdrawal::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $fresh->isPending()) {
                throw new BalanceOperationException('该提现单已被处理，无法重复操作。');
            }

            $amount = (string) $fresh->amount;

            // 释放冻结并真正扣除余额
            $locked->frozen_balance = bcsub((string) $locked->frozen_balance, $amount, 2);
            $this->writeLedger($locked, MerchantBalanceTransaction::TYPE_WITHDRAWAL, '-'.$amount, [
                'withdrawal_id' => $fresh->id,
                'operator_id' => $reviewer->id,
                'reason' => $remark,
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
     * 驳回提现：释放冻结（frozen 减少），可用余额恢复；不产生余额流水。
     */
    public function rejectWithdrawal(MerchantWithdrawal $withdrawal, User $reviewer, ?string $remark = null): void
    {
        $this->mutate($withdrawal->merchant_id, function (Merchant $locked) use ($withdrawal, $reviewer, $remark) {
            $fresh = MerchantWithdrawal::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $fresh->isPending()) {
                throw new BalanceOperationException('该提现单已被处理，无法重复操作。');
            }

            $locked->frozen_balance = bcsub((string) $locked->frozen_balance, (string) $fresh->amount, 2);
            $locked->save();

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
     * 不写余额流水（冻结/释放不改变 balance 总额，同提现冻结的既有约定）。
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
            $merchant->save();

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
            $merchant->save();

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
     * 不校验可用余额上限——允许超冻，与争议审核冻结（freezeForDisputeEvent）行为一致。
     */
    public function freezeFunds(Merchant $merchant, string|float $amount, User $operator, string $reason, ?\DateTimeInterface $releaseAt = null): MerchantFundFreeze
    {
        $amount = $this->normalize($amount);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new BalanceOperationException('冻结金额必须大于 0。');
        }

        if (trim($reason) === '') {
            throw new BalanceOperationException('冻结必须填写理由。');
        }

        return $this->mutate($merchant->id, function (Merchant $locked) use ($amount, $operator, $reason, $releaseAt) {
            $freeze = MerchantFundFreeze::query()->create([
                'merchant_id' => $locked->id,
                'amount' => $amount,
                'status' => MerchantFundFreeze::STATUS_FROZEN,
                'reason' => $reason,
                'release_at' => $releaseAt,
                'frozen_by' => $operator->id,
                'frozen_at' => now(),
            ]);

            $locked->frozen_balance = bcadd((string) $locked->frozen_balance, $amount, 2);
            $locked->save();

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
            $fresh = MerchantFundFreeze::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($freeze->id);

            if (! $fresh->isFrozen()) {
                return false;
            }

            $merchant->frozen_balance = bcsub((string) $merchant->frozen_balance, (string) $fresh->amount, 2);
            $merchant->save();

            $fresh->update([
                'status' => MerchantFundFreeze::STATUS_RELEASED,
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

    /**
     * 在事务内锁住商户行后执行回调，回调拿到的是加锁后的最新 Merchant 实例。
     */
    private function mutate(int $merchantId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($merchantId, $callback) {
            $merchant = Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);

            return $callback($merchant);
        });
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
}
