<?php
/**
 * Bento主题：浅紫色调网格拼贴发票卡片（原型见 src/Frontend/Tpl/checkout-05-bento.html）。
 *
 * 彻底重写DOM结构，不复用AbstractTheme的默认renderInvoiceCard()——这套设计把
 * 原本一整张卡片拆成多张网格卡片（notice横跨全宽、title卡片+id chip、紫色
 * pay-card竖跨两行放总额与操作按钮、billed-to和summary各自成卡），按
 * ThemeInterface的约定由主题自己决定notice和actions_html放在哪。
 *
 * loading页/操作按钮沿用AbstractTheme的默认markup（class名与这里的CSS对得上），
 * 不需要重写。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

class BentoTheme extends AbstractTheme
{
    public function getSlug()
    {
        return 'bento';
    }

    public function getLabel()
    {
        return __('Bento（网格卡片拼贴发票）', 'sscpay-gateway-aggregator');
    }

    protected function getStylesheetRelativePath()
    {
        return 'assets/css/themes/bento.css';
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
        $initial = $viewModel['customer_name'] !== '' ? mb_substr($viewModel['customer_name'], 0, 1) : '';

        ob_start();
        ?>
        <?php if (!empty($viewModel['notice'])) : ?>
            <div class="pga-notice pga-notice--<?php echo esc_attr($badgeType); ?>" role="status">
                <span class="pga-notice-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                <div><strong><?php echo esc_html($viewModel['notice']['title']); ?></strong><?php echo esc_html($viewModel['notice']['body']); ?></div>
            </div>
        <?php endif; ?>

        <div class="pga-card pga-bento-head">
            <div class="pga-title-row">
                <h1><?php esc_html_e('Invoice', 'sscpay-gateway-aggregator'); ?></h1>
                <?php if (!empty($viewModel['status_badge'])) : ?>
                    <span class="pga-status-badge pga-status-badge--<?php echo esc_attr($badgeType); ?>">
                        <i></i><?php echo esc_html($viewModel['status_badge']['label']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="pga-bento-ids">
                <div class="pga-chip">
                    <p class="pga-k"><?php esc_html_e('Invoice Number', 'sscpay-gateway-aggregator'); ?></p>
                    <p class="pga-v"><?php echo esc_html($viewModel['invoice_number']); ?></p>
                </div>
                <div class="pga-chip">
                    <p class="pga-k"><?php esc_html_e('Order No.', 'sscpay-gateway-aggregator'); ?></p>
                    <p class="pga-v"><?php echo esc_html($viewModel['order_no']); ?></p>
                </div>
            </div>
        </div>

        <div class="pga-card pga-bento-pay">
            <span class="pga-dim"><?php esc_html_e('Total due', 'sscpay-gateway-aggregator'); ?></span>
            <?php if ($grandRow) : ?>
                <div class="pga-bento-amount">
                    <span class="pga-dim pga-bento-amount-num"><?php echo esc_html($grandRow['value']); ?></span>
                    <?php if ($viewModel['date'] !== '') : ?>
                        <span class="pga-dim" style="font-weight:500"><?php echo esc_html($viewModel['date']); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($viewModel['actions_html'])) : ?>
                <div class="pga-invoice-actions"><?php echo $viewModel['actions_html']; // phpcs:ignore -- 调用方已拼好的可信HTML ?></div>
            <?php endif; ?>
        </div>

        <div class="pga-card pga-bento-billed">
            <p class="pga-k"><?php esc_html_e('Billed To', 'sscpay-gateway-aggregator'); ?></p>
            <div class="pga-person">
                <span class="pga-avatar"><?php echo esc_html($initial); ?></span>
                <b><?php echo esc_html($viewModel['customer_name']); ?></b>
            </div>
            <address>
                <?php foreach ($viewModel['address_lines'] as $line) : ?>
                    <?php echo esc_html($line); ?><br>
                <?php endforeach; ?>
                <?php echo esc_html($viewModel['customer_email']); ?><br>
                <?php if ($viewModel['customer_phone'] !== '') : ?>
                    <?php echo esc_html($viewModel['customer_phone']); ?>
                <?php endif; ?>
            </address>
        </div>

        <div class="pga-card pga-bento-sum">
            <div class="pga-sum">
                <p class="pga-k" style="margin:0"><?php esc_html_e('Summary', 'sscpay-gateway-aggregator'); ?></p>
                <?php foreach ($viewModel['totals'] as $row) : ?>
                    <div class="pga-row<?php echo !empty($row['grand']) ? ' pga-row--total' : ''; ?>">
                        <span><?php echo esc_html($row['label']); ?></span>
                        <span><?php echo esc_html($row['value']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
