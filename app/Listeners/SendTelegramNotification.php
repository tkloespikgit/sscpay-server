<?php

namespace App\Listeners;

use App\Events\LogisticsImportCompleted;
use App\Events\OrderStatusChanged;
use App\Services\TelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;

/**
 * 业务代码只管 fire 事件，不需要关心 Telegram 发不发得出去——
 * 商户未配置/未启用 Telegram 时 TelegramNotificationService::send() 内部
 * 会直接跳过，这里不需要额外判断。
 *
 * 国际化说明：这些消息文案跟着系统当前的 App::getLocale() 走，不是按
 * 商户各自的语言偏好发送——这套系统目前没有"每个商户单独设置语言"这个
 * 字段，如果需要，需要在 merchants 表加一列（如 locale）并在这里
 * App::setLocale($merchant->locale) 临时切换，现在没有实现这一层。
 */
class SendTelegramNotification implements ShouldQueue
{
    public function __construct(private readonly TelegramNotificationService $telegram) {}

    public function handleOrderStatusChanged(OrderStatusChanged $event): void
    {
        // disputing/refunded/chargeback 目前只会由 payment_status webhook
        // （OrderPaymentStatusService）触发本事件，所以下面这几条消息文案里
        // "来自网关通知"这类措辞是安全的；如果未来后台人工操作也开始 fire
        // 这个事件，需要回头看看这几条文案是否还准确。
        $message = match ($event->newStatus) {
            'paid' => $this->paidMessage($event),
            'shipped' => $this->shippedMessage($event),
            'cancelled', 'failed' => $this->failedMessage($event),
            'disputing' => $this->disputingMessage($event),
            'refunded' => $this->refundedMessage($event),
            'chargeback' => $this->chargebackMessage($event),
            default => null,
        };

        if ($message === null) {
            return;
        }

        $this->telegram->send($event->order->merchant_id, $message);
    }

    public function handleLogisticsImportCompleted(LogisticsImportCompleted $event): void
    {
        $task = $event->task;

        $message = __('admin.telegram_notification.logistics_import_completed', [
            'file_name' => $task->file_name,
            'total' => $task->total_records,
            'success' => $task->success_count,
            'failed' => $task->fail_count,
        ]);

        $this->telegram->send($task->merchant_id, $message);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderStatusChanged::class => 'handleOrderStatusChanged',
            LogisticsImportCompleted::class => 'handleLogisticsImportCompleted',
        ];
    }

    private function paidMessage(OrderStatusChanged $event): string
    {
        $order = $event->order;

        // 从 disputing 回退到 paid 是"争议胜诉"，不是首次支付成功，用不同文案区分。
        if ($event->oldStatus === 'disputing') {
            return __('admin.telegram_notification.order_dispute_resolved', [
                'order_no' => $order->order_no,
            ]);
        }

        return __('admin.telegram_notification.order_paid', [
            'order_no' => $order->order_no,
            'currency' => $order->currency,
            'amount' => $order->amount,
            'converted_amount' => $order->converted_amount,
            'payment_method' => $order->payment_method,
        ]);
    }

    private function shippedMessage(OrderStatusChanged $event): string
    {
        return __('admin.telegram_notification.order_shipped', [
            'order_no' => $event->order->order_no,
        ]);
    }

    private function failedMessage(OrderStatusChanged $event): string
    {
        return __('admin.telegram_notification.order_failed', [
            'order_no' => $event->order->order_no,
            'status' => __('admin.order.statuses.'.$event->newStatus),
        ]);
    }

    private function disputingMessage(OrderStatusChanged $event): string
    {
        return __('admin.telegram_notification.order_disputing', [
            'order_no' => $event->order->order_no,
        ]);
    }

    private function refundedMessage(OrderStatusChanged $event): string
    {
        $order = $event->order;

        return __('admin.telegram_notification.order_refund_gateway', [
            'order_no' => $order->order_no,
            'currency' => $order->currency,
            'amount' => $order->amount,
        ]);
    }

    private function chargebackMessage(OrderStatusChanged $event): string
    {
        $order = $event->order;

        return __('admin.telegram_notification.order_chargeback_gateway', [
            'order_no' => $order->order_no,
            'currency' => $order->currency,
            'amount' => $order->amount,
        ]);
    }
}
