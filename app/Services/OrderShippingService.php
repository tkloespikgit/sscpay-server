<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShipping;
use Illuminate\Validation\ValidationException;

/**
 * 商户后台"手动添加物流单 / 修改订单物流状态"入口（本轮新增需求）。
 *
 * 业务规则：
 *   - 允许录入物流的订单状态见 RECORDABLE_STATUSES：待支付（预录入物流，
 *     不改变订单状态）、待发货（录入后自动推进为已发货）、已发货（补发/改单）、
 *     部分退款（仍需发货，只记物流不改状态）、争议中（业务方明确要求争议期间
 *     不强制拦截发货，是否暂停发货由人工判断，见 OrderPaymentStatusService 的
 *     Telegram 提醒）。终态订单（已完成/已取消/已退款/已拒付）不允许录入。
 *   - 发货状态自动机由 OrderShippingObserver 驱动：只有 paid / shipped 状态的订单
 *     会被推进为 shipped，其余状态只保存物流记录。
 *   - 如果该订单已经有一条物流记录，视为"补发/改单"，直接覆盖更新，
 *     而不是报错拒绝——这是 OrderShipping::recordShipment() 的 updateOrCreate
 *     语义，本服务只负责前置校验，不重复实现 upsert 逻辑。
 */
class OrderShippingService
{
    /**
     * 允许录入物流信息的订单状态。后台录入入口与详情页"录入物流"按钮的
     * 可见性都以这个列表为准。
     */
    public const RECORDABLE_STATUSES = ['paid', 'shipped', 'partially_refunded', 'disputing'];

    /**
     * 外部（非登录用户）来源写入时使用的操作人 ID：物流同步 API 等场景没有
     * 真实的后台用户，前端按 operator_id === 0 判断显示为"API"。
     */
    public const API_OPERATOR_ID = 0;

    /**
     * @param  int  $operatorId  录入物流信息的操作人 ID；外部 API 写入固定传 API_OPERATOR_ID
     *
     * @throws ValidationException
     */
    public function record(Order $order, int $operatorId, array $attributes): OrderShipping
    {
        if (! in_array($order->status, self::RECORDABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'order' => "订单当前状态为「{$order->status}」，只有已支付、已发货（补发改单）或部分退款状态的订单才能录入物流信息。",
            ]);
        }

        return OrderShipping::recordShipment($order->id, array_merge($attributes, [
            'merchant_id' => $order->merchant_id,
            'operator_id' => $operatorId,
        ]));
    }
}
