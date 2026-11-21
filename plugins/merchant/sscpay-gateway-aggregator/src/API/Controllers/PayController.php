<?php
/**
 * POST /v1/pay —— 对应设计文档v2 第七节7.5。
 *
 * 编排职责：参数校验 -> 幂等检查 -> 解析网关配置(引用或直传) ->
 * OrderManager创建WP订单 -> TransactionManager落库交易记录 ->
 * Gateway::preparePayment() 发起三方支付会话 -> 记录external_ref -> 返回pay_url。
 *
 * 网关无关的部分（订单创建、交易落库）不在这里重复实现，全部委托给
 * OrderManager / TransactionManager，这里只做编排。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\API\ApiResponse;
use PaymentGatewayAggregator\Core\ConfigEncryptor;
use PaymentGatewayAggregator\Core\CurrencyManager;
use PaymentGatewayAggregator\Core\GatewayConfigManager;
use PaymentGatewayAggregator\Core\GatewayFactory;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\OrderManager;
use PaymentGatewayAggregator\Core\PaymentSessionManager;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Helpers\Validator;
use PaymentGatewayAggregator\Models\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

class PayController
{
    public static function handle(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return ApiResponse::error(10005, '请求体不是合法JSON');
        }

        $validationError = self::validate($body);
        if ($validationError) {
            return ApiResponse::error(10005, $validationError);
        }

        // trans_type / invoice_number 归一化一次，后面幂等比对和落库都用归一化后的值，
        // 不让"create"和"CREATE"在库里变成两种写法。
        $transType = strtoupper(trim((string) $body['trans_type']));
        $invoiceNumber = trim((string) $body['invoice_number']);

        // ---- 幂等检查 ----
        $existing = TransactionManager::findBySOrderId($body['s_order_id']);
        if ($existing) {
            // trans_type/invoice_number 也参与比对：同一个 s_order_id 换了交易类型或
            // 账单号就是另一笔业务请求，不能拿旧订单的会话当幂等结果返回。
            $sameRequest = (float) $existing['amount'] === (float) $body['amount']
                && $existing['currency'] === CurrencyManager::normalize($body['currency'])
                && $existing['payment_method'] === $body['payment_method']
                && Transaction::normalizeTransType($existing['trans_type'] ?? '') === $transType
                && (string) ($existing['invoice_number'] ?? '') === $invoiceNumber;

            if ($sameRequest) {
                try {
                    $context = PaymentSessionManager::ensure($existing, false);
                    $existing = $context['transaction'];
                } catch (\Throwable $e) {
                    LogManager::error($body['s_order_id'], '重试预创建平台会话失败：' . $e->getMessage(), [
                        'payment_method' => $body['payment_method'],
                    ]);
                    return ApiResponse::error(10008, '支付网关返回错误：' . $e->getMessage(), 502);
                }
                LogManager::info($body['s_order_id'], '/v1/pay 幂等命中，返回既有订单', ['request_data' => $body]);
                return ApiResponse::success(self::buildPayResponseData($existing));
            }

            return ApiResponse::error(10005, 's_order_id已存在，但请求参数与原订单不一致');
        }

        // ---- 解析网关配置：gateway_config_id 引用 或 gateway_config 直传，二选一 ----
        try {
            $configResolution = self::resolveGatewayConfig($body);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'GATEWAY_CONFIG_NOT_FOUND') {
                return ApiResponse::error(10011, '网关配置引用不存在或已吊销');
            }
            return ApiResponse::error(10005, $e->getMessage());
        }

        $config = $configResolution['config'];
        $gatewayConfigId = $configResolution['gateway_config_id'];

        $probeGateway = GatewayFactory::create($body['payment_method']);
        $missingConfigFields = $probeGateway->validateConfig($config);
        if (!empty($missingConfigFields)) {
            return ApiResponse::error(10006, '支付配置不完整，缺少字段：' . implode(', ', $missingConfigFields));
        }

        // ---- 创建WordPress订单（网关无关）----
        try {
            $wpOrderId = OrderManager::createOrder($body);
        } catch (\RuntimeException $e) {
            LogManager::error($body['s_order_id'], '创建WordPress订单失败：' . $e->getMessage(), ['request_data' => $body]);
            return ApiResponse::error(10007, '创建订单失败：' . $e->getMessage(), 500);
        }

        // ---- 落库交易记录（网关无关，含加密配置快照）----
        $transaction = TransactionManager::create([
            's_order_id' => $body['s_order_id'],
            'wp_order_id' => $wpOrderId,
            'payment_method' => $body['payment_method'],
            'trans_type' => $transType,
            'invoice_number' => $invoiceNumber,
            'subject' => trim((string) $body['subject']),
            'currency' => CurrencyManager::normalize($body['currency']),
            'amount' => $body['amount'],
            'subtotal' => $body['subtotal'],
            'customer_name' => $body['customer']['name'],
            'customer_email' => $body['customer']['email'],
            'customer_phone' => $body['customer']['phone'] ?? null,
            'billing_address' => $body['billing_address'],
            'shipping_address' => $body['shipping_address'],
            'items' => $body['items'],
            'shipping_fee' => $body['shipping_fee'] ?? 0,
            'tax_fee' => $body['tax_fee'] ?? 0,
            'discount_fee' => $body['discount_fee'] ?? 0,
            'gateway_config_encrypted' => ConfigEncryptor::encrypt($config),
            'gateway_config_id' => $gatewayConfigId,
            'callback_url' => $body['callback_url'],
            'return_url' => $body['return_url'],
            'cancel_url' => $body['cancel_url'],
            'allow_returned_source' => $body['allow_returned_source'] ?? 'Y',
        ]);

        // /v1/pay 仍预创建平台会话；幂等命中不轮换，付款页才核实付款并判断期限。
        try {
            $context = PaymentSessionManager::ensure($transaction, false);
            $transaction = $context['transaction'];
        } catch (\Throwable $e) {
            LogManager::error($body['s_order_id'], '预创建平台会话失败：' . $e->getMessage(), [
                'payment_method' => $body['payment_method'],
            ]);
            return ApiResponse::error(10008, '支付网关返回错误：' . $e->getMessage(), 502);
        }

        LogManager::info($body['s_order_id'], '/v1/pay 创建成功', [
            'payment_method' => $body['payment_method'],
            'wp_order_id' => $wpOrderId,
            'request_data' => $body,
        ]);

        $transaction = TransactionManager::findBySOrderId($body['s_order_id']);

        return ApiResponse::success(self::buildPayResponseData($transaction), '支付请求已创建');
    }

    private static function validate(array $body)
    {
        $required = [
            's_order_id', 'amount', 'currency', 'payment_method',
            'trans_type', 'invoice_number', 'subject', 'subtotal',
            'callback_url', 'return_url', 'cancel_url',
            'customer.name', 'customer.email',
            'billing_address.country', 'shipping_address.country',
        ];

        $missing = Validator::requireFields($body, $required);
        if (!empty($missing)) {
            return '缺少必填参数：' . implode(', ', $missing);
        }

        if (!Validator::isSafeIdentifier($body['s_order_id'])) {
            return 's_order_id格式不合法（只允许字母数字下划线中划线，长度不超过64）';
        }

        if (!Validator::isOneOf(strtoupper(trim((string) $body['trans_type'])), Transaction::transTypes())) {
            return 'trans_type必须是 ' . implode(' / ', Transaction::transTypes()) . ' 之一';
        }

        if (!Validator::isInvoiceNumber($body['invoice_number'])) {
            return 'invoice_number格式不合法（只允许字母数字和 _ - . / # ，长度不超过127）';
        }

        // subject 三种模式都必传：MATCH/CREATE 下虽然不参与通道组装，但作为订单主题
        // 落库备查，也保证S系统不会因为"这个模式用不到"就漏传，日后切模式时才发现没值。
        if (!Validator::isSubject($body['subject'])) {
            return 'subject格式不合法（不能为空，长度不超过255，且不能包含控制字符）';
        }

        // subtotal：VIRTUAL 模式下作为唯一那条虚拟商品的单价（见 LineItemBuilder），
        // 其余模式落库备查。允许为0（全额折扣/纯运费订单），不允许负数。
        if (!Validator::isNonNegativeDecimal($body['subtotal'])) {
            return 'subtotal必须是大于等于0的数字';
        }

        if (!Validator::isPositiveDecimal($body['amount'])) {
            return 'amount必须是大于0的数字';
        }

        // discount_fee 可选：不传视为无折扣；传了必须是>=0的数字。
        if (isset($body['discount_fee']) && !Validator::isNonNegativeDecimal($body['discount_fee'])) {
            return 'discount_fee必须是大于等于0的数字';
        }

        // allow_returned_source 可选：不传按升级前的行为默认Y（结果页展示"Return to
        // Store"按钮）；传了只能是Y/N，控制支付结果页是否展示该按钮（见PaymentPage）。
        if (isset($body['allow_returned_source']) && !Validator::isOneOf($body['allow_returned_source'], ['Y', 'N'])) {
            return 'allow_returned_source必须是 Y 或 N';
        }

        if (!CurrencyManager::isValid($body['currency'])) {
            return 'currency格式不合法（需要3位大写字母，如USD）';
        }

        if (!GatewayFactory::isSupported($body['payment_method'])) {
            return '不支持的payment_method：' . $body['payment_method'];
        }

        if (!Validator::isValidUrl($body['callback_url']) || !Validator::isValidUrl($body['return_url']) || !Validator::isValidUrl($body['cancel_url'])) {
            return 'callback_url/return_url/cancel_url必须是合法的URL';
        }

        if (!Validator::isValidEmail($body['customer']['email'])) {
            return 'customer.email格式不合法';
        }

        $customerIp = $body['customer_ip'] ?? null;
        if ($customerIp !== null && (!is_string($customerIp) || filter_var($customerIp, FILTER_VALIDATE_IP) === false)) {
            return 'customer_ip必须是合法IPv4或IPv6地址';
        }

        if (empty($body['items']) || !is_array($body['items'])) {
            return 'items不能为空';
        }

        $hasConfigId = !empty($body['gateway_config_id']);
        $hasInlineConfig = !empty($body['gateway_config']);
        if ($hasConfigId === $hasInlineConfig) {
            // 同时为true或同时为false都不允许
            return 'gateway_config_id 和 gateway_config 必须二选一提供';
        }

        return null;
    }

    /**
     * @return array{config:array,gateway_config_id:?int}
     * @throws \RuntimeException
     */
    private static function resolveGatewayConfig(array $body)
    {
        if (!empty($body['gateway_config_id'])) {
            $resolved = GatewayConfigManager::resolve((int) $body['gateway_config_id']);
            return ['config' => $resolved['config'], 'gateway_config_id' => (int) $body['gateway_config_id']];
        }

        $paymentMethod = $body['payment_method'];
        $inline = $body['gateway_config'][$paymentMethod] ?? null;

        if (!is_array($inline)) {
            throw new \RuntimeException(sprintf('gateway_config中缺少%s对应的配置', $paymentMethod));
        }

        return ['config' => $inline, 'gateway_config_id' => null];
    }

    private static function buildPayResponseData(array $transaction)
    {
        return [
            's_order_id' => $transaction['s_order_id'],
            'wp_order_id' => (int) $transaction['wp_order_id'],
            'payment_method' => $transaction['payment_method'],
            'currency' => $transaction['currency'],
            'amount' => $transaction['amount'],
            'status' => $transaction['status'],
            'pay_url' => add_query_arg(
                'token',
                rawurlencode($transaction['pay_token']),
                home_url('/payment/' . rawurlencode($transaction['s_order_id']))
            ),
            'expires_at' => TransactionManager::formatExpiresAt($transaction['expires_at'] ?? null),
            'gateway_session_expires_at' => TransactionManager::formatExpiresAt($transaction['gateway_session_expires_at'] ?? null),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($transaction['created_at'] . ' UTC')),
        ];
    }
}
