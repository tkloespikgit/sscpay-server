<?php
/**
 * 网关配置管理页（对应设计文档v2 第十二节12.3）：只展示元信息（config_key、
 * payment_method、状态、指纹、时间），从不展示解密后的明文密钥——
 * 后台管理员也不应该在界面上直接看到这些密钥，需要排查问题时应通过
 * config_fingerprint比对内容是否变化，而不是把明文展示出来。
 */

namespace PaymentGatewayAggregator\Admin;

use PaymentGatewayAggregator\Core\GatewayConfigManager;

if (!defined('ABSPATH')) {
    exit;
}

class GatewayConfigViewer
{
    const PAGE_SLUG = 'pga-gateway-configs';

    public static function registerHooks()
    {
        add_action('admin_post_pga_revoke_config', [self::class, 'handleRevoke']);
    }

    public static function handleRevoke()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('pga_revoke_config')) {
            wp_die(esc_html__('权限不足', 'sscpay-gateway-aggregator'));
        }

        $configId = isset($_POST['config_id']) ? (int) $_POST['config_id'] : 0;
        if ($configId) {
            GatewayConfigManager::revoke($configId);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&pga_revoked=1'));
        exit;
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $configs = GatewayConfigManager::listAll();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('网关配置管理', 'sscpay-gateway-aggregator'); ?></h1>

            <?php if (isset($_GET['pga_revoked'])) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('配置已吊销，后续引用该config_id的下单请求会被拒绝。', 'sscpay-gateway-aggregator'); ?></p></div>
            <?php endif; ?>

            <p><?php esc_html_e('通过 /v1/gateway-config 接口注册的网关凭证列表，出于安全考虑不展示明文/密文内容。', 'sscpay-gateway-aggregator'); ?></p>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th>ID</th>
                    <th><?php esc_html_e('config_key', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('支付方式', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('指纹', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('状态', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('更新时间', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('操作', 'sscpay-gateway-aggregator'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($configs)) : ?>
                    <tr><td colspan="7"><?php esc_html_e('暂无网关配置', 'sscpay-gateway-aggregator'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($configs as $config) : ?>
                        <tr>
                            <td><?php echo (int) $config['id']; ?></td>
                            <td><?php echo esc_html($config['config_key']); ?></td>
                            <td><?php echo esc_html($config['payment_method']); ?></td>
                            <td><code><?php echo esc_html(substr($config['config_fingerprint'], 0, 12)); ?>…</code></td>
                            <td><?php echo esc_html($config['status']); ?></td>
                            <td><?php echo esc_html($config['updated_at']); ?></td>
                            <td>
                                <?php if ($config['status'] === GatewayConfigManager::STATUS_ACTIVE) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('确认吊销该配置？之后引用该config_id的下单请求会被拒绝。', 'sscpay-gateway-aggregator')); ?>');">
                                        <?php wp_nonce_field('pga_revoke_config'); ?>
                                        <input type="hidden" name="action" value="pga_revoke_config">
                                        <input type="hidden" name="config_id" value="<?php echo (int) $config['id']; ?>">
                                        <button type="submit" class="button button-small"><?php esc_html_e('吊销', 'sscpay-gateway-aggregator'); ?></button>
                                    </form>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
