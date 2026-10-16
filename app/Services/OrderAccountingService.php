<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderAccountingTask;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class OrderAccountingService
{
    public function __construct(private readonly BalanceService $balanceService) {}

    /** 在持有订单行锁的状态事务内调用；旧单不补建历史记账任务。 */
    public function recordForStatus(Order $order, string $targetStatus): void
    {
        if ($order->settlement_mode === null || in_array($targetStatus, Order::NEVER_PAID_STATUSES, true)) {
            return;
        }

        $this->recordTask($order, OrderAccountingTask::TYPE_PAID);

        if (in_array($targetStatus, ['refunded', 'chargeback'], true)) {
            $this->recordTask($order, $targetStatus === 'refunded' ? OrderAccountingTask::TYPE_REFUND : OrderAccountingTask::TYPE_CHARGEBACK);
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
                        ->whereIn('type', [OrderAccountingTask::TYPE_REFUND, OrderAccountingTask::TYPE_CHARGEBACK])
                        ->where('status', OrderAccountingTask::STATUS_PENDING)->orderBy('id')
                        ->lockForUpdate()->first() ?? $task;
                }

                $fresh = OrderAccountingTask::query()->withoutGlobalScopes()
                    ->where('merchant_id', $order->merchant_id)->where('order_id', $order->id)
                    ->lockForUpdate()->findOrFail($task->id);

                if ($fresh->status !== OrderAccountingTask::STATUS_PENDING) {
                    return false;
                }

                if ($order->settlement_mode === null) {
                    throw new RuntimeException('历史订单不能执行新结算记账任务。');
                }

                if ($fresh->type !== OrderAccountingTask::TYPE_PAID) {
                    $paidTask = OrderAccountingTask::query()->withoutGlobalScopes()
                        ->where('merchant_id', $order->merchant_id)->where('order_id', $order->id)
                        ->where('type', OrderAccountingTask::TYPE_PAID)->lockForUpdate()->firstOrFail();

                    if ($paidTask->status !== OrderAccountingTask::STATUS_COMPLETED) {
                        $this->balanceService->creditForPaidOrder($order);
                        $this->complete($paidTask);
                    }
                }

                if ($fresh->type === OrderAccountingTask::TYPE_PAID) {
                    $this->balanceService->creditForPaidOrder($order);
                } elseif ($fresh->type === OrderAccountingTask::TYPE_REFUND) {
                    $remaining = bcsub((string) $order->amount, (string) $order->refunded_amount, 2);

                    if (bccomp($remaining, '0', 2) > 0) {
                        $this->balanceService->refund($order, $remaining, null,
                            __('admin.finance.refund.gateway_auto_reason'), "gateway_refund:{$order->id}");
                    }
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
                OrderAccountingTask::TYPE_REFUND => bccomp((string) $order->refunded_amount, (string) $order->amount, 2) >= 0,
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
