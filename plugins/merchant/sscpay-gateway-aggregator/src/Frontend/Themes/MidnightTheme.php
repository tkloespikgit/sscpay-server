<?php
/**
 * Midnight主题：深色科技感发票卡片，两栏grid布局（原型见 src/Frontend/Tpl/checkout-06-midnight.html）。
 *
 * 彻底重写DOM结构，不复用AbstractTheme的默认renderInvoiceCard()——按ThemeInterface
 * 的约定，卡片自己决定notice提示文案和actions_html操作按钮放在哪，这套设计把
 * notice放在头部下方、actions放在右栏底部（贴着求和列表/总额）。
 *
 * loading页/操作按钮沿用AbstractTheme的默认markup（class名与这里的CSS对得上），
 * 不需要重写。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

class MidnightTheme extends AbstractTheme
{
    public function getSlug()
    {
        return 'midnight';
    }

    public function getLabel()
    {
        return __('Midnight（暗色科技感发票）', 'sscpay-gateway-aggregator');
    }

    protected function getStylesheetRelativePath()
    {
        return 'assets/css/themes/midnight.css';
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
            <div class="pga-brand">
                <span class="pga-logo">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 3 2V2l-3 2-3-2-3 2-3-2-3 2z"/><path d="M8 9h8"/><path d="M8 13h6"/></svg>
                </span>
                <h1><?php esc_html_e('Invoice', 'sscpay-gateway-aggregator'); ?></h1>
            </div>
            <?php if (!empty($viewModel['status_badge'])) : ?>
                <span class="pga-status-badge pga-status-badge--<?php echo esc_attr($badgeType); ?>">
                    <i></i><?php echo esc_html($viewModel['status_badge']['label']); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($viewModel['notice'])) : ?>
            <div class="pga-notice pga-notice--<?php echo esc_attr($badgeType); ?>" role="status">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <div><strong><?php echo esc_html($viewModel['notice']['title']); ?></strong> <span><?php echo esc_html($viewModel['notice']['body']); ?></span></div>
            </div>
        <?php endif; ?>

        <div class="pga-cols">
            <div class="pga-left">
                <div>
                    <p class="pga-k"><?php esc_html_e('Invoice number', 'sscpay-gateway-aggregator'); ?></p>
                    <p class="pga-v pga-mono"><?php echo esc_html($viewModel['invoice_number']); ?></p>
                </div>
                <div class="pga-pair">
                    <div>
                        <p class="pga-k"><?php esc_html_e('Order no.', 'sscpay-gateway-aggregator'); ?></p>
                        <p class="pga-v pga-mono"><?php echo esc_html($viewModel['order_no']); ?></p>
                    </div>
                    <?php if ($viewModel['date'] !== '') : ?>
                        <div>
                            <p class="pga-k"><?php esc_html_e('Date', 'sscpay-gateway-aggregator'); ?></p>
                            <p class="pga-v"><?php echo esc_html($viewModel['date']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="pga-box">
                    <p class="pga-k"><?php esc_html_e('Billed to', 'sscpay-gateway-aggregator'); ?></p>
                    <address>
                        <b><?php echo esc_html($viewModel['customer_name']); ?></b><br>
                        <?php foreach ($viewModel['address_lines'] as $line) : ?>
                            <?php echo esc_html($line); ?><br>
                        <?php endforeach; ?>
                        <?php echo esc_html($viewModel['customer_email']); ?>
                        <?php if ($viewModel['customer_phone'] !== '') : ?>
                            <br><?php echo esc_html($viewModel['customer_phone']); ?>
                        <?php endif; ?>
                    </address>
                </div>
            </div>

            <div class="pga-right pga-box">
                <div class="pga-sum pga-mono">
                    <?php foreach ($viewModel['totals'] as $row) : ?>
                        <?php if (!empty($row['grand'])) : continue; endif; ?>
                        <div class="pga-row">
                            <span><?php echo esc_html($row['label']); ?></span>
                            <span><?php echo esc_html($row['value']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($grandRow) : ?>
                    <div class="pga-total">
                        <p class="pga-k"><?php esc_html_e('Total due', 'sscpay-gateway-aggregator'); ?></p>
                        <div class="pga-num"><?php echo esc_html($grandRow['value']); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($viewModel['actions_html'])) : ?>
                    <div class="pga-invoice-actions"><?php echo $viewModel['actions_html']; // phpcs:ignore -- 调用方已拼好的可信HTML ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
