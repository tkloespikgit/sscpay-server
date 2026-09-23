<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway;

use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WordPress支付网关聚合插件的客户端封装。
 *
 * 对应插件API文档的四个业务接口：/gateway-config /pay /sync-tracking /health。
 * 参数结构直接对着API文档的请求体字段传（本类不做额外的字段改名/包装），
 * 这样对着文档写调用代码时可以直接照抄字段名，减少"文档一个名字、
 * 代码另一个名字"的心智负担。
 *
 * 用法（一般通过依赖注入拿到实例，见PaymentGatewayServiceProvider）：
 *
 *   public function __construct(private PaymentGatewayService $paymentGateway) {}
 *
 *   $result = $this->paymentGateway->createPayment([
 *       's_order_id' => $order->no,
 *       'amount' => $order->total,
 *       'currency' => 'USD',
 *       'payment_method' => 'stripe',
 *       'callback_url' => route('webhooks.payment-gateway.status'),
 *       'return_url' => route('checkout.thank-you', $order),
 *       'cancel_url' => route('cart.show'),
 *       'gateway_config_id' => 101,
 *       'customer' => ['name' => $order->customer_name, 'email' => $order->customer_email],
 *       'billing_address' => [...],
 *       'shipping_address' => [...],
 *       'items' => [...],
 *   ]);
 *   redirect($result['pay_url']);
 */
class PaymentGatewayService
{
    public function __construct(
        private readonly array $config,
        private readonly ?string $baseUrlOverride = null,
        private readonly ?array $credentialsOverride = null,
    ) {}

    /**
     * 返回一个本次改用【指定站点地址与 WooCommerce REST API 密钥】的新实例。
     * 适用于每个支付方式对接各自 WordPress 站点、凭证存在支付方式记录里（而不是全局 .env）的场景，
     * 如 PaymentMethodResource 的「同步支付配置」按钮。
     *
     * WordPress 侧统一用站点的 Consumer Key / Secret 认证，不再区分「订单账号」「配置账号」
     * 这两套 WordPress 应用密码（已弃用）。注意插件侧的 WooKeyAuthenticator 不认标准 HTTP
     * Basic Auth，也不能保证明文 Authorization 头能被转发到位，传输方式是 URL query 参数
     * `consumer_key`/`consumer_secret`（见 client() 方法注释）。
     *
     * @param  string  $baseUrl  形如 https://example.com/wp-json/payment-plugin/v1
     * @param  string  $consumerKey  WooCommerce REST API Consumer Key（ck_xxx）
     * @param  string  $consumerSecret  WooCommerce REST API Consumer Secret（cs_xxx）
     */
    public function withConnection(string $baseUrl, string $consumerKey, string $consumerSecret): self
    {
        return new self($this->config, rtrim($baseUrl, '/'), [
            'username' => $consumerKey,
            'password' => $consumerSecret,
        ]);
    }

    /**
     * 注册或轮换一份网关凭证（对应 POST /gateway-config，使用 WooCommerce REST API 密钥认证）。
     * 重复传同一个config_key会覆盖旧配置，旧密钥立即失效。
     *
     * @param  string  $configKey  自定义标识，字母数字下划线中划线，≤64字符
     * @param  string  $paymentMethod  stripe / paypal_js / paypal_rest / airwallex / antom
     * @param  array  $config  该网关要求的明文凭证字段，见API文档"config 字段"表
     * @return array{config_id:int,config_key:string,payment_method:string,config_fingerprint:string,created_at:string}
     *
     * @throws PaymentGatewayException
     */
    public function registerGatewayConfig(string $configKey, string $paymentMethod, array $config): array
    {
        return $this->request('gateway-config', [
            'config_key' => $configKey,
            'payment_method' => $paymentMethod,
            'config' => $config,
        ]);
    }

