<?php
/**
 * 数据库迁移执行器
 *
 * 设计原则：
 * 1. 迁移只增不改——已发布的版本号和对应回调，一旦上线就不要再修改内容，
 *    否则不同时间升级的站点会得到不一致的结果。真要改，就新开一个版本号。
 * 2. 结构变更（建表/加字段/加索引）一律通过 dbDelta() 完成——传入"完整的"
 *    目标表结构，dbDelta 自己比对当前结构差异并只执行需要的ALTER，
 *    不要手写 ALTER TABLE（手写容易在字段已存在时报错，dbDelta是幂等的）。
 * 3. 数据回填（比如给新字段填默认值）用普通 $wpdb->query()，写在同一个
 *    迁移回调里，放在 dbDelta() 之后执行。
 * 4. 每完成一个版本就立刻 update_option()，不是等全部跑完再存——
 *    这样如果中途失败，下次会从失败的下一个版本继续，不会重复执行已成功的迁移。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Migrator
{
    const OPTION_DB_VERSION = 'pga_db_version';
    const OPTION_MIGRATION_FAILED = 'pga_migration_failed';

    /**
     * 版本号 => 迁移回调，按 version_compare 升序执行。
     *
     * 新增数据库变更时，只需要在这里加一行，并写一个对应的 migrate_x_y_z() 方法。
     * 同时别忘了把主插件文件里的 PGA_DB_VERSION 常量改成同一个版本号。
     */
    private static function migrations()
    {
        return [
            '1.0.0' => [self::class, 'migrate_1_0_0'],
            '1.1.0' => [self::class, 'migrate_1_1_0'],
            '1.2.0' => [self::class, 'migrate_1_2_0'],
            '1.3.0' => [self::class, 'migrate_1_3_0'],
            '1.4.0' => [self::class, 'migrate_1_4_0'],
            '1.5.0' => [self::class, 'migrate_1_5_0'],
            '1.6.0' => [self::class, 'migrate_1_6_0'],
        ];
    }

    /**
     * 插件激活时调用（register_activation_hook）。
     * 全新安装：直接从 0.0.0 跑到最新版本。
     */
    public static function activate()
    {
        self::runPendingMigrations('0.0.0');
    }

    /**
     * 每次请求早期调用（挂在 plugins_loaded），用于：
     * - 插件通过"在线更新"覆盖了代码但数据库还没跟上的场景
     * - 手动上传新版本zip覆盖安装的场景
     * 两者都不会触发 register_activation_hook，所以必须在这里兜底检查。
     */
    public static function maybeUpgrade()
    {
        $installed = get_option(self::OPTION_DB_VERSION, '0.0.0');

        if (version_compare($installed, PGA_DB_VERSION, '<')) {
            self::runPendingMigrations($installed);
        }
    }

    private static function runPendingMigrations($fromVersion)
    {
        global $wpdb;

        $migrations = self::migrations();
        uksort($migrations, 'version_compare');

        // 迁移过程中如果并发请求同时触发，用一个短生命周期的锁避免重复执行。
        if (!self::acquireLock()) {
            return;
        }

        delete_option(self::OPTION_MIGRATION_FAILED);

        foreach ($migrations as $version => $callback) {
            if (version_compare($version, $fromVersion, '<=')) {
                continue;
            }

            try {
                call_user_func($callback, $wpdb);
                update_option(self::OPTION_DB_VERSION, $version);
                self::log("数据库已升级到 {$version}");
                $fromVersion = $version;
            } catch (\Throwable $e) {
                self::log("升级到 {$version} 失败：" . $e->getMessage(), 'ERROR');
                update_option(self::OPTION_MIGRATION_FAILED, $version);
                // 停在这一步，不再继续跑后面的迁移，避免在结构不完整的表上继续操作。
                break;
            }
        }

        self::releaseLock();
    }

    private static function acquireLock()
    {
        // 用 wp_options 的 autoload=no 实现一个简易互斥锁，60秒超时防止死锁。
        global $wpdb;
        $lockKey = 'pga_migration_lock';
        $now = time();

        $existing = get_option($lockKey);
        if ($existing && ($now - (int) $existing) < 60) {
            return false;
        }

        update_option($lockKey, $now, false);
        return true;
    }

    private static function releaseLock()
    {
        delete_option('pga_migration_lock');
    }

    private static function log($message, $level = 'INFO')
    {
        if (class_exists(LogManager::class) && method_exists(LogManager::class, 'system')) {
            LogManager::system($message, $level);
            return;
        }
        // LogManager 还没实现时的兜底，避免迁移过程因为日志模块缺失而报错。
        error_log('[sscpay-gateway-aggregator] ' . $level . ': ' . $message);
    }

    // ==================== 具体迁移 ====================

    /**
     * 1.0.0：初始建表，对应设计文档 v2 第六节的全部5张表。
     *
     * 比文档草稿多了一个字段：payment_transactions.gateway_session ——
     * 存放Gateway::preparePayment()返回的、渲染支付页面所需的会话数据
     * （比如Stripe的Checkout Session URL、PayPal的order id）。
     * 这是实现过程中发现必须补的字段：如果不缓存这份数据，每次用户
     * 刷新支付页面都会重新调用一次preparePayment()，导致在三方那边
     * 重复创建支付会话——参考 PaymentPage.php 和 PayController.php 里的用法。
     */
    private static function migrate_1_0_0($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        // dbDelta 对格式比较敏感：每个字段独占一行、KEY定义前两个空格、
        // 不要用反引号包字段名、不要用 INDEX 关键字（要用 KEY）。
        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    amount DECIMAL(15,4) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    payment_intent VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    billing_address TEXT,
    shipping_address TEXT,
    items TEXT NOT NULL,
    shipping_fee DECIMAL(15,4) DEFAULT 0.0000,
    tax_fee DECIMAL(15,4) DEFAULT 0.0000,
    gateway_config LONGTEXT NOT NULL,
    gateway_config_id BIGINT UNSIGNED DEFAULT NULL,
    pay_token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    gateway_session TEXT DEFAULT NULL,
    callback_url VARCHAR(512) DEFAULT NULL,
    return_url VARCHAR(512) DEFAULT NULL,
    cancel_url VARCHAR(512) DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    shipping_company VARCHAR(128) DEFAULT NULL,
    tracking_url VARCHAR(512) DEFAULT NULL,
    ship_date DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id),
    UNIQUE KEY uk_pay_token (pay_token),
    KEY idx_wp_order_id (wp_order_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_payment_intent (payment_intent)
) {$charsetCollate};

