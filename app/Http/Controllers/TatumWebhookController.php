<?php

namespace App\Http\Controllers;

use App\Services\Crypto\CryptoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tatum 地址交易通知。当前不做验签，直接交给 CryptoPaymentService 匹配入账。
 */
class TatumWebhookController extends Controller
{
    public function address(
        Request $request,
        CryptoPaymentService $cryptoPayments,
    ): JsonResponse {
        $payload = $request->all();
        if ($payload === []) {
            $decoded = json_decode($request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        try {
            $cryptoPayments->handleIncomingTransfer($payload);
        } catch (\Throwable $e) {
            Log::error('tatum webhook: 处理失败', ['message' => $e->getMessage()]);

            return response()->json(['code' => 500, 'message' => 'error'], 500);
        }

        return response()->json(['code' => 0, 'message' => 'ok']);
    }
}
