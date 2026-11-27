<?php
/**
 * Plugin Name: SSCPay Gateway Aggregator
 * Description: 接收Laravel系统S的API请求，创建WordPress订单，提供WooCommerce商品与变体同步，并聚合多个支付网关。
 * Version: 1.16.0
 * Update URI: false
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * WC requires at least: 5.0
 * Text Domain: sscpay-gateway-aggregator
 *
 * 版本号说明：
 * - "Version:" 是插件包版本，须与 PGA_VERSION 一起递增，由发布流程读取。
 * - PGA_DB_VERSION 是数据库结构版本，只有涉及建表/加字段才需要提升，
 *   由 Migrator.php 使用。加数据库变更、新增网关、修数据库以外的bug，
 *   具体怎么操作见压缩包里的 UPGRADE.md。
 */

namespace PaymentGatewayAggregator;

use PaymentGatewayAggregator\API\Controllers\ProductCatalogController;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\Migrator;
use PaymentGatewayAggregator\Core\QueueManager;
use PaymentGatewayAggregator\Core\RefundInbox;
use PaymentGatewayAggregator\Core\Roles;
use PaymentGatewayAggregator\Core\UpdateChecker;
use PaymentGatewayAggregator\Admin\GatewayConfigViewer;
use PaymentGatewayAggregator\Admin\LogViewer;
use PaymentGatewayAggregator\Admin\OrderMetaBox;
use PaymentGatewayAggregator\Admin\SettingsPage;
use PaymentGatewayAggregator\Admin\ThemePreviewController;
use PaymentGatewayAggregator\Frontend\PaymentPage;

if (!defined('ABSPATH')) {
    exit;
}

define('PGA_VERSION', '1.16.0');
// 1.7.0 新增退款收件及逐笔退款凭证。
define('PGA_DB_VERSION', '1.7.0');
define('PGA_PLUGIN_FILE', __FILE__);
define('PGA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PGA_PLUGIN_URL', plugin_dir_url(__FILE__));

if (!defined('DISABLE_WP_CRON')) {
    // 关掉WP默认的"访问触发"cron：回调重试队列/日志清理的定时任务在低流量站点上
    // 可能不准时触发。需要配合系统级crontab：
    //   * * * * * curl -s https://your-site.com/wp-cron.php >/dev/null 2>&1
    define('DISABLE_WP_CRON', true);
}

// composer依赖（PSR-4自动加载 + stripe-php + Plugin Update Checker）是硬依赖——
// 插件自身的类也是通过composer的PSR-4规则自动加载的（见composer.json），
// vendor/autoload.php缺失的话插件完全无法工作，不是"降级运行"，直接停止初始化。
if (!file_exists(PGA_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('SSCPay Gateway Aggregator：缺少 vendor/ 目录，请在插件目录下执行 Aomposer install，或使用发布流程（.github/workflows/release.yml）打出的release资源包安装。', 'sscpay-gateway-aggregator')
        );
    });
    return;
}

require_once PGA_PLUGIN_DIR . 'vendor/autoload.php';
// 路由注册函数是过程式代码，不是类，composer的PSR-4自动加载不会去找它，
// 需要手动require一次（文件本身只是定义函数，不会产生副作用）。
require_once PGA_PLUGIN_DIR . 'src/API/Routes/api.php';

// ------------------------------------------------------------------
// 激活 / 停用
// ------------------------------------------------------------------

register_activation_hook(__FILE__, function () {
    Migrator::activate();
    Roles::activate();

    PaymentPage::registerRewriteRule();
    flush_rewrite_rules();

    QueueManager::ensureScheduled();
    LogManager::ensurePurgeScheduled();
});

register_deactivation_hook(__FILE__, function () {
    QueueManager::unschedule();
    LogManager::unschedulePurge();
    flush_rewrite_rules();
});

// ------------------------------------------------------------------
// 数据库迁移（兜底：通过"在线更新"覆盖代码后，下一次请求自动检查升级）
// ------------------------------------------------------------------

add_action('plugins_loaded', [Migrator::class, 'maybeUpgrade']);

add_action('admin_notices', function () {
    $failedVersion = get_option(Migrator::OPTION_MIGRATION_FAILED);
    if (!$failedVersion) {
        return;
    }
    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        sprintf(
            /* translators: %s: 数据库迁移版本号 */
            esc_html__('SSCPay Gateway Aggregator：数据库升级到 %s 失败，插件可能无法正常工作，请查看错误日志或联系开发者。', 'sscpay-gateway-aggregator'),
            esc_html($failedVersion)
        )
    );
});

// ------------------------------------------------------------------
// 在线更新
// ------------------------------------------------------------------

add_action('plugins_loaded', function () {
    UpdateChecker::init(PGA_PLUGIN_FILE);
});

// ------------------------------------------------------------------
// 定时任务：回调重试队列 + 日志清理
// ------------------------------------------------------------------

add_filter('cron_schedules', [QueueManager::class, 'registerCronSchedule']);
add_action(QueueManager::CRON_HOOK, [QueueManager::class, 'processDueBatch']);
add_action(QueueManager::CRON_HOOK, [RefundInbox::class, 'retryDue']);
add_action(QueueManager::CRON_HOOK_IMMEDIATE, [QueueManager::class, 'processDueBatch']);
add_action(LogManager::CRON_HOOK, [LogManager::class, 'purgeOldLogs']);

// 自愈：如果计划任务因为某些原因（比如手动清空过cron）丢失了，每次init时补上。
add_action('init', function () {
    QueueManager::ensureScheduled();
    LogManager::ensurePurgeScheduled();
});

// ------------------------------------------------------------------
// 支付页面路由 /payment/{s_order_id}
// ------------------------------------------------------------------

PaymentPage::registerHooks();

// ------------------------------------------------------------------
// REST API 路由
// ------------------------------------------------------------------

add_action('rest_api_init', 'PaymentGatewayAggregator\\API\\Routes\\pga_register_routes');
add_action('plugins_loaded', [ProductCatalogController::class, 'registerHooks'], 30);

// ------------------------------------------------------------------
// 后台管理
// ------------------------------------------------------------------

if (is_admin()) {
    SettingsPage::registerHooks();
    LogViewer::registerHooks();
    GatewayConfigViewer::registerHooks();
    OrderMetaBox::registerHooks();
    ThemePreviewController::registerHooks();
}
