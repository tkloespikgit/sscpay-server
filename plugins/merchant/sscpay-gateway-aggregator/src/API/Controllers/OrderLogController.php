<?php
/**
 * POST /v1/order-logs —— 按S系统订单号查询该订单的全部日志记录（对应F7日志检索）。
 *
 * 只读接口：从 wp_payment_logs 表按 s_order_id 拉取日志，供S系统排查一笔订单的
 * 完整支付链路（下单、发起支付、Webhook回调、取消/重试等）。日志在落库前已经过
 * SensitiveDataFilter 脱敏，这里直接返回，不需要在查询层再过滤一次。
 *
 * created_at 为UTC时间（LogManager用 current_time('mysql', true) 落库），
 * 格式 'Y-m-d H:i:s'，与后台LogViewer展示的一致。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\API\ApiResponse;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class OrderLogController
{
    /** API单次查询返回的日志上限；后台LogViewer单独分页，不受该上限影响。 */
    const MAX_LIMIT = 500;

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

        $sOrderId = $body['s_order_id'];
        $rows = LogManager::findBySOrderId($sOrderId, self::MAX_LIMIT);

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = self::formatRow($row);
        }

        // 查不到日志不算错误：可能是订单号写错，也可能是订单刚创建还没产生日志，
        // 统一返回空列表 + total=0，由调用方自行判断。
        return ApiResponse::success([
            's_order_id' => $sOrderId,
            'total' => count($logs),
            'logs' => $logs,
        ], '查询完成');
    }

    /**
     * 把 wp_payment_logs 的一行整理成对外的JSON结构。
     *
     * request_data/response_data/callback_data 落库时是JSON字符串，这里解码成
     * 嵌套对象方便调用方直接使用；解码失败（历史脏数据）则原样返回字符串，
     * 不因为个别脏行让整个查询失败。
     */
    private static function formatRow(array $row)
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'level' => $row['level'] ?? null,
            'message' => $row['message'] ?? null,
            'payment_method' => $row['payment_method'] ?? null,
            'wp_order_id' => isset($row['wp_order_id']) && $row['wp_order_id'] !== null
                ? (int) $row['wp_order_id'] : null,
            'request_data' => self::decodeJson($row['request_data'] ?? null),
            'response_data' => self::decodeJson($row['response_data'] ?? null),
            'callback_data' => self::decodeJson($row['callback_data'] ?? null),
            'ip' => $row['ip'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /** 空值返回null；能解析的JSON返回数组；解析不了的原样返回字符串。 */
    private static function decodeJson($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
