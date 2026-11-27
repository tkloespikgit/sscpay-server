<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderAccountingTask;
use App\Models\OrderDisputeEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class OrderAccountingService
{
    public function __construct(private readonly BalanceService $balanceService) {}

    /** 在订单锁内记录可靠待办；旧单仅记录本次首次收款或新发生的反向待办，不补历史收入。 */
    public function recordForStatus(Order $order, string $targetStatus, bool $firstPayment = false): void
    {
        if ($order->payment_type !== 'fiat') {
            return;
        }

        if (in_array($targetStatus, Order::NEVER_PAID_STATUSES, true)) {
            return;
        }

        if ($targetStatus === 'refunded') {
            $this->recordTask($order, OrderAccountingTask::TYPE_REFUND);

            return;
        }

        if ($order->settlement_mode !== null || $firstPayment) {
            $this->recordTask($order, OrderAccountingTask::TYPE_PAID);
        }

        if ($targetStatus === 'chargeback') {
            $this->recordTask($order, OrderAccountingTask::TYPE_CHARGEBACK);
        }
    }

    public function processPendingForOrder(Order $order): void
    {
        OrderAccountingTask::query()->withoutGlobalScopes()
            ->where('merchant_id', $order->merchant_id)
            ->where('order_id', $order->id)
            ->where('status', OrderAccountingTask::STATUS_PENDING)
            ->orderByRaw("CASE WHEN type = 'paid' THEN 1 ELSE 0 END")
            ->orderBy('id')
            ->get()
            ->each(fn (OrderAccountingTask $task): bool => $this->process($task));
    }

    public function process(OrderAccountingTask $task): bool
    {
        try {
            return DB::transaction(function () use (&$task): bool {
                Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->merchant_id);
                $order = Order::query()->withoutGlobalScopes()
                    ->where('merchant_id', $task->merchant_id)->lockForUpdate()->findOrFail($task->order_id);

                if ($task->type === OrderAccountingTask::TYPE_PAID) {
                    $task = OrderAccountingTask::query()->withoutGlobalScopes()
                        ->where('merchant_id', $order->merchant_id)->where('order_id', $order->id)
                        ->where('type', OrderAccountingTask::TYPE_CHARGEBACK)
                        ->where('status', OrderAccountingTask::STATUS_PENDING)->orderBy('id')
                        ->lockForUpdate()->first() ?? $task;
                }

                $fresh = OrderAccountingTask::query()->withoutGlobalScopes()
                    ->where('merchant_id', $order->merchant_id)->where('order_id', $order->id)
                    ->lockForUpdate()->findOrFail($task->id);

                if ($fresh->status !== OrderAccountingTask::STATUS_PENDING) {
                    return false;
                }

                if ($fresh->type === OrderAccountingTask::TYPE_REFUND) {
                    if (bccomp((string) $order->refunded_amount, (string) $order->amount, 2) >= 0 || BalanceService::hasChargebackLedger($order)) {
                        $this->complete($fresh);
                    } else {
                        $fresh->update(['status' => OrderAccountingTask::STATUS_MANUAL_REVIEW,
                            'last_attempted_at' => now(), 'last_error' => OrderAccountingTask::REFUND_DETAIL_REQUIRED, 'next_attempt_at' => now()->addMinute()]);
                    }

                    return true;
                }

                if ($fresh->type !== OrderAccountingTask::TYPE_PAID && $order->settlement_mode !== null) {
                    $paidTask = OrderAccountingTask::query()->withoutGlobalScopes()
                        ->where('merchant_id', $order->merchant_id)->where('order_id', $order->id)
                        ->where('type', OrderAccountingTask::TYPE_PAID)->lockForUpdate()->firstOrFail();

                    if ($paidTask->status !== OrderAccountingTask::STATUS_COMPLETED) {
                        $this->balanceService->creditForPaidOrder($order);
                        $this->complete($paidTask);
                    }
                }

                if ($fresh->type !== OrderAccountingTask::TYPE_PAID) {
                    $review = $order->activeDisputeEvent()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->first();
                    if ($review) {
                        $this->balanceService->releaseForDisputeEvent($review, null, OrderDisputeEvent::CLOSE_TYPE_AUTO,
                            '网关已确认拒付，审核冻结与拒付扣款在同一事务处理。');
                    }
                    $order->refresh();
                }

                if ($fresh->type === OrderAccountingTask::TYPE_PAID) {
                    $this->balanceService->creditForPaidOrder($order);
                } elseif ($fresh->type === OrderAccountingTask::TYPE_CHARGEBACK) {
                    if (! BalanceService::hasChargebackLedger($order)) {
                        $this->balanceService->chargeback($order, null,
                            __('admin.finance.chargeback.gateway_auto_reason'), "gateway_chargeback:{$order->id}");
                    }
                } else {
                    throw new RuntimeException('未知订单记账任务类型。');
                }

                $this->complete($fresh);

                return true;
            }, 3);
        } catch (Throwable $exception) {
            OrderAccountingTask::query()->withoutGlobalScopes()
                ->where('merchant_id', $task->merchant_id)
                ->where('order_id', $task->order_id)->where('status', OrderAccountingTask::STATUS_PENDING)
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_attempted_at' => now(),
                    'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** min(6, (int) $task->attempts)))),
                    'last_error' => $exception instanceof BalanceOperationException ? $exception->getMessage() : $exception::class,
                    'status' => $exception instanceof BalanceOperationException
                        ? OrderAccountingTask::STATUS_MANUAL_REVIEW : OrderAccountingTask::STATUS_PENDING,
                ]);

            throw $exception;
        }
    }

    /** 人工补录后的本金结清证据用于关闭待办，不再次执行任何资金操作。 */
    public function reconcileCompletedTask(OrderAccountingTask $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->merchant_id);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $task->merchant_id)
                ->lockForUpdate()->findOrFail($task->order_id);
            $fresh = OrderAccountingTask::query()->withoutGlobalScopes()
                ->where('merchant_id', $order->merchant_id)->where('order_id', $order->id)
                ->lockForUpdate()->findOrFail($task->id);

            if ($fresh->status !== OrderAccountingTask::STATUS_MANUAL_REVIEW) {
                return false;
            }

            $accounted = match ($fresh->type) {
                OrderAccountingTask::TYPE_PAID => $order->balanceTransactions()->withoutGlobalScopes()
                    ->where('merchant_id', $order->merchant_id)->where('type', 'order_paid')->exists(),
                OrderAccountingTask::TYPE_REFUND => bccomp((string) $order->refunded_amount, (string) $order->amount, 2) >= 0
                    || BalanceService::hasChargebackLedger($order),
                OrderAccountingTask::TYPE_CHARGEBACK => BalanceService::hasChargebackLedger($order),
                default => false,
            };

            if (! $accounted) {
                return false;
            }

            $this->complete($fresh);

            return true;
        }, 3);
    }

    /** 认证查单确认退款清单完整，且清单内每笔均已入账。 */
    public function completeRefundHint(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->merchant_id);
            $locked = Order::forMerchant($order->merchant_id)->lockForUpdate()->findOrFail($order->id);
            if ($locked->gatewayRefunds()->withoutGlobalScopes()->where('merchant_id', $locked->merchant_id)->unsettled()->exists()) {
                return;
            }
            OrderAccountingTask::forMerchant($locked->merchant_id)->where('order_id', $locked->id)
                ->where('type', OrderAccountingTask::TYPE_REFUND)
                ->where('status', OrderAccountingTask::STATUS_MANUAL_REVIEW)
                ->where('last_error', OrderAccountingTask::REFUND_DETAIL_REQUIRED)
                ->update(['status' => OrderAccountingTask::STATUS_COMPLETED, 'completed_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);

            // 旧插件可能将部分退款记成整单终态；完整渠道清单已核实时，解除这项历史出款阻断。
            if ($locked->gateway_status === 'refunded' && $locked->paid_at !== null
                && bccomp($locked->refunded_amount, '0', 2) > 0 && bccomp($locked->refunded_amount, $locked->amount, 2) < 0) {
                $locked->update(['gateway_status' => 'paid', 'gateway_status_version' => $locked->gateway_status_version + 1]);
            }
        }, 3);
    }

    private function recordTask(Order $order, string $type): void
    {
        $task = OrderAccountingTask::query()->withoutGlobalScopes()->firstOrCreate([
            'order_id' => $order->id,
            'type' => $type,
        ], ['merchant_id' => $order->merchant_id]);

        if ((int) $task->merchant_id !== (int) $order->merchant_id) {
            throw new RuntimeException('订单记账待办与订单商户不一致。');
        }
    }

    private function complete(OrderAccountingTask $task): void
    {
        $task->update([
            'status' => OrderAccountingTask::STATUS_COMPLETED,
            'attempts' => $task->attempts + 1,
            'last_attempted_at' => now(),
            'next_attempt_at' => null,
            'completed_at' => now(),
            'last_error' => null,
        ]);
    }
}
