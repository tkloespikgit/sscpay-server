<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * 定时任务：支付超过 UNSHIPPED_HOURS 仍无 OrderShipping 的订单，按商户汇总 Telegram 提醒。
 * 不依赖物流跟踪平台白名单 / 订单快照；与 17TRACK 轨迹无关。
 */
class NotifyUnshippedOverdueCommand extends Command
{
    protected $signature = 'orders:notify-unshipped-overdue';

    protected $description = '汇总超期未发货订单并发送商户 Telegram 通知';

    /** 单条消息中列出的订单号上限，超出用「另有 N 笔」摘要。 */
    private const LIST_LIMIT = 20;

    public function handle(TelegramNotificationService $telegram): int
    {
        $deadline = now()->subHours(LogisticsTrackingSyncService::UNSHIPPED_HOURS);

        $orders = Order::query()->withoutGlobalScopes()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $deadline)
            ->doesntHave('shipping')
            ->orderBy('paid_at')
            ->get(['id', 'merchant_id', 'order_no', 'merchant_order_no', 'paid_at']);

        if ($orders->isEmpty()) {
            $this->info('No overdue unshipped orders.');

            return self::SUCCESS;
        }

        $sent = 0;

        $orders->groupBy('merchant_id')->each(function (Collection $group, $merchantId) use ($telegram, &$sent): void {
            $count = $group->count();
            $lines = $group->take(self::LIST_LIMIT)->map(
                fn (Order $order) => e($order->order_no).' / '.e((string) $order->merchant_order_no)
            )->implode("\n");

            $more = $count > self::LIST_LIMIT
                ? "\n… 另有 ".($count - self::LIST_LIMIT).' 笔'
                : '';

            $message = __('admin.telegram_notification.unshipped_overdue', [
                'count' => $count,
                'hours' => LogisticsTrackingSyncService::UNSHIPPED_HOURS,
                'list' => $lines.$more,
            ]);

            if ($telegram->send((int) $merchantId, $message)) {
                $sent++;
            }
        });

        $this->info("Notified {$sent} merchant(s); {$orders->count()} order(s) overdue.");

        return self::SUCCESS;
    }
}