    /**
     * 创建一笔支付订单（对应 POST /pay，使用 WooCommerce REST API 密钥认证）。
     * 对同一个s_order_id重复调用是幂等的（插件那边保证），网络超时后可以放心重试，
     * 不会重复创建订单——本方法内部也默认开启了HTTP层的自动重试，见config('payment_gateway.retry_times')。
     *
     * @param  array  $payload  完整请求体，字段见API文档 POST /pay 一节
     * @return array{s_order_id:string,wp_order_id:int,payment_method:string,currency:string,amount:float,status:string,pay_url:string,expires_at:string,created_at:string}
     *
     * @throws PaymentGatewayException
     */
    public function createPayment(array $payload): array
    {
        $result = $this->request('pay', $payload);

        // 插件返回 code=0（业务成功）却没给 pay_url，本该是异常情况——之前这里
        // 直接透传给调用方，OrderCreationService 用 ?? null 兜底，最终订单创建
        // "成功"但 pay_url 是 null，商户和排查的人都看不出这一步其实失败了。
        if (blank($result['pay_url'] ?? null)) {
            Log::warning('支付网关插件 /pay 返回成功但缺少 pay_url', [
                's_order_id' => $payload['s_order_id'] ?? null,
                'response' => $result,
            ]);

            throw new PaymentGatewayException('支付网关插件 /pay 返回成功但未提供 pay_url，无法生成收银台链接', -1, 200, $result);
        }

        return $result;
    }

    /**
     * 同步物流单号（对应 POST /sync-tracking，使用 WooCommerce REST API 密钥认证）。
     * 只有订单状态为paid时才会成功，单号重复同步到同一订单是幂等的。
     *
     * @param  array  $payload  见API文档 POST /sync-tracking 一节
     * @return array{s_order_id:string,wp_order_id:int,tracking_number:string,updated_at:string}
     *
     * @throws PaymentGatewayException
     */
    public function syncTracking(array $payload): array
    {
        return $this->request('sync-tracking', $payload);
    }

    /**
     * 检测某个支付账号配置是否可用（对应 POST /health，使用 WooCommerce REST API 密钥认证）。
     * $gatewayConfigId 和 $gatewayConfig 二选一，优先用 $gatewayConfigId。
     *
     * @param  int|null  $gatewayConfigId  已注册过的配置引用（推荐）
     * @param  array|null  $gatewayConfig  直传明文配置，形如 ['stripe' => [...]]
     * @return array{payment_method:string,status:string,message:string,account_id:?string,account_name:?string,checked_at:string}
     *
     * @throws PaymentGatewayException
     */
    public function checkHealth(string $paymentMethod, ?int $gatewayConfigId = null, ?array $gatewayConfig = null): array
    {
        $payload = ['payment_method' => $paymentMethod];

        if ($gatewayConfigId !== null) {
            $payload['gateway_config_id'] = $gatewayConfigId;
        } elseif ($gatewayConfig !== null) {
            $payload['gateway_config'] = $gatewayConfig;
        }

        return $this->request('health', $payload);
    }

    /**
     * 主动查询某笔订单在插件侧的最新状态（对应 POST /order-query，使用 WooCommerce REST API 密钥认证）。
     * 用来补偿"回调 5 次重试都失败了"的极端情况，或核对/兜底获取 expired 状态——
     * expired 不会触发 payment_status 回调（见文档第六节），这是目前唯一能查到的途径。
     * 不建议做成高频轮询，会给插件站点数据库增加不必要的压力。
     *
     * @return array{s_order_id:string,wp_order_id:?int,payment_method:?string,status:string,transaction_id:?string,currency:string,amount:string,pay_url:?string,tracking:array,expires_at:?string,paid_at:?string,created_at:string,updated_at:string}
     *
     * @throws PaymentGatewayException 订单不存在时 pgaCode=10001（$e->isOrderNotFound()）
     */
    public function orderQuery(string $sOrderId): array
    {
        return $this->request('order-query', ['s_order_id' => $sOrderId]);
    }

