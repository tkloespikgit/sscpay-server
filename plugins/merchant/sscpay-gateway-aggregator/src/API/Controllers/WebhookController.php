<?php
/**
 * POST /v1/webhook/{gateway} —— 统一Webhook入口，对应设计文档v2 第七节7.6。
 * 不做应用密码认证（见AuthMiddleware::publicWebhook()），安全性完全依赖
 * WebhookRouter里针对每个网关做的签名验证。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\Core\WebhookRouter;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookController
{
    public static function handle(\WP_REST_Request $request)
    {
        $paymentMethod = $request->get_param('gateway');
        // 必须用get_body()拿原始字节，不能用get_json_params()——
        // 那样会丢失原始格式，导致签名验证时字符串对不上。
        $rawBody = $request->get_body();

        $headers = self::normalizeHeaders($request);
        // Antom等网关的验签需要HTTP method + path参与签名计算，通过约定的
        // x-pga-*伪头传给Gateway::verifyCallbackSignature()，不影响其它网关
        // （它们直接忽略这两个键）。
        $headers['x-pga-http-method'] = 'POST';
        $headers['x-pga-http-path'] = '/ams/sandbox/api/v1/payments/pay'; // 占位，见下方说明

        // 说明：Antom通知(notify)签名用的path，理论上应该是商户在Antom后台配置的
        // notify接收路径，而不是Pay API的请求路径——Antom文档对"通知请求的
        // string-to-sign具体用哪个path"这一点在可核实到的资料里不够明确，
        // 这里先按一个占位实现，接入前请对照实际收到的通知请求和Antom提供的
        // 验签工具/SDK核实一遍这个path该怎么取，不要直接信任这个占位值。
        $result = WebhookRouter::handle($paymentMethod, $rawBody, $headers);

        return new \WP_REST_Response([
            'code' => $result['code'],
            'message' => $result['message'],
            'data' => null,
        ], $result['http_status']);
    }

    private static function normalizeHeaders(\WP_REST_Request $request)
    {
        $headers = [];
        foreach ($request->get_headers() as $key => $values) {
            // WP_REST_Request内部按 canonicalize_header_name() 的规则把header名称的'-'
            // 换成了'_'存储（比如 PayPal-Transmission-Id 存成 paypal_transmission_id），
            // 而所有Gateway::verifyCallbackSignature()里都是按中划线格式查
            // （stripe-signature / paypal-transmission-id / x-signature 等）——
            // 这里必须转回中划线，否则查出来永远是空字符串，验签必然失败。
            $normalizedKey = str_replace('_', '-', strtolower($key));
            $headers[$normalizedKey] = is_array($values) ? implode(',', $values) : $values;
        }
        return $headers;
    }
}
