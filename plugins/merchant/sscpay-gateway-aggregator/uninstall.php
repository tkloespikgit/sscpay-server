<?php
/**
 * 卸载脚本：只在用户于"插件"列表页点击"删除"时执行（且插件已处于停用状态）。
 *
 * 有意【不】自动删除 wp_payment_transactions / wp_payment_logs 等数据表——
 * 这些是支付/交易记录，属于财务凭证性质的数据，静默删除的风险和后果
 * 远大于"卸载后表还留着"这点存储空间。这里只清理插件自身的配置项
 * 和定时任务，数据表如果确实需要清空，请管理员手动执行（并自行确认
 * 已经不需要这些历史交易记录用于对账/合规）。
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 【不要】把 pga_route_identity 加进这个列表：它决定本站发给支付通道的回调/
// 回跳地址形态，而交易表在卸载时是故意保留的（见上面的说明）。option 删了、表还在，
// 等于把那些历史订单回跳地址对应的路由弄丢；重装后会生成一套新的，通道后台里
// 已登记的 Webhook 也会失效。
$optionsToDelete = [
    'pga_db_version',
    'pga_migration_failed',
    'pga_migration_lock',
    'pga_pay_link_ttl_minutes',
    'pga_log_retention_days',
    'pga_gateway_display_names',
    'pga_outbound_shared_secret',
    'pga_default_outbound_shared_secret',
    'pga_product_catalog_cache_generation',
];

foreach ($optionsToDelete as $option) {
    delete_option($option);
}

delete_site_option('pga_plugin_update_source');
delete_site_option('pga_update_base_url');
delete_site_option('external_updates-sscpay-gateway-aggregator');

wp_clear_scheduled_hook('pga_process_callback_queue');
wp_clear_scheduled_hook('pga_purge_old_logs');

// 移除插件专用的自定义角色（不影响已有用户账号本身，只是角色定义）。
remove_role('pga_order_operator');
remove_role('pga_config_operator');

flush_rewrite_rules();
