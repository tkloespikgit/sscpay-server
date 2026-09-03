<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Support\SignatureCanonicalizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 对外 API 鉴权中间件。
 *
 * 鉴权信息拆成两部分（按最终业务决定）：
 *   - Header：App-ID、Timestamp、X-Nonce —— 这三个是"轻量元信息"，在解析 body
 *     之前就能用来做早期拒绝（时间戳过期、Nonce 重放），不需要等 body 解析完。
 *   - Body：sign 字段 —— 签名本身放在请求体里，不再使用 Signature header。
 *
 * StringToSign = App-ID + "\n" + Timestamp + "\n" + X-Nonce + "\n" + 规范化后的请求体（不含 sign 字段）
 *
 * 「规范化」是这里唯一的技术难点：sign 字段本身在 body 内部，如果直接对
 * "客户端发来的原始 JSON 字符串"取值来验签，服务端没法在不破坏字符串的前提下
 * 摘除 sign 字段。因此约定一套双方都必须遵守的规范化编码算法：
 *   1. 解析 body 为关联数组，移除顶层 sign 字段。
 *   2. 对所有关联数组（对象）按 key 做递归 ksort；数字索引数组（列表，如 items）
 *      保持原始顺序，不做排序。
 *   3. 用 json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) 编码。
 * 商户端必须在"生成 sign 之前"用同样的算法（先 ksort 再序列化，且不包含 sign
 * 字段本身）构造待签名字符串，否则服务端算出来的签名永远对不上——这是最容易
 * 踩坑的地方。具体实现见 SignatureCanonicalizer，这套算法与
 * OrderNotificationService 推送 webhook 时使用的签名算法完全一致（只是方向相反），
 * 商户端可以只实现一次签名/验签函数，两边直接复用。
 */
class ApiAuthentication
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300; // ±5 分钟

    private const NONCE_TTL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $appId = $request->header('App-ID');
        $timestamp = $request->header('Timestamp');
        $nonce = $request->header('X-Nonce');

        $body = $request->json()->all();
        $sign = $body['sign'] ?? null;

        if (! $appId || ! $timestamp || ! $nonce || ! $sign) {
            return $this->reject('Missing authentication parameters: App-ID/Timestamp/X-Nonce headers and sign field in body are all required.');
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return $this->reject('Request timestamp expired.');
        }

        // 走 Cache 门面而不是直接依赖 Redis：驱动由 CACHE_STORE 决定，
        // 想彻底去掉 Redis 时把 CACHE_STORE 设为 database 即可（需 cache 表）。
        $nonceKey = "api_nonce:{$nonce}";
        if (Cache::has($nonceKey)) {
            Log::warning('Reused Nonce detected', ['nonce' => $nonce, 'ip' => $request->ip()]);

            return $this->reject('Nonce already used.');
        }

        $application = Application::query()
            ->where('app_id', $appId)
            ->where('status', true)
            ->first();

        if (! $application) {
            Log::warning('Invalid App-ID', ['app_id' => $appId, 'ip' => $request->ip()]);

            return $this->reject('Invalid App-ID or application disabled.');
        }

        // Application::api_key 使用了 encrypted cast，这里读到的已经是明文，
        // 不要再对它调用 decrypt()。
        $apiKey = $application->api_key;

        $expectedSign = SignatureCanonicalizer::sign($appId, $timestamp, $nonce, $body, $apiKey);

        if (! hash_equals($expectedSign, $sign)) {
            Log::warning('Signature mismatch', [
                'app_id' => $appId,
                'ip' => $request->ip(),
            ]);

            return $this->reject('Signature verification failed.');
        }

        // Nonce 校验通过才占用，放在签名验证通过之后，避免签名错误的请求也消耗掉 Nonce 配额。
        Cache::put($nonceKey, true, self::NONCE_TTL_SECONDS);

        $request->attributes->set('merchant_id', $application->merchant_id);
        $request->attributes->set('application_id', $application->id);
        $request->attributes->set('application', $application);

        return $next($request);
    }

    private function reject(string $message): Response
    {
        return response()->json([
            'code' => 401,
            'msg' => $message,
        ], 401);
    }
}
