<?php
/**
 * POST /v1/order-query —— 按S系统订单号查询该订单当前的最新状态/支付结果/物流信息。
 *
 * 每次调用都会先经 OrderStatusReconciler 主动向对应的三方支付渠道核实一次真实状态
 * （而不是无条件信任本地缓存）：核实结果与本地一致或渠道方仍在处理中时原样使用本地
 * 记录；不一致时会同步落库并触发和Webhook一样的副作用（标记WP订单、回调S系统），
 * 这样即使某次Webhook没送达，S系统主动查一次单也能拿到修正后的真实状态。
 * 渠道方查询本身失败（网络/鉴权异常）时降级返回本地缓存状态，不影响本接口可用性。
 *
 * 出于最小暴露原则，不返回 gateway_config / gateway_config_id / gateway_session /
 * pay_token 原文、访客风控字段——这些是插件内部实现细节，S系统不需要也不应该拿到。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\API\ApiResponse;
use PaymentGatewayAggregator\Core\OrderStatusReconciler;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class OrderQueryController
{
    public static function handle(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return ApiResponse::error(10005, '请求体不是合法JSON');
        }

        $missing = Validator::requireFields($body, ['s_order_id']);
        if (!empty($missing)) {
            return ApiResponse::error(10005, '缺少必填参数：' . implode(', ', $missing));
        }

        if (!Validator::isSafeIdentifier($body['s_order_id'])) {
            return ApiResponse::error(10005, 's_order_id格式不合法（只允许字母数字下划线中划线，长度不超过64）');
        }

        $transaction = TransactionManager::findBySOrderId($body['s_order_id']);
        if (!$transaction) {
            return ApiResponse::error(10001, '订单不存在');
        }

        $transaction = OrderStatusReconciler::reconcile($transaction);

        return ApiResponse::success(self::buildQueryResponseData($transaction), '查询成功');
    }

    private static function buildQueryResponseData(array $transaction)
    {
        return [
            's_order_id' => $transaction['s_order_id'],
            'wp_order_id' => (int) $transaction['wp_order_id'],
            'payment_method' => $transaction['payment_method'],
            'status' => $transaction['status'],
            'transaction_id' => $transaction['transaction_id'] ?? null,
            'currency' => $transaction['currency'],
            'amount' => $transaction['amount'],
            // 待支付订单可以把这个链接原样再甩给买家；已支付/终态订单打开仍是原来的
            // 支付页面（会提示当前状态），不单独判断状态再决定是否返回。
            'pay_url' => add_query_arg(
                'token',
                rawurlencode($transaction['pay_token']),
                home_url('/payment/' . rawurlencode($transaction['s_order_id']))
            ),
            'tracking' => [
                'tracking_number' => $transaction['tracking_number'] ?? null,
                'carrier_code' => $transaction['carrier_code'] ?? null,
                'is_other_carrier' => $transaction['is_other_carrier'] ?? null,
                // shipping_company 是改造前的历史字段名，继续保留兼容已经在读它的S系统
                // 老逻辑（值和carrier_code同步写入，见TransactionManager::updateTracking()）。
                'shipping_company' => $transaction['shipping_company'] ?? null,
                'tracking_url' => $transaction['tracking_url'] ?? null,
                // 字段名沿用改造前的 ship_date（/v1/sync-tracking 输入参数已经改名叫
                // shipped_at，但这里是已有S系统在读的老字段名，不随之改名，避免破坏兼容性）。
                'ship_date' => self::formatUtc($transaction['ship_date'] ?? null),
            ],
            'expires_at' => TransactionManager::formatExpiresAt($transaction['expires_at'] ?? null),
            'gateway_session_expires_at' => self::formatUtc($transaction['gateway_session_expires_at'] ?? null),
            'paid_at' => self::formatUtc($transaction['paid_at'] ?? null),
            'created_at' => self::formatUtc($transaction['created_at'] ?? null),
            'updated_at' => self::formatUtc($transaction['updated_at'] ?? null),
        ];
    }

    /** 库里存的是 'Y-m-d H:i:s' 格式的UTC时间；空值/NULL原样返回null，不强行格式化出一个假时间。 */
    private static function formatUtc($value)
    {
        if (empty($value)) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($value . ' UTC'));
    }
}
