<?php
/**
 * Split主题：深色侧栏 + 白色发票明细的分屏布局（原型见 src/Frontend/Tpl/checkout-02-split.html）。
 *
 * 彻底重写DOM结构，不复用AbstractTheme的默认renderInvoiceCard()——这套设计把
 * "总额+操作按钮+订单号"放进左侧深色栏，"提示条+发票明细+费用汇总"放进右侧
 * 主区域，两者是并列的兄弟节点（<aside>+<main>），不是单张卡片。
 *
 * renderInvoiceCard()返回的这两个兄弟节点直接就是split.css里`.pga-payment-container--wide`
 * 分屏网格（grid-template-columns）的两个格子，PaymentPage::renderShell()负责在外面
 * 包`<main class="pga-payment-container pga-payment-container--wide">`，这里不用再
 * 额外包一层容器div。
 *
 * loading页/操作按钮沿用AbstractTheme的默认markup（class名与split.css对得上），
 * 不需要重写。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

class SplitTheme extends AbstractTheme
{
    public function getSlug()
    {
        return 'split';
    }

    public function getLabel()
    {
        return __('Split（深色侧栏分屏发票）', 'sscpay-gateway-aggregator');
    }

    protected function getStylesheetRelativePath()
    {
        return 'assets/css/themes/split.css';
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
        <aside class="pga-split-side">
            <div class="pga-split-brand">
                <span class="pga-split-eyebrow"><?php esc_html_e('Invoice', 'sscpay-gateway-aggregator'); ?></span>
                <?php if (!empty($viewModel['status_badge'])) : ?>
                    <span class="pga-status-badge pga-status-badge--<?php echo esc_attr($badgeType); ?>">
                        <?php echo esc_html($viewModel['status_badge']['label']); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="pga-split-side-mid">
                <?php if ($grandRow) : ?>
                    <div class="pga-split-total-block">
                        <span class="pga-split-dim"><?php esc_html_e('Total', 'sscpay-gateway-aggregator'); ?></span>
                        <span class="pga-split-total-amount"><?php echo esc_html($grandRow['value']); ?></span>
                        <?php if ($viewModel['date'] !== '') : ?>
                            <span class="pga-split-dim"><?php echo esc_html($viewModel['date']); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($viewModel['actions_html'])) : ?>
                    <div class="pga-invoice-actions"><?php echo $viewModel['actions_html']; // phpcs:ignore -- 调用方已拼好的可信HTML ?></div>
                <?php endif; ?>
            </div>

            <div class="pga-split-order">
                <span><?php esc_html_e('Order no.', 'sscpay-gateway-aggregator'); ?></span>
                <b><?php echo esc_html($viewModel['order_no']); ?></b>
            </div>
        </aside>

        <main class="pga-split-main">
            <?php if (!empty($viewModel['notice'])) : ?>
                <div class="pga-notice pga-notice--<?php echo esc_attr($badgeType); ?>" role="status">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <div><strong><?php echo esc_html($viewModel['notice']['title']); ?></strong><?php echo esc_html($viewModel['notice']['body']); ?></div>
                </div>
            <?php endif; ?>

            <div class="pga-split-grid2">
                <div>
                    <p class="pga-split-k"><?php esc_html_e('Invoice Number', 'sscpay-gateway-aggregator'); ?></p>
                    <p class="pga-split-v"><?php echo esc_html($viewModel['invoice_number']); ?></p>
                </div>
                <?php if ($viewModel['date'] !== '') : ?>
                    <div>
                        <p class="pga-split-k"><?php esc_html_e('Date', 'sscpay-gateway-aggregator'); ?></p>
                        <p class="pga-split-v"><?php echo esc_html($viewModel['date']); ?></p>
                    </div>
                <?php endif; ?>
                <div class="pga-split-mobile-only">
                    <p class="pga-split-k"><?php esc_html_e('Order No.', 'sscpay-gateway-aggregator'); ?></p>
                    <p class="pga-split-v"><?php echo esc_html($viewModel['order_no']); ?></p>
                </div>
            </div>

            <section class="pga-split-section">
                <p class="pga-split-k"><?php esc_html_e('Billed To', 'sscpay-gateway-aggregator'); ?></p>
                <h2 class="pga-split-name"><?php echo esc_html($viewModel['customer_name']); ?></h2>
                <div class="pga-split-grid2">
                    <address>
                        <?php foreach ($viewModel['address_lines'] as $line) : ?>
                            <?php echo esc_html($line); ?><br>
                        <?php endforeach; ?>
                    </address>
                    <address>
                        <?php echo esc_html($viewModel['customer_email']); ?>
                        <?php if ($viewModel['customer_phone'] !== '') : ?>
                            <br><?php echo esc_html($viewModel['customer_phone']); ?>
                        <?php endif; ?>
                    </address>
                </div>
            </section>

            <section class="pga-split-sum">
                <?php foreach ($viewModel['totals'] as $row) : ?>
                    <div class="pga-split-row<?php echo !empty($row['grand']) ? ' pga-split-row--total' : ''; ?>">
                        <span><?php echo esc_html($row['label']); ?></span>
                        <span><?php echo esc_html($row['value']); ?></span>
                    </div>
                <?php endforeach; ?>
            </section>
        </main>
        <?php
        return ob_get_clean();
    }
}
