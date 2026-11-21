<?php
/**
 * wp_payment_gateway_configs 表名的唯一出处，实际逻辑见 Core/GatewayConfigManager.php。
 */

namespace PaymentGatewayAggregator\Models;

if (!defined('ABSPATH')) {
    exit;
}

class GatewayConfig
{
    const TABLE_SUFFIX = 'payment_gateway_configs';

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }
}
