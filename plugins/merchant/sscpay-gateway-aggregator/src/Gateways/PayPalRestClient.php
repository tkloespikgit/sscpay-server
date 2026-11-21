<?php
/**
 * PayPal REST API (Orders v2) 的共享客户端，被 PayPalJsGateway（前端按钮流程的
 * 服务端部分）和 PayPalRestGateway（纯跳转流程）共用，避免OAuth2取token、
 * create/capture订单、验证webhook签名这些逻辑在两个Gateway类里各写一遍。
 *
 * 参考：
 * - OAuth2: POST /v1/oauth2/token
 * - Orders: POST /v2/checkout/orders ，POST /v2/checkout/orders/{id}/capture
 * - Webhook验证: POST /v1/notifications/verify-webhook-signature
 */

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\Exceptions\GatewayAccountForbiddenException;
use PaymentGatewayAggregator\Core\Exceptions\PayPalCaptureException;
use PaymentGatewayAggregator\Core\Exceptions\PayPalOrderNotFoundException;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\PaymentBase;
use PaymentGatewayAggregator\Helpers\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class PayPalRestClient
{
    public static function baseUrl(array $config)
    {
        // 环境字段统一走 resolveMode()：S系统注册的配置里叫 env 不叫 mode，
        // 直接读 mode 会静默降级到沙箱，拿生产凭据打 sandbox 会得到
        // invalid_client / Client Authentication failed。
        $mode = PaymentBase::resolveMode($config);
        return $mode === PaymentBase::MODE_LIVE
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * @param  array  $config  需要 client_id / client_secret / mode
     * @return string access token
     * @throws \RuntimeException
     */
    public static function getAccessToken(array $config)
    {
        $cacheKey = 'pga_paypal_token_'.md5($config['client_id'].'|'.self::baseUrl($config));
        $cached   = get_transient($cacheKey);
        if ($cached) {
            return $cached;
        }

        $result = HttpClient::postForm(
            self::baseUrl($config).'/v1/oauth2/token',
            ['grant_type' => 'client_credentials'],
            ['Authorization' => 'Basic '.base64_encode($config['client_id'].':'.$config['client_secret'])],
            HttpClient::DEFAULT_TIMEOUT,
            $config['proxy'] ?? null
        );

        if (!$result['ok'] || empty($result['body']['access_token'])) {
            if ($result['status'] === 401) {
                // client_id/client_secret无效或已吊销，PayPal典型返回invalid_client：
                // 账户凭证问题，不是这次请求本身的问题。
                throw new GatewayAccountForbiddenException('PayPal获取access_token失败（账户凭证问题）：'.($result['error'] ?? wp_json_encode($result['body'])));
            }
            throw new \RuntimeException('PayPal获取access_token失败：'.($result['error'] ?? wp_json_encode($result['body'])));
        }

        $ttl = max(60, (int) ($result['body']['expires_in'] ?? 1800) - 60);
        set_transient($cacheKey, $result['body']['access_token'], $ttl);

        return $result['body']['access_token'];
    }

    /**
     * @param  array  $config
     * @param  string  $sOrderId
     * @param  float  $amount
     * @param  string  $currency
     * @param  array  $urls  ['return_url'=>..,'cancel_url'=>..] 跳转流程需要，JS按钮流程可不传
     * @param  array  $details  订单明细（来自wp_payment_transactions，见AbstractPayPalGateway::buildOrderDetails()）：
     *                       items / invoice_number / shipping_address / shipping_fee / tax_fee / discount_fee / customer_name
     *                       其中items已经按trans_type组装完毕（见Core/LineItemBuilder），
     *                       这里不再区分CREATE/MATCH/VIRTUAL，只负责翻译成PayPal的字段。
     * @param  string|null $requestId 已持久化的本次会话创建幂等键
     * @return array{id:string,approve_url:?string}
     * @throws \RuntimeException
     */
    public static function createOrder(
        array $config,
        $sOrderId,
        $amount,
        $currency,
        array $urls = [],
        array $details = [],
        $requestId = null
    ) {
        $token        = self::getAccessToken($config);
        $currencyCode = strtoupper($currency);

        $money = function ($value) use ($currencyCode) {
            return ['currency_code' => $currencyCode, 'value' => number_format((float) $value, 2, '.', '')];
        };

        $domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        $unit = [
            'amount' => $money($amount),
        ];
        if ($domain !== '') {
            // custom_id：商户侧可见、买家不可见，标记这笔订单来自哪个站点。
            $unit['custom_id'] = mb_substr($domain, 0, 127);
        }

        // 账单号：PayPal会把它展示在买家的交易历史和邮件通知里，上限127字符。
        // 三种trans_type模式下一律带上；为空（1.4.0之前的历史交易）时整个字段不写，
        // 避免PayPal对空字符串的invoice_id报错。
        //
        // 不把S系统内部订单号(s_order_id，即$sOrderId参数)透传给PayPal：
        // reference_id/invoice_id统一用账单号(invoice_no)标记，历史交易没有
        // 账单号时reference_id也不写（$sOrderId参数目前只用于下面的日志记录）。
        $invoiceNumber = trim((string) ($details['invoice_number'] ?? ''));
        if ($invoiceNumber !== '') {
            $unit['reference_id'] = $invoiceNumber;
            $unit['invoice_id'] = mb_substr($invoiceNumber, 0, 127);
        }

        // 商品行明细。name/sku已在上游按trans_type选好（CREATE模式name就是SKU，
        // VIRTUAL模式只有subject那一条），这里只做截断和金额累加。
        // 注意：PayPal Orders v2要求只要携带purchase_units[].items，
        // 就必须同时提供amount.breakdown.item_total（否则报ITEM_TOTAL_REQUIRED），
        // 且会严格校验 item_total + shipping + tax_total - discount === amount.value
        // （否则报AMOUNT_MISMATCH）。所以items和breakdown必须同进同出：金额能对得上时
        // 两者都传；对不上时两者都不传，退化为只传总金额（PayPal允许不传明细）。
        // VIRTUAL模式下这个校验等价于要求 subtotal + 运费 + 税费 - 折扣 === amount，
        // S系统传的subtotal不自洽时会走下面的降级分支，订单仍然能创建成功。
        $shippingFee = (float) ($details['shipping_fee'] ?? 0);
        $taxFee      = (float) ($details['tax_fee'] ?? 0);
        $discountFee = (float) ($details['discount_fee'] ?? 0);

        $items     = [];
        $itemTotal = 0.0;
        if (!empty($details['items'])) {
            foreach ($details['items'] as $item) {
                $quantity   = max(1, (int) ($item['quantity'] ?? 1));
                $unitAmount = number_format((float) ($item['price'] ?? 0), 2, '.', '');

                $name = trim((string) ($item['name'] ?? ''));
                $sku  = trim((string) ($item['sku'] ?? ''));

                // 商品链接：S系统在 /v1/pay 的 items[].product_url 里已经把站内商品
                // 详情页带过来了（见 Core/LineItemBuilder），这里直接用，不再按SKU
                // 回查本地商品库。为空就不传这个字段（PayPal允许url缺省），不阻断下单。
                $productUrl = trim((string) ($item['url'] ?? ''));
                $productUrl = $productUrl !== '' ? $productUrl : null;

                $items[]   = array_filter([
                    'name'        => mb_substr($name !== '' ? $name : 'Item', 0, 127),
                    'sku'         => $sku !== '' ? mb_substr($sku, 0, 127) : null,
                    'url'         => $productUrl !== null ? mb_substr($productUrl, 0, 2000) : null,
                    'quantity'    => (string) $quantity,
                    'unit_amount' => ['currency_code' => $currencyCode, 'value' => $unitAmount],
                ]);
                $itemTotal += (float) $unitAmount * $quantity;
            }
        }

        if ($items) {
            $sum = round($itemTotal + $shippingFee + $taxFee - $discountFee, 2, PHP_ROUND_HALF_DOWN);
            if (abs($sum - round((float) $amount, 2, PHP_ROUND_HALF_DOWN)) < 0.01) {
                $breakdown = ['item_total' => $money($itemTotal)];
                if ($shippingFee > 0) {
                    $breakdown['shipping'] = $money($shippingFee);
                }
                if ($taxFee > 0) {
                    $breakdown['tax_total'] = $money($taxFee);
                }
                if ($discountFee > 0) {
                    $breakdown['discount'] = $money($discountFee);
                }
                $unit['amount']['breakdown'] = $breakdown;
                $unit['items']               = $items;
            } else {
                // 明细合计（含折扣）与订单总金额仍对不上：
                // items和breakdown一起放弃，只传总额，保证下单不被阻断。
                LogManager::warning($sOrderId, sprintf(
                    'PayPal明细合计%.2f与订单总金额%.2f不一致（运费%.2f/税费%.2f/折扣%.2f），放弃商品明细仅传总额',
                    $sum,
                    (float) $amount,
                    $shippingFee,
                    $taxFee,
                    $discountFee
                ), ['payment_method' => 'paypal']);
            }
        }

        // 收货地址：配合下面application_context的SET_PROVIDED_ADDRESS，
        // 买家在PayPal页面上看到/锁定的就是S系统下单时填的地址。
        if (!empty($details['shipping_address']['country'])) {
            $addr     = $details['shipping_address'];
            $shipping = [];
            if (!empty($details['customer_name'])) {
                $shipping['name'] = ['full_name' => mb_substr((string) $details['customer_name'], 0, 127)];
            }
            $shipping['address'] = array_filter([
                'address_line_1' => $addr['address'] ?? '',
                'admin_area_2'   => $addr['city'] ?? '',
                'admin_area_1'   => $addr['state'] ?? '',
                'postal_code'    => $addr['zip'] ?? '',
                'country_code'   => strtoupper((string) $addr['country']),
            ]);
            $unit['shipping']    = $shipping;
        }

        $body = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [$unit],
        ];

        $appContext = [];
        if (!empty($urls['return_url']) && !empty($urls['cancel_url'])) {
            $appContext['return_url']  = $urls['return_url'];
            $appContext['cancel_url']  = $urls['cancel_url'];
            $appContext['user_action'] = 'PAY_NOW';
        }
        if (!empty($unit['shipping'])) {
            // 使用商户提供的收货地址，买家不能在PayPal页面改成别的地址。
            $appContext['shipping_preference'] = 'SET_PROVIDED_ADDRESS';
        }
        if ($appContext) {
            $body['application_context'] = $appContext;
        }

        $result = HttpClient::postJson(
            self::baseUrl($config).'/v2/checkout/orders',
            $body,
            [
                'Authorization'     => 'Bearer '.$token,
                'PayPal-Request-Id' => is_string($requestId) && $requestId !== '' ? $requestId : wp_generate_uuid4(),
            ],
            HttpClient::DEFAULT_TIMEOUT,
            $config['proxy'] ?? null
        );

        if (!$result['ok'] || empty($result['body']['id'])) {
            if (self::hasIssue($result['body'], 'PAYEE_ACCOUNT_RESTRICTED')) {
                // PayPal Orders v2对"收款方账户被限制"有明确的issue代码，
                // 这是账户级问题，重试同样的订单不会成功。
                throw new GatewayAccountForbiddenException('PayPal创建订单失败（收款账户被限制）：'.wp_json_encode($result['body']));
            }
            throw new \RuntimeException('PayPal创建订单失败：'.wp_json_encode($result['body']));
        }

        $approveUrl = null;
        foreach ($result['body']['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                $approveUrl = $link['href'];
                break;
            }
        }

        return ['id' => $result['body']['id'], 'approve_url' => $approveUrl];
    }

    /**
     * ⚠️ 未经PayPal沙箱核实的实现：Orders v2 API下"Add Tracking Information"
     * 端点（POST /v2/checkout/orders/{id}/track），文档参考
     * https://developer.paypal.com/docs/api/orders/v2/#orders_track_create 。
     * 上线前请用真实沙箱订单核实一遍字段名和响应结构，尤其是carrier枚举值列表。
     *
     * @param  array  $config
     * @param  string  $orderId
     * @param  array  $payload  ['capture_id'=>..,'tracking_number'=>..,'carrier'=>..,
     *                          'carrier_name_other'=>?..,'notify_buyer'=>bool]
     * @return array PayPal的响应体（成功时通常为空数组，201/204状态码）
     * @throws \RuntimeException
     */
    public static function addTracking(array $config, $orderId, array $payload)
    {
        $token = self::getAccessToken($config);

        $result = HttpClient::postJson(
            self::baseUrl($config)."/v2/checkout/orders/{$orderId}/track",
            $payload,
            ['Authorization' => 'Bearer '.$token],
            HttpClient::DEFAULT_TIMEOUT,
            $config['proxy'] ?? null
        );

        if (!$result['ok']) {
            throw new \RuntimeException('PayPal同步物流信息失败：'.wp_json_encode($result['body']));
        }

        return is_array($result['body']) ? $result['body'] : [];
    }

    /**
     * GET一次订单当前状态，供主动核实（/v1/order-query）使用——只读查询，
     * 不会像captureOrder()那样触发扣款。
     *
     * @param  array  $config
     * @param  string  $orderId
     * @return array PayPal的Order响应体（含status/purchase_units[].payments.captures[]等）
     * @throws PayPalOrderNotFoundException PayPal明确返回该订单不存在，是否轮换由会话管理器决定
     * @throws \RuntimeException
     */
    public static function getOrder(array $config, $orderId)
    {
        $token = self::getAccessToken($config);

        $result = HttpClient::getJson(
            self::baseUrl($config)."/v2/checkout/orders/{$orderId}",
            ['Authorization' => 'Bearer '.$token],
            HttpClient::DEFAULT_TIMEOUT,
            $config['proxy'] ?? null
        );

        if (!$result['ok']) {
            $message = 'PayPal查询订单状态失败：'.($result['error'] ?? wp_json_encode($result['body']));
            $body = is_array($result['body']) ? $result['body'] : [];
            // 只识别 Orders GET 的明确错误；代理返回的普通404、鉴权失败及网络错误不能据此重建。
            if ($result['status'] === 404 && ($body['name'] ?? '') === 'RESOURCE_NOT_FOUND'
                && is_array($body['details'] ?? null)) {
                foreach ($body['details'] as $detail) {
                    if (is_array($detail) && ($detail['issue'] ?? '') === 'INVALID_RESOURCE_ID') {
                        throw new PayPalOrderNotFoundException($message, 404);
                    }
                }
            }
            throw new \RuntimeException($message);
        }

        return $result['body'];
    }

    /**
     * @param  array  $config
     * @param  string  $orderId
     * @return array PayPal的capture响应体
     * @throws \RuntimeException
     */
    public static function captureOrder(array $config, $orderId)
    {
        $token = self::getAccessToken($config);

        $result = HttpClient::postJson(
            self::baseUrl($config)."/v2/checkout/orders/{$orderId}/capture",
            [],
            [
                'Authorization'     => 'Bearer '.$token,
                'PayPal-Request-Id' => wp_generate_uuid4(),
            ],
            HttpClient::DEFAULT_TIMEOUT,
            $config['proxy'] ?? null
        );

        if (!$result['ok']) {
            if (is_array($result['body'])) {
                throw new PayPalCaptureException($result['body']);
            }
            throw new \RuntimeException('PayPal捕获支付失败：'.wp_json_encode($result['body']));
        }

        return $result['body'];
    }

    /**
     * 用PayPal官方的服务端验证接口验证webhook签名，而不是本地手动验证证书链——
     * 更简单也更不容易出错，代价是多一次到PayPal的HTTP调用。
     *
     * @param  array  $config  需要 webhook_id / client_id / client_secret / mode
     * @param  array  $headers  小写key的请求头
     * @param  string  $rawBody
     * @return bool
     */
    public static function verifyWebhookSignature(array $config, array $headers, $rawBody)
    {
        if (empty($config['webhook_id'])) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'PayPal Webhook验签中止：config缺少webhook_id', [
                'payment_method' => 'paypal',
            ]);
            return false;
        }

        try {
            $token = self::getAccessToken($config);
        } catch (\RuntimeException $e) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'PayPal Webhook验签中止：获取access_token失败——' . $e->getMessage(), [
                'payment_method' => 'paypal',
                'request_data' => ['mode' => PaymentBase::resolveMode($config), 'client_id' => $config['client_id'] ?? null],
            ]);
            return false;
        }

        $webhookEvent = json_decode($rawBody, true);
        if (!is_array($webhookEvent)) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'PayPal Webhook验签中止：rawBody不是合法JSON', [
                'payment_method' => 'paypal',
            ]);
            return false;
        }

        $body = [
            'transmission_id'   => $headers['paypal-transmission-id'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'cert_url'          => $headers['paypal-cert-url'] ?? '',
            'auth_algo'         => $headers['paypal-auth-algo'] ?? '',
            'transmission_sig'  => $headers['paypal-transmission-sig'] ?? '',
            'webhook_id'        => $config['webhook_id'],
            'webhook_event'     => $webhookEvent,
        ];

        $result = HttpClient::postJson(
            self::baseUrl($config).'/v1/notifications/verify-webhook-signature',
            $body,
            ['Authorization' => 'Bearer '.$token],
            HttpClient::DEFAULT_TIMEOUT,
            $config['proxy'] ?? null
        );

        $verified = $result['ok'] && (($result['body']['verification_status'] ?? '') === 'SUCCESS');

        if (!$verified) {
            // 诊断用：把PayPal verify接口的真实返回（或HTTP层错误）落到系统日志里，
            // 否则WebhookRouter那边只会记一句"签名验证失败"，看不出是access_token
            // 没问题但PayPal判定FAILURE，还是HTTP调用本身就没成功。
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'PayPal Webhook验签未通过，verify-webhook-signature返回详情如下', [
                'payment_method' => 'paypal',
                'request_data' => [
                    'mode' => PaymentBase::resolveMode($config),
                    'webhook_id' => $config['webhook_id'],
                    'transmission_id' => $body['transmission_id'],
                    // 完整入站请求头：transmission_id等三个字段为空时，靠这个判断
                    // 是"PayPal专属头整体丢失"（反向代理/CDN/WAF在Header层面被吃掉）
                    // 还是"只有这几个头没送达"。
                    'incoming_headers' => $headers,
                ],
                'response_data' => [
                    'http_status' => $result['status'],
                    'http_error' => $result['error'],
                    'body' => $result['body'],
                ],
            ]);
        }

        return $verified;
    }

    public static function accountHealth(array $config)
    {
        // PayPal REST API没有一个专门的"账户信息"接口适合做健康检测，
        // 用能否成功换取access_token作为最基本的可用性检查。
        $token = self::getAccessToken($config);
        return (bool) $token;
    }

    /**
     * 在PayPal错误响应的details[]数组里查找指定issue代码
     * （形如 {"details":[{"issue":"PAYEE_ACCOUNT_RESTRICTED", ...}]}）。
     *
     * @param mixed $body HttpClient返回的result['body']，可能不是数组（如原始HTML/文本）
     * @param string $issue
     * @return bool
     */
    private static function hasIssue($body, $issue)
    {
        if (!is_array($body) || empty($body['details']) || !is_array($body['details'])) {
            return false;
        }

        foreach ($body['details'] as $detail) {
            if (($detail['issue'] ?? '') === $issue) {
                return true;
            }
        }

        return false;
    }
}
