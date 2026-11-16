<?php

namespace App\Services;

use App\Jobs\SendOrderNotificationJob;
use App\Models\CombinedOrder;
use App\Models\Order;
use App\Models\OrderNotificationAttempt;
use App\Support\SignatureCanonicalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 商户交易结果通知服务（回调 orders.notify_url）。
 *
 * 重试调度不在这里做轮询/延迟，而是拆成两半：
 *   - dispatchInitial()：首次确认支付成功时调用，与本地发货/结算阶段分开，
 *     创建第 1 次尝试记录并马上入队发送。
 *   - attempt()：真正发起 HTTP 请求、判定成功/失败、写入 OrderNotificationAttempt。
 *     由 SendOrderNotificationJob 调用；该 Job 既用于首次发送，也用于
 *     ProcessDueOrderNotifications 命令扫描到到期重试后创建的后续尝试。
 *
 * 签名与 ApiAuthentication 中间件用的是同一套算法（SignatureCanonicalizer），
 * 只是方向相反：商户调用我们的接口时，App-ID/Timestamp/X-Nonce 放 Header、
 * sign 放 body；我们推 webhook 给商户时同样把 App-ID/Timestamp/X-Nonce 放
 * Header、sign 放 body，商户端只需要实现一次签名/验签函数即可两边复用。
 * Timestamp/Nonce 在每次尝试（含重试）时现算，不在创建通知记录时就固定下来
 * ——重试可能间隔长达 1 小时，固定旧值会让签名看起来像是"过期"的请求。
 */
class OrderNotificationService
{
    private const SUCCESS_STATUS_RANGE = [200, 299];

    public function __construct(private readonly OrderApiPayload $payloads) {}

    public function dispatchInitial(Order $order, string $notifyType = 'trade_result'): ?OrderNotificationAttempt
    {
        if (empty($order->notify_url)) {
            return null; // 商户没传回调地址，没有通知的必要
        }

        $attempt = OrderNotificationAttempt::createInitialAttempt(
            $order,
            $notifyType,
            $this->buildPayload($order)
        );

        SendOrderNotificationJob::dispatch($attempt->id)->afterCommit();

        return $attempt;
    }

    /**
     * 供 ProcessDueOrderNotifications 命令在扫描到到期重试记录后调用。
     */
    public function dispatchRetry(OrderNotificationAttempt $dueAttempt): ?OrderNotificationAttempt
    {
        return DB::transaction(function () use ($dueAttempt): ?OrderNotificationAttempt {
            $next = $dueAttempt->createNextAttempt();

            if ($next !== null) {
                SendOrderNotificationJob::dispatch($next->id)->afterCommit();
            }

            return $next;
        });
    }

    /**
     * 真正执行一次 HTTP 通知尝试，并把结果写回 $attempt。
     * 这个方法本身不做重试判断——重试与否、下次什么时候重试，
     * 全部由 OrderNotificationAttempt::markFailed() 内部根据配置计算。
     */
    public function attempt(OrderNotificationAttempt $attempt): void
    {
        $claimed = OrderNotificationAttempt::query()->withoutGlobalScopes()
            ->where('merchant_id', $attempt->merchant_id)->whereKey($attempt->id)->where('status', 'pending')
            ->update(['status' => 'processing', 'attempted_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        [$headers, $body] = $this->sign($attempt);
        if ($attempt->event_id && $headers === []) {
            $attempt->markFailed(null, null, 'Application signing credentials unavailable');

            return;
        }

        $attempt->update([
            'request_headers' => $headers,
            'request_payload' => $body,
        ]);

        $startedAt = microtime(true);

        try {
            $response = Http::timeout(10)
                ->retry(0) // 这里的"重试"由我们自己的 attempt_number 机制管理，不用 HTTP 客户端自带的重试
                ->withHeaders($headers)
                ->post($attempt->notify_url, $body);

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            if ($this->isSuccessStatus($response->status())) {
                $attempt->markSuccess($response->status(), $response->body(), $durationMs);

                return;
            }

            $attempt->markFailed($response->status(), $response->body(), null, $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $attempt->markFailed(null, null, $e->getMessage(), $durationMs);
        }
    }

    private function isSuccessStatus(int $status): bool
    {
        return $status >= self::SUCCESS_STATUS_RANGE[0] && $status <= self::SUCCESS_STATUS_RANGE[1];
    }

    /**
     * 现算本次尝试的签名 Header + 带 sign 的最终 body。如果订单所属 application
     * 缺失 app_id/api_key 时返回空 Header；V1 保留历史发送行为，
     * 带 event_id 的 V2 通知由 attempt() 拒绝未签名投递并记录失败。
     *
     * @return array{0: array<string,string>, 1: array}
     */
    private function sign(OrderNotificationAttempt $attempt): array
    {
        $application = $attempt->order?->application;
        $appId = $application?->app_id;
        $apiKey = $application?->api_key;

        $body = $attempt->request_payload;

        if (! $appId || ! $apiKey) {
            return [[], $body];
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));

        $body['sign'] = SignatureCanonicalizer::sign($appId, $timestamp, $nonce, $body, $apiKey);

        return [
            ['App-ID' => $appId, 'Timestamp' => $timestamp, 'X-Nonce' => $nonce],
            $body,
        ];
    }

    /**
     * 通知商户的交易结果 payload（业务字段，不含签名相关字段）。字段按需扩展，
     * 但一旦发出去了商户就可能依赖这个结构，后续加字段要注意向后兼容（只增不改/不删）。
     * 签名在每次实际发送时（见 sign()）现算，不在这里做。
     */
    private function buildPayload(Order $order): array
    {
        if ($order->combined_order_id) {
            $parent = CombinedOrder::query()->forMerchant($order->merchant_id)->withTrashed()->findOrFail($order->combined_order_id);

            return $this->payloads->parent($parent) + [
                'event_id' => (string) Str::uuid(), 'paid_order_no' => $order->order_no,
                'occurred_at' => now()->toIso8601String(),
            ];
        }
        $extra = $order->webhook_version >= 2 ? [
            'order_type' => 'single', 'paid_amount' => (string) $order->amount,
            'paid_converted_amount' => (string) $order->converted_amount, 'remaining_amount' => '0.00',
            'remaining_converted_amount' => '0.00', 'children' => [], 'event_id' => (string) Str::uuid(),
            'version' => 1, 'paid_order_no' => $order->order_no,
        ] : [];

        return [
            ...$extra,
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'status' => 'paid',
            'currency' => $order->currency,
            'amount' => (string) $order->amount,
            'converted_currency' => $order->converted_currency,
            'converted_amount' => (string) $order->converted_amount,
            'payment_method' => $order->payment_method,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
