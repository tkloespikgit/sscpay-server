<?php
/**
 * POST /v1/paypal-js/capture —— PayPal JS SDK按钮流程的服务端捕获桥接。
 * 公开端点（支付页面上的匿名访客调用），安全性依赖 s_order_id + pay_token
 * 这对凭证，而不是WordPress应用密码（见PaymentPage的token机制）。
 *
 * 再次强调（对应评审第7点）：这里调用PayPal捕获成功与否，只影响
 * 返回给前端的提示信息，不会直接把订单标记为paid——那必须等
 * WebhookRouter收到真正的PAYMENT.CAPTURE.COMPLETED事件才会发生。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\API\ApiResponse;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\Exceptions\PayPalCaptureException;
use PaymentGatewayAggregator\Core\PayPalPaymentRecovery;
use PaymentGatewayAggregator\Core\PaymentSessionReferences;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Gateways\PayPalRestClient;
use PaymentGatewayAggregator\Helpers\TokenGenerator;

if (!defined('ABSPATH')) {
    exit;
}

class PayPalJsController
{
    public static function capture(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return ApiResponse::error(10005, '请求体不是合法JSON');
        }

        $sOrderId = $body['s_order_id'] ?? '';
        $token = $body['token'] ?? '';
        $orderId = $body['order_id'] ?? '';
        if (!is_string($sOrderId) || !is_string($token) || !is_string($orderId) || $orderId === '') {
            return ApiResponse::error(10005, '参数格式错误');
        }

        $transaction = TransactionManager::findBySOrderId($sOrderId);

        // 不区分"订单不存在"和"token不对"，统一返回同一个错误，避免被用来枚举订单号。
        if (!$transaction || !TokenGenerator::isEqual($transaction['pay_token'], $token)) {
            return ApiResponse::error(10015, '链接无效或已过期', 403);
        }

        try {
            return PaymentSessionReferences::withOrderLock($sOrderId, function () use ($sOrderId, $token, $orderId) {
                $transaction = TransactionManager::findBySOrderId($sOrderId);
                if (!$transaction || !TokenGenerator::isEqual($transaction['pay_token'], $token)) {
                    return ApiResponse::error(10015, '链接无效或已过期', 403);
                }
                if ($transaction['payment_method'] !== 'paypal_js' || $transaction['status'] !== 'pending') {
                    return ApiResponse::error(10002, '订单状态异常', 409);
                }
                if (!empty(TransactionManager::getGatewaySession($transaction)['_creating'])) {
                    return ApiResponse::error(10002, '支付会话正在更新，请稍后重试', 409);
                }
                if ($transaction['payment_intent'] !== $orderId) {
                    LogManager::warning($sOrderId, 'PayPal capture请求并非当前Order，未执行capture', ['payment_method' => 'paypal_js']);
                    return ApiResponse::error(10005, '支付会话已更新，请重新打开付款链接', 409);
                }
                try {
                    $config = TransactionManager::decryptGatewayConfig($transaction);
                    $result = PayPalRestClient::captureOrder($config, $orderId);
                } catch (\Throwable $e) {
                    LogManager::error($sOrderId, 'PayPal capture调用失败：' . $e->getMessage(), ['payment_method' => 'paypal_js']);
                    if ($e instanceof PayPalCaptureException && $e->isInstrumentDeclined()) {
                        $latest = PayPalPaymentRecovery::remember($transaction, $e);
                        if ($latest['status'] !== 'pending' || $latest['payment_intent'] !== $orderId) {
                            return ApiResponse::success(['captured' => false]);
                        }
                        return ApiResponse::success([
                            'captured' => false, 'issue' => 'INSTRUMENT_DECLINED', 'restart' => true,
                            'redirect_url' => PayPalPaymentRecovery::redirectUrl($latest),
                        ], 'Please select a different payment method in PayPal.');
                    }
                    return ApiResponse::success(['captured' => false], '捕获请求已提交，最终结果以订单状态为准');
                }
                return ApiResponse::success(['captured' => true, 'status' => $result['status'] ?? null]);
            });
        } catch (\Throwable $e) {
            LogManager::error($sOrderId, 'PayPal capture暂时无法处理：' . $e->getMessage(), ['payment_method' => 'paypal_js']);
            return ApiResponse::error(10002, '支付会话正在处理中，请稍后重试', 503);
        }
    }
}