CREATE TABLE {$prefix}payment_gateway_configs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    config_key VARCHAR(64) NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    config_encrypted LONGTEXT NOT NULL,
    config_fingerprint VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    revoked_at DATETIME DEFAULT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_config_key (config_key),
    KEY idx_payment_method (payment_method),
    KEY idx_status (status)
) {$charsetCollate};

CREATE TABLE {$prefix}payment_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_method VARCHAR(32) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    s_order_id VARCHAR(64) DEFAULT NULL,
    event_type VARCHAR(64) DEFAULT NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_method_event (payment_method, event_id),
    KEY idx_s_order_id (s_order_id)
) {$charsetCollate};

CREATE TABLE {$prefix}payment_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED DEFAULT NULL,
    payment_method VARCHAR(32) DEFAULT NULL,
    level VARCHAR(16) NOT NULL DEFAULT 'INFO',
    message TEXT NOT NULL,
    request_data LONGTEXT,
    response_data LONGTEXT,
    callback_data LONGTEXT,
    ip VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(512) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_s_order_id (s_order_id),
    KEY idx_level (level),
    KEY idx_created_at (created_at)
) {$charsetCollate};

CREATE TABLE {$prefix}payment_callback_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    payload LONGTEXT NOT NULL,
    retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_retries TINYINT UNSIGNED NOT NULL DEFAULT 5,
    next_retry_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    last_error TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_s_order_id (s_order_id),
    KEY idx_status_next_retry (status, next_retry_at)
) {$charsetCollate};
";

        dbDelta($sql);
    }

    /**
     * 1.1.0：payment_transactions 增加 discount_fee 字段——/v1/pay 接口新增的
     * 订单折扣金额，创建WooCommerce订单时以负数Fee行项目体现。
     */
    private static function migrate_1_1_0($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        // 重新声明这张表【完整】的目标结构，dbDelta 会自动比对出 discount_fee
        // 是新字段并只补它，已有数据不受影响。
        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    amount DECIMAL(15,4) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    payment_intent VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    billing_address TEXT,
    shipping_address TEXT,
    items TEXT NOT NULL,
    shipping_fee DECIMAL(15,4) DEFAULT 0.0000,
    tax_fee DECIMAL(15,4) DEFAULT 0.0000,
    discount_fee DECIMAL(15,4) DEFAULT 0.0000,
    gateway_config LONGTEXT NOT NULL,
    gateway_config_id BIGINT UNSIGNED DEFAULT NULL,
    pay_token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    gateway_session TEXT DEFAULT NULL,
    callback_url VARCHAR(512) DEFAULT NULL,
    return_url VARCHAR(512) DEFAULT NULL,
    cancel_url VARCHAR(512) DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    shipping_company VARCHAR(128) DEFAULT NULL,
    tracking_url VARCHAR(512) DEFAULT NULL,
    ship_date DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id),
    UNIQUE KEY uk_pay_token (pay_token),
    KEY idx_wp_order_id (wp_order_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_payment_intent (payment_intent)
) {$charsetCollate};
";

        dbDelta($sql);
    }

    /**
     * 1.2.0：payment_transactions 增加 returned_to_s 字段——标记用户是否已经通过
     * 支付结果页的返回按钮跳回过S系统（见 Frontend/PaymentPage.php 的结果页路由）。
     * 已跳回过的订单，结果页不再展示返回按钮，改为根据 wp_order_id 自动跳转到
     * WordPress的order-received页面。
     */
    private static function migrate_1_2_0($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        // 重新声明这张表【完整】的目标结构，dbDelta 会自动比对出 returned_to_s
        // 是新字段并只补它，已有数据不受影响。
        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    amount DECIMAL(15,4) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    payment_intent VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    billing_address TEXT,
    shipping_address TEXT,
    items TEXT NOT NULL,
    shipping_fee DECIMAL(15,4) DEFAULT 0.0000,
    tax_fee DECIMAL(15,4) DEFAULT 0.0000,
    discount_fee DECIMAL(15,4) DEFAULT 0.0000,
    gateway_config LONGTEXT NOT NULL,
    gateway_config_id BIGINT UNSIGNED DEFAULT NULL,
    pay_token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    gateway_session TEXT DEFAULT NULL,
    callback_url VARCHAR(512) DEFAULT NULL,
    return_url VARCHAR(512) DEFAULT NULL,
    cancel_url VARCHAR(512) DEFAULT NULL,
    returned_to_s TINYINT(1) NOT NULL DEFAULT 0,
    tracking_number VARCHAR(255) DEFAULT NULL,
    shipping_company VARCHAR(128) DEFAULT NULL,
    tracking_url VARCHAR(512) DEFAULT NULL,
    ship_date DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id),
    UNIQUE KEY uk_pay_token (pay_token),
    KEY idx_wp_order_id (wp_order_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_payment_intent (payment_intent)
) {$charsetCollate};
";

        dbDelta($sql);
    }

    /**
     * 1.3.0：payment_transactions 增加访客风控字段——用户第一次打开支付页时，
     * 插件生成随机 visitor_token 写入这里并种进浏览器cookie，后续在
     * return-to-shop 端点跳回S系统前校验"来的是不是打开这笔订单的浏览器"
     * （见 Frontend/PaymentPage.php 的风控逻辑）。访客IP/UA一并落库，
     * 仅作为审计线索，不参与硬性判断。
     */
    private static function migrate_1_3_0($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        // 重新声明这张表【完整】的目标结构，dbDelta 会自动比对出三个 visitor
        // 字段是新的并只补它们，已有数据不受影响。
        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    amount DECIMAL(15,4) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    payment_intent VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    billing_address TEXT,
    shipping_address TEXT,
    items TEXT NOT NULL,
    shipping_fee DECIMAL(15,4) DEFAULT 0.0000,
    tax_fee DECIMAL(15,4) DEFAULT 0.0000,
    discount_fee DECIMAL(15,4) DEFAULT 0.0000,
    gateway_config LONGTEXT NOT NULL,
    gateway_config_id BIGINT UNSIGNED DEFAULT NULL,
    pay_token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    gateway_session TEXT DEFAULT NULL,
    callback_url VARCHAR(512) DEFAULT NULL,
    return_url VARCHAR(512) DEFAULT NULL,
    cancel_url VARCHAR(512) DEFAULT NULL,
    returned_to_s TINYINT(1) NOT NULL DEFAULT 0,
    visitor_token VARCHAR(64) DEFAULT NULL,
    visitor_ip VARCHAR(64) DEFAULT NULL,
    visitor_user_agent VARCHAR(255) DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    shipping_company VARCHAR(128) DEFAULT NULL,
    tracking_url VARCHAR(512) DEFAULT NULL,
    ship_date DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id),
    UNIQUE KEY uk_pay_token (pay_token),
    KEY idx_wp_order_id (wp_order_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_payment_intent (payment_intent)
) {$charsetCollate};
";

        dbDelta($sql);
    }

    /**
     * 1.4.0：payment_transactions 增加交易类型与账单号相关字段——/v1/pay 新增的
     * 四个入参，全部由S系统必传（见 PayController::validate()）：
     *
     * - trans_type     CREATE / MATCH / VIRTUAL，决定商品明细以什么形态组装给支付
     *                  通道（见 Core/LineItemBuilder）。
     * - invoice_number 账单号，所有通道、所有模式下都要带上（PayPal 落在
     *                  purchase_units[].invoice_id，Stripe/Airwallex 落在 metadata）。
     * - subject        订单主题，VIRTUAL 模式下当作唯一那条虚拟商品的商品名。
     * - subtotal       订单商品小计，VIRTUAL 模式下当作那条虚拟商品的单价。
     *                  之所以存下来而不是用时反算：组装发生在 preparePayment() 阶段，
     *                  那时手上只有交易记录这一行，拿不到原始请求体。
     *
     * 历史记录（升级前建的订单）由字段默认值回填：trans_type='MATCH' 就是
     * "按商品名正常传"，和升级前的行为一致；invoice_number/subject 为空时
     * 各通道会跳过不写，不会因为空字符串被三方拒绝。
     */
    private static function migrate_1_4_0($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        // 重新声明这张表【完整】的目标结构，dbDelta 会自动比对出 trans_type /
        // invoice_number / subject / subtotal 四个是新字段并只补它们，已有数据不受影响。
        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    trans_type VARCHAR(16) NOT NULL DEFAULT 'MATCH',
    invoice_number VARCHAR(127) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    amount DECIMAL(15,4) NOT NULL,
    subtotal DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    payment_intent VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    billing_address TEXT,
    shipping_address TEXT,
    items TEXT NOT NULL,
    shipping_fee DECIMAL(15,4) DEFAULT 0.0000,
    tax_fee DECIMAL(15,4) DEFAULT 0.0000,
    discount_fee DECIMAL(15,4) DEFAULT 0.0000,
    gateway_config LONGTEXT NOT NULL,
    gateway_config_id BIGINT UNSIGNED DEFAULT NULL,
    pay_token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    gateway_session TEXT DEFAULT NULL,
    callback_url VARCHAR(512) DEFAULT NULL,
    return_url VARCHAR(512) DEFAULT NULL,
    cancel_url VARCHAR(512) DEFAULT NULL,
    returned_to_s TINYINT(1) NOT NULL DEFAULT 0,
    visitor_token VARCHAR(64) DEFAULT NULL,
    visitor_ip VARCHAR(64) DEFAULT NULL,
    visitor_user_agent VARCHAR(255) DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    shipping_company VARCHAR(128) DEFAULT NULL,
    tracking_url VARCHAR(512) DEFAULT NULL,
    ship_date DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id),
    UNIQUE KEY uk_pay_token (pay_token),
    KEY idx_wp_order_id (wp_order_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_payment_intent (payment_intent)
) {$charsetCollate};
";

        dbDelta($sql);

        // 兜底回填：dbDelta 给新字段带了 DEFAULT，正常不会出现 NULL/空值；
        // 但 trans_type 直接驱动组装分支，这里再幂等地把异常值扫成 MATCH，
        // 避免旧版 MySQL 或人工改过表结构时留下空字符串走到未定义分支。
        $wpdb->query(
            "UPDATE {$prefix}payment_transactions
             SET trans_type = 'MATCH'
             WHERE trans_type IS NULL OR trans_type = ''"
        );
    }

    /**
     * 1.5.0：新增三个字段。
     * - allow_returned_source：/v1/pay 新参数，Y/N，控制支付结果页是否展示
     *   “Return to Store”按钮（见 Frontend/PaymentPage.php）。历史订单/未传该
     *   参数的请求默认 Y，保持升级前的行为不变。
     * - carrier_code / is_other_carrier：/v1/sync-tracking 改造后的新参数，
     *   取代原来的 shipping_company（该列继续保留、继续同步写入以兼容仍在读
     *   它的旧代码，见 TransactionManager::updateTracking()）。
     */
    private static function migrate_1_5_0($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    trans_type VARCHAR(16) NOT NULL DEFAULT 'MATCH',
    invoice_number VARCHAR(127) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    amount DECIMAL(15,4) NOT NULL,
    subtotal DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    payment_intent VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    billing_address TEXT,
    shipping_address TEXT,
    items TEXT NOT NULL,
    shipping_fee DECIMAL(15,4) DEFAULT 0.0000,
    tax_fee DECIMAL(15,4) DEFAULT 0.0000,
    discount_fee DECIMAL(15,4) DEFAULT 0.0000,
    gateway_config LONGTEXT NOT NULL,
    gateway_config_id BIGINT UNSIGNED DEFAULT NULL,
    pay_token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    gateway_session TEXT DEFAULT NULL,
    callback_url VARCHAR(512) DEFAULT NULL,
    return_url VARCHAR(512) DEFAULT NULL,
    cancel_url VARCHAR(512) DEFAULT NULL,
    allow_returned_source CHAR(1) NOT NULL DEFAULT 'Y',
    returned_to_s TINYINT(1) NOT NULL DEFAULT 0,
    visitor_token VARCHAR(64) DEFAULT NULL,
    visitor_ip VARCHAR(64) DEFAULT NULL,
    visitor_user_agent VARCHAR(255) DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    shipping_company VARCHAR(128) DEFAULT NULL,
    carrier_code VARCHAR(128) DEFAULT NULL,
    is_other_carrier CHAR(1) NOT NULL DEFAULT 'N',
    tracking_url VARCHAR(512) DEFAULT NULL,
    ship_date DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id),
    UNIQUE KEY uk_pay_token (pay_token),
    KEY idx_wp_order_id (wp_order_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_payment_intent (payment_intent)
) {$charsetCollate};
";

        dbDelta($sql);

        // 兜底回填：老订单没有这三个新字段时dbDelta已经用DEFAULT填过了，这里再幂等
        // 扫一遍NULL/空字符串（比如手工改过表结构的极端情况），保持和1.4.0同样的谨慎。
        $wpdb->query(
            "UPDATE {$prefix}payment_transactions
             SET allow_returned_source = 'Y'
             WHERE allow_returned_source IS NULL OR allow_returned_source = ''"
        );
        $wpdb->query(
            "UPDATE {$prefix}payment_transactions
             SET is_other_carrier = 'N'
             WHERE is_other_carrier IS NULL OR is_other_carrier = ''"
        );
    }

    /** 1.6.0: Local provider-session deadlines and durable historical references. */
    private static function migrate_1_6_0($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    trans_type VARCHAR(16) NOT NULL DEFAULT 'MATCH',
    invoice_number VARCHAR(127) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    amount DECIMAL(15,4) NOT NULL,
    subtotal DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    payment_intent VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(64) DEFAULT NULL,
    billing_address TEXT,
    shipping_address TEXT,
    items TEXT NOT NULL,
    shipping_fee DECIMAL(15,4) DEFAULT 0.0000,
    tax_fee DECIMAL(15,4) DEFAULT 0.0000,
    discount_fee DECIMAL(15,4) DEFAULT 0.0000,
    gateway_config LONGTEXT NOT NULL,
    gateway_config_id BIGINT UNSIGNED DEFAULT NULL,
    pay_token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    gateway_session TEXT DEFAULT NULL,
    gateway_session_expires_at DATETIME DEFAULT NULL,
    callback_url VARCHAR(512) DEFAULT NULL,
    return_url VARCHAR(512) DEFAULT NULL,
    cancel_url VARCHAR(512) DEFAULT NULL,
    allow_returned_source CHAR(1) NOT NULL DEFAULT 'Y',
    returned_to_s TINYINT(1) NOT NULL DEFAULT 0,
    visitor_token VARCHAR(64) DEFAULT NULL,
    visitor_ip VARCHAR(64) DEFAULT NULL,
    visitor_user_agent VARCHAR(255) DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    shipping_company VARCHAR(128) DEFAULT NULL,
    carrier_code VARCHAR(128) DEFAULT NULL,
    is_other_carrier CHAR(1) NOT NULL DEFAULT 'N',
    tracking_url VARCHAR(512) DEFAULT NULL,
    ship_date DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id),
    UNIQUE KEY uk_pay_token (pay_token),
    KEY idx_wp_order_id (wp_order_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_payment_intent (payment_intent)
) {$charsetCollate};
";

        dbDelta($sql);

        $transactionError = $wpdb->last_error;
        if ($transactionError !== '') {
            throw new \RuntimeException('支付会话期限字段迁移失败');
        }
        $table = $prefix . 'payment_session_references';
        dbDelta("
CREATE TABLE {$table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ref_hash CHAR(64) NOT NULL,
    external_ref VARCHAR(255) NOT NULL,
    s_order_id VARCHAR(64) NOT NULL,
    session_ref VARCHAR(255) NOT NULL,
    session_data LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_ref_hash (ref_hash),
    KEY idx_s_order_id (s_order_id)
) {$charsetCollate};
");
        $referenceError = $wpdb->last_error;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        $column = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$prefix}payment_transactions LIKE %s", 'gateway_session_expires_at'
        ));
        if ($found !== $table || $column !== 'gateway_session_expires_at' || $referenceError !== '' || !empty($wpdb->last_error)) {
            throw new \RuntimeException('支付会话历史引用或期限字段迁移失败');
        }
    }

    /**
     * 示例：以后要新增字段/表时照这个样子写一个新方法，
     * 然后在上面 migrations() 数组里注册对应版本号。
     *
     * 下面这段本身不会被执行（没有在 migrations() 里注册），纯粹作为模板参考。
     */
    private static function migrate_1_1_0_EXAMPLE($wpdb)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        // 加字段：重新声明这张表【完整】的目标结构（不是只写新增的那一行），
        // dbDelta 会自动比对出哪些字段是新的、只补那些字段，已有数据不受影响。
        $sql = "
CREATE TABLE {$prefix}payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    s_order_id VARCHAR(64) NOT NULL,
    wp_order_id BIGINT UNSIGNED NOT NULL,
    -- ... 省略中间未变化的字段，实际迁移文件里必须写全 ...
    refund_amount DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY uk_s_order_id (s_order_id)
) {$charsetCollate};
";
        dbDelta($sql);

        // 数据回填：dbDelta 做不到"把已有的历史数据填成某个值"，这类操作用普通SQL，
        // 并且要写成幂等的（可以安全地重复跑一次而不出错）。
        $wpdb->query(
            "UPDATE {$prefix}payment_transactions
             SET refund_amount = 0.0000
             WHERE refund_amount IS NULL"
        );
    }
}
