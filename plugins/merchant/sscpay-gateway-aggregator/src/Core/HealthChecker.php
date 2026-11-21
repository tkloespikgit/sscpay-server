<?php
/**
 * 健康检测调度（对应 F6 / /v1/health 接口），只负责"找到对应网关并调用"，
 * 具体怎么检测由各Gateway::healthCheck()实现。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class HealthChecker
{
    /**
     * @param string $paymentMethod
     * @param array $config 明文网关配置（由Controller解密/直传后传入）
     * @return array{status:string,message:string,account_id:?string,account_name:?string}
     */
    public static function check($paymentMethod, array $config)
    {
        try {
            $gateway = GatewayFactory::create($paymentMethod, $config);
        } catch (\RuntimeException $e) {
            return [
                'status' => 'unhealthy',
                'message' => '不支持的支付方式：' . $paymentMethod,
                'account_id' => null,
                'account_name' => null,
            ];
        }

        $missing = $gateway->validateConfig($config);
        if (!empty($missing)) {
            return [
                'status' => 'unhealthy',
                'message' => '配置不完整，缺少字段：' . implode(', ', $missing),
                'account_id' => null,
                'account_name' => null,
            ];
        }

        try {
            $result = $gateway->healthCheck($config);
            return array_merge([
                'status' => 'unhealthy',
                'message' => '',
                'account_id' => null,
                'account_name' => null,
            ], $result);
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => '检测过程发生异常：' . $e->getMessage(),
                'account_id' => null,
                'account_name' => null,
            ];
        }
    }
}
