<?php

namespace App\Jobs;

use App\Models\OrderShipping;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 发货/改单后向物流 Provider 注册运单（仅新发货路径；无历史回填）。
 *
 * 由 OrderShippingService 在落库后 dispatch 到 low 队列；
 * 启用门闩未通过或已注册同号时 SyncService::registerShipping 内静默跳过。
 * ShouldBeUnique 防止同一运单短时间重复入队打 Provider。
 * 与 SyncOrderTrackingJob（WP 站点 sync_status）无关。
 */
class RegisterLogisticsTrackingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> 秒：1 分钟 / 5 分钟 / 10 分钟 */
    public array $backoff = [60, 300, 600];

    /** 唯一锁保留秒数，覆盖并发双投窗口。 */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $orderShippingId)
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return (string) $this->orderShippingId;
    }

    /** 平台未开放驱动、无运单号、已注册或门闩失败时直接返回。 */
    public function handle(LogisticsTrackingSyncService $sync): void
    {
        if (! $sync->isPlatformEnabled()) {
            return;
        }

        $shipping = OrderShipping::query()->withoutGlobalScopes()->find($this->orderShippingId);

        if (! $shipping || blank($shipping->tracking_number)) {
            return;
        }

        try {
            $sync->registerShipping($shipping);
        } catch (Throwable $exception) {
            Log::warning('RegisterLogisticsTrackingJob 失败', [
                'order_shipping_id' => $this->orderShippingId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
