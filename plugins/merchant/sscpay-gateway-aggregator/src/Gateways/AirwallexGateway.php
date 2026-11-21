<?php
/**
 * Airwallex：Payment Intent + 跳转到Airwallex Hosted Payment Page完成支付。
 *
 * ⚠️ P1网关，标记为"需要在接入前对照Airwallex最新官方文档核实"：
 *    - Payment Intent创建接口（/api/v1/pa/payment_intents/create）的鉴权方式、
 *      核心字段（request_id/amount/currency/merchant_order_id）经过核实，比较确定。
 *    - 但下面preparePayment()里跳转用的Hosted Payment Page URL格式，
 *      是按Airwallex公开资料里常见的模式拼的，没有能够独立核实到当前
 *      最新、完整的query参数列表，上线前请对照 Airwallex 开发者后台的
 *      "Hosted Payment Page" 文档核实一遍，必要时改用他们的 Drop-in Element
 *      JS SDK（前端嵌入，而不是纯跳转）替代这里的实现。
 *    - amount字段这里按Airwallex文档展示的"十进制金额"格式传（如 "99.99"），
 *      不是Stripe那种整数分——如果实测发现不对，检查一下当前Airwallex
 *      账号版本的API是否改成了最小单位整数。
 */

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\Exceptions\GatewayAccountForbiddenException;
use PaymentGatewayAggregator\Core\PaymentBase;
use PaymentGatewayAggregator\Core\LineItemBuilder;
use PaymentGatewayAggregator\Frontend\PaymentPage;
use PaymentGatewayAggregator\Helpers\HttpClient;
use PaymentGatewayAggregator\Helpers\TokenGenerator;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class AirwallexGateway extends PaymentBase
{
    public function code()
    {
        return 'airwallex';
    }

    public function validateConfig(array $config)
    {
        return array_merge(
            Validator::requireFields($config, ['api_key', 'client_id', 'webhook_secret']),
            self::requireMode($config)
        );
    }

    private function baseUrl()
    {
        $mode = self::resolveMode($this->config);
        return $mode === self::MODE_LIVE
            ? 'https://api.airwallex.com'
            : 'https://api.sandbox.airwallex.com';
    }

    private function getAccessToken()
    {
        $cacheKey = 'pga_airwallex_token_' . md5($this->config['client_id'] . '|' . $this->baseUrl());
        $cached = get_transient($cacheKey);
        if ($cached) {
            return $cached;
        }

        $result = HttpClient::postJson($this->baseUrl() . '/api/v1/authentication/login', [], [
            'x-api-key' => $this->config['api_key'],
            'x-client-id' => $this->config['client_id'],
        ], HttpClient::DEFAULT_TIMEOUT, $this->config['proxy'] ?? null);

        if (!$result['ok'] || empty($result['body']['token'])) {
            if (in_array($result['status'], [401, 403], true)) {
                // api_key/client_id无效或权限不足：账户凭证问题，不是这次请求本身的问题，
                // 换一次请求参数重试也不会成功。
                throw new GatewayAccountForbiddenException('Airwallex认证失败（账户凭证问题）：' . wp_json_encode($result['body']));
            }
            throw new \RuntimeException('Airwallex认证失败：' . wp_json_encode($result['body']));
        }

        // 官方文档标明token有效期30分钟，缓存25分钟留出余量。
        set_transient($cacheKey, $result['body']['token'], 25 * MINUTE_IN_SECONDS);

        return $result['body']['token'];
    }

    public function preparePayment(array $transaction)
    {
        $token = $this->getAccessToken();

        $payload = [
            'request_id' => TokenGenerator::generateIdempotencyKey(),
            'amount' => (float) $transaction['amount'],
            'currency' => strtoupper($transaction['currency']),
            // 部分跳转类支付方式（3DS挑战/银行跳转等）会用这个字段把浏览器带回来，
            // 不能直接指向S系统的return_url：真正的支付结果以Webhook为准，这里和
            // 下面Hosted Payment Page的successUrl一样统一落回插件自己的结果页
            // （见PaymentPage::resultPageUrl），"Return to Store"按钮才跳S系统。
            'return_url' => PaymentPage::resultPageUrl($transaction),
        ];

        // metadata里带上本站域名，让Airwallex后台能看出这笔Payment Intent来自哪个站点。
        $payload['metadata'] = ['site_domain' => (string) wp_parse_url(home_url(), PHP_URL_HOST)];

        // 不把S系统内部订单号(s_order_id)透传给Airwallex：merchant_order_id/metadata
        // 统一用账单号(invoice_no)标记；历史交易没有账单号时这两个字段都不写，
        // 不能传空字符串。这个网关目前不向Airwallex传商品明细，
        // 所以trans_type对它没有可见影响。
        $invoiceNumber = LineItemBuilder::invoiceNumber($transaction);
        if ($invoiceNumber !== '') {
            $payload['merchant_order_id'] = $invoiceNumber;
            $payload['metadata']['invoice_no'] = $invoiceNumber;
        }

        $result = HttpClient::postJson(
            $this->baseUrl() . '/api/v1/pa/payment_intents/create',
            $payload,
            ['Authorization' => 'Bearer ' . $token],
            HttpClient::DEFAULT_TIMEOUT,
            $this->config['proxy'] ?? null
        );

        if (!$result['ok'] || empty($result['body']['id'])) {
            if (in_array($result['status'], [401, 403], true)) {
                // 认证过了但这一步被拒（比如key没有创建Payment Intent的权限，
                // 或账户本身被限制），同样是账户级问题，不是这一单参数有误。
                throw new GatewayAccountForbiddenException('Airwallex创建Payment Intent失败（账户凭证/权限问题）：' . wp_json_encode($result['body']));
            }
            throw new \RuntimeException('Airwallex创建Payment Intent失败：' . wp_json_encode($result['body']));
        }

        $intentId = $result['body']['id'];
        $clientSecret = $result['body']['client_secret'] ?? '';
        $mode = self::resolveMode($this->config);
        $checkoutHost = $mode === self::MODE_LIVE ? 'checkout.airwallex.com' : 'checkout-demo.airwallex.com';

        // 见文件头注释：这个URL格式未完全核实，上线前请对照官方文档确认。
        // successUrl/failUrl都指向插件自己的页面，不直接跳S系统：
        // - successUrl落回结果页（PaymentPage::resultPageUrl），真正的支付结果以
        //   Webhook为准，结果页展示终态后"Return to Store"按钮才跳S系统的
        //   return_url（与PayPal/Stripe两个网关的return流程一致）；
        // - failUrl指向插件自己的取消页而不是S系统的cancel_url：买家在Airwallex
        //   收银台取消/失败时落回取消页，交易仍是pending，那里会写 user canceled
        //   日志并展示高亮的"Retry Payment"按钮（与其他网关行为一致，见PaymentPage）。
        $redirectUrl = sprintf(
            'https://%s/#/standalone/checkout?intent_id=%s&client_secret=%s&currency=%s&successUrl=%s&failUrl=%s',
            $checkoutHost,
            rawurlencode($intentId),
            rawurlencode($clientSecret),
            rawurlencode(strtoupper($transaction['currency'])),
            rawurlencode(PaymentPage::resultPageUrl($transaction)),
            rawurlencode(PaymentPage::cancelPageUrl($transaction))
        );

        return [
            'intent_id' => $intentId,
            'redirect_url' => $redirectUrl,
            'external_ref' => $intentId,
        ];
    }

    public function renderPaymentPage(array $transaction, array $sessionData)
    {
        $url = esc_url($sessionData['redirect_url']);
        ob_start();
        ?>
        <div class="pga-gateway pga-gateway-airwallex">
            <a class="pga-pay-button" href="<?php echo $url; ?>" rel="noopener">
                <?php esc_html_e('前往支付 (Airwallex)', 'sscpay-gateway-aggregator'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    public function extractExternalRef($rawBody, array $headers)
    {
        $data = json_decode($rawBody, true);
        $name = $data['name'] ?? '';
        $object = $data['data']['object'] ?? [];

        // dispute.* 事件里 object.id 是Dispute对象自己的id，不是我们表里存的
        // Payment Intent id，反查要用payment_intent_id（字段名见下方parseCallbackEvent
        // 同一处注释：未经沙箱核实，上线前需对照Airwallex最新Dispute Webhook文档确认）。
        if (strpos($name, 'dispute.') === 0) {
            return $object['payment_intent_id'] ?? ($object['payment_attempt_id'] ?? null);
        }

        return $object['id'] ?? null;
    }

    public function verifyCallbackSignature($rawBody, array $headers, array $config)
    {
        $signature = $headers['x-signature'] ?? '';
        $timestamp = $headers['x-timestamp'] ?? '';

        if (!$signature || !$timestamp || empty($config['webhook_secret'])) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $rawBody, $config['webhook_secret']);

        return hash_equals($expected, $signature);
    }

    public function parseCallbackEvent($rawBody, array $headers)
    {
        $data = json_decode($rawBody, true) ?: [];
        $name = $data['name'] ?? '';
        $object = $data['data']['object'] ?? [];

        $result = [
            'event_id' => $data['id'] ?? '',
            'external_ref' => $object['id'] ?? '',
            'event_status' => 'ignored',
            'amount_minor' => null,
            'currency' => null,
            'transaction_id' => null,
        ];

        switch ($name) {
            case 'payment_intent.succeeded':
                $result['event_status'] = 'paid';
                $result['transaction_id'] = $object['id'] ?? null;
                if (isset($object['amount'], $object['currency'])) {
                    $result['amount_minor'] = \PaymentGatewayAggregator\Core\CurrencyManager::toMinorUnit(
                        $object['amount'],
                        $object['currency']
                    );
                    $result['currency'] = strtoupper($object['currency']);
                }
                break;

            case 'payment_intent.failed':
            case 'payment_intent.cancelled':
                $result['event_status'] = 'failed';
                $result['transaction_id'] = $object['id'] ?? null;
                break;

            case 'refund.succeeded':
                $result['event_status'] = 'refunded';
                $result['transaction_id'] = $object['id'] ?? null;
                break;

            // ⚠️ dispute.* 三个事件名和Dispute对象字段（reason_code/payment_intent_id等）
            // 是按Airwallex其它webhook命名习惯（'资源.动作'）推断的占位实现，没有找到
            // 独立核实过的Dispute Webhook样例payload——上线前必须对照Airwallex开发者
            // 后台当前账号版本的Dispute文档核实事件名和字段名，必要时调整。
            case 'dispute.created':
                $result['event_status'] = 'dispute_created';
                $result = self::fillAirwallexDisputeInfo($result, $object);
                break;

            case 'dispute.updated':
                $result['event_status'] = 'dispute_updated';
                $result = self::fillAirwallexDisputeInfo($result, $object);
                break;

            case 'dispute.closed':
                $status = strtolower((string) ($object['status'] ?? ''));
                if (strpos($status, 'lost') !== false) {
                    $result['event_status'] = 'dispute_lost';
                } elseif (strpos($status, 'won') !== false) {
                    $result['event_status'] = 'dispute_won';
                } else {
                    $result['event_status'] = 'dispute_updated';
                }
                $result = self::fillAirwallexDisputeInfo($result, $object);
                break;
        }

        return $result;
    }

    /**
     * @param array $result
     * @param array $object Dispute对象（data.object，字段名待核实，见上方parseCallbackEvent注释）
     * @return array
     */
    private static function fillAirwallexDisputeInfo(array $result, array $object)
    {
        $result['dispute_id'] = $object['id'] ?? null;
        $result['dispute_reason'] = $object['reason_code'] ?? ($object['dispute_reason_type'] ?? null);
        $result['transaction_id'] = $object['payment_intent_id'] ?? ($object['payment_attempt_id'] ?? null);

        if (isset($object['amount'], $object['currency'])) {
            $result['amount_minor'] = \PaymentGatewayAggregator\Core\CurrencyManager::toMinorUnit(
                $object['amount'],
                $object['currency']
            );
            $result['currency'] = strtoupper($object['currency']);
        }

        return $result;
    }

    /**
     * 主动核实：Retrieve Payment Intent（payment_intent字段落库的是Intent id，
     * 见preparePayment()里的external_ref）。
     */
    public function queryOrderStatus(array $transaction)
    {
        $intentId = $transaction['payment_intent'] ?? '';
        if ($intentId === '') {
            return ['status' => null, 'transaction_id' => null, 'amount_minor' => null, 'currency' => null];
        }

        $token = $this->getAccessToken();

        $result = HttpClient::getJson(
            $this->baseUrl() . '/api/v1/pa/payment_intents/' . rawurlencode($intentId),
            ['Authorization' => 'Bearer ' . $token],
            HttpClient::DEFAULT_TIMEOUT,
            $this->config['proxy'] ?? null
        );

        if (!$result['ok']) {
            throw new \RuntimeException('Airwallex查询Payment Intent失败：' . wp_json_encode($result['body']));
        }

        $body = $result['body'];
        $status = null;
        switch ($body['status'] ?? '') {
            case 'SUCCEEDED':
                $status = \PaymentGatewayAggregator\Core\TransactionStateMachine::PAID;
                break;
            case 'CANCELLED':
            case 'EXPIRED':
                $status = \PaymentGatewayAggregator\Core\TransactionStateMachine::CANCELLED;
                break;
        }

        return [
            'status' => $status,
            'transaction_id' => $body['id'] ?? null,
            'amount_minor' => isset($body['amount'], $body['currency'])
                ? \PaymentGatewayAggregator\Core\CurrencyManager::toMinorUnit($body['amount'], $body['currency']) : null,
            'currency' => isset($body['currency']) ? strtoupper($body['currency']) : null,
        ];
    }

    /**
     * Airwallex Payment Intent流程没有找到一个对应的"物流信息回传"API，如实告知
     * 不支持，不假装同步成功（这个网关本身就是P1网关，见文件头注释）。
     */
    public function syncTrackingToRemote(array $transaction, array $trackingData)
    {
        return ['supported' => false, 'success' => false, 'message' => 'Airwallex不提供物流信息同步接口'];
    }

    public function healthCheck(array $config)
    {
        try {
            $this->getAccessToken();
            return [
                'status' => 'healthy',
                'message' => 'Airwallex API凭证有效',
                'account_id' => $config['client_id'] ?? null,
                'account_name' => null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }
}
