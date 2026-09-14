<?php

namespace App\Services;

use App\Jobs\NotifyAdConversionJob;
use App\Models\AdConversionAttempt;
use App\Models\Order;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 支付成功后把订单转化告知广告平台（Meta/Google/TikTok）。
 *
 * 触发条件（见 dispatchInitial()，由 OrderPaymentStatusService 在订单首次
 * 变为 paid 时调用）：订单下单时经 CreateOrderRequest.ad_params 透传了某个
 * 平台的追踪参数，且该订单所属 Application 配置了该平台的转化 API 凭证——
 * 两者都具备就直接同步，与支付方式是否配置"禁止返回源站"（allow_returned_source）
 * 无关，不再以此作为前置条件。
 *
 * ad_params 是通用透传字段，按平台分 key（如 {"meta":{"fbc":"..."},"google":{"gclid":"..."}}），
 * 系统不校验里面具体字段是否完整，只在构建各平台请求体时按需取用；商户要在
 * Application 上配置对应平台的转化 API 凭证（Application::adCredentialsFor()），
 * 两者都具备才会真正发起上报。
 *
 * 重试机制与 OrderNotificationService 完全对称（见 AdConversionAttempt 的注释）：
 * dispatchInitial() 创建首次尝试并入队，attempt() 真正发请求，失败由
 * ProcessDueAdConversions 命令扫描到期记录后调用 dispatchRetry() 生成下一次尝试。
 */
class AdConversionService
{
    public const PLATFORM_META = 'meta';

    public const PLATFORM_GOOGLE = 'google';

    public const PLATFORM_TIKTOK = 'tiktok';

    public const SUPPORTED_PLATFORMS = [self::PLATFORM_META, self::PLATFORM_GOOGLE, self::PLATFORM_TIKTOK];

    private const SUCCESS_STATUS_RANGE = [200, 299];

    /**
     * 按支持的平台逐个判断是否需要发起转化通知：商户下单传了该平台的追踪参数、
     * 该应用配置了该平台的凭证、且能构建出有效请求体（如 Google 缺 gclid 时
     * 无法归因，直接跳过）——三者都满足才创建尝试记录并入队，与支付方式的
     * allow_returned_source 配置无关。
     */
    public function dispatchInitial(Order $order): void
    {
        $adParams = (array) ($order->ad_params ?? []);
        if ($adParams === []) {
            return;
        }

        $application = $order->application;
        if (! $application) {
            return;
        }

        foreach (self::SUPPORTED_PLATFORMS as $platform) {
            $platformParams = (array) ($adParams[$platform] ?? []);
            if ($platformParams === []) {
                continue;
            }

            if (! $application->adCredentialsFor($platform)) {
                continue;
            }

            $payload = $this->buildPayload($platform, $order, $platformParams);
            if ($payload === null) {
                continue;
            }

            $attempt = AdConversionAttempt::createInitialAttempt($order, $platform, $payload);

            NotifyAdConversionJob::dispatch($attempt->id);
        }
    }

    /**
     * 供 ProcessDueAdConversions 命令在扫描到到期重试记录后调用。
     */
    public function dispatchRetry(AdConversionAttempt $dueAttempt): AdConversionAttempt
    {
        $next = $dueAttempt->createNextAttempt();

        NotifyAdConversionJob::dispatch($next->id);

        return $next;
    }

