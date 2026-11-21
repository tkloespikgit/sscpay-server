<?php
/**
 * Ticket主题：橙色背景+车票造型发票卡片，左侧main区放明细、右侧深色stub
 * 票根区（圆孔缺口视觉+虚线分割）放日期/总额/操作按钮（原型见
 * src/Frontend/Tpl/checkout-08-ticket.html）。
 *
 * 彻底重写DOM结构，不复用AbstractTheme的默认renderInvoiceCard()——这套设计
 * 把notice放在车票外部顶端、actions放进票根区内部，跟Paper主题（notice+
 * actions都在同一张卡片内）、默认AbstractTheme（notice+actions都在卡片内部
 * 底部）都不一样。
 *
 * loading页/操作按钮沿用AbstractTheme的默认markup（class名与这里的CSS对得上），
 * 不需要重写。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

class TicketTheme extends AbstractTheme
{
    public function getSlug()
    {
        return 'ticket';
    }

    public function getLabel()
    {
        return __('Ticket（车票根风格发票）', 'sscpay-gateway-aggregator');
    }

    protected function getStylesheetRelativePath()
    {
        return 'assets/css/themes/ticket.css';
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
        <?php if (!empty($viewModel['notice'])) : ?>
            <div class="pga-notice pga-notice--<?php echo esc_attr($badgeType); ?>" role="status">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <div><strong><?php echo esc_html($viewModel['notice']['title']); ?></strong> <?php echo esc_html($viewModel['notice']['body']); ?></div>
            </div>
        <?php endif; ?>

        <div class="pga-ticket">
            <section class="pga-ticket-main">
                <div class="pga-ticket-head">
                    <h1><?php esc_html_e('Invoice', 'sscpay-gateway-aggregator'); ?></h1>
                    <?php if (!empty($viewModel['status_badge'])) : ?>
                        <span class="pga-status-badge pga-status-badge--<?php echo esc_attr($badgeType); ?>">
                            <?php echo esc_html($viewModel['status_badge']['label']); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="pga-ticket-grid">
                    <div>
                        <p class="pga-k"><?php esc_html_e('Invoice Number', 'sscpay-gateway-aggregator'); ?></p>
                        <p class="pga-v pga-mono"><?php echo esc_html($viewModel['invoice_number']); ?></p>
                    </div>
                    <div>
                        <p class="pga-k"><?php esc_html_e('Order No.', 'sscpay-gateway-aggregator'); ?></p>
                        <p class="pga-v pga-mono"><?php echo esc_html($viewModel['order_no']); ?></p>
                    </div>
                    <div>
                        <p class="pga-k"><?php esc_html_e('Billed To', 'sscpay-gateway-aggregator'); ?></p>
                        <address>
                            <b><?php echo esc_html($viewModel['customer_name']); ?></b><br>
                            <?php foreach ($viewModel['address_lines'] as $line) : ?>
                                <?php echo esc_html($line); ?><br>
                            <?php endforeach; ?>
                        </address>
                    </div>
                    <div>
                        <p class="pga-k"><?php esc_html_e('Contact', 'sscpay-gateway-aggregator'); ?></p>
                        <address>
                            <?php echo esc_html($viewModel['customer_email']); ?>
                            <?php if ($viewModel['customer_phone'] !== '') : ?>
                                <br><?php echo esc_html($viewModel['customer_phone']); ?>
                            <?php endif; ?>
                        </address>
                    </div>
                </div>

                <div class="pga-ticket-sum pga-mono">
                    <?php foreach ($viewModel['totals'] as $row) : ?>
                        <?php if (!empty($row['grand'])) : continue; endif; ?>
                        <div><span><?php echo esc_html($row['label']); ?></span><span><?php echo esc_html($row['value']); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </section>

            <aside class="pga-ticket-stub">
                <div class="pga-ticket-top-row">
                    <?php if ($viewModel['date'] !== '') : ?>
                        <div>
                            <p class="pga-k"><?php esc_html_e('Date', 'sscpay-gateway-aggregator'); ?></p>
                            <div class="pga-ticket-date"><?php echo esc_html($viewModel['date']); ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($grandRow) : ?>
                        <div>
                            <p class="pga-k"><?php echo esc_html($grandRow['label']); ?></p>
                            <div class="pga-ticket-price"><?php echo esc_html($grandRow['value']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($viewModel['actions_html'])) : ?>
                    <div class="pga-invoice-actions"><?php echo $viewModel['actions_html']; // phpcs:ignore -- 调用方已拼好的可信HTML ?></div>
                <?php endif; ?>
            </aside>
        </div>
        <?php
        return ob_get_clean();
    }
}
