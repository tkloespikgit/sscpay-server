<?php
/**
 * wp_payment_callback_queue 表名的唯一出处，实际逻辑见 Core/QueueManager.php。
 */

namespace PaymentGatewayAggregator\Models;

if (!defined('ABSPATH')) {
    exit;
}

class CallbackQueue
{
    const TABLE_SUFFIX = 'payment_callback_queue';

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }
}
