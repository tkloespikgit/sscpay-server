<?php
/**
 * wp_payment_logs 表名的唯一出处，实际逻辑见 Core/LogManager.php。
 */

namespace PaymentGatewayAggregator\Models;

if (!defined('ABSPATH')) {
    exit;
}

class Log
{
    const TABLE_SUFFIX = 'payment_logs';

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }
}
