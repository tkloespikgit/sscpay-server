<?php
/**
 * wp_payment_webhook_events 表名的唯一出处，实际去重逻辑见 Core/WebhookRouter.php。
 */

namespace PaymentGatewayAggregator\Models;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookEvent
{
    const TABLE_SUFFIX = 'payment_webhook_events';

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }
}
