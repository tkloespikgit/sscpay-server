<?php

namespace App\Http\Controllers;

use App\Services\OrderPaymentStatusService;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 接收支付网关聚合插件的 payment_status 回调（doc/s-system-payment-status-notify.md 第一节）。
 *
 * 只做验签 + 转交，业务逻辑全部在 OrderPaymentStatusService。签名校验通过但
 * "重试也没用"的几种情况（未知 event、找不到订单、未知 status，均在
 * OrderPaymentStatusService 内部记警告日志后直接跳过）仍然返回 2xx，避免插件
 * 白白重试 5 次；真正的异常（如落库时数据库报错）不在这里捕获，让它走 Laravel
 * 默认异常处理返回 5xx，触发插件侧退避重试（文档第一节的重试策略正是为此设计）。
 */
class PaymentGatewayWebhookController extends Controller
{
    public function status(Request $request, PaymentGatewayService $paymentGateway, OrderPaymentStatusService $service): JsonResponse
    {
        $raw = $request->getContent();

        if (! $paymentGateway->verifyWebhookSignature($raw, $request->header('X-PGA-Signature'))) {
            Log::warning('payment_status webhook: 签名校验失败', ['ip' => $request->ip()]);

            return response()->json(['code' => 401, 'message' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ($payload['event'] ?? null) !== 'payment_status') {
            return response()->json(['code' => 0, 'message' => 'ignored']);
        }

        $service->handle($payload);

        return response()->json(['code' => 0, 'message' => 'ok']);
    }
}
