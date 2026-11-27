<?php
/**
 * Run with wp eval-file from an extracted copy of the new ZIP, before replacing
 * an active gateway older than 1.15.2. Each multisite blog has its own options.
 */

use PaymentGatewayAggregator\Helpers\SiteCipher;

if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    exit(1);
}

if (!defined('PGA_VERSION') || version_compare(PGA_VERSION, '1.15.2', '>=')) {
    WP_CLI::error('请在需要升级的站点启用原来低于 1.15.2 的网关插件，再运行本命令。');
}

if (!class_exists(SiteCipher::class, false)) {
    require_once __DIR__ . '/Helpers/SiteCipher.php';
}

try {
    SiteCipher::preserveLegacyKey();
    $option = 'pga_default_outbound_shared_secret';
    $saved = SiteCipher::readCallbackSecret(get_option($option, ''));
    if ($saved === '' && defined('PGA_DEFAULT_OUTBOUND_SHARED_SECRET') && PGA_DEFAULT_OUTBOUND_SHARED_SECRET !== '') {
        update_option($option, SiteCipher::protectCallbackSecret(PGA_DEFAULT_OUTBOUND_SHARED_SECRET), false);
        if (SiteCipher::readCallbackSecret(get_option($option, '')) !== PGA_DEFAULT_OUTBOUND_SHARED_SECRET) {
            throw new RuntimeException('回调密钥未成功保存。');
        }
    }
} catch (Throwable $error) {
    WP_CLI::error('旧版凭证迁移失败，请保留原插件、备份和站点安全盐，核对配置后重试。');
}

WP_CLI::success('本站旧版凭证迁移准备已完成，可上传同一 ZIP 覆盖升级，并在网关设置中核对回调密钥状态。');
