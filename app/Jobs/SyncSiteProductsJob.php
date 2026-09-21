<?php

namespace App\Jobs;

use App\Models\PaymentMethod;
use App\Services\TelegramNotificationService;
use App\Services\WooCommerceProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 站点商品同步任务：拉取支付方式所配置 WordPress 站点的全部商品到本地。
 * 商品量大 + 名称翻译限频（1 QPS），整体耗时可能较长，必须走队列。
 */
class SyncSiteProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 600]; // 1分钟、5分钟、10分钟

    public function __construct(public readonly int $paymentMethodId)
    {
        $this->onQueue('low');
    }

    public function handle(
        WooCommerceProductSyncService $syncService,
        TelegramNotificationService $telegram,
    ): void {
        $paymentMethod = PaymentMethod::query()->find($this->paymentMethodId);

        // 支付方式可能已被删除，直接放弃即可。
        if (!$paymentMethod) {
            return;
        }

        $stats = $syncService->sync($paymentMethod);

        // 收件人取"全部绑定该通道的商户"：系统级支付方式 merchant_id 为 NULL，
        // 原来的 if ($paymentMethod->merchant_id) 守卫会让它一条通知都发不出去，
        // 而它的商品池恰恰是被多个商户共用的。口径与通道自动禁用通知一致
        // （见 PaymentMethod::notifiableMerchantIds()）。
        $recipientIds = $paymentMethod->notifiableMerchantIds();

        $message = sprintf(
            "✅ 站点商品同步完成：%s\n共同步 %d 个商品（新增 %d / 更新 %d / 清理 %d / 跳过 0 价 %d）",
            $paymentMethod->domain,
            $stats['total'],
            $stats['created'],
            $stats['updated'],
            $stats['deleted'],
            $stats['skipped'] ?? 0,
        );

        foreach ($recipientIds as $merchantId) {
            $telegram->send($merchantId, $message);
        }

        Log::info('Site products sync completed', [
            'payment_method_id'  => $paymentMethod->id,
            'stats'              => $stats,
            'notified_merchants' => $recipientIds,
        ]);
    }

    /**
     * 重试全部耗尽后的最终失败通知。
     * 注意：Laravel 调用 failed() 时只传异常实例，不做容器注入，
     * 需要自己通过 app() 取服务。
     */
    public function failed(?Throwable $exception): void
    {
        $paymentMethod = PaymentMethod::find($this->paymentMethodId);

        // 同上：系统级支付方式没有归属商户，原来的守卫会让"重试耗尽"这条最要紧的
        // 通知也静默丢掉——商品池就此停更，直到所有被分配商户的下单开始因为匹配
        // 不到商品而整单失败才会被发现（OrderItemService 各模式都会抛 RuntimeException）。
        $recipientIds = $paymentMethod?->notifiableMerchantIds() ?? [];

        Log::error('Site products sync failed permanently', [
            'payment_method_id'  => $this->paymentMethodId,
            'error'              => $exception?->getMessage(),
            'notified_merchants' => $recipientIds,
        ]);

        if ($recipientIds === []) {
            return;
        }

        $message = sprintf(
            "❌ 站点商品同步失败：%s（%s）\n%s",
            $paymentMethod->domain,
            $paymentMethod->method_name,
            mb_substr($exception?->getMessage() ?? '未知错误', 0, 200),
        );

        $telegram = app(TelegramNotificationService::class);

        foreach ($recipientIds as $merchantId) {
            $telegram->send($merchantId, $message);
        }
    }
}
