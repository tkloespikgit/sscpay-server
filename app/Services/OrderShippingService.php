<?php

namespace App\Services;

use App\Exceptions\CombinedPaymentException;
use App\Jobs\RegisterLogisticsTrackingJob;
use App\Jobs\SyncOrderTrackingJob;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderShipping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商户后台"手动添加物流单 / 修改订单物流状态"入口（本轮新增需求）。
 *
 * 业务规则：
 *   - 已收款且未全额退款/拒付的订单允许录入、补录或修改物流；完成结算不影响履约。
 *   - 物流记录独立保存；订单业务状态由 OrderShippingObserver 按结算与售后事实推导。
 *   - 如果该订单已经有一条物流记录，视为"补发/改单"，直接覆盖更新，
 *     而不是报错拒绝——这是 OrderShipping::recordShipment() 的 updateOrCreate
 *     语义，本服务只负责前置校验，不重复实现 upsert 逻辑。
 *   - 每次落库后都会自动把"同步给 WordPress 商城系统插件"这个动作丢进队列
 *     （SyncOrderTrackingJob）尝试一次；同步状态（待同步/已同步/同步失败）
 *     记在 OrderShipping.sync_status 上，失败/待同步的记录可以在后台手动
 *     重新入队重试，见 OrderResource\Pages\ViewOrder 的"手动同步"按钮。
 *   - 同时派发 RegisterLogisticsTrackingJob：订单快照开启时向物流 Provider 注册运单，后续轨迹靠 Webhook。
 */
class OrderShippingService
{
    public function __construct(private readonly MerchantShipmentSyncService $merchantShipmentSync) {}

    /**
     * 允许录入物流信息的订单状态。后台录入入口与详情页"录入物流"按钮的
     * 可见性都以这个列表为准。
     */
    public const RECORDABLE_STATUSES = ['paid', 'pending_settlement', 'shipped', 'completed', 'partially_refunded', 'disputing'];

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
        return DB::transaction(function () use ($order, $operatorId, $attributes): OrderShipping {
            Merchant::query()->whereKey($order->merchant_id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->forMerchant($order->merchant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->combined_order_id && ! $order->combinedOrder()->forMerchant($order->merchant_id)->where('status', 'paid')->exists()) {
                throw new CombinedPaymentException('COMBINE_ORDER_NOT_PAID');
            }
            if (! in_array($order->status, self::RECORDABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'order' => "订单当前状态为「{$order->status}」，只有已收款且未全额退款、未拒付的订单才能录入物流信息。",
                ]);
            }
            $shipping = OrderShipping::recordShipment($order->id, array_merge($attributes, [
                'merchant_id' => $order->merchant_id,
                'operator_id' => $operatorId,
            ]));

            $this->merchantShipmentSync->record($order, $shipping);
            SyncOrderTrackingJob::dispatch($shipping->id)->afterCommit();
            RegisterLogisticsTrackingJob::dispatch($shipping->id)->afterCommit();

            return $shipping;
        });
    }
}
