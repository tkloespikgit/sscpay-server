<?php

namespace App\Http\Controllers;

use App\Services\GatewayRefundService;
use App\Services\OrderPaymentStatusService;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentGatewayWebhookController extends Controller
{
    public function status(Request $request, PaymentGatewayService $paymentGateway, OrderPaymentStatusService $service, GatewayRefundService $refunds): JsonResponse
    {
        $raw = $request->getContent();

        if (! $paymentGateway->verifyWebhookSignature($raw, $request->header('X-PGA-Signature'))) {
            Log::warning('payment_status webhook: 签名校验失败', ['ip' => $request->ip()]);

            return response()->json(['code' => 401, 'message' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);

        if (is_array($payload) && ($payload['event'] ?? null) === 'payment_refund') {
            try {
                $receipt = $refunds->receive($payload);
            } catch (ValidationException $exception) {
                return response()->json(['code' => 422, 'errors' => $exception->errors()], 422);
            }

            return response()->json(['code' => 0, 'received' => true, 'refund_id' => $receipt->refund_id]);
        }

        if (! is_array($payload) || ($payload['event'] ?? null) !== 'payment_status') {
            return response()->json(['code' => 0, 'message' => 'ignored']);
        }

        $service->handle($payload);

        return response()->json(['code' => 0, 'message' => 'ok']);
    }
}
