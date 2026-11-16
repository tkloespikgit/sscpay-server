<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\TelegramNotificationRouter;
use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * 定时任务：支付超过通知配置天数仍无 OrderShipping 的订单，按商户汇总 Telegram 提醒。
 * 不依赖物流跟踪平台白名单 / 订单快照；与 17TRACK 轨迹无关。
 */
class NotifyUnshippedOverdueCommand extends Command
{
    protected $signature = 'orders:notify-unshipped-overdue';

    protected $description = '汇总超期未发货订单并发送商户 Telegram 通知';

    private const MESSAGE_LENGTH_LIMIT = 4000;

    public function handle(TelegramNotificationRouter $routes): int
    {
        $settings = $routes->settings(TelegramNotificationKind::UnshippedOverdue);

        if (! $settings['enabled']) {
            return self::SUCCESS;
        }

        $hours = $settings['unshipped_days'] * 24;
        $deadline = now()->subHours($hours);

        $orders = Order::query()->withoutGlobalScopes()
            ->paidUnshipped()
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $deadline)
            ->orderBy('paid_at')
            ->get(['id', 'merchant_id', 'order_no', 'merchant_order_no', 'transaction_id', 'payment_method_id', 'payment_method', 'paid_at', 'currency', 'amount']);

        if ($orders->isEmpty()) {
            $this->info('No overdue unshipped orders.');

            return self::SUCCESS;
        }

        $sent = 0;

        $orders->groupBy('merchant_id')->each(function (Collection $group, $merchantId) use ($routes, $hours, &$sent): void {
            $heading = $routes->message(TelegramNotificationKind::UnshippedOverdue, [
                'count' => $group->count(),
                'hours' => $hours,
            ]);
            $message = $heading;
            $notified = false;
            $summaries = OrderTelegramSummary::forOrders($group);

            foreach ($group as $order) {
                $summary = $summaries[(int) $order->id];

                if ($message !== $heading && mb_strlen($message."\n\n".$summary) > self::MESSAGE_LENGTH_LIMIT) {
                    $notified = $routes->send(TelegramNotificationKind::UnshippedOverdue, $message, (int) $merchantId)->sent || $notified;
                    $message = $heading;
                }

                $message .= "\n\n".$summary;
            }

            if ($routes->send(TelegramNotificationKind::UnshippedOverdue, $message, (int) $merchantId)->sent || $notified) {
                $sent++;
            }
        });

        $this->info("Notified {$sent} merchant(s); {$orders->count()} order(s) overdue.");

        return self::SUCCESS;
    }
}
