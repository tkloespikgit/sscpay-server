<?php
/**
 * 网关账户级异常：密钥失效/权限不足/账户被限制导致完全无法发起支付会话，
 * 区别于普通的（参数错误、网络抖动、卡被拒等）下单失败。preparePayment()
 * 抛出这个类型时，PayController会把订单标记为FAILED并立刻通知S系统，
 * webhook payload里的account_forbiden会置为1，方便S系统据此触发商家侧告警
 * （而不是当成一次普通的支付失败）。
 */

namespace PaymentGatewayAggregator\Core\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

class GatewayAccountForbiddenException extends \RuntimeException
{
}