    /**
     * 真正执行一次转化上报，并把结果写回 $attempt。凭证在发送时现取
     * （而不是随 request_payload 一起固定下来），商户中途改凭证不影响已排队的重试。
     */
    public function attempt(AdConversionAttempt $attempt): void
    {
        $order = Order::query()->withoutGlobalScopes()->find($attempt->order_id);
        $application = $order?->application;

        if (! $order || ! $application) {
            $attempt->markFailed(null, null, '订单或所属应用已不存在，无法继续上报');

            return;
        }

        $credentials = $application->adCredentialsFor($attempt->platform);

        if (! $credentials) {
            $attempt->markFailed(null, null, "应用未配置 {$attempt->platform} 平台的转化 API 凭证");

            return;
        }

        $startedAt = microtime(true);

        try {
            $response = match ($attempt->platform) {
                self::PLATFORM_META => $this->sendMeta($credentials, $attempt->request_payload),
                self::PLATFORM_GOOGLE => $this->sendGoogle($credentials, $attempt->request_payload),
                self::PLATFORM_TIKTOK => $this->sendTikTok($credentials, $attempt->request_payload),
                default => throw new \RuntimeException("未知的广告平台：{$attempt->platform}"),
            };

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

    // ------------------------------------------------------------------
    // 各平台请求体构建（下单时的业务数据，凭证不在这里，发送时才附加）
    // ------------------------------------------------------------------

    private function buildPayload(string $platform, Order $order, array $adParams): ?array
    {
        return match ($platform) {
            self::PLATFORM_META => $this->buildMetaPayload($order, $adParams),
            self::PLATFORM_GOOGLE => $this->buildGooglePayload($order, $adParams),
            self::PLATFORM_TIKTOK => $this->buildTikTokPayload($order, $adParams),
            default => null,
        };
    }

    /**
     * Meta Conversions API 的 event 结构（v19.0），event_id 用系统订单号，
     * 便于商户如果同时也在前端上报了同一笔 Purchase 像素事件时去重。
     */
    private function buildMetaPayload(Order $order, array $adParams): array
    {
        $userData = array_filter([
            'em' => filled($order->customer_email) ? [$this->hash($order->customer_email)] : null,
            'ph' => filled($order->customer_phone) ? [$this->hash($order->customer_phone)] : null,
            'fbc' => $adParams['fbc'] ?? null,
            'fbp' => $adParams['fbp'] ?? null,
            'client_ip_address' => $order->customer_ip,
            'client_user_agent' => $order->user_agent,
        ], fn ($value) => filled($value));

        return [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => ($order->paid_at ?? now())->timestamp,
                'event_id' => $order->order_no,
                'action_source' => 'website',
                'user_data' => $userData,
                'custom_data' => [
                    'currency' => strtoupper((string) $order->currency),
                    'value' => (float) $order->amount,
                    'order_id' => $order->order_no,
                ],
            ]],
        ];
    }

    /**
     * TikTok Events API（v1.3）的 event 结构，event_source_id（Pixel Code）
     * 属于商户凭证，不在这里附加，见 sendTikTok()。
     */
    private function buildTikTokPayload(Order $order, array $adParams): array
    {
        $user = array_filter([
            'email' => filled($order->customer_email) ? $this->hash($order->customer_email) : null,
            'phone' => filled($order->customer_phone) ? $this->hash($order->customer_phone) : null,
            'ttclid' => $adParams['ttclid'] ?? null,
            'ip' => $order->customer_ip,
            'user_agent' => $order->user_agent,
        ], fn ($value) => filled($value));

        return [
            'event_source' => 'web',
            'data' => [[
                'event' => 'CompletePayment',
                'event_time' => ($order->paid_at ?? now())->timestamp,
                'event_id' => $order->order_no,
                'user' => $user,
                'properties' => [
                    'currency' => strtoupper((string) $order->currency),
                    'value' => (float) $order->amount,
                ],
            ]],
        ];
    }

    /**
     * Google Ads 的 Click Conversion，强依赖 gclid 才能归因——缺失时直接返回
     * null，dispatchInitial() 据此跳过本次上报（而不是创建一条注定失败的尝试记录）。
     * conversionAction 资源名在发送时才拼出来（需要凭证里的 customer_id），见 sendGoogle()。
     */
    private function buildGooglePayload(Order $order, array $adParams): ?array
    {
        $gclid = (string) ($adParams['gclid'] ?? '');
        if ($gclid === '') {
            return null;
        }

        return [
            'gclid' => $gclid,
            'conversionDateTime' => ($order->paid_at ?? now())->timezone('UTC')->format('Y-m-d H:i:sP'),
            'conversionValue' => (float) $order->amount,
            'currencyCode' => strtoupper((string) $order->currency),
            'orderId' => $order->order_no,
        ];
    }

    // ------------------------------------------------------------------
    // 各平台实际发送（凭证在这里附加）
    // ------------------------------------------------------------------

    private function sendMeta(array $credentials, array $payload): Response
    {
        $pixelId = (string) ($credentials['pixel_id'] ?? '');
        $accessToken = (string) ($credentials['access_token'] ?? '');

        if ($pixelId === '' || $accessToken === '') {
            throw new \RuntimeException('Meta 转化 API 凭证不完整（缺少 pixel_id/access_token）');
        }

        $body = $payload;
        // 事件名称随凭证配置现取（默认 Purchase），而不是随 request_payload 固定下来——
        // 商户改了事件名称后，已排队等待重试的记录也会用上新值，与 pixel_id/access_token
        // 的取值时机保持一致。
        if (isset($body['data'][0])) {
            $body['data'][0]['event_name'] = filled($credentials['event_name'] ?? null)
                ? $credentials['event_name']
                : 'Purchase';
        }
        if (filled($credentials['test_event_code'] ?? null)) {
            $body['test_event_code'] = $credentials['test_event_code'];
        }

        return Http::timeout(10)
            ->retry(0)
            ->asJson()
            ->post("https://graph.facebook.com/v19.0/{$pixelId}/events?access_token=".urlencode($accessToken), $body);
    }

