<?php
/**
 * PayPal Orders GET 明确返回订单不存在；本身不证明未收款或允许重建。
 * 会话管理器还须核对本地期限、付款记录和旧会话，其他查单调用仍按异常处理。
 */
namespace PaymentGatewayAggregator\Core\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

class PayPalOrderNotFoundException extends \RuntimeException
{
}
