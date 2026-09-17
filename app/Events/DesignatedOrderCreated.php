<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 商户下单时显式传了 payment_method_key（指定渠道，源网站直连）——
 * 与 PaymentService 加权路由选中的订单区分开，只有这类订单才需要
 * 额外的 Telegram 提醒（见 SendTelegramNotification）。
 */
class DesignatedOrderCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}
}