    private function sendTikTok(array $credentials, array $payload): Response
    {
        $pixelCode = (string) ($credentials['pixel_code'] ?? '');
        $accessToken = (string) ($credentials['access_token'] ?? '');

        if ($pixelCode === '' || $accessToken === '') {
            throw new \RuntimeException('TikTok 转化 API 凭证不完整（缺少 pixel_code/access_token）');
        }

        $body = array_merge($payload, ['event_source_id' => $pixelCode]);
        // 事件名称随凭证配置现取（默认 CompletePayment），道理同 sendMeta() 里的 event_name。
        if (isset($body['data'][0])) {
            $body['data'][0]['event'] = filled($credentials['event_name'] ?? null)
                ? $credentials['event_name']
                : 'CompletePayment';
        }

        return Http::timeout(10)
            ->retry(0)
            ->withHeaders(['Access-Token' => $accessToken])
            ->asJson()
            ->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $body);
    }

    /**
     * Google Ads API 用 OAuth2 access token（由 refresh_token 换取，短期有效），
     * 需要 developer_token（开发者令牌）+ customer_id（目标 Google Ads 账号）+
     * conversion_action_id（转化操作，纯数字 ID 或完整资源名均可）；
     * login_customer_id 仅当通过 MCC 经理账号访问客户账号时才需要，可选。
     */
    private function sendGoogle(array $credentials, array $payload): Response
    {
        foreach (['customer_id', 'conversion_action_id', 'developer_token', 'client_id', 'client_secret', 'refresh_token'] as $field) {
            if (blank($credentials[$field] ?? null)) {
                throw new \RuntimeException("Google Ads 转化 API 凭证不完整（缺少 {$field}）");
            }
        }

        $accessToken = $this->resolveGoogleAccessToken($credentials);
        $customerId = preg_replace('/\D/', '', (string) $credentials['customer_id']);
        $conversionAction = str_starts_with((string) $credentials['conversion_action_id'], 'customers/')
            ? (string) $credentials['conversion_action_id']
            : "customers/{$customerId}/conversionActions/{$credentials['conversion_action_id']}";

        $body = [
            'conversions' => [array_merge($payload, ['conversionAction' => $conversionAction])],
            'partialFailure' => true,
        ];

        $request = Http::timeout(10)
            ->retry(0)
            ->withToken($accessToken)
            ->withHeaders(['developer-token' => $credentials['developer_token']]);

        if (filled($credentials['login_customer_id'] ?? null)) {
            $request = $request->withHeaders(['login-customer-id' => preg_replace('/\D/', '', (string) $credentials['login_customer_id'])]);
        }

        return $request->asJson()->post("https://googleads.googleapis.com/v17/customers/{$customerId}:uploadClickConversions", $body);
    }

    /**
     * 换取 Google OAuth access token，缓存 45 分钟（有效期通常 1 小时）避免
     * 每次上报都刷新一次；缓存 key 按 client_id+refresh_token 区分，不同应用/
     * 不同 Google Ads 账号互不影响。
     */
    private function resolveGoogleAccessToken(array $credentials): string
    {
        $cacheKey = 'ad_conversion:google_access_token:'.md5($credentials['client_id'].':'.$credentials['refresh_token']);

        return Cache::remember($cacheKey, 2700, function () use ($credentials) {
            $response = Http::timeout(10)->asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'refresh_token' => $credentials['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful() || blank($response->json('access_token'))) {
                throw new \RuntimeException('刷新 Google Ads OAuth access token 失败：'.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    private function isSuccessStatus(int $status): bool
    {
        return $status >= self::SUCCESS_STATUS_RANGE[0] && $status <= self::SUCCESS_STATUS_RANGE[1];
    }

    /**
     * Meta/TikTok 均要求邮箱/手机号以 SHA-256 哈希后传输：统一转小写、去空白再哈希。
     */
    private function hash(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }
}
