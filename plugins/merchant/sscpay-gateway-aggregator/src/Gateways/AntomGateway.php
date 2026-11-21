<?php
/**
 * Antom（Ant International跨境支付网关，文档站 docs.antom.com）。
 *
 * ⚠️ P1网关，实现前做过一轮针对性调研，几个点需要在真正接入前用
 *    Antom沙箱账号核实一遍，不要直接当作已验证的最终实现：
 *    1. 认证方式确认是"双向RSA签名"（商户用私钥签请求，Antom用商户公钥验签；
 *       Antom用自己的私钥签响应/通知，商户用Antom公钥验签），不是常见的
 *       bearer token模式，这里已按此实现。
 *    2. Pay API请求体的具体字段（paymentAmount/paymentMethod/order等的
 *       准确结构）在Antom文档里有多份内容高度相似又不完全一致的版本，
 *       这里按最常见的"CASHIER_PAYMENT"模式实现，字段名请对照你实际
 *       申请到的商户文档核实。
 *    3. 域名是按地区分配的（美国/北美/亚太/欧洲各不同），config里的
 *       region_domain必须是Antom为你的商户号分配的那个域名，不能瞎填。
 *    4. 原设计文档表格里写的是"渲染一个FORM表单POST提交"，但实际查到的
 *       Antom Pay API是服务端JSON请求、响应里带一个跳转链接（normalUrl），
 *       不是让浏览器直接POST表单到Antom——这里按实际API方式实现，
 *       页面呈现效果上对用户没有区别（都是跳转到Antom收银台）。
 */

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\PaymentBase;
use PaymentGatewayAggregator\Core\CurrencyManager;
use PaymentGatewayAggregator\Core\Exceptions\GatewayAccountForbiddenException;
use PaymentGatewayAggregator\Core\LineItemBuilder;
use PaymentGatewayAggregator\Core\RouteIdentity;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Core\TransactionStateMachine;
use PaymentGatewayAggregator\Frontend\PaymentPage;
use PaymentGatewayAggregator\Helpers\HttpClient;
use PaymentGatewayAggregator\Helpers\TokenGenerator;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class AntomGateway extends PaymentBase
{
    public function code()
    {
        return 'antom';
    }

    public function validateConfig(array $config)
    {
        return array_merge(
            Validator::requireFields($config, [
                'client_id', 'private_key', 'antom_public_key', 'region_domain',
            ]),
            self::requireMode($config)
        );
    }

    private function baseUrl()
    {
        $mode = self::resolveMode($this->config);
        $domain = $this->config['region_domain'];
        $prefix = $mode === self::MODE_LIVE ? '/ams/api' : '/ams/sandbox/api';
        return 'https://' . $domain . $prefix;
    }

    public function preparePayment(array $transaction)
    {
        $paymentRequestId = TokenGenerator::generateIdempotencyKey();
        $amountMinor = CurrencyManager::toMinorUnit($transaction['amount'], $transaction['currency']);
        $invoiceNumber = LineItemBuilder::invoiceNumber($transaction);

        $body = [
            'paymentRequestId' => $paymentRequestId,
            'productCode' => 'CASHIER_PAYMENT',
            'paymentAmount' => [
                'currency' => strtoupper($transaction['currency']),
                'value' => (string) $amountMinor,
            ],
            'order' => [
                // 不把S系统内部订单号(s_order_id)透传给Antom：referenceOrderId/
                // orderDescription统一用账单号(invoice_no)标记。
                'referenceOrderId' => $invoiceNumber,
                // Antom 的 order 对象没有 metadata / 专门的账单号或来源站点字段（见文件头注：
                // 本网关字段名本来就待沙箱核实），只能用 orderDescription 这个自由文本
                // 同时带上账单号和本站域名，它会展示在 Antom 收银台上。这个网关目前不传
                // goods 明细，所以 trans_type 对它没有可见影响。
                'orderDescription' => trim(
                    ($invoiceNumber !== '' ? ('Order ' . $invoiceNumber) : 'Order')
                    . ' - ' . wp_parse_url(home_url(), PHP_URL_HOST)
                ),
                'orderAmount' => [
                    'currency' => strtoupper($transaction['currency']),
                    'value' => (string) $amountMinor,
                ],
                'buyer' => [
                    'referenceBuyerId' => $transaction['customer_email'],
                ],
            ],
            // Antom的 AMS pay 接口只接受一个 paymentRedirectUrl，没有对应的
            // "取消回跳地址"（不像Stripe的cancel_url、Airwallex的failUrl），所以
            // 买家在Antom收银台放弃支付时无法被引导到插件的取消页
            // （/payment/{order}/cancel），也就拿不到那里的 user canceled 日志和
            // "Retry Payment"按钮——这是五个网关里唯一没被取消页覆盖的一个。
            // 交易仍停在pending，买家重新打开 pay_url 即可重试；真正的失败/取消
            // 以webhook为准。若后续Antom文档确认存在取消回跳字段，改成
            // PaymentPage::cancelPageUrl($transaction) 就能与其他网关对齐。
            //
            // 不管成功还是放弃，这个字段都不能直接指向S系统的return_url：统一落回
            // 插件自己的结果页（PaymentPage::resultPageUrl），由结果页按订单当前
            // 状态展示终态（pending/paid都覆盖，见PaymentPage::resultCopy()的
            // default分支），"Return to Store"按钮才跳S系统，与其它网关一致。
            'paymentRedirectUrl' => PaymentPage::resultPageUrl($transaction),
            // 随每次请求下发，所以改了本站的端点形态立刻生效，不像PayPal/Stripe
            // 那样需要商户去通道后台重新登记（见Core/RouteIdentity）。
            'paymentNotifyUrl' => RouteIdentity::restUrl(RouteIdentity::notifyPath() . '/antom'),
            'env' => ['terminalType' => 'WEB'],
        ];

        $path = '/v1/payments/pay';
        $response = $this->signedRequest('POST', $path, $body);

        if (empty($response['paymentId']) && empty($response['normalUrl'])) {
            throw new \RuntimeException('Antom创建支付失败：' . wp_json_encode($response));
        }

        return [
            'payment_id' => $response['paymentId'] ?? null,
            'payment_request_id' => $paymentRequestId,
            'redirect_url' => $response['normalUrl'] ?? ($response['applinkUrl'] ?? null),
            'external_ref' => $response['paymentId'] ?? $paymentRequestId,
        ];
    }

    public function renderPaymentPage(array $transaction, array $sessionData)
    {
        if (empty($sessionData['redirect_url'])) {
            return '<p>' . esc_html__('支付渠道暂不可用，请联系客服。', 'sscpay-gateway-aggregator') . '</p>';
        }

        $url = esc_url($sessionData['redirect_url']);
        ob_start();
        ?>
        <div class="pga-gateway pga-gateway-antom">
            <a class="pga-pay-button" href="<?php echo $url; ?>" rel="noopener">
                <?php esc_html_e('前往支付 (Antom)', 'sscpay-gateway-aggregator'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    public function extractExternalRef($rawBody, array $headers)
    {
        $data = json_decode($rawBody, true);

        // Dispute类通知假设payment id嵌在dispute子对象里，字段名未经核实，
        // 见下方parseCallbackEvent()同一处注释。
        if (isset($data['dispute']['paymentId'])) {
            return $data['dispute']['paymentId'];
        }

        return $data['paymentId'] ?? ($data['paymentRequestId'] ?? null);
    }

    public function verifyCallbackSignature($rawBody, array $headers, array $config)
    {
        $signatureHeader = $headers['signature'] ?? '';
        $clientId = $headers['client-id'] ?? '';
        $requestTime = $headers['request-time'] ?? '';
        // 由WebhookController补充传入，用于拼string-to-sign（见该Controller注释）。
        $method = $headers['x-pga-http-method'] ?? 'POST';
        $path = $headers['x-pga-http-path'] ?? '';

        if (!$signatureHeader || !$requestTime || empty($config['antom_public_key'])) {
            return false;
        }

        $signature = $this->extractSignatureValue($signatureHeader);
        if (!$signature) {
            return false;
        }

        $stringToSign = "{$method} {$path}\n{$clientId}.{$requestTime}.{$rawBody}\n";

        return $this->verifyRsaSignature($stringToSign, $signature, $config['antom_public_key']);
    }

    public function parseCallbackEvent($rawBody, array $headers)
    {
        $data = json_decode($rawBody, true) ?: [];

        // ⚠️ Antom的Dispute通知结构没有找到能独立核实的公开样例payload（比本文件头注释
        // 列的4点已知不确定性更甚），这里只能按"和支付结果通知走同一个paymentNotifyUrl，
        // 用notifyType或dispute子对象区分"这个假设写一个占位实现，字段名
        // （notifyType/dispute/disputeId/disputeStatus/disputeReason/disputeAmount）
        // 全部待Antom沙箱核实，上线前必须用真实的Dispute通知样例重新核对，否则这条链路
        // 大概率是收不到/解析不出正确结果的。
        if (isset($data['notifyType']) && stripos((string) $data['notifyType'], 'DISPUTE') !== false) {
            return $this->parseAntomDisputeEvent($data);
        }
        if (isset($data['dispute']) && is_array($data['dispute'])) {
            return $this->parseAntomDisputeEvent($data);
        }

        $result = [
            'event_id' => $data['paymentId'] ?? ($data['paymentRequestId'] ?? uniqid('antom_', true)),
            'external_ref' => $this->extractExternalRef($rawBody, $headers),
            'event_status' => 'ignored',
            'amount_minor' => null,
            'currency' => null,
            'transaction_id' => $data['paymentId'] ?? null,
        ];

        $resultStatus = $data['result']['resultStatus'] ?? ($data['paymentResultCode'] ?? '');
        $paymentStatus = $data['paymentStatus'] ?? '';

        if ($paymentStatus === 'SUCCESS' || $resultStatus === 'S') {
            $result['event_status'] = 'paid';
        } elseif ($paymentStatus === 'FAIL' || $resultStatus === 'F') {
            $result['event_status'] = 'failed';
        } elseif ($paymentStatus === 'REFUNDED') {
            $result['event_status'] = 'refunded';
        }

        if (isset($data['paymentAmount']['value'], $data['paymentAmount']['currency'])) {
            $result['amount_minor'] = (int) $data['paymentAmount']['value'];
            $result['currency'] = strtoupper($data['paymentAmount']['currency']);
        }

        return $result;
    }

    /**
     * @param array $data 见parseCallbackEvent()里的核实提醒——字段名未经Antom沙箱核实
     * @return array
     */
    private function parseAntomDisputeEvent(array $data)
    {
        $dispute = $data['dispute'] ?? $data;
        $paymentId = $dispute['paymentId'] ?? ($data['paymentId'] ?? null);

        $result = [
            'event_id' => $dispute['disputeId'] ?? ($paymentId ?: uniqid('antom_dispute_', true)),
            'external_ref' => $paymentId,
            'event_status' => 'dispute_updated',
            'amount_minor' => null,
            'currency' => null,
            'transaction_id' => $paymentId,
            'dispute_id' => $dispute['disputeId'] ?? null,
            'dispute_reason' => $dispute['disputeReason'] ?? null,
        ];

        $status = strtoupper((string) ($dispute['disputeStatus'] ?? $data['notifyType'] ?? ''));
        if (strpos($status, 'CREATE') !== false) {
            $result['event_status'] = 'dispute_created';
        } elseif (strpos($status, 'LOST') !== false || strpos($status, 'CLOSE_MERCHANT') !== false) {
            $result['event_status'] = 'dispute_lost';
        } elseif (strpos($status, 'WON') !== false) {
            $result['event_status'] = 'dispute_won';
        }

        if (isset($dispute['disputeAmount']['value'], $dispute['disputeAmount']['currency'])) {
            $result['amount_minor'] = (int) $dispute['disputeAmount']['value'];
            $result['currency'] = strtoupper($dispute['disputeAmount']['currency']);
        }

        return $result;
    }

    /**
     * 主动核实：inquiryPayment（和healthCheck()复用同一个接口，健康检测那里传的是
     * 探测用的假paymentRequestId，这里传这笔交易preparePayment()阶段真正生成的那个，
     * 见gateway_session里的payment_request_id——优先用它，因为它是我们自己生成、
     * 一定存在的id；paymentId是Antom分配的，preparePayment()响应里可能没有）。
     */
    public function queryOrderStatus(array $transaction)
    {
        $sessionData = TransactionManager::getGatewaySession($transaction);
        $paymentRequestId = $sessionData['payment_request_id'] ?? ($transaction['payment_intent'] ?? '');
        if ($paymentRequestId === '') {
            return ['status' => null, 'transaction_id' => null, 'amount_minor' => null, 'currency' => null];
        }

        try {
            $response = $this->signedRequest('POST', '/v1/payments/inquiryPayment', [
                'paymentRequestId' => $paymentRequestId,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Antom查询支付状态失败：' . $e->getMessage());
        }

        $resultStatus = $response['result']['resultStatus'] ?? '';
        $paymentStatus = $response['paymentStatus'] ?? '';

        $status = null;
        if ($paymentStatus === 'SUCCESS' || $resultStatus === 'S') {
            $status = TransactionStateMachine::PAID;
        } elseif ($paymentStatus === 'FAIL' || $resultStatus === 'F') {
            $status = TransactionStateMachine::FAILED;
        } elseif ($paymentStatus === 'CANCELLED') {
            $status = TransactionStateMachine::CANCELLED;
        } elseif ($paymentStatus === 'REFUNDED') {
            $status = TransactionStateMachine::REFUNDED;
        }

        return [
            'status' => $status,
            'transaction_id' => $response['paymentId'] ?? null,
            'amount_minor' => isset($response['paymentAmount']['value']) ? (int) $response['paymentAmount']['value'] : null,
            'currency' => isset($response['paymentAmount']['currency']) ? strtoupper($response['paymentAmount']['currency']) : null,
        ];
    }

    /**
     * Antom Pay API没有找到一个对应的"物流信息回传"接口，如实告知不支持，
     * 不假装同步成功（这个网关本身就是P1网关，见文件头注释）。
     */
    public function syncTrackingToRemote(array $transaction, array $trackingData)
    {
        return ['supported' => false, 'success' => false, 'message' => 'Antom不提供物流信息同步接口'];
    }

    public function healthCheck(array $config)
    {
        // Antom没有一个专门的"账户信息查询"公开接口适合这里直接复用，
        // 用一次极小额（或查询类）请求的可达性/鉴权是否通过作为基本检测，
        // 实际接入时建议换成Antom提供的商户状态查询接口（如果有）。
        try {
            $this->config = $config;
            $this->signedRequest('POST', '/v1/payments/inquiryPayment', [
                'paymentRequestId' => 'health-check-' . time(),
            ]);
            return [
                'status' => 'healthy',
                'message' => 'Antom签名认证通过（返回内容本身可能是业务错误，但说明鉴权链路正常）',
                'account_id' => $config['client_id'] ?? null,
                'account_name' => null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }

    // -------------------- RSA签名请求/验签工具 --------------------

    /**
     * @param string $method
     * @param string $path
     * @param array $body
     * @return array 解析后的响应体
     * @throws \RuntimeException
     */
    private function signedRequest($method, $path, array $body)
    {
        $clientId = $this->config['client_id'];
        $requestTime = gmdate('Y-m-d\TH:i:sP');
        $rawBody = wp_json_encode($body);

        $stringToSign = "{$method} {$path}\n{$clientId}.{$requestTime}.{$rawBody}\n";
        $signature = $this->signWithPrivateKey($stringToSign, $this->config['private_key']);

        $headers = [
            'Client-Id' => $clientId,
            'Request-Time' => $requestTime,
            'Signature' => 'algorithm=RSA256,keyVersion=1,signature=' . rawurlencode($signature),
        ];

        $result = HttpClient::postJson($this->baseUrl() . $path, $body, $headers, HttpClient::DEFAULT_TIMEOUT, $this->config['proxy'] ?? null);

        if (!$result['ok']) {
            if (in_array($result['status'], [401, 403], true)) {
                // 签名验证失败/client_id与商户号不匹配/商户未被授权调用该接口：
                // 账户/凭证配置问题，不是这一单本身的参数问题，重试同样的订单不会成功。
                throw new GatewayAccountForbiddenException('Antom请求失败（账户凭证问题）：HTTP ' . $result['status'] . ' ' . wp_json_encode($result['body']));
            }
            throw new \RuntimeException('Antom请求失败：HTTP ' . $result['status'] . ' ' . wp_json_encode($result['body']));
        }

        return is_array($result['body']) ? $result['body'] : [];
    }

    private function signWithPrivateKey($stringToSign, $privateKeyPem)
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new \RuntimeException('Antom私钥格式不正确，请检查config中的private_key是否是完整PEM');
        }

        $signature = '';
        $ok = openssl_sign($stringToSign, $signature, $key, OPENSSL_ALGO_SHA256);

        if (!$ok) {
            throw new \RuntimeException('Antom请求签名失败');
        }

        return base64_encode($signature);
    }

    private function verifyRsaSignature($stringToSign, $signatureBase64, $publicKeyPem)
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return false;
        }

        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            return false;
        }

        $result = openssl_verify($stringToSign, $signature, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * 从 "algorithm=RSA256,keyVersion=1,signature=xxx" 里取出signature值。
     */
    private function extractSignatureValue($signatureHeader)
    {
        foreach (explode(',', $signatureHeader) as $part) {
            if (strpos($part, 'signature=') === 0) {
                return rawurldecode(substr($part, strlen('signature=')));
            }
        }
        return null;
    }
}
