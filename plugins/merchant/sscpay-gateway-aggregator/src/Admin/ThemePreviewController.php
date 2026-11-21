<?php
/**
 * 后台"支付页面外观主题"下拉框旁边的预览按钮，打开对应主题渲染出的
 * 发票页/loading页（用假样例数据，不读真实订单）。
 *
 * 用 admin-post.php 而不是普通的admin.php子页面：预览要的是主题CSS接管后的
 * 完整页面效果（跟买家在支付页看到的一样），不能再套一层wp-admin的
 * 页头/侧边栏，所以这里直接输出完整HTML并exit，跟PaymentPage的做法一致。
 */

namespace PaymentGatewayAggregator\Admin;

use PaymentGatewayAggregator\Frontend\Themes\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

class ThemePreviewController
{
    const ACTION = 'pga_theme_preview';

    public static function registerHooks()
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'render']);
    }

    /** 供SettingsPage拼预览链接用。$view缺省是发票页，还可以传'loading'看loading页。 */
    public static function previewUrl($slug, $view = 'invoice')
    {
        $url = add_query_arg([
            'action' => self::ACTION,
            'theme' => $slug,
            'view' => $view,
        ], admin_url('admin-post.php'));

        return wp_nonce_url($url, self::ACTION);
    }

    public static function render()
    {
        if (!current_user_can('manage_options') || !check_admin_referer(self::ACTION)) {
            wp_die(esc_html__('权限不足', 'sscpay-gateway-aggregator'));
        }

        $requestedSlug = isset($_GET['theme']) ? sanitize_key(wp_unslash($_GET['theme'])) : '';
        $theme = ThemeManager::get($requestedSlug);
        // 主题slug非法时ThemeManager::get()已经静默回退默认主题，这里同步一下，
        // 保证下面"切换视图"链接带的是真正生效的slug，不是用户瞎填的那个。
        $slug = $theme->getSlug();
        $view = (isset($_GET['view']) && $_GET['view'] === 'loading') ? 'loading' : 'invoice';

        nocache_headers();
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Referrer-Policy: origin');
        header('Content-Type: text/html; charset=utf-8');

        $cssUrl = $theme->getStylesheetUrl();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html(sprintf(__('主题预览：%s', 'sscpay-gateway-aggregator'), $theme->getLabel())); ?></title>
            <?php if ($cssUrl) : ?>
                <link rel="stylesheet" href="<?php echo esc_url($cssUrl); ?>">
            <?php endif; ?>
            <style>
                /* 预览工具条自己的样式，跟主题CSS完全隔离（不用pga-前缀，避免被
                   任何主题的选择器意外命中），固定在顶部，不占据页面文档流。 */
                #pga-preview-bar {
                    position: fixed; top: 0; left: 0; right: 0; z-index: 999999;
                    box-sizing: border-box; width: 100%; margin: 0; padding: 10px 16px;
                    background: #1d2327; color: #fff;
                    font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px;
                }
                #pga-preview-bar a { color: #8ab4f8; text-decoration: none; }
                #pga-preview-bar a:hover { text-decoration: underline; }
                #pga-preview-spacer { height: 44px; }
            </style>
        </head>
        <body class="pga-payment-page">
            <div id="pga-preview-bar">
                <span><?php echo esc_html(sprintf(__('预览：%s（示例数据，非真实订单）', 'sscpay-gateway-aggregator'), $theme->getLabel())); ?></span>
                <span>
                    <?php if ($view === 'loading') : ?>
                        <a href="<?php echo esc_url(self::previewUrl($slug, 'invoice')); ?>"><?php esc_html_e('← 查看发票页', 'sscpay-gateway-aggregator'); ?></a>
                    <?php else : ?>
                        <a href="<?php echo esc_url(self::previewUrl($slug, 'loading')); ?>"><?php esc_html_e('查看Loading页 →', 'sscpay-gateway-aggregator'); ?></a>
                    <?php endif; ?>
                </span>
            </div>
            <div id="pga-preview-spacer"></div>
            <?php if ($view === 'loading') : ?>
                <?php echo $theme->renderLoadingBody(self::fakeLoadingViewModel()); // phpcs:ignore -- 内部生成的可信HTML ?>
            <?php else : ?>
                <main class="pga-payment-container pga-payment-container--wide">
                    <?php echo $theme->renderInvoiceCard(self::fakeInvoiceViewModel()); // phpcs:ignore -- 内部生成的可信HTML ?>
                </main>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }

    /** 假样例数据，字段形状与 PaymentPage::buildInvoiceViewModel() 保持一致。 */
    private static function fakeInvoiceViewModel()
    {
        $actionsHtml = '<a class="pga-pay-button" href="#" onclick="return false;">'
                . esc_html__('前往支付 (Preview)', 'sscpay-gateway-aggregator') . '</a>'
            . '<a class="pga-button-secondary" href="#" onclick="return false;">'
                . esc_html__('Return to Store', 'sscpay-gateway-aggregator') . '</a>';

        return [
            'invoice_number' => 'INV-PREVIEW-0001',
            'order_no' => 'ORD-PREVIEW-0001',
            'date' => date_i18n(get_option('date_format')),
            'customer_name' => 'John Doe',
            'address_lines' => ['Alexanderplatz 1 Apt. 4B', 'Berlin, Berlin 10178', 'DE'],
            'customer_email' => 'john.doe@example.com',
            'customer_phone' => '+4915112345678',
            'status_badge' => ['label' => __('Awaiting Payment', 'sscpay-gateway-aggregator'), 'type' => 'warning'],
            'totals' => [
                ['label' => __('Subtotal', 'sscpay-gateway-aggregator'), 'value' => 'EUR 380.00', 'grand' => false],
                ['label' => __('Shipping', 'sscpay-gateway-aggregator'), 'value' => 'EUR 15.00', 'grand' => false],
                ['label' => __('Tax', 'sscpay-gateway-aggregator'), 'value' => 'EUR 5.00', 'grand' => false],
                ['label' => __('Discount', 'sscpay-gateway-aggregator'), 'value' => "\u{2212}EUR 10.00", 'grand' => false],
                ['label' => __('Total', 'sscpay-gateway-aggregator'), 'value' => 'EUR 390.00', 'grand' => true],
            ],
            'notice' => [
                'title' => __('Your order is awaiting payment', 'sscpay-gateway-aggregator'),
                'body' => __('Complete payment to confirm your order.', 'sscpay-gateway-aggregator'),
            ],
            'actions_html' => $actionsHtml,
        ];
    }

    private static function fakeLoadingViewModel()
    {
        $logoPath = 'assets/images/logos/creditcard.png';
        $logoUrl = (defined('PGA_PLUGIN_URL') && defined('PGA_PLUGIN_DIR') && is_file(PGA_PLUGIN_DIR . $logoPath))
            ? PGA_PLUGIN_URL . $logoPath
            : '';

        return [
            'logo_url' => $logoUrl,
            'countdown_seconds' => 2,
            'target_url' => '#',
        ];
    }
}
