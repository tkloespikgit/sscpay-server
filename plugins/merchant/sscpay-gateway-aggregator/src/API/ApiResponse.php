<?php
/**
 * 统一响应格式封装（对应设计文档v2 第七节7.2）。
 */

namespace PaymentGatewayAggregator\API;

if (!defined('ABSPATH')) {
    exit;
}

class ApiResponse
{
    public static function success($data = null, $message = 'success', $httpStatus = 200)
    {
        return new \WP_REST_Response([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ], $httpStatus);
    }

    public static function error($code, $message, $httpStatus = 400)
    {
        return new \WP_REST_Response([
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }
}
