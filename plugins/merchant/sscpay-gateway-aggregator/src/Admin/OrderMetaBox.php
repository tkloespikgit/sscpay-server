<?php
/**
 * WooCommerce订单详情页的支付信息展示（对应设计文档v2 第九节Admin/OrderMetaBox.php）。
 * 只读展示，不在这里提供任何"修改支付状态"之类的入口——状态变更必须走
 * WebhookRouter，避免后台手动改状态绕过状态机校验和S系统同步。
 */

namespace PaymentGatewayAggregator\Admin;

use PaymentGatewayAggregator\Core\LineItemBuilder;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Models\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

class OrderMetaBox
{
    public static function registerHooks()
    {
        add_action('add_meta_boxes', [self::class, 'register']);
    }

    public static function register()
    {
        $screen = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'pga_payment_info',
            __('支付网关聚合插件 - 支付信息', 'sscpay-gateway-aggregator'),
            [self::class, 'render'],
            $screen,
            'side'
        );
    }

    public static function render($postOrOrder)
    {
        $orderId = is_a($postOrOrder, 'WC_Order') ? $postOrOrder->get_id() : (int) $postOrOrder->ID;
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $sOrderId = $order->get_meta('_s_order_id');
        if (!$sOrderId) {
            echo '<p>' . esc_html__('该订单不是通过支付网关聚合插件创建的。', 'sscpay-gateway-aggregator') . '</p>';
            return;
        }

        $transaction = TransactionManager::findBySOrderId($sOrderId);
        if (!$transaction) {
            echo '<p>' . esc_html__('未找到对应的交易记录。', 'sscpay-gateway-aggregator') . '</p>';
            return;
        }

        ?>
        <p><strong><?php esc_html_e('S系统订单号', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['s_order_id']); ?></p>
        <p><strong><?php esc_html_e('支付方式', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['payment_method']); ?></p>
        <p><strong><?php esc_html_e('交易类型', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html(Transaction::normalizeTransType($transaction['trans_type'] ?? '')); ?></p>
        <?php if (!empty($transaction['invoice_number'])) : ?>
            <p><strong><?php esc_html_e('账单号', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['invoice_number']); ?></p>
        <?php endif; ?>
        <?php if (!empty($transaction['subject'])) : ?>
            <p><strong><?php esc_html_e('订单主题', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['subject']); ?></p>
        <?php endif; ?>
        <p><strong><?php esc_html_e('状态', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['status']); ?></p>
        <p><strong><?php esc_html_e('金额', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['currency'] . ' ' . $transaction['amount']); ?></p>
        <?php
        // 实际同步给支付通道的商品行（已按trans_type组装），方便排查
        // "通道那边看到的商品名/SKU 为什么和站内不一样" 这类问题，不用去翻日志。
        $channelItems = LineItemBuilder::build($transaction);
        if (!empty($channelItems)) :
            ?>
            <p>
                <strong><?php esc_html_e('同步给通道的商品行', 'sscpay-gateway-aggregator'); ?>：</strong><br>
                <?php foreach ($channelItems as $channelItem) : ?>
                    <?php
                    echo esc_html(sprintf(
                        '%s%s ×%d @ %s %s',
                        $channelItem['name'] !== '' ? $channelItem['name'] : '(no name)',
                        $channelItem['sku'] !== '' ? ' [' . $channelItem['sku'] . ']' : '',
                        $channelItem['quantity'],
                        $transaction['currency'],
                        $channelItem['price']
                    ));
                    ?>
                    <br>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($transaction['transaction_id'])) : ?>
            <p><strong><?php esc_html_e('三方交易ID', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['transaction_id']); ?></p>
        <?php endif; ?>
        <?php if (!empty($transaction['paid_at'])) : ?>
            <p><strong><?php esc_html_e('支付时间', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['paid_at']); ?></p>
        <?php endif; ?>
        <?php if (!empty($transaction['tracking_number'])) : ?>
            <p><strong><?php esc_html_e('物流单号', 'sscpay-gateway-aggregator'); ?>：</strong><?php echo esc_html($transaction['tracking_number']); ?>（<?php echo esc_html($transaction['carrier_code'] ?? $transaction['shipping_company']); ?>）</p>
        <?php endif; ?>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . LogViewer::PAGE_SLUG . '&s_order_id=' . rawurlencode($sOrderId))); ?>">
                <?php esc_html_e('查看相关日志 →', 'sscpay-gateway-aggregator'); ?>
            </a>
        </p>
        <?php
    }
}
