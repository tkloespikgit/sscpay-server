<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderAccountingTask;
use App\Models\OrderSettlement;
use App\Services\BalanceService;
use App\Services\OrderAccountingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class SettleDueOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:settle-due {--limit=500 : 每类任务单次最多处理的记录数（1-10000）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '补偿订单记账、启动发货结算并处理到期待结算记录';

    /**
     * Execute the console command.
     */
    public function handle(BalanceService $balanceService, OrderAccountingService $accountingService): int
    {
        $limit = (string) $this->option('limit');

        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 10000) {
            $this->error('--limit 必须在 1 至 10000 之间。');

            return self::INVALID;
        }

        $failed = 0;
        $reconciled = $this->processCandidates(
            OrderAccountingTask::query()->withoutGlobalScopes()
                ->where('status', OrderAccountingTask::STATUS_MANUAL_REVIEW)
                ->where(function (Builder $query): void {
                    $query->where(fn (Builder $paid) => $paid->where('type', OrderAccountingTask::TYPE_PAID)
                        ->whereHas('order.balanceTransactions', fn (Builder $ledger) => $ledger->withoutGlobalScopes()->where('type', 'order_paid')))
                        ->orWhere(fn (Builder $refund) => $refund->where('type', OrderAccountingTask::TYPE_REFUND)
                            ->whereHas('order', fn (Builder $order) => $order->withoutGlobalScopes()->whereColumn('refunded_amount', '>=', 'amount')))
                        ->orWhere(fn (Builder $chargeback) => $chargeback->where('type', OrderAccountingTask::TYPE_CHARGEBACK)
                            ->whereHas('order.balanceTransactions', fn (Builder $ledger) => $ledger->withoutGlobalScopes()->where('type', 'chargeback')));
                }),
            (int) $limit,
            fn (OrderAccountingTask $task): bool => $accountingService->reconcileCompletedTask($task),
            $failed,
        );

        $accounted = $this->processCandidates(
            OrderAccountingTask::query()->withoutGlobalScopes()
                ->where('status', OrderAccountingTask::STATUS_PENDING)
                ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())),
            (int) $limit,
            fn (OrderAccountingTask $task): bool => $accountingService->process($task),
            $failed,
        );

        $started = $this->processCandidates(
            OrderSettlement::query()->withoutGlobalScopes()->where('status', 'waiting_shipment')
                ->whereHas('order', fn (Builder $query) => $query->withoutGlobalScopes()
                    ->whereIn('status', ['paid', 'shipped', 'completed', 'partially_refunded'])->whereHas('shipping')),
            (int) $limit,
            function (OrderSettlement $settlement) use ($balanceService): bool {
                $order = Order::query()->withoutGlobalScopes()
                    ->where('merchant_id', $settlement->merchant_id)->find($settlement->order_id);

                if (! $order) {
                    return false;
                }

                $balanceService->startSettlementForShippedOrder($order);

                return true;
            },
            $failed,
        );

        $settled = $this->processCandidates(
            OrderSettlement::query()->withoutGlobalScopes()->where('status', 'pending')->where('due_at', '<=', now())
                ->whereHas('order', fn (Builder $query) => $query->withoutGlobalScopes()
                    ->whereIn('status', ['paid', 'shipped', 'completed', 'partially_refunded'])),
            (int) $limit,
            fn (OrderSettlement $settlement): bool => $balanceService->settleOrder($settlement),
            $failed,
        );

        $this->info("记账 {$accounted} 笔，人工补录核销 {$reconciled} 笔，检查发货 {$started} 笔，结算 {$settled} 笔，失败 {$failed} 笔。");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** 无锁读取候选，服务逐笔执行短事务；失败不阻断其他商户。 */
    private function processCandidates(Builder $query, int $limit, callable $process, int &$failed): int
    {
        $visited = 0;
        $processed = 0;
        $query->chunkById(min(100, $limit), function ($records) use ($limit, $process, &$visited, &$processed, &$failed): bool {
            foreach ($records as $record) {
                try {
                    if ($process($record)) {
                        $processed++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }

                $visited++;

                if ($visited >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $processed;
    }
}
