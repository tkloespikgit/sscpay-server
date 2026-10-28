<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 当前支付渠道无法准备订单商品，可由下单服务尝试组内其他渠道。
 * 仅用于明确的匹配、站点配置及远端建品失败，不包装数据库或程序异常。
 */
class OrderItemPreparationException extends RuntimeException {}
