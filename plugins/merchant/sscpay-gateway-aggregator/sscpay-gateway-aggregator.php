<?php
/**
 * Plugin Name: SSCPay Gateway Aggregator
 * Description: 接收Laravel系统S的API请求，创建WordPress订单，提供WooCommerce商品与变体同步，并聚合多个支付网关。
 * Version: 1.15.0
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * WC requires at least: 5.0
 * Text Domain: sscpay-gateway-aggregator
 *
 * 版本号说明：
 * - "Version:" 是插件包版本，在线更新检测器（UpdateChecker）靠它判断
 *   "线上是否有新版本"，每次发布都必须改这一行，并且要和GitHub release
 *   的tag（如 v1.0.0）保持一致。
 * - PGA_DB_VERSION 是数据库结构版本，只有涉及建表/加字段才需要提升，
 *   由 Migrator.php 使用。加数据库变更、新增网关、修数据库以外的bug，
 *   具体怎么操作见压缩包里的 UPGRADE.md。
 */

namespace PaymentGatewayAggregator;

use PaymentGatewayAggregator\API\Controllers\ProductCatalogController;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\Migrator;
use PaymentGatewayAggregator\Core\QueueManager;
use PaymentGatewayAggregator\Core\Roles;
use PaymentGatewayAggregator\Core\UpdateChecker;
use PaymentGatewayAggregator\Admin\GatewayConfigViewer;
use PaymentGatewayAggregator\Admin\OrderMetaBox;
use PaymentGatewayAggregator\Admin\SettingsPage;
use PaymentGatewayAggregator\Admin\ThemePreviewController;
use PaymentGatewayAggregator\Frontend\PaymentPage;

if (!defined('ABSPATH')) {
    exit;
}

define('PGA_VERSION', '1.15.0');
// 1.6.0（DB版本）新增平台会话期限与历史引用表，见 Migrator::migrate_1_6_0()。
define('PGA_DB_VERSION', '1.6.0');
define('PGA_PLUGIN_FILE', __FILE__);
define('PGA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PGA_PLUGIN_URL', plugin_dir_url(__FILE__));

// ------------------------------------------------------------------
// 内置默认配置：不用再改 wp-config.php，装完插件即生效。
// 全部用 if (!defined()) 保护，站点如果确实需要在 wp-config.php 里
// 覆盖（比如私有仓库的 PGA_GITHUB_TOKEN），仍然优先生效。
//
// 【重要】PGA_ENCRYPTION_KEY 一旦用于加密过数据就不能再换，换了库里
// 已存的网关凭证就解不出来了。发布新版本时不要动这一行的值。
// ------------------------------------------------------------------
if (!defined('PGA_ENCRYPTION_KEY')) {
    // base64_encode(random_bytes(32)) 的一次性生成结果，已固定写死，不要重新生成。
    define('PGA_ENCRYPTION_KEY', 'BR9L9awVqmi0Q5sB9UYraSbNlOG1RSdLAtOvHYBXMAY=');
}
if (!defined('PGA_UPDATE_REPO_URL')) {
    // 在线更新用的GitHub仓库地址，见压缩包里的 UPGRADE.md。
    // SSCPAY_GITHUB_OWNER 是四个 sscpay-* 插件共用的 GitHub 账号/组织名，
    // 只需在 wp-config.php 定义一次；没定义时才用下面的默认值兜底。
    $pga_github_owner = defined('SSCPAY_GITHUB_OWNER') ? SSCPAY_GITHUB_OWNER : 'tkloespikgit';
    define('PGA_UPDATE_REPO_URL', 'https://github.com/' . $pga_github_owner . '/sscpay-gateway-aggregator');
}
if (!defined('PGA_GITHUB_TOKEN')) {
    // 私有仓库才需要填；有值才会在 UpdateChecker 里启用鉴权。
    // SSCPAY_GITHUB_TOKEN 是四个 sscpay-* 插件共用的 Access Token，建议放
    // wp-config.php 里定义，不要写进代码库。
    define('PGA_GITHUB_TOKEN', defined('SSCPAY_GITHUB_TOKEN') ? SSCPAY_GITHUB_TOKEN : '');
}

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
    GatewayConfigViewer::registerHooks();
    OrderMetaBox::registerHooks();
    ThemePreviewController::registerHooks();
}
