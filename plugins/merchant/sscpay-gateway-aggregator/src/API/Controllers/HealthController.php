<?php
/**
 * POST /v1/health —— 对应设计文档v2 第七节7.8 / F6。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\API\ApiResponse;
use PaymentGatewayAggregator\Core\GatewayConfigManager;
use PaymentGatewayAggregator\Core\GatewayFactory;
use PaymentGatewayAggregator\Core\HealthChecker;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class HealthController
{
    public static function handle(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return ApiResponse::error(10005, '请求体不是合法JSON');
        }

        $missing = Validator::requireFields($body, ['payment_method']);
        if (!empty($missing)) {
            return ApiResponse::error(10005, '缺少必填参数：' . implode(', ', $missing));
        }

        if (!GatewayFactory::isSupported($body['payment_method'])) {
            return ApiResponse::error(10005, '不支持的payment_method：' . $body['payment_method']);
        }

        if (!empty($body['gateway_config_id'])) {
            try {
                $resolved = GatewayConfigManager::resolve((int) $body['gateway_config_id']);
                $config = $resolved['config'];
            } catch (\RuntimeException $e) {
                return ApiResponse::error(10011, '网关配置引用不存在或已吊销');
            }
        } else {
            $config = $body['gateway_config'][$body['payment_method']] ?? null;
            if (!is_array($config)) {
                return ApiResponse::error(10005, '需要提供 gateway_config_id 或 gateway_config');
            }
        }

        $result = HealthChecker::check($body['payment_method'], $config);
        $result = array_merge([
            'payment_method' => $body['payment_method'],
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $result);

        return ApiResponse::success($result, '检测完成');
    }
}
