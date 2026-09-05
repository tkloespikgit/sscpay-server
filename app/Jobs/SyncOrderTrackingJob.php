<?php

namespace App\Jobs;

use App\Models\Carrier;
use App\Models\OrderShipping;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 把一条物流记录同步给 WordPress 商城系统插件（POST /sync-tracking，见
 * doc/s-system-sync-tracking.md）。由 OrderShippingService::record() 在每次
 * 物流写入后自动 dispatch 一次，后台"手动同步"按钮针对 pending/failed 状态
 * 的记录重新 dispatch 即可重试——两个入口共用同一个 Job，不做区分。
 *
 * 只尝试一次（不用 Laravel 的自动重试）：接口本身幂等，失败原因（网络问题/
 * 站点未配置/订单状态非 paid 等）大概率不是"再自动试几次"能解决的，交给
 * 人工在后台看 sync_message 后决定要不要点「手动同步」重试。
 */
class SyncOrderTrackingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $orderShippingId) {}

    public function handle(PaymentGatewayService $paymentGateway): void
    {
        $shipping = OrderShipping::query()->withoutGlobalScopes()->find($this->orderShippingId);

        if (! $shipping) {
            return;
        }

        $order = $shipping->order()->withoutGlobalScopes()->first();

        if (! $order) {
            return;
        }

        $paymentMethod = $order->paymentMethod ?: $order->paymentMethodConfig();

        if (! $paymentMethod || ! $paymentMethod->sync_logistics) {
            $shipping->update([
                'sync_status' => OrderShipping::SYNC_STATUS_FAILED,
                'sync_message' => '该支付方式未启用物流站点同步（sync_logistics 未开启）',
            ]);

            return;
        }

        if (blank($paymentMethod->domain) || blank($paymentMethod->domain_client_id) || blank($paymentMethod->domain_client_sk)) {
            $shipping->update([
                'sync_status' => OrderShipping::SYNC_STATUS_FAILED,
                'sync_message' => '支付方式未配齐站点域名/WooCommerce REST API 密钥',
            ]);

            return;
        }

        [$carrierCode, $isOtherCarrier] = Carrier::resolveTrackingCode($shipping->logistics_company);

        $payload = [
            's_order_id' => $order->order_no,
            'tracking_number' => $shipping->tracking_number,
            'carrier_code' => $carrierCode,
            'is_other_carrier' => $isOtherCarrier,
            'shipped_at' => $shipping->shipped_at->format('Y-m-d H:i:s'),
            // 是否让插件进一步把物流信息转发给支付渠道方（如 PayPal）；渠道不支持时
            // 插件会如实返回 synced_to_remote=false，不影响本次调用本身，固定传 Y 即可。
            'need_sync_to_remote' => 'Y',
        ];

        if (filled($shipping->tracking_url)) {
            $payload['tracking_url'] = $shipping->tracking_url;
        }

        try {
            $data = $paymentGateway
                ->withConnection(
                    rtrim((string) $paymentMethod->domain, '/').'/wp-json/payment-plugin/v1',
                    (string) $paymentMethod->domain_client_id,
                    (string) $paymentMethod->domain_client_sk,
                )
                ->syncTracking($payload);
        } catch (\Throwable $e) {
            Log::warning('SyncOrderTrackingJob: 物流信息同步到 WordPress 站点失败', [
                'order_no' => $order->order_no,
                'payment_method_id' => $paymentMethod->id,
                'error' => $e->getMessage(),
            ]);

            $shipping->update([
                'sync_status' => OrderShipping::SYNC_STATUS_FAILED,
                'sync_message' => $e->getMessage(),
            ]);

            return;
        }

        // 外层 code:0 只代表插件本地落库成功，真正的渠道方同步结果要看
        // synced_to_remote（见文档第五节），不支持转发的渠道（Stripe/Airwallex/Antom）
        // 或 PayPal 侧失败都会是 false——这里如实记录为同步失败，不美化。
        $syncedToRemote = (bool) ($data['synced_to_remote'] ?? false);

        $shipping->update([
            'sync_status' => $syncedToRemote ? OrderShipping::SYNC_STATUS_SYNCED : OrderShipping::SYNC_STATUS_FAILED,
            'sync_message' => (string) ($data['remote_sync_message'] ?? ''),
            'synced_at' => $syncedToRemote ? now() : null,
        ]);
    }
}
