<?php
/**
 * POST /v1/sync-tracking —— 对应设计文档v2 第七节7.7 / F5。
 *
 * 请求体：{ s_order_id, tracking_number, carrier_code, is_other_carrier(Y/N),
 *          tracking_url(可选), shipped_at(可选), need_sync_to_remote(Y/N) }
 *
 * 处理顺序：
 * 1. 先落库（wp_payment_transactions）并写进WordPress订单（meta+订单备注），
 *    这一步始终执行，不受need_sync_to_remote影响——S系统这边的物流记录是
 *    第一位的事实来源，不能因为三方同步失败就连本地都没存上。
 * 2. need_sync_to_remote=Y：再调用对应支付渠道的物流同步API（目前只有PayPal
 *    的Orders v2 Add Tracking Information接口是真正实现的，其余网关会如实
 *    返回"不支持"，见各Gateway::syncTrackingToRemote()），并把三方同步的
 *    成功与否原样透传给调用方。
 *    need_sync_to_remote=N：不发起任何三方调用，直接视为同步成功——本地落库
 *    已经完成，S系统这次调用的诉求已经达到。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\API\ApiResponse;
use PaymentGatewayAggregator\Core\GatewayFactory;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\OrderManager;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class SyncTrackingController
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

        $transaction = TransactionManager::findBySOrderId($body['s_order_id']);
        if (!$transaction) {
            return ApiResponse::error(10001, '订单不存在');
        }

        if ($transaction['status'] !== 'paid') {
            return ApiResponse::error(10002, '订单状态异常，只有已支付订单才能同步物流信息，当前状态：' . $transaction['status']);
        }

        if (TransactionManager::trackingNumberUsedByOthers($body['tracking_number'], $body['s_order_id'])) {
            return ApiResponse::error(10003, '物流单号已被其它订单使用');
        }

        // ---- 本地落库：始终执行，不受need_sync_to_remote影响 ----
        TransactionManager::updateTracking($transaction, $body);

        try {
            OrderManager::applyTracking($transaction['wp_order_id'], $body);
        } catch (\RuntimeException $e) {
            return ApiResponse::error(10001, 'WordPress订单不存在：' . $e->getMessage());
        }

        LogManager::info($body['s_order_id'], '物流信息已同步：' . $body['tracking_number'], [
            'payment_method' => $transaction['payment_method'],
            'wp_order_id' => $transaction['wp_order_id'],
            'request_data' => $body,
        ]);

        // ---- 按need_sync_to_remote决定是否调用渠道方的物流同步API ----
        $needSyncToRemote = $body['need_sync_to_remote'] === 'Y';
        $remoteResult = $needSyncToRemote
            ? self::syncToRemoteGateway($transaction, $body)
            : ['supported' => null, 'success' => true, 'message' => '未要求同步至渠道方（need_sync_to_remote=N）'];

        return ApiResponse::success([
            's_order_id' => $body['s_order_id'],
            'wp_order_id' => (int) $transaction['wp_order_id'],
            'tracking_number' => $body['tracking_number'],
            'synced_to_remote' => $remoteResult['success'],
            'remote_sync_message' => $remoteResult['message'],
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], '物流单号同步成功');
    }

    /**
     * 解密网关配置、创建网关实例、调用该网关的syncTrackingToRemote()。
     * 网关本身不支持/调用失败都不影响本地已经落库成功的结果——只是把
     * "三方那边有没有同步成功"如实反映在响应里，供S系统自己决定要不要重试。
     *
     * @param array $transaction
     * @param array $trackingData
     * @return array{supported:bool,success:bool,message:string}
     */
    private static function syncToRemoteGateway(array $transaction, array $trackingData)
    {
        try {
            $config = TransactionManager::decryptGatewayConfig($transaction);
            $gateway = GatewayFactory::create($transaction['payment_method'], $config);
            $result = $gateway->syncTrackingToRemote($transaction, $trackingData);
        } catch (\Throwable $e) {
            LogManager::warning($transaction['s_order_id'], '物流信息同步至渠道方失败：' . $e->getMessage(), [
                'payment_method' => $transaction['payment_method'],
            ]);
            return ['supported' => true, 'success' => false, 'message' => $e->getMessage()];
        }

        $logMethod = $result['success'] ? [LogManager::class, 'info'] : [LogManager::class, 'warning'];
        call_user_func($logMethod, $transaction['s_order_id'], '物流信息同步至渠道方：' . $result['message'], [
            'payment_method' => $transaction['payment_method'],
            'response_data' => $result,
        ]);

        return $result;
    }

    private static function validate(array $body)
    {
        $required = [
            's_order_id', 'tracking_number', 'carrier_code', 'is_other_carrier', 'need_sync_to_remote',
        ];

        $missing = Validator::requireFields($body, $required);
        if (!empty($missing)) {
            return '缺少必填参数：' . implode(', ', $missing);
        }

        if (!Validator::isOneOf($body['is_other_carrier'], ['Y', 'N'])) {
            return 'is_other_carrier必须是 Y 或 N';
        }

        if (!Validator::isOneOf($body['need_sync_to_remote'], ['Y', 'N'])) {
            return 'need_sync_to_remote必须是 Y 或 N';
        }

        if (isset($body['tracking_url']) && $body['tracking_url'] !== '' && !Validator::isValidUrl($body['tracking_url'])) {
            return 'tracking_url必须是合法的URL';
        }

        return null;
    }
}
