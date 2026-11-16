<?php

namespace App\Listeners;

use App\Events\DesignatedOrderCreated;
use App\Events\LogisticsImportCompleted;
use App\Events\OrderDisputeOpened;
use App\Events\OrderStatusChanged;
use App\Events\PaymentLinkCreationFailed;
use App\Models\Order;
use App\Services\TelegramNotificationRouter;
use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;

class SendTelegramNotification implements ShouldQueue
{
    public function __construct(private readonly TelegramNotificationRouter $telegramRoutes) {}

    public function handleOrderStatusChanged(OrderStatusChanged $event): void
    {
        if (($event->firstPayment
                || ($event->newStatus === 'paid' && in_array($event->oldStatus, Order::NEVER_PAID_STATUSES, true)))
            && filled($event->order->designated_payment_method_key)) {
            $this->sendDesignatedOrderPaidMessages($event->order);

            return;
        }

        $kind = match ($event->newStatus) {
            'disputing' => TelegramNotificationKind::OrderDisputing,
            'refunded' => TelegramNotificationKind::OrderRefundGateway,
            'chargeback' => TelegramNotificationKind::OrderChargebackGateway,
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $this->telegramRoutes->sendNotification(
            $kind,
            ['order_no' => $event->order->order_no, 'currency' => $event->order->currency, 'amount' => $event->order->amount],
            (int) $event->order->merchant_id,
            $event->order->paymentMethodConfig(),
            orderDetails: OrderTelegramSummary::details($event->order),
        );
    }

    public function handleDesignatedOrderCreated(DesignatedOrderCreated $event): void
    {
        $order = $event->order;
        $this->telegramRoutes->sendNotification(
            TelegramNotificationKind::DesignatedOrderCreated,
            [
                'order_no' => $order->order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'customer_email' => $order->customer_email,
                'currency' => $order->currency,
                'amount' => $order->amount,
                'payment_method' => $order->payment_method,
            ],
            (int) $order->merchant_id,
            $order->paymentMethodConfig(),
            orderDetails: OrderTelegramSummary::details($order),
        );
    }

    public function handleLogisticsImportCompleted(LogisticsImportCompleted $event): void
    {
        $task = $event->task;
        $this->telegramRoutes->sendNotification(
            TelegramNotificationKind::LogisticsImportCompleted,
            [
                'file_name' => $task->displayFileName(),
                'total' => $task->total_records,
                'success' => $task->success_count,
                'failed' => $task->fail_count,
            ],
            (int) $task->merchant_id,
        );
    }

    public function handlePaymentLinkCreationFailed(PaymentLinkCreationFailed $event): void
    {
        $methodId = $event->details['payment_method_id'] ?? null;
        $this->telegramRoutes->sendNotification(
            TelegramNotificationKind::PaymentLinkCreationFailed,
            $event->details,
            $event->merchantId,
            paymentMethodId: is_numeric($methodId) ? (int) $methodId : null,
            orderDetails: $event->details,
        );
    }

    public function handleOrderDisputeOpened(OrderDisputeOpened $event): void
    {
        $methodId = $event->details['payment_method_id'] ?? null;
        $this->telegramRoutes->sendNotification(
            TelegramNotificationKind::OrderDisputeOpened,
            ['event_no' => $event->eventNo, 'due_at' => $event->dueAt],
            $event->merchantId,
            paymentMethodId: is_numeric($methodId) ? (int) $methodId : null,
            orderDetails: $event->details,
        );
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

    private function sendDesignatedOrderPaidMessages(Order $order): void
    {
        $settings = $this->telegramRoutes->settings(TelegramNotificationKind::OrderPaid);
        $orderDetails = OrderTelegramSummary::details($order);

        for ($i = 0; $i < $settings['repeat_count']; $i++) {
            $this->telegramRoutes->sendNotification(
                TelegramNotificationKind::OrderPaid,
                [
                    'order_no' => $order->order_no,
                    'currency' => $order->currency,
                    'amount' => $order->amount,
                    'converted_amount' => $order->converted_amount,
                    'payment_method' => $order->payment_method,
                ],
                (int) $order->merchant_id,
                $order->paymentMethodConfig(),
                orderDetails: $orderDetails,
            );
        }
    }
}