    /**
     * 查询某笔订单在插件侧的完整日志（对应 POST /order-logs，使用 WooCommerce REST API 密钥认证）。
     * 查无此单也返回成功、空列表（插件不会因为订单号写错就报错），调用方按
     * data.logs 是否为空自行判断，不需要额外处理"未找到"的异常分支。
     *
     * @return array{s_order_id:string,total:int,logs:array<int,array{id:int,level:string,message:string,payment_method:?string,wp_order_id:?int,request_data:mixed,response_data:mixed,callback_data:mixed,ip:?string,user_agent:?string,created_at:string}>}
     *
     * @throws PaymentGatewayException
     */
    public function orderLogs(string $sOrderId): array
    {
        return $this->request('order-logs', ['s_order_id' => $sOrderId]);
    }

    /**
     * 验证插件转发过来的支付状态回调签名（X-PGA-Signature 头）。
     * 必须传【原始请求体字节】，不要先json_decode再重新encode——
     * 那样字段顺序/转义方式可能变化，算出来的签名会对不上。
     *
     * 用法见 app/Http/Controllers/PaymentGatewayWebhookController.php。
     *
     * @param  string  $rawBody  $request->getContent()
     * @param  string|null  $signatureHeader  $request->header('X-PGA-Signature')
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = $this->config['webhook_secret'] ?? '';

        if (! $secret || ! $signatureHeader) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * @param  string  $path  不带前导斜杠，如 'pay'
     *
     * @throws PaymentGatewayException
     */
    private function request(string $path, array $json): array
    {
        Log::debug('支付网关请求数据', [
            'path' => $path,
            'request_body' => $json
        ]);
        $baseUrl = rtrim((string) ($this->baseUrlOverride ?? $this->config['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new PaymentGatewayException('未配置支付网关站点地址（payment_gateway.base_url 或 withConnection 传入）', -1);
        }

        // 认证凭证只认 withConnection() 传入的支付方式站点凭证（domain_client_id/domain_client_sk），
        // 不再有全局兜底：不同支付方式对接不同 WordPress 站点，用全局共享的一把 key 兜底
        // 反而会在配置遗漏时悄悄拿错站点的密钥去认证，把问题从"报错拒绝"变成"用错凭证"。
        $credentials = $this->credentialsOverride ?? [];
        if (empty($credentials['username']) || empty($credentials['password'])) {
            throw new PaymentGatewayException(
                '未提供支付网关凭证：必须先调用 withConnection() 传入该支付方式的 domain_client_id / domain_client_sk',
                -1
            );
        }

        try {
            $response = $this->client($credentials['username'], $credentials['password'])
                ->post("{$baseUrl}/{$path}", $json);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException(
                "请求支付插件接口 /{$path} 时连接失败：".$e->getMessage(),
                -1,
                0,
                null,
                $e
            );
        }

        $body = $response->json();

        // 不管成功失败都记一条原始响应：pay_url 为空但下单接口显示成功的情况
        // （即插件返回了 200 + code 字段，但没给出预期的 pay_url）单靠上面的失败分支
        // 日志看不到，必须有一条无条件的原始响应记录才能确认插件到底返回了什么结构。
        Log::debug('支付网关插件请求响应', [
            'path' => $path,
            'base_url' => $baseUrl,
            'http_status' => $response->status(),
            'response_body' => mb_substr($response->body(), 0, 1000),
        ]);

        if (! is_array($body) || ! array_key_exists('code', $body)) {
            throw new PaymentGatewayException(
                "接口 /{$path} 返回了非预期的响应格式（HTTP {$response->status()}）",
                -1,
                $response->status(),
                is_array($body) ? $body : null
            );
        }

        // (int) 强转非数字 code（如插件返回 "success" 这类非数字状态字符串）会被 PHP 转成 0，
        // 跟真正的成功码 0 混为一谈——先判 is_numeric 排除这种误判为成功的情况，
        // 而不是只用 (int) 转换后判断是否等于 0。
        if (! is_numeric($body['code']) || (int) $body['code'] !== 0) {
            // 鉴权失败等场景插件走的是 WordPress REST 框架原生的 WP_Error 响应格式：
            // 顶层 code 是字符串 slug（如 "pga_auth_failed"），真正的业务数字码嵌在
            // data.code 里（如 10004，PaymentGatewayException::isAuthFailure() 认的
            // 就是这个数字）。顶层 code 本来就是数字时才直接用它，避免这种情况下
            // (int) 顶层 code 恒等于 0，pgaCode 传出去全变成 -1，isAuthFailure() 永远判不出来。
            $pgaCode = is_numeric($body['code']) ? (int) $body['code'] : (int) ($body['data']['code'] ?? -1);

            // WordPress 侧站点认证类失败（如 WooCommerce REST API 密钥错误/被禁用/权限不足）
            // 单独打日志：只记凭证长度和首尾几位做指纹比对，不记完整明文，
            // 方便核对"这次用的到底是哪个支付方式的哪一把 key/secret"，而不用去猜。
            Log::warning('支付网关插件请求业务失败', [
                'path' => $path,
                'base_url' => $baseUrl,
                'username_fingerprint' => $this->credentialFingerprint($credentials['username']),
                'password_fingerprint' => $this->credentialFingerprint($credentials['password']),
                'http_status' => $response->status(),
                'code' => $body['code'],
                'pga_code' => $pgaCode,
                'message' => $body['message'] ?? null,
            ]);

            throw new PaymentGatewayException(
                (string) ($body['message'] ?? '未知错误'),
                $pgaCode,
                $response->status(),
                $body
            );
        }

        return $body['data'] ?? [];
    }

    /** 凭证指纹：只保留首尾各 4 位和长度，既能核对"是不是同一把 key"，又不落明文。 */
    private function credentialFingerprint(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4).str_repeat('*', $length - 8).substr($value, -4)." (len={$length})";
    }

    private function client(string $username, string $password): PendingRequest
    {
        // 站点侧认证插件（WooKeyAuthenticator）明确排除标准 HTTP Basic Auth
        // （代码里判定 Authorization 头含 "Basic" 字样就直接跳过，视为未提供凭据），
        // 只认 "Authorization: ck:cs" 明文格式或 query 参数。改用 query 参数而不是明文
        // Authorization 头：本地/部分反代环境的 Web 服务器默认不会把自定义 Authorization
        // 头转发给 PHP-FPM（经典的 mod_fcgid/Nginx 陷阱，跟 Basic 前缀无关，服务器压根没转发
        // 这个 header），实测明文 header 方式仍然 401；query 参数不依赖任何 header 转发配置，
        // 一定能到达 PHP，是两种支持方式里更可靠的一个。
        return Http::withOptions(['query' => ['consumer_key' => $username, 'consumer_secret' => $password]])
            ->acceptJson()
            ->withoutVerifying()
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->retry(
                (int) ($this->config['retry_times'] ?? 2),
                (int) ($this->config['retry_sleep_ms'] ?? 300),
                // 只对"连接层面的问题"自动重试，插件已经明确返回业务错误（4xx/5xx且带JSON body）
                // 的情况不重试——重试解决不了参数错误/权限问题，只会让排查更麻烦。
                fn ($exception) => $exception instanceof ConnectionException,
                // retry() 的 $throw 参数默认是 true：即使上面这个 when 回调判定"不重试"，
                // 只要最终响应是非 2xx，Laravel 仍会自动抛 RequestException，导致下面
                // 解析 $body['code']/$body['message'] 并包装成 PaymentGatewayException 的
                // 逻辑永远走不到——插件对业务错误（如 /order-query 的订单不存在）经常是
                // 用 4xx 状态码带 JSON body 返回的，不是 200 + code!=0，必须关掉这个默认行为。
                throw: false
            );
    }
}
