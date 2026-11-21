<?php
/**
 * POST /v1/gateway-config —— 对应设计文档v2 第七节7.4。
 * 需要用"配置账号"（Roles::CAP_MANAGE_GATEWAY_CONFIG）的应用密码调用，
 * 和 /v1/pay 使用的"订单账号"是分开的，见 API/Routes/api.php。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\API\ApiResponse;
use PaymentGatewayAggregator\Core\GatewayConfigManager;
use PaymentGatewayAggregator\Core\GatewayFactory;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class GatewayConfigController
{
    public static function register(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return ApiResponse::error(10005, '请求体不是合法JSON');
        }

        $missing = Validator::requireFields($body, ['config_key', 'payment_method', 'config']);
        if (!empty($missing)) {
            return ApiResponse::error(10005, '缺少必填参数：' . implode(', ', $missing));
        }

        if (!Validator::isSafeIdentifier($body['config_key'])) {
            return ApiResponse::error(10005, 'config_key格式不合法');
        }

        if (!GatewayFactory::isSupported($body['payment_method'])) {
            return ApiResponse::error(10005, '不支持的payment_method：' . $body['payment_method']);
        }

        if (!is_array($body['config']) || empty($body['config'])) {
            return ApiResponse::error(10005, 'config不能为空');
        }

        $gateway = GatewayFactory::create($body['payment_method']);
        $missingConfigFields = $gateway->validateConfig($body['config']);
        if (!empty($missingConfigFields)) {
            return ApiResponse::error(10006, '支付配置不完整，缺少字段：' . implode(', ', $missingConfigFields));
        }

        $row = GatewayConfigManager::register($body['config_key'], $body['payment_method'], $body['config']);

        LogManager::info(LogManager::SYSTEM_ORDER_ID, sprintf('网关配置已注册/更新：%s (%s)', $body['config_key'], $body['payment_method']), [
            'payment_method' => $body['payment_method'],
        ]);

        return ApiResponse::success([
            'config_id' => (int) $row['id'],
            'config_key' => $row['config_key'],
            'payment_method' => $row['payment_method'],
            'config_fingerprint' => $row['config_fingerprint'],
            'created_at' => $row['created_at'],
        ], '配置已保存');
    }
}
