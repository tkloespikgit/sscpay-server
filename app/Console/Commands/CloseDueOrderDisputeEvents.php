<?php

namespace App\Console\Commands;

use App\Models\OrderDisputeEvent;
use App\Services\OrderDisputeService;
use Illuminate\Console\Command;

/**
 * 扫描"处理中且已到期"的争议审核事件，逐个自动结束（释放冻结资金，
 * 订单状态回退 paid）。close_type 落 auto，closed_by 落 NULL，
 * 与人工手动结束在审计记录里可区分。
 *
 * 建议调度频率：每 5-10 分钟一次。
 *
 *   Schedule::command('order-disputes:close-due')->everyFiveMinutes()->withoutOverlapping();
 */
class CloseDueOrderDisputeEvents extends Command
{
    protected $signature = 'order-disputes:close-due';

    protected $description = '扫描到期的争议审核事件并自动结束（释放冻结资金）';

    public function handle(OrderDisputeService $service): int
    {
        $dueEvents = OrderDisputeEvent::query()
            ->withoutGlobalScopes()
            ->dueForAutoClose()
            ->get();

        if ($dueEvents->isEmpty()) {
            $this->info('No due dispute events.');

            return self::SUCCESS;
        }

        $closed = 0;

        foreach ($dueEvents as $event) {
            if ($service->autoClose($event)) {
                $closed++;
            }
        }

        $this->info("Auto-closed {$closed} of {$dueEvents->count()} due dispute event(s).");

        return self::SUCCESS;
    }
}
