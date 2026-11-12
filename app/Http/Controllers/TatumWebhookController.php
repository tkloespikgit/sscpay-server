<?php

namespace App\Http\Controllers;

use App\Models\CryptoAddressMonitor;
use App\Services\TatumWebhookService;
use App\Support\TatumWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class TatumWebhookController extends Controller
{
    public function __invoke(Request $request, string $environment, string $monitor, TatumWebhookSignature $signature, TatumWebhookService $service): JsonResponse
    {
        if (! config('services.tatum.subscriptions_enabled')) {
            return response()->json(['code' => 503, 'message' => 'notification receiver is disabled'], 503);
        }
        $raw = $request->getContent();
        if (strlen($raw) > 65536) {
            return response()->json(['code' => 413, 'message' => 'payload too large'], 413);
        }
        $mode = $signature->verify($raw, $request->header('x-payload-hash'), $environment);
        if ($mode === null) {
            return response()->json(['code' => 401, 'message' => 'invalid signature'], 401);
        }
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return response()->json(['code' => 400, 'message' => 'invalid JSON'], 400);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            return response()->json(['code' => 400, 'message' => 'invalid JSON object'], 400);
        }
        $record = CryptoAddressMonitor::query()->withoutGlobalScopes()->where('callback_uuid', $monitor)->where('environment', $environment)->firstOrFail();
        $receipt = $service->receive($record, $payload, $raw, $mode);

        return response()->json(['code' => 0, 'message' => $receipt ? 'accepted for verification' : 'ignored'], 202);
    }
}
