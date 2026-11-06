<?php

namespace App\Listeners;

use App\Events\DesignatedOrderCreated;
use App\Events\LogisticsImportCompleted;
use App\Events\OrderDisputeOpened;
use App\Events\OrderStatusChanged;
use App\Events\PaymentLinkCreationFailed;
use App\Models\Order;
use App\Services\TelegramNotificationService;
use App\Support\OrderTelegramSummary;
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
        // 指定渠道首次确认收款时连发 5 条；本地可能直接进入待结算或完成。
        // 不走下面 match() 的"单条消息"逻辑，与普通订单的支付成功区分开
        // （普通订单目前没有接"支付成功"这条 Telegram 通知，order_paid 文案
        // 此前只是没被用到的现成模板）。跟 OrderPaymentStatusService::applyStatus()
        // 里"是否首次支付成功"的判断口径保持一致：曾收款状态纠正为 paid
        // 不代表新的支付成功，不重复连发。
        if (($event->firstPayment
                || ($event->newStatus === 'paid' && in_array($event->oldStatus, Order::NEVER_PAID_STATUSES, true)))
            && filled($event->order->designated_payment_method_key)) {
            $this->sendDesignatedOrderPaidMessages($event->order);

            return;
        }

        // disputing/refunded/chargeback 目前只会由 payment_status webhook
        // （OrderPaymentStatusService）触发本事件，所以下面这几条消息文案里
        // "来自网关通知"这类措辞是安全的；如果未来后台人工操作也开始 fire
        // 这个事件，需要回头看看这几条文案是否还准确。
        $message = match ($event->newStatus) {
            'disputing' => $this->disputingMessage($event),
            'refunded' => $this->refundedMessage($event),
            'chargeback' => $this->chargebackMessage($event),
            default => null,
        };

        if ($message === null) {
            return;
        }

        $this->telegram->send($event->order->merchant_id, $message."\n\n".OrderTelegramSummary::forOrder($event->order));
    }

    /**
     * 指定渠道下单，源网站已经建好订单——提醒商户去关注这笔订单
     * （携带订单号、客户邮箱等信息，方便商户直接在源站核对）。
     */
    public function handleDesignatedOrderCreated(DesignatedOrderCreated $event): void
    {
        $order = $event->order;

        $message = __('admin.telegram_notification.designated_order_created', [
            'order_no' => e((string) $order->order_no),
            'merchant_order_no' => e((string) $order->merchant_order_no),
            'customer_email' => e((string) $order->customer_email),
            'currency' => e((string) $order->currency),
            'amount' => e((string) $order->amount),
            'payment_method' => e((string) $order->payment_method),
        ]);

        $this->telegram->send($order->merchant_id, $message."\n\n".OrderTelegramSummary::forOrder($order));
    }

    public function handleLogisticsImportCompleted(LogisticsImportCompleted $event): void
    {
        $task = $event->task;

        $message = __('admin.telegram_notification.logistics_import_completed', [
            // 显示商户自己上传时的文件名，而不是 Filament 生成的随机存储名；
            // 历史任务没有 original_file_name，displayFileName() 会回退到保存名。
            'file_name' => $task->displayFileName(),
            'total' => $task->total_records,
            'success' => $task->success_count,
            'failed' => $task->fail_count,
        ]);

        $this->telegram->send($task->merchant_id, $message);
    }

    public function handlePaymentLinkCreationFailed(PaymentLinkCreationFailed $event): void
    {
        $details = array_map(fn ($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $event->details);
        $message = __('admin.telegram_notification.payment_link_creation_failed', $details);

        $this->telegram->send($event->merchantId, $message."\n\n".OrderTelegramSummary::render($event->details));
    }

    public function handleOrderDisputeOpened(OrderDisputeOpened $event): void
    {
        $message = __('admin.telegram_notification.order_dispute_opened', [
            'event_no' => htmlspecialchars(mb_substr($event->eventNo, 0, 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'due_at' => htmlspecialchars($event->dueAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);

        $this->telegram->send($event->merchantId, $message."\n\n".OrderTelegramSummary::render($event->details));
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderStatusChanged::class => 'handleOrderStatusChanged',
            LogisticsImportCompleted::class => 'handleLogisticsImportCompleted',
            DesignatedOrderCreated::class => 'handleDesignatedOrderCreated',
            PaymentLinkCreationFailed::class => 'handlePaymentLinkCreationFailed',
            OrderDisputeOpened::class => 'handleOrderDisputeOpened',
        ];
    }

    /**
     * 连发 5 条一样的消息，就是要足够显眼——指定渠道下单的订单对商户来说
     * 是"源网站自己的订单"，普通的单条提醒容易被刷屏的其他消息淹没。
     */
    private function sendDesignatedOrderPaidMessages(Order $order): void
    {
        $message = __('admin.telegram_notification.order_paid', [
            'order_no' => e((string) $order->order_no),
            'currency' => e((string) $order->currency),
            'amount' => e((string) $order->amount),
            'converted_amount' => e((string) $order->converted_amount),
            'payment_method' => e((string) $order->payment_method),
        ]);
        $message .= "\n\n".OrderTelegramSummary::forOrder($order);

        for ($i = 0; $i < 5; $i++) {
            $this->telegram->send($order->merchant_id, $message);
        }
    }

    private function disputingMessage(OrderStatusChanged $event): string
    {
        return __('admin.telegram_notification.order_disputing', [
            'order_no' => e($event->order->order_no),
        ]);
    }

    private function refundedMessage(OrderStatusChanged $event): string
    {
        $order = $event->order;

        return __('admin.telegram_notification.order_refund_gateway', [
            'order_no' => e((string) $order->order_no),
            'currency' => e((string) $order->currency),
            'amount' => e((string) $order->amount),
        ]);
    }

    private function chargebackMessage(OrderStatusChanged $event): string
    {
        $order = $event->order;

        return __('admin.telegram_notification.order_chargeback_gateway', [
            'order_no' => e((string) $order->order_no),
            'currency' => e((string) $order->currency),
            'amount' => e((string) $order->amount),
        ]);
    }
}
