<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;

/**
 * 支付通道自动禁用通知，发送给通道所属人并附上触发订单摘要。
 */
class PaymentChannelTelegramNotifier
{
    public function __construct(private readonly TelegramNotificationRouter $routes) {}

    /** @param array<string, scalar|null> $variables */
    public function notify(TelegramNotificationKind $kind, PaymentMethod $method, array $variables, ?Order $order = null): void
    {
        $summary = '';

        if ($order !== null && ($method->merchant_id === null || (int) $order->merchant_id === (int) $method->merchant_id)) {
            $summary = OrderTelegramSummary::forOrder($order);
        }

        $this->routes->sendNotification($kind, $variables, $order?->merchant_id !== null ? (int) $order->merchant_id : null, $method, summary: $summary);
    }
}
