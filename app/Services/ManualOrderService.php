<?php

namespace App\Services;

use App\Jobs\SendPaymentLinkJob;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;

/**
 * 商户后台手工创建支付订单（4.4 节）。核心下单逻辑完全复用 OrderCreationService
 * （source='manual'），本服务只额外负责：生成付款链接后推送邮件发送 Job。
 */
class ManualOrderService
{
    public function __construct(
        private readonly OrderCreationService $orderCreationService,
    ) {}

    /**
     * @param  array  $data  管理后台表单提交的数据，字段结构与 API 下单一致
     *                       （见 OrderCreationService::createOrder 的参数说明），
     *                       但没有 customer_ip / user_agent / accept_language
     *                       这些"客户端环境"字段（手工建单没有真实客户端请求）。
     */
    public function createOrder(array $data, Merchant $merchant, Application $application, int $operatorId): Order
    {
        $order = $this->orderCreationService->createOrder(
            data: $data,
            merchant: $merchant,
            application: $application,
            source: 'manual',
        );

        // 幂等命中已存在订单的情况下（重复提交同一个 merchant_order_no），
        // 不应该重复发送付款链接邮件，只有真正新建的订单才发送。
        if ($order->wasRecentlyCreated) {
            SendPaymentLinkJob::dispatch($order->id);
        }

        return $order;
    }
}
