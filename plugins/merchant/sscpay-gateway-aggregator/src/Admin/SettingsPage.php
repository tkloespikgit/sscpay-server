<?php
/**
 * 后台设置页（对应设计文档v2 第十二节12.1）。
 */

namespace PaymentGatewayAggregator\Admin;

use PaymentGatewayAggregator\Core\GatewayFactory;
use PaymentGatewayAggregator\Core\OutboundSigner;
use PaymentGatewayAggregator\Core\RouteIdentity;
use PaymentGatewayAggregator\Core\UpdateChecker;
use PaymentGatewayAggregator\Frontend\Themes\ThemeManager;
use PaymentGatewayAggregator\Helpers\SiteCipher;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsPage
{
    const OPTION_GROUP = 'pga_settings';
    const PAGE_SLUG = 'pga-settings';

    public static function registerHooks()
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_pga_save_secret', [self::class, 'handleSaveSecret']);
        add_action('admin_post_pga_save_update_source', [self::class, 'handleSaveUpdateSource']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function enqueueAssets($hook)
    {
        // 只在插件自己的后台页面加载（页面slug里都带pga-前缀），
        // 避免给其它无关的wp-admin页面也加载这份CSS。
        if (strpos((string) $hook, 'pga') === false) {
            return;
        }
        wp_enqueue_style('pga-admin', PGA_PLUGIN_URL . 'assets/css/admin.css', [], PGA_VERSION);
    }

    public static function registerMenu()
    {
        add_menu_page(
            __('支付网关聚合插件', 'sscpay-gateway-aggregator'),
            __('支付网关聚合', 'sscpay-gateway-aggregator'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render'],
            'dashicons-money-alt'
        );

        add_submenu_page(self::PAGE_SLUG, __('设置', 'sscpay-gateway-aggregator'), __('设置', 'sscpay-gateway-aggregator'), 'manage_options', self::PAGE_SLUG, [self::class, 'render']);
        add_submenu_page(self::PAGE_SLUG, __('日志', 'sscpay-gateway-aggregator'), __('日志', 'sscpay-gateway-aggregator'), 'manage_options', LogViewer::PAGE_SLUG, [LogViewer::class, 'render']);
        add_submenu_page(self::PAGE_SLUG, __('网关配置管理', 'sscpay-gateway-aggregator'), __('网关配置管理', 'sscpay-gateway-aggregator'), 'manage_options', GatewayConfigViewer::PAGE_SLUG, [GatewayConfigViewer::class, 'render']);
    }

    public static function registerSettings()
    {
        register_setting(self::OPTION_GROUP, 'pga_pay_link_ttl_minutes', ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint']);
        register_setting(self::OPTION_GROUP, 'pga_log_retention_days', ['type' => 'integer', 'default' => 90, 'sanitize_callback' => 'absint']);
        register_setting(self::OPTION_GROUP, 'pga_gateway_display_names', ['type' => 'array', 'default' => [], 'sanitize_callback' => [self::class, 'sanitizeDisplayNames']]);
        register_setting(self::OPTION_GROUP, ThemeManager::OPTION_NAME, [
            'type' => 'string',
            'default' => ThemeManager::DEFAULT_SLUG,
            'sanitize_callback' => [self::class, 'sanitizePaymentTheme'],
        ]);
    }

    /**
     * 后台选的主题slug必须是ThemeManager里登记过的，否则静默回退默认主题，
     * 避免非法值一路存进option后每次支付页渲染都要单独处理"未知主题"分支。
     */
    public static function sanitizePaymentTheme($value)
    {
        $value = is_string($value) ? $value : '';
        return ThemeManager::isValidSlug($value) ? $value : ThemeManager::DEFAULT_SLUG;
    }

    public static function sanitizeDisplayNames($value)
    {
        $result = [];
        if (is_array($value)) {
            foreach ($value as $method => $label) {
                if (GatewayFactory::isSupported($method)) {
                    $result[$method] = sanitize_text_field($label);
                }
            }
        }
        return $result;
    }

    public static function handleSaveSecret()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !current_user_can('manage_options') || !check_admin_referer('pga_save_secret')) {
            wp_die(esc_html__('权限不足', 'sscpay-gateway-aggregator'), '', ['response' => 403]);
        }

        $redirectBase = admin_url('admin.php?page=' . self::PAGE_SLUG);

        $submitted = $_POST['pga_outbound_shared_secret'] ?? '';
        $secret = is_string($submitted) ? trim(wp_unslash($submitted)) : '';

        if ($secret === '') {
            wp_safe_redirect(add_query_arg('pga_secret_error', 'empty', $redirectBase));
            exit;
        }
        // 密钥进HTTP头/比对十六进制签名，限定为ASCII可打印字符，避免编码差异导致两端验签不一致。
        if (!preg_match('/^[\x20-\x7E]+$/D', $secret)) {
            wp_safe_redirect(add_query_arg('pga_secret_error', 'charset', $redirectBase));
            exit;
        }

        update_option(OutboundSigner::SECRET_OPTION, SiteCipher::protectCallbackSecret($secret), false);
        if (OutboundSigner::configuredSecret() !== $secret) {
            wp_die(esc_html__('回调密钥未保存，请检查数据库后重试。', 'sscpay-gateway-aggregator'), '', ['response' => 500]);
        }
        wp_safe_redirect(add_query_arg('pga_secret_saved', 'custom', $redirectBase));
        exit;
    }

    public static function handleSaveUpdateSource(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || defined('PGA_UPDATE_BASE_URL') || !current_user_can('manage_options')
            || !current_user_can('update_plugins') || !check_admin_referer('pga_save_update_source')) {
            wp_die(esc_html__('权限不足', 'sscpay-gateway-aggregator'), '', ['response' => 403]);
        }

        $input = $_POST[UpdateChecker::BASE_OPTION] ?? null;
        $value = is_string($input) ? trim(wp_unslash($input)) : null;
        if ($value === '') {
            delete_site_option(UpdateChecker::BASE_OPTION);
        } elseif ($value !== null && ($url = UpdateChecker::normalizeBaseUrl($value)) !== null) {
            update_site_option(UpdateChecker::BASE_OPTION, $url);
        } else {
            wp_die(esc_html__('请输入 HTTPS 域名，不含账号、路径或查询参数。', 'sscpay-gateway-aggregator'), '', ['response' => 400]);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $ttl = (int) get_option('pga_pay_link_ttl_minutes', 0);
        $retention = (int) get_option('pga_log_retention_days', 90);
        try {
            $hasSecret = OutboundSigner::configuredSecret() !== '';
        } catch (\RuntimeException $error) {
            $hasSecret = false;
        }
        $updateSource = UpdateChecker::sourceUrl();
        $displayNames = get_option('pga_gateway_display_names', []);
        $activeTheme = get_option(ThemeManager::OPTION_NAME, ThemeManager::DEFAULT_SLUG);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('支付网关聚合插件 - 设置', 'sscpay-gateway-aggregator'); ?></h1>

            <?php if (isset($_GET['pga_secret_saved'])) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('出站回调签名密钥已保存，请把它同步给S系统那边用于验证回调签名。', 'sscpay-gateway-aggregator'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['pga_secret_error'])) : ?>
                <div class="notice notice-error"><p><?php esc_html_e('密钥未保存。请输入非空的可打印 ASCII 字符。', 'sscpay-gateway-aggregator'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pga_pay_link_ttl_minutes"><?php esc_html_e('支付链接有效期（分钟）', 'sscpay-gateway-aggregator'); ?></label></th>
                        <td>
                            <input type="number" min="0" id="pga_pay_link_ttl_minutes" name="pga_pay_link_ttl_minutes" value="<?php echo esc_attr($ttl); ?>" class="small-text" aria-describedby="pga_pay_link_ttl_description">
                            <p class="description" id="pga_pay_link_ttl_description"><?php esc_html_e('默认 0，表示本站支付链接永久有效；正整数表示有效分钟数。仅影响新建订单，支付平台的会话仍有自身有效期。', 'sscpay-gateway-aggregator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pga_log_retention_days"><?php esc_html_e('日志保留天数（0表示永久保留）', 'sscpay-gateway-aggregator'); ?></label></th>
                        <td><input type="number" min="0" id="pga_log_retention_days" name="pga_log_retention_days" value="<?php echo esc_attr($retention); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('在线更新地址', 'sscpay-gateway-aggregator'); ?></th>
                        <td>
                            <code><?php echo esc_html($updateSource ?? __('更新地址配置无效', 'sscpay-gateway-aggregator')); ?></code>
                            <p class="description"><?php esc_html_e('插件直接通过 HTTPS 检查和下载公开更新，不需要令牌。', 'sscpay-gateway-aggregator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pga_payment_theme"><?php esc_html_e('支付页面外观主题', 'sscpay-gateway-aggregator'); ?></label></th>
                        <td>
                            <select id="pga_payment_theme" name="<?php echo esc_attr(ThemeManager::OPTION_NAME); ?>">
                                <?php foreach (ThemeManager::getAvailableLabels() as $slug => $label) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($activeTheme, $slug); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <a href="<?php echo esc_url(ThemePreviewController::previewUrl($activeTheme)); ?>"
                                id="pga_theme_preview_link"
                                class="button"
                                target="_blank" rel="noopener">
                                <?php esc_html_e('预览', 'sscpay-gateway-aggregator'); ?>
                            </a>
                            <p class="description"><?php esc_html_e('决定loading页/结账页/结果页/取消页的视觉风格，路由和业务逻辑不受影响。预览按钮打开的是下拉框当前选中的主题（不用先保存），用假样例数据展示效果。', 'sscpay-gateway-aggregator'); ?></p>
                            <script>
                            (function () {
                                // 预览链接的theme参数跟着下拉框实时联动，不用先点保存
                                // 才能看到刚选的主题长什么样。base里已经带了当前激活
                                // 主题的nonce，nonce本身不跟theme参数绑定校验，切换
                                // 主题不会让链接失效。
                                var select = document.getElementById('pga_payment_theme');
                                var link = document.getElementById('pga_theme_preview_link');
                                if (!select || !link) { return; }
                                var baseHref = link.getAttribute('href');
                                function syncHref() {
                                    link.setAttribute('href', baseHref.replace(
                                        /([?&]theme=)[^&]*/,
                                        '$1' + encodeURIComponent(select.value)
                                    ));
                                }
                                select.addEventListener('change', syncHref);
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('各支付方式显示名称', 'sscpay-gateway-aggregator'); ?></th>
                        <td>
                            <?php foreach (GatewayFactory::supportedMethods() as $method) : ?>
                                <p>
                                    <label style="display:inline-block;width:140px;"><?php echo esc_html($method); ?></label>
                                    <input type="text" name="pga_gateway_display_names[<?php echo esc_attr($method); ?>]" value="<?php echo esc_attr($displayNames[$method] ?? ''); ?>">
                                </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if (current_user_can('update_plugins') && !defined('PGA_UPDATE_BASE_URL')) : ?>
                <details>
                    <summary><?php esc_html_e('修改更新域名', 'sscpay-gateway-aggregator'); ?></summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('pga_save_update_source'); ?>
                        <input type="hidden" name="action" value="pga_save_update_source">
                        <label for="pga_update_base_url"><?php esc_html_e('可信 HTTPS 域名（留空恢复默认）', 'sscpay-gateway-aggregator'); ?></label>
                        <input type="url" class="regular-text" id="pga_update_base_url" name="pga_update_base_url" placeholder="https://www.ssecurepay.com" value="<?php echo esc_attr(get_site_option(UpdateChecker::BASE_OPTION, '')); ?>">
                        <?php submit_button(__('保存更新域名', 'sscpay-gateway-aggregator')); ?>
                    </form>
                </details>
            <?php endif; ?>

            <hr>

            <h2><?php esc_html_e('本站 Webhook 地址', 'sscpay-gateway-aggregator'); ?></h2>
            <p>
                <?php esc_html_e('这些地址每个站点都不一样（插件会在首次使用时为本站随机生成一套），目的是不让各个站点的回调地址长成完全相同的形态、被支付通道的风控拿去做跨站关联。请按下面显示的地址去对应通道的后台登记 Webhook。', 'sscpay-gateway-aggregator'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('登记后不要再改动：', 'sscpay-gateway-aggregator'); ?></strong>
                <?php esc_html_e('这套地址生成后就固定下来，插件不会自己重新生成。通道那边保存的是登记当时的地址，本站地址一旦变化，已登记的 Webhook 会收不到。', 'sscpay-gateway-aggregator'); ?>
            </p>
            <table class="form-table">
                <?php foreach ([
                    'paypal_js' => 'PayPal JS (paypal_js)',
                    'paypal_rest' => 'PayPal REST (paypal_rest)',
                    'stripe' => 'Stripe',
                    'airwallex' => 'Airwallex',
                    'antom' => 'Antom',
                ] as $gatewayCode => $gatewayLabel) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($gatewayLabel); ?></th>
                        <td>
                            <input type="text" readonly onclick="this.select();"
                                   style="width:100%;max-width:700px;font-family:monospace;"
                                   value="<?php echo esc_attr(RouteIdentity::restUrl(RouteIdentity::notifyPath() . '/' . $gatewayCode)); ?>">
                            <?php if ($gatewayCode === 'antom') : ?>
                                <p class="description"><?php esc_html_e('Antom 的通知地址随每笔支付请求一起下发，不需要在通道后台登记，这里只是供核对。', 'sscpay-gateway-aggregator'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="description">
                <?php esc_html_e('旧版本的固定地址（/wp-json/payment-plugin/v1/webhook/…）仍然保持可用，已经按旧地址登记过的站点不需要改。', 'sscpay-gateway-aggregator'); ?>
            </p>

            <hr>

            <h2><?php esc_html_e('出站回调签名密钥', 'sscpay-gateway-aggregator'); ?></h2>
            <p><?php echo esc_html($hasSecret ? __('已配置，不显示现有密钥。', 'sscpay-gateway-aggregator') : __('回调密钥未配置或无法读取，请初始化或保存新密钥。', 'sscpay-gateway-aggregator')); ?></p>
            <details>
                <summary><?php esc_html_e('修改回调密钥', 'sscpay-gateway-aggregator'); ?></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pga_save_secret'); ?>
                    <input type="hidden" name="action" value="pga_save_secret">
                    <p>
                        <label for="pga_outbound_shared_secret"><?php esc_html_e('新密钥', 'sscpay-gateway-aggregator'); ?></label><br>
                        <input type="password" id="pga_outbound_shared_secret" name="pga_outbound_shared_secret"
                               class="regular-text" autocomplete="new-password" required>
                    </p>
                    <p class="description"><?php esc_html_e('保存后，请同步更新 S 系统使用的回调验签密钥。', 'sscpay-gateway-aggregator'); ?></p>
                    <?php submit_button(__('保存新密钥', 'sscpay-gateway-aggregator'), 'primary'); ?>
                </form>
            </details>
        </div>
        <?php
    }
}
