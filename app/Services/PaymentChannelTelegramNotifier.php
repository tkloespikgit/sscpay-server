<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;

/**
 * 支付通道自动禁用通知。正文仍附上触发订单摘要；发给谁由通知接收人配置决定。
 */
class PaymentChannelTelegramNotifier
{
    public function __construct(private readonly TelegramNotificationRouter $routes) {}

    public function notify(TelegramNotificationKind $kind, PaymentMethod $method, string $message, ?Order $order = null): void
    {
        $text = $message;

        if ($order !== null && ($method->merchant_id === null || (int) $order->merchant_id === (int) $method->merchant_id)) {
            $text .= "\n\n".OrderTelegramSummary::forOrder($order);
        }

        $this->routes->send($kind, $text, $order?->merchant_id !== null ? (int) $order->merchant_id : null, $method);
    }
}
