<?php

namespace App\Http\Controllers;

use App\Models\MerchantLogisticsTrackingSetting;
use App\Services\LogisticsTracking\LogisticsTrackingManager;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * 物流轨迹 Webhook：POST /api/webhooks/logistics-tracking/{driver}/{webhookToken}
 *
 * 流程：解析驱动 → 校验平台白名单 → 用 webhookToken 定位商户凭证 → 验签
 * → parseWebhook → SyncService 落库。快照关闭的订单在 SyncService 内忽略写入。
 * 处理异常时仍返回 code:0，避免 Provider 无意义重试刷屏（已打 warning 日志）。
 */
class LogisticsTrackingWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $driver,
        string $webhookToken,
        LogisticsTrackingManager $manager,
        LogisticsTrackingSyncService $sync,
    ): JsonResponse {
        try {
            $trackingDriver = $manager->driver($driver);
        } catch (InvalidArgumentException) {
            return response()->json(['code' => 404, 'message' => 'unknown driver'], 404);
        }

        if (! $trackingDriver->isPlatformSelected()) {
            return response()->json(['code' => 503, 'message' => 'driver disabled'], 503);
        }

        $setting = MerchantLogisticsTrackingSetting::query()
            ->withoutGlobalScopes()
            ->where('webhook_token', $webhookToken)
            ->whereNull('deleted_at')
            ->first();

        if (! $setting || ! $setting->isUsable()) {
            return response()->json(['code' => 404, 'message' => 'unknown webhook'], 404);
        }

        $raw = $request->getContent();

        if (! $trackingDriver->verifyWebhook($raw, $request->header('sign'), (string) $setting->webhook_secret)) {
            Log::warning('logistics tracking webhook: 签名校验失败', [
                'driver' => $driver,
                'merchant_id' => $setting->merchant_id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['code' => 401, 'message' => 'invalid signature'], 401);
        }

        try {
            $snapshots = $trackingDriver->parseWebhook($raw);
            $updated = $sync->applyWebhookSnapshots($snapshots, $driver, (int) $setting->merchant_id);
        } catch (Throwable $exception) {
            Log::warning('logistics tracking webhook: 处理失败', [
                'driver' => $driver,
                'merchant_id' => $setting->merchant_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['code' => 0, 'message' => 'ignored']);
        }

        return response()->json(['code' => 0, 'message' => 'ok', 'updated' => $updated]);
    }
}
