<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderShipping;
use App\Services\BalanceService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 物流保存后启动结算周期并同步订单业务阶段。
 *
 * 业务规则：
 *   - 上传物流起算订单新增有效物流后进入 shipped；支付起算订单保留待结算阶段。
 *   - 已结算、部分退款及争议订单的显示状态由生命周期服务根据独立事实推导。
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
    public function __construct(private readonly BalanceService $balanceService) {}

    /**
     * 物流变更不重启既有周期，也不应把已结算或售后状态拉回已发货。
     */
    public function created(OrderShipping $shipping): void
    {
        $this->syncOrderStatus($shipping);
    }

    public function updated(OrderShipping $shipping): void
    {
        if (! $shipping->wasChanged(['order_id', 'tracking_number', 'logistics_company', 'shipped_at', 'tracking_url'])) {
            return;
        }

        $this->syncOrderStatus($shipping);
    }

    private function syncOrderStatus(OrderShipping $shipping): void
    {
        $order = $shipping->order()->first();

        if (! $order) {
            return;
        }

        if (! in_array($order->status, ['paid', 'pending_settlement', 'shipped', 'completed', 'partially_refunded', 'disputing', Order::STATUS_DISPUTE_REVIEW], true)) {
            return;
        }

        DB::afterCommit(function () use ($order, $shipping): void {
            try {
                $fresh = Order::query()->withoutGlobalScopes()->where('merchant_id', $shipping->merchant_id)->find($order->id);

                if ($fresh) {
                    $this->balanceService->startSettlementForShippedOrder($fresh);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
