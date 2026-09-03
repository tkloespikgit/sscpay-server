<?php

namespace App\Observers;

use App\Events\OrderStatusChanged;
use App\Models\OrderShipping;

/**
 * 发货状态自动机（2.5 节）的唯一驱动源。
 *
 * 业务规则：
 *   - paid 状态的订单新增物流记录 → 订单状态自动变为 shipped（待发货→已发货）。
 *   - pending / partially_refunded 等非可推进状态的订单也允许预录入物流记录，
 *     但状态保持不变（见 ADVANCEABLE_STATUSES）。
 *   - 没有任何记录 → 订单状态保持原样。
 *   - 重复插入（同一订单再次提交物流单号）按 OrderShipping::recordShipment()
 *     的约定走 updateOrCreate，即"补发/改单覆盖原记录"，本 Observer 对
 *     created 和 updated 都要处理，保证两种路径下状态机效果一致。
 *
 * 这里只负责状态流转本身，不做发货前置校验（比如"该订单是否属于当前商户"、
 * "该订单是否已经是 paid 状态"）——那些校验应该在 Service 层调用
 * OrderShipping::recordShipment() 之前完成，Observer 作为状态机不应该
 * 反过来阻塞已经发生的写入。
 */
class OrderShippingObserver
{
    /**
     * 允许被自动推进为 shipped 的订单状态集合。如果订单已经是更靠后的终态
     * （completed / cancelled / refunded），不应该被一条物流记录拉回 shipped——
     * 这类异常情况交给上层业务日志/告警去发现，而不是让 Observer 静默"纠正"状态。
     */
    private const ADVANCEABLE_STATUSES = ['paid', 'shipped'];

    public function created(OrderShipping $shipping): void
    {
        $this->syncOrderStatus($shipping);
    }

    public function updated(OrderShipping $shipping): void
    {
        $this->syncOrderStatus($shipping);
    }

    private function syncOrderStatus(OrderShipping $shipping): void
    {
        $order = $shipping->order()->first();

        if (! $order) {
            return;
        }

        if (! in_array($order->status, self::ADVANCEABLE_STATUSES, true)) {
            return;
        }

        if ($order->status !== 'shipped') {
            $oldStatus = $order->status;
            $order->forceFill(['status' => 'shipped'])->save();

            event(new OrderStatusChanged($order, $oldStatus, 'shipped'));
        }
    }
}
