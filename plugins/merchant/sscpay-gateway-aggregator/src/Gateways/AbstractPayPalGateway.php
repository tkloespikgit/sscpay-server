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
