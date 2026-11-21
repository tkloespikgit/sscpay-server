<?php
/**
 * 主题基类：提供三个模板方法的默认实现（原PaymentPage里发票卡片/loading页/
 * 按钮的markup，逐字迁移），以及CSS地址的通用解析逻辑。
 *
 * 新增一个"纯换肤"的主题（颜色/间距/圆角不同，但DOM结构/class命名沿用
 * 这里的默认markup）时，继承这个类，只需实现 getSlug()/getLabel()/
 * getStylesheetRelativePath() 三个方法，不用重写任何HTML。当前登记的几套
 * 主题（Paper/Split/Bento/Midnight/Editorial/Ticket）设计差异较大，
 * 都各自重写了 renderInvoiceCard()，没有直接复用这份默认实现的例子。
 *
 * 如果某个主题需要彻底不同的DOM结构（不只是换CSS），直接在子类里重写
 * renderInvoiceCard()/renderLoadingBody()/renderActionButton()三个方法即可，
 * ThemeInterface契约不限制具体标签结构，只要求 renderLoadingBody() 里
 * 保留 #pga-jump-link / #pga-countdown-num 这两个id（见ThemeInterface注释）。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractTheme implements ThemeInterface
{
    /** 相对PGA_PLUGIN_DIR的CSS文件路径，例如 'assets/css/themes/classic.css'。 */
    abstract protected function getStylesheetRelativePath();

    public function getStylesheetUrl()
    {
        $path = $this->getStylesheetRelativePath();

        if (!defined('PGA_PLUGIN_URL') || !is_file(PGA_PLUGIN_DIR . $path)) {
            return '';
        }

        // 带文件修改时间做版本号：静态资源被nginx设置了超长缓存，
        // 不加版本号时浏览器可能长期命中旧响应（包括文件不存在时缓存的404）。
        return PGA_PLUGIN_URL . $path . '?v=' . filemtime(PGA_PLUGIN_DIR . $path);
    }

    public function renderInvoiceCard(array $viewModel)
    {
        ob_start();
        ?>
        <div class="pga-invoice">
            <div class="pga-invoice-header">
                <div class="pga-invoice-label"><?php esc_html_e('Invoice', 'sscpay-gateway-aggregator'); ?></div>
                <?php if (!empty($viewModel['status_badge'])) : ?>
                    <span class="pga-status-badge pga-status-badge--<?php echo esc_attr($viewModel['status_badge']['type']); ?>">
                        <?php echo esc_html($viewModel['status_badge']['label']); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="pga-invoice-meta">
                <div class="pga-invoice-meta-item">
                    <span class="pga-invoice-meta-label"><?php esc_html_e('Invoice Number', 'sscpay-gateway-aggregator'); ?></span>
                    <span class="pga-invoice-meta-value"><?php echo esc_html($viewModel['invoice_number']); ?></span>
                </div>
                <div class="pga-invoice-meta-item">
                    <span class="pga-invoice-meta-label"><?php esc_html_e('Order No.', 'sscpay-gateway-aggregator'); ?></span>
                    <span class="pga-invoice-meta-value"><?php echo esc_html($viewModel['order_no']); ?></span>
                </div>
                <?php if ($viewModel['date'] !== '') : ?>
                    <div class="pga-invoice-meta-item">
                        <span class="pga-invoice-meta-label"><?php esc_html_e('Date', 'sscpay-gateway-aggregator'); ?></span>
                        <span class="pga-invoice-meta-value"><?php echo esc_html($viewModel['date']); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pga-invoice-party">
                <div class="pga-invoice-party-label"><?php esc_html_e('Billed To', 'sscpay-gateway-aggregator'); ?></div>
                <div class="pga-invoice-party-name"><?php echo esc_html($viewModel['customer_name']); ?></div>
                <?php foreach ($viewModel['address_lines'] as $line) : ?>
                    <div class="pga-invoice-party-line"><?php echo esc_html($line); ?></div>
                <?php endforeach; ?>
                <div class="pga-invoice-party-line"><?php echo esc_html($viewModel['customer_email']); ?></div>
                <?php if ($viewModel['customer_phone'] !== '') : ?>
                    <div class="pga-invoice-party-line"><?php echo esc_html($viewModel['customer_phone']); ?></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($viewModel['notice'])) : ?>
                <div class="pga-notice<?php echo !empty($viewModel['status_badge']['type']) ? ' pga-notice--' . esc_attr($viewModel['status_badge']['type']) : ''; ?>">
                    <strong><?php echo esc_html($viewModel['notice']['title']); ?></strong>
                    <span><?php echo esc_html($viewModel['notice']['body']); ?></span>
                </div>
            <?php endif; ?>

            <div class="pga-invoice-totals">
                <?php foreach ($viewModel['totals'] as $row) : ?>
                    <div class="pga-invoice-total-row<?php echo !empty($row['grand']) ? ' pga-invoice-total-row--grand' : ''; ?>">
                        <span><?php echo esc_html($row['label']); ?></span>
                        <span><?php echo esc_html($row['value']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($viewModel['actions_html'])) : ?>
                <div class="pga-invoice-actions"><?php echo $viewModel['actions_html']; // phpcs:ignore -- 调用方已拼好的可信HTML ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderLoadingBody(array $viewModel)
    {
        ob_start();
        ?>
        <main class="pga-loading-page">
            <?php if ($viewModel['logo_url']) : ?>
                <img class="pga-loading-logo" src="<?php echo esc_url($viewModel['logo_url']); ?>" alt="">
            <?php endif; ?>
            <div class="pga-loading-spinner" aria-hidden="true"></div>
            <p class="pga-loading-text">
                <?php
                printf(
                    /* translators: %s: 包裹剩余秒数的span（主题自己生成的可信HTML） */
                    esc_html__('Redirecting you to the payment page in %s seconds…', 'sscpay-gateway-aggregator'),
                    '<span id="pga-countdown-num">' . esc_html((string) $viewModel['countdown_seconds']) . '</span>' // phpcs:ignore -- 内部生成的可信HTML
                );
                ?>
            </p>
            <a id="pga-jump-link" class="pga-jump-link" href="<?php echo esc_url($viewModel['target_url']); ?>" rel="noopener">
                <?php esc_html_e('If you are not redirected automatically, click here to continue.', 'sscpay-gateway-aggregator'); ?>
            </a>
        </main>
        <?php
        return ob_get_clean();
    }

    public function renderActionButton(array $viewModel)
    {
        $class = !empty($viewModel['primary']) ? 'pga-pay-button' : 'pga-button-secondary';

        return '<a class="' . $class . '" href="' . esc_url($viewModel['url']) . '" rel="noopener">'
            . esc_html($viewModel['label']) . '</a>';
    }
}
