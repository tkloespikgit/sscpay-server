<?php
/**
 * PayPal JS SDK 和 PayPal REST 两个网关共用的部分：都是Orders v2 API，
 * webhook事件结构、签名验证方式完全一样，只有"怎么让用户完成支付"
 * （前端按钮 vs 服务端跳转）不同——那部分留给子类各自实现
 * preparePayment() / renderPaymentPage()。
 */

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\PaymentBase;
use PaymentGatewayAggregator\Core\CurrencyManager;
use PaymentGatewayAggregator\Core\LineItemBuilder;
use PaymentGatewayAggregator\Core\TransactionStateMachine;
use PaymentGatewayAggregator\Core\Exceptions\PayPalCaptureException;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractPayPalGateway extends PaymentBase
{
    public function validateConfig(array $config)
    {
        return array_merge(
            Validator::requireFields($config, ['client_id', 'client_secret', 'webhook_id']),
            // 环境字段配错只会安静地跑到沙箱去，必须在注册时就拦住，见 requireMode()。
            self::requireMode($config)
        );
    }

    /**
     * 从交易记录构建传给PayPal创建订单的明细（商品行/账单号/运费/税费/收货地址）。
     * wp_payment_transactions里的shipping_address/billing_address是JSON字符串
     * （见TransactionManager::create()），这里统一解码成数组。
     *
     * 商品行不直接解码items，而是走 LineItemBuilder：由 trans_type 决定商品名位置
     * 写商品名（MATCH）、写SKU（CREATE），还是忽略items整单合成一条虚拟商品（VIRTUAL）。
     *
     * @param array $transaction findBySOrderId()等返回的交易记录
     * @return array 供PayPalRestClient::createOrder()的$details参数使用
     */
    protected function buildOrderDetails(array $transaction)
    {
        $shippingAddress = json_decode($transaction['shipping_address'] ?? '', true);

        return [
            'items' => LineItemBuilder::build($transaction),
            'invoice_number' => LineItemBuilder::invoiceNumber($transaction),
            'shipping_address' => is_array($shippingAddress) ? $shippingAddress : [],
            'shipping_fee' => $transaction['shipping_fee'] ?? 0,
            'tax_fee' => $transaction['tax_fee'] ?? 0,
            'discount_fee' => $transaction['discount_fee'] ?? 0,
            'customer_name' => $transaction['customer_name'] ?? '',
        ];
    }

    public function extractExternalRef($rawBody, array $headers)
    {
        $data = json_decode($rawBody, true);
        $resource = $data['resource'] ?? [];

        // Dispute资源（CUSTOMER.DISPUTE.*）没有resource.id/supplementary_data，
        // 交易标识在disputed_transactions[]里——用它反查我们自己表里的transaction_id
        // （TransactionManager::findByExternalRef()本来就是payment_intent/transaction_id两列一起查）。
        if (isset($resource['disputed_transactions'][0])) {
            $disputedTransaction = $resource['disputed_transactions'][0];
            return $disputedTransaction['seller_transaction_id']
                ?? $disputedTransaction['buyer_transaction_id']
                ?? null;
        }

        return $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['id']
            ?? null;
    }

    public function verifyCallbackSignature($rawBody, array $headers, array $config)
    {
        return PayPalRestClient::verifyWebhookSignature($config, $headers, $rawBody);
    }

    public function parseCallbackEvent($rawBody, array $headers)
    {
        $data = json_decode($rawBody, true) ?: [];
        $eventType = $data['event_type'] ?? '';
        $resource = $data['resource'] ?? [];

        $result = [
            'event_id' => $data['id'] ?? '',
            'external_ref' => $this->extractExternalRef($rawBody, $headers),
            'event_status' => 'ignored',
            'amount_minor' => null,
            'currency' => null,
            'transaction_id' => null,
        ];

        $amount = $resource['amount']['value']
            ?? ($resource['seller_receivable_breakdown']['gross_amount']['value']
                ?? ($resource['dispute_amount']['value'] ?? null));
        $currency = $resource['amount']['currency_code']
            ?? ($resource['seller_receivable_breakdown']['gross_amount']['currency_code']
                ?? ($resource['dispute_amount']['currency_code'] ?? null));

        switch ($eventType) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $result['event_status'] = 'paid';
                $result['transaction_id'] = $resource['id'] ?? null;
                if ($amount !== null && $currency !== null) {
                    $result['amount_minor'] = CurrencyManager::toMinorUnit($amount, $currency);
                    $result['currency'] = strtoupper($currency);
                }
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                $result['event_status'] = 'failed';
                $result['transaction_id'] = $resource['id'] ?? null;
                break;

            case 'PAYMENT.CAPTURE.REFUNDED':
                $result['event_status'] = 'refunded';
                $result['transaction_id'] = $resource['id'] ?? null;
                break;

            // 网关侧直接反转扣款（不一定伴随一次完整的Dispute生命周期），
            // 资源结构和PAYMENT.CAPTURE.REFUNDED一样，是最终确定的"钱没了"信号。
            case 'PAYMENT.CAPTURE.REVERSED':
                $result['event_status'] = 'capture_reversed';
                $result['transaction_id'] = $resource['id'] ?? null;
                $result['dispute_reason'] = $resource['reason'] ?? null;
                if ($amount !== null && $currency !== null) {
                    $result['amount_minor'] = CurrencyManager::toMinorUnit($amount, $currency);
                    $result['currency'] = strtoupper($currency);
                }
                break;

            // 买家发起争议（客诉），结果未定。
            case 'CUSTOMER.DISPUTE.CREATED':
                $result['event_status'] = 'dispute_created';
                $result = $this->fillPayPalDisputeInfo($result, $resource, $amount, $currency);
                break;

            // 争议中间状态变化（比如商家提交了证据、进入新阶段），结果仍未定。
            case 'CUSTOMER.DISPUTE.UPDATED':
                $result['event_status'] = 'dispute_updated';
                $result = $this->fillPayPalDisputeInfo($result, $resource, $amount, $currency);
                break;

            // 争议有最终结论了：dispute_outcome.outcome_code为RESOLVED_BUYER_FAVOUR
            // 表示买家胜诉（我方拒付败诉），其余结果（RESOLVED_SELLER_FAVOUR等）视为我方胜诉。
            case 'CUSTOMER.DISPUTE.RESOLVED':
                $outcomeCode = $resource['dispute_outcome']['outcome_code'] ?? '';
                $result['event_status'] = ($outcomeCode === 'RESOLVED_BUYER_FAVOUR') ? 'dispute_lost' : 'dispute_won';
                $result = $this->fillPayPalDisputeInfo($result, $resource, $amount, $currency);
                break;
        }

        return $result;
    }

    /**
     * 把Dispute资源里的争议单号/原因/涉及交易ID/金额统一填进事件结果，
     * 供WebhookRouter拼装订单日志message用。
     *
     * @param array $result
     * @param array $resource
     * @param string|null $amount
     * @param string|null $currency
     * @return array
     */
    private function fillPayPalDisputeInfo(array $result, array $resource, $amount, $currency)
    {
        $result['dispute_id'] = $resource['dispute_id'] ?? null;
        $result['dispute_reason'] = $resource['reason'] ?? null;

        if (empty($result['transaction_id']) && isset($resource['disputed_transactions'][0]['seller_transaction_id'])) {
            $result['transaction_id'] = $resource['disputed_transactions'][0]['seller_transaction_id'];
        }

        if ($amount !== null && $currency !== null) {
            $result['amount_minor'] = CurrencyManager::toMinorUnit($amount, $currency);
            $result['currency'] = strtoupper($currency);
        }

        return $result;
    }

    public function healthCheck(array $config)
    {
        try {
            PayPalRestClient::accountHealth($config);
            return [
                'status' => 'healthy',
                'message' => 'PayPal client credentials是有效的',
                'account_id' => $config['client_id'] ?? null,
                'account_name' => null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }

    /**
     * 主动同步：Add Tracking Information（payment_intent字段落库的是PayPal Order id，
     * transaction_id字段是Webhook确认支付时落库的capture id，见WebhookRouter/
     * AbstractPayPalGateway::parseCallbackEvent()）。paypal_js/paypal_rest 都是
     * Orders v2 API，放这里共用。
     *
     * is_other_carrier=Y时按PayPal"其它承运商"的约定传carrier=OTHER +
     * carrier_name_other=carrier_code；否则原样把carrier_code当作PayPal的承运商
     * 枚举值传（调用方需保证传的是PayPal认识的代码，比如FEDEX/UPS/USPS）。
     */
    public function syncTrackingToRemote(array $transaction, array $trackingData)
    {
        $orderId = $transaction['payment_intent'] ?? '';
        $captureId = $transaction['transaction_id'] ?? '';
        if ($orderId === '' || $captureId === '') {
            return ['supported' => true, 'success' => false, 'message' => '缺少PayPal订单号或capture id，暂时无法同步物流信息'];
        }

        $isOtherCarrier = ($trackingData['is_other_carrier'] ?? 'N') === 'Y';
        $carrierCode = trim((string) ($trackingData['carrier_code'] ?? ''));

        $payload = [
            'capture_id' => $captureId,
            'tracking_number' => (string) $trackingData['tracking_number'],
            'carrier' => $isOtherCarrier ? 'OTHER' : strtoupper($carrierCode),
            'notify_buyer' => true,
        ];
        if ($isOtherCarrier) {
            $payload['carrier_name_other'] = $carrierCode;
        }

        try {
            PayPalRestClient::addTracking($this->config, $orderId, $payload);
        } catch (\Throwable $e) {
            return ['supported' => true, 'success' => false, 'message' => $e->getMessage()];
        }

        return ['supported' => true, 'success' => true, 'message' => 'PayPal物流信息同步成功'];
    }

    private function refundParents(array $refund): array
    {
        $related = $refund['supplementary_data']['related_ids'] ?? [];
        $capture = $related['capture_id'] ?? null;
        $order = $related['order_id'] ?? null;
        foreach ($refund['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') !== 'up' || !is_string($link['href'] ?? null)) {
                continue;
            }
            $path = parse_url($link['href'], PHP_URL_PATH);
            if (is_string($path) && preg_match('~\A/v2/payments/captures/([A-Za-z0-9_-]+)\z~', $path, $match)) {
                if ($capture !== null && $capture !== $match[1]) {
                    throw new \RuntimeException('PayPal退款的原Capture编号冲突');
                }
                $capture = $match[1];
            } elseif (is_string($path) && preg_match('~\A/v2/checkout/orders/([A-Za-z0-9_-]+)\z~', $path, $match)) {
                if ($order !== null && $order !== $match[1]) {
                    throw new \RuntimeException('PayPal退款的原Order编号冲突');
                }
                $order = $match[1];
            }
        }
        return ['capture' => $capture, 'order' => $order];
    }

    private function isRefundedCapture(array $event): bool
    {
        $resource = $event['resource'] ?? [];
        foreach ($resource['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') !== 'self' || !is_string($link['href'] ?? null)) {
                continue;
            }
            $path = parse_url($link['href'], PHP_URL_PATH);
            if (is_string($path) && preg_match('~\A/v2/payments/(refunds|captures)/([A-Za-z0-9_-]+)\z~', $path, $match)) {
                if (($resource['id'] ?? null) !== $match[2]) {
                    throw new \RuntimeException('PayPal退款资源链接与编号冲突');
                }
                return $match[1] === 'captures';
            }
        }
        return ($event['resource_type'] ?? '') === 'capture' || in_array($resource['status'] ?? '', ['REFUNDED', 'PARTIALLY_REFUNDED'], true);
    }

    public function refundReferences(array $event): array
    {
        $resource = $event['resource'] ?? [];
        $parents = $this->refundParents($resource);
        if ($this->isRefundedCapture($event)) {
            return RefundEvidence::refs($resource['id'] ?? null, $parents['order']);
        }
        return RefundEvidence::refs($parents['capture'], $parents['order']);
    }

    private function fetchRefundResource(string $type, string $id): array
    {
        $result = \PaymentGatewayAggregator\Helpers\HttpClient::getJson(
            PayPalRestClient::baseUrl($this->config) . '/v2/payments/' . $type . '/' . rawurlencode($id),
            ['Authorization' => 'Bearer ' . PayPalRestClient::getAccessToken($this->config)],
            \PaymentGatewayAggregator\Helpers\HttpClient::DEFAULT_TIMEOUT,
            $this->config['proxy'] ?? null
        );
        if (!$result['ok'] || ($result['body']['id'] ?? '') !== $id) {
            throw new \RuntimeException('PayPal退款查询失败或返回了其他编号');
        }
        return $result['body'];
    }

    public function readRefund(array $event, ?array $transaction): array
    {
        $resource = $event['resource'] ?? [];
        $extra = ['event_id' => $event['id'] ?? null];
        if ($this->isRefundedCapture($event)) {
            $refs = $this->refundReferences($event);
            if ($transaction === null) {
                $parents = $this->refundParents($resource);
                $order = $parents['order'];
                $capture = RefundEvidence::refs($resource['id'] ?? null)[0] ?? null;
                if (!$order && $capture) {
                    $parents = $this->refundParents($this->fetchRefundResource('captures', $capture));
                    $order = $parents['order'];
                }
                if (!$order) {
                    return RefundEvidence::result('lookup', ['payment_refs' => $refs] + $extra);
                }
                $transaction = ['payment_intent' => $order, 'transaction_id' => $capture];
            }
            $result = $this->queryRefunds($transaction);
            if (!$result['refunds'] && $result['kind'] === 'ignored') {
                $result['kind'] = 'lookup';
            }
            $result['evidence'] += $extra;
            return $result;
        }
        $id = RefundEvidence::refs($resource['id'] ?? null)[0] ?? null;
        $parents = $this->refundParents($resource);
        if ($id && (!$parents['capture'] || empty($resource['amount']) || empty($resource['status']))) {
            $detail = $this->fetchRefundResource('refunds', $id);
            $fromApi = $this->refundParents($detail);
            foreach ($parents as $key => $value) {
                if ($value && $fromApi[$key] && $fromApi[$key] !== $value) {
                    throw new \RuntimeException('PayPal退款详情与通知的原付款不符');
                }
                $parents[$key] = $fromApi[$key] ?: $value;
            }
            $resource = $detail;
        }
        if ($transaction === null && $parents['capture'] && !$parents['order']) {
            $capture = $this->fetchRefundResource('captures', $parents['capture']);
            $parents['order'] = $this->refundParents($capture)['order'];
        }
        $refs = RefundEvidence::refs($parents['capture'], $parents['order']);
        $result = $this->paypalRefund($resource, $refs, $event['create_time'] ?? null, $extra);
        $expectedCapture = $transaction['transaction_id'] ?? null;
        if ($result['kind'] === 'succeeded' && (!$parents['capture'] || ($expectedCapture && $parents['capture'] !== $expectedCapture))) {
            return RefundEvidence::result('lookup', $result['evidence']);
        }
        $eventStates = ['PAYMENT.REFUND.FAILED' => 'failed', 'PAYMENT.REFUND.PENDING' => 'pending'];
        $required = $eventStates[$event['event_type'] ?? ''] ?? null;
        if ($required && $result['kind'] !== $required) {
            return RefundEvidence::result('lookup', $result['evidence']);
        }
        return $result;
    }

    public function queryRefunds(array $transaction): array
    {
        $orderId = RefundEvidence::refs($transaction['payment_intent'] ?? null)[0] ?? null;
        if (!$orderId) {
            return RefundEvidence::result('lookup');
        }
        $order = PayPalRestClient::getOrder($this->config, rawurlencode($orderId));
        if (($order['id'] ?? '') !== $orderId || !is_array($order['purchase_units'] ?? null)) {
            throw new \RuntimeException('PayPal的原订单或付款单元不匹配');
        }
        $observations = [];
        foreach ($order['purchase_units'] as $unit) {
            $captures = [];
            $refundedCaptures = [];
            foreach ($unit['payments']['captures'] ?? [] as $capture) {
                $id = $capture['id'] ?? null;
                if (is_string($id)) {
                    $captures[] = $id;
                    if (in_array($capture['status'] ?? '', ['REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                        $refundedCaptures[$id] = true;
                    }
                }
            }
            foreach ($unit['payments']['refunds'] ?? [] as $refund) {
                $id = RefundEvidence::refs($refund['id'] ?? null)[0] ?? null;
                $parents = $this->refundParents($refund);
                if ($id && (empty($refund['status']) || empty($refund['amount']) || !$parents['capture'])) {
                    $detail = $this->fetchRefundResource('refunds', $id);
                    $detailParents = $this->refundParents($detail);
                    if (($parents['capture'] && $detailParents['capture'] && $parents['capture'] !== $detailParents['capture'])
                        || ($parents['order'] && $parents['order'] !== $orderId)) {
                        throw new \RuntimeException('PayPal退款摘要的关联不一致');
                    }
                    $refund = $detail;
                    $parents = ['capture' => $detailParents['capture'] ?: $parents['capture'], 'order' => $detailParents['order'] ?: $parents['order']];
                }
                if (($parents['order'] && $parents['order'] !== $orderId)
                    || ($parents['capture'] && $captures && !in_array($parents['capture'], $captures, true))) {
                    throw new \RuntimeException('PayPal退款不属于所查订单或Capture');
                }
                unset($refundedCaptures[$parents['capture'] ?? '']);
                $observation = $this->paypalRefund($refund, RefundEvidence::refs($orderId, $parents['capture']), null);
                $expectedCapture = $transaction['transaction_id'] ?? null;
                if ($observation['kind'] === 'succeeded' && (!$parents['capture'] || ($expectedCapture && $expectedCapture !== $parents['capture']))) {
                    $observation = RefundEvidence::result('lookup', $observation['evidence']);
                }
                $observations[] = $observation;
            }
            foreach (array_keys($refundedCaptures) as $unresolved) {
                $observations[] = RefundEvidence::result('lookup', ['payment_refs' => [$orderId, $unresolved]]);
            }
        }
        return RefundEvidence::collection($observations, [$orderId]);
    }

    private function paypalRefund(array $refund, array $refs, $time, array $extra = []): array
    {
        $states = ['COMPLETED' => 'succeeded', 'PENDING' => 'pending', 'FAILED' => 'failed', 'CANCELLED' => 'failed'];
        $status = $refund['status'] ?? '';
        return RefundEvidence::observation($states[$status] ?? 'lookup', $refund['id'] ?? null, $refund['amount']['value'] ?? null,
            $refund['amount']['currency_code'] ?? null, $refs, $refund['update_time'] ?? $time, $status, $extra);
    }

    /**
     * 主动核实：GET Order（payment_intent字段落库的是PayPal Order id，
     * 见 PayPalJsGateway/PayPalRestGateway::preparePayment()里的external_ref）。
     * paypal_js/paypal_rest 都是Orders v2 API，查询逻辑完全一样，放这里共用。
     */
    public function queryOrderStatus(array $transaction)
    {
        $orderId = $transaction['payment_intent'] ?? '';
        if ($orderId === '') {
            return ['status' => null, 'transaction_id' => null, 'amount_minor' => null, 'currency' => null, 'session_state' => 'unknown'];
        }

        // 保留客户端异常类型，供会话管理器区分旧订单不存在与临时查询故障。
        $order = PayPalRestClient::getOrder($this->config, $orderId);

        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        $hasPayments = false;
        foreach ($order['purchase_units'] ?? [] as $unit) {
            if (!empty($unit['payments']['captures']) || !empty($unit['payments']['authorizations'])) {
                $hasPayments = true;
            }
        }

        $status = null;
        $sessionState = 'unknown';
        if ($capture) {
            switch ($capture['status'] ?? '') {
                case 'COMPLETED':
                    $status = TransactionStateMachine::PAID;
                    break;
                case 'DECLINED':
                    $status = TransactionStateMachine::FAILED;
                    break;
                case 'REFUNDED':
                    $status = TransactionStateMachine::REFUNDED;
                    break;
                case 'PENDING':
                    $sessionState = 'processing';
                    break;
            }
        } elseif (!$hasPayments && ($order['status'] ?? '') === 'VOIDED') {
            $sessionState = 'closed';
        } elseif (!$hasPayments && ($order['status'] ?? '') === 'CREATED') {
            $sessionState = 'reusable';
        } elseif (!$hasPayments && in_array($order['status'] ?? '', ['APPROVED', 'PAYER_ACTION_REQUIRED'], true)) {
            // No money has been captured. Resume buyer approval on this same Order;
            // never rotate an approved Order merely because the local session TTL elapsed.
            $sessionState = 'requires_approval';
        }

        // 顶层COMPLETED仅说明订单intent已完成；必须由Capture COMPLETED确认实际收款。

        $amount = $capture['amount']['value'] ?? null;
        $currency = $capture['amount']['currency_code'] ?? null;

        $approvalUrl = null;
        foreach ($order['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $approvalUrl = PayPalCaptureException::validatedRedirectUrl($link['href'] ?? null);
                if ($approvalUrl !== null) { break; }
            }
        }

        return [
            'status' => $status,
            'session_state' => $sessionState,
            'approval_url' => $approvalUrl,
            'transaction_id' => $capture['id'] ?? null,
            'amount_minor' => ($amount !== null && $currency !== null)
                ? CurrencyManager::toMinorUnit($amount, $currency) : null,
            'currency' => $currency !== null ? strtoupper($currency) : null,
        ];
    }
}
