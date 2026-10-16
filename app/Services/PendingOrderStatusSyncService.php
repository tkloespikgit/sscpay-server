<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Scopes\MerchantScope;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class PendingOrderStatusSyncService
{
    public function __construct(private OrderPaymentStatusService $paymentStatus) {}

    /**
     * 全平台补偿查询：仅绕过商户 scope，保留订单软删除过滤，沿用每笔订单锁定的渠道。
     *
     * @return array{orders_checked:int,orders_changed:int,orders_failed:int}
     */
    public function sync(): array
    {
        $now = now();
        $stats = ['orders_checked' => 0, 'orders_changed' => 0, 'orders_failed' => 0];

        Order::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->where('status', 'pending')
            ->whereNull('paid_at')
            ->whereBetween('created_at', [$now->copy()->subHours(48), $now])
            ->with(['paymentMethod' => function (BelongsTo $relation): void {
                $relation->withoutGlobalScope(MerchantScope::class);
            }])
            ->chunkById(200, function (Collection $orders) use (&$stats): void {
                foreach ($orders as $order) {
                    $stats['orders_checked']++;

                    try {
                        $result = $this->paymentStatus->queryAndApply($order);

                        if ($result['mapped_status'] === null) {
                            $stats['orders_failed']++;

                            continue;
                        }

                        if ($result['changed']) {
                            $stats['orders_changed']++;
                        }
                    } catch (Throwable $e) {
                        $stats['orders_failed']++;

                        Log::warning('待支付订单状态查询或更新失败', [
                            'order_id' => $order->id,
                            'order_no' => $order->order_no,
                            'merchant_id' => $order->merchant_id,
                            'exception_class' => $e::class,
                            'pga_code' => $e instanceof PaymentGatewayException ? $e->pgaCode : null,
                            'http_status' => $e instanceof PaymentGatewayException ? $e->httpStatus : null,
                        ]);
                    }
                }
            });

        return $stats;
    }
}
