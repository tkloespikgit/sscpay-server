<?php

namespace App\Console\Commands;

use App\Models\OrderDisputeEvent;
use App\Services\OrderDisputeService;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;

/**
 * 扫描"24 小时内到期、尚未提醒过"的处理中争议审核事件，通过商户已配置的
 * Telegram 机器人发一条到期提醒。reminded_at 置位后不会重复发送
 * （即便商户未配置 Telegram 导致实际没发出去，也按"跳过不算失败"处理，
 * 不会无限重试，同 TelegramNotificationService::send() 的既有哲学一致）。
 *
 * 建议调度频率：每 5-10 分钟一次。
 *
 *   Schedule::command('order-disputes:send-reminders')->everyFiveMinutes()->withoutOverlapping();
 */
class SendDueOrderDisputeReminders extends Command
{
    protected $signature = 'order-disputes:send-reminders';

    protected $description = '扫描即将到期的争议审核事件并发送 Telegram 提醒';

    public function handle(OrderDisputeService $service, TelegramNotificationService $telegram): int
    {
        $dueSoonEvents = OrderDisputeEvent::query()
            ->withoutGlobalScopes()
            ->dueForReminder()
            ->get();

        if ($dueSoonEvents->isEmpty()) {
            $this->info('No dispute events due for a reminder.');

            return self::SUCCESS;
        }

        foreach ($dueSoonEvents as $event) {
            $service->sendDueReminder($event, $telegram);
        }

        $this->info("Sent reminder for {$dueSoonEvents->count()} dispute event(s).");

        return self::SUCCESS;
    }
}
