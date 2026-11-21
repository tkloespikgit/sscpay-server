<?php
/**
 * Paper主题：暖白纸感发票卡片，居中单栏布局（原型见 src/Frontend/Tpl/checkout-01-paper.html）。
 *
 * 彻底重写DOM结构，不复用AbstractTheme的默认renderInvoiceCard()——按ThemeInterface
 * 的约定，卡片自己决定notice提示文案和actions_html操作按钮放在哪，这套设计把
 * 两者都放进同一张卡片内部（notice在顶部、actions在底部），不是分开的独立区块。
 *
 * loading页/操作按钮沿用AbstractTheme的默认markup（class名与这里的CSS对得上），
 * 不需要重写。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

class PaperTheme extends AbstractTheme
{
    public function getSlug()
    {
        return 'paper';
    }

    public function getLabel()
    {
        return __('Paper（暖白纸感发票）', 'sscpay-gateway-aggregator');
    }

    protected function getStylesheetRelativePath()
    {
        return 'assets/css/themes/paper.css';
    }

    public function renderInvoiceCard(array $viewModel)
    {
        $grandRow = null;
        foreach ($viewModel['totals'] as $row) {
            if (!empty($row['grand'])) {
                $grandRow = $row;
            }
        }
        $badgeType = $viewModel['status_badge']['type'] ?? 'warning';

        ob_start();
        ?>
        <div class="pga-head">
            <span class="pga-eyebrow"><?php esc_html_e('Invoice', 'sscpay-gateway-aggregator'); ?></span>
            <?php if (!empty($viewModel['status_badge'])) : ?>
                <span class="pga-status-badge pga-status-badge--<?php echo esc_attr($badgeType); ?>">
                    <i></i><?php echo esc_html($viewModel['status_badge']['label']); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($viewModel['notice'])) : ?>
            <div class="pga-notice pga-notice--<?php echo esc_attr($badgeType); ?>" role="status">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <div><strong><?php echo esc_html($viewModel['notice']['title']); ?></strong><?php echo esc_html($viewModel['notice']['body']); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($grandRow) : ?>
            <div class="pga-due">
                <span class="pga-due-label"><?php esc_html_e('Total due', 'sscpay-gateway-aggregator'); ?></span>
                <span class="pga-due-amount"><?php echo esc_html($grandRow['value']); ?></span>
                <?php if ($viewModel['date'] !== '') : ?>
                    <span class="pga-due-date"><?php echo esc_html($viewModel['date']); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="pga-info">
            <div>
                <p class="pga-k"><?php esc_html_e('Invoice Number', 'sscpay-gateway-aggregator'); ?></p>
                <p class="pga-v"><?php echo esc_html($viewModel['invoice_number']); ?></p>
                <p class="pga-k"><?php esc_html_e('Order No.', 'sscpay-gateway-aggregator'); ?></p>
                <p class="pga-v" style="margin-bottom:0"><?php echo esc_html($viewModel['order_no']); ?></p>
            </div>
            <address>
                <p class="pga-k"><?php esc_html_e('Billed To', 'sscpay-gateway-aggregator'); ?></p>
                <b><?php echo esc_html($viewModel['customer_name']); ?></b><br>
                <?php foreach ($viewModel['address_lines'] as $line) : ?>
                    <?php echo esc_html($line); ?><br>
                <?php endforeach; ?>
                <span class="pga-m"><?php echo esc_html($viewModel['customer_email']); ?></span>
                <?php if ($viewModel['customer_phone'] !== '') : ?>
                    <br><span class="pga-m"><?php echo esc_html($viewModel['customer_phone']); ?></span>
                <?php endif; ?>
            </address>
        </div>

        <div class="pga-sum">
            <?php foreach ($viewModel['totals'] as $row) : ?>
                <div class="pga-row<?php echo !empty($row['grand']) ? ' pga-row--total' : ''; ?>">
                    <span><?php echo esc_html($row['label']); ?></span>
                    <span><?php echo esc_html($row['value']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($viewModel['actions_html'])) : ?>
            <div class="pga-invoice-actions"><?php echo $viewModel['actions_html']; // phpcs:ignore -- 调用方已拼好的可信HTML ?></div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
