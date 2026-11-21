<?php
/**
 * Editorial主题：报刊排版风发票卡片，masthead+三栏分栏布局
 * （原型见 src/Frontend/Tpl/checkout-07-editorial.html）。
 *
 * 彻底重写DOM结构，不复用AbstractTheme的默认renderInvoiceCard()——这套设计
 * 把notice做成引用式提示条、把明细/收件人/汇总拆成三栏报纸排版，跟默认的
 * 纵向堆叠结构完全不同。CSS负责把 .pga-payment-container--wide 直接改造成
 * 原型里的 .page 容器，这里不再额外套一层包装div。
 *
 * loading页/操作按钮沿用AbstractTheme的默认markup（class名与这里的CSS对得上），
 * 不需要重写。
 */

namespace PaymentGatewayAggregator\Frontend\Themes;

if (!defined('ABSPATH')) {
    exit;
}

class EditorialTheme extends AbstractTheme
{
    public function getSlug()
    {
        return 'editorial';
    }

    public function getLabel()
    {
        return __('Editorial（报刊排版风发票）', 'sscpay-gateway-aggregator');
    }

    protected function getStylesheetRelativePath()
    {
        return 'assets/css/themes/editorial.css';
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
        <header class="pga-mast">
            <div>
                <div class="pga-no"><?php echo esc_html($viewModel['order_no']); ?></div>
                <h1 class="pga-serif"><?php esc_html_e('Invoice', 'sscpay-gateway-aggregator'); ?></h1>
            </div>
            <div class="pga-meta">
                <?php if (!empty($viewModel['status_badge'])) : ?>
                    <span class="pga-status-badge pga-status-badge--<?php echo esc_attr($badgeType); ?>">
                        <?php echo esc_html($viewModel['status_badge']['label']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($viewModel['date'] !== '') : ?>
                    <span class="pga-date pga-serif"><?php echo esc_html($viewModel['date']); ?></span>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($viewModel['notice'])) : ?>
            <div class="pga-notice pga-notice--<?php echo esc_attr($badgeType); ?>" role="status">
                <span class="pga-notice-lbl"><?php esc_html_e('Notice', 'sscpay-gateway-aggregator'); ?></span>
                <p class="pga-serif"><strong><?php echo esc_html($viewModel['notice']['title']); ?></strong> <?php echo esc_html($viewModel['notice']['body']); ?></p>
            </div>
        <?php endif; ?>

        <div class="pga-cols">
            <div class="pga-col">
                <h2 class="pga-sec pga-serif"><?php esc_html_e('i. Details', 'sscpay-gateway-aggregator'); ?></h2>
                <p class="pga-k"><?php esc_html_e('Invoice Number', 'sscpay-gateway-aggregator'); ?></p>
                <p class="pga-v"><?php echo esc_html($viewModel['invoice_number']); ?></p>
                <p class="pga-k"><?php esc_html_e('Order No.', 'sscpay-gateway-aggregator'); ?></p>
                <p class="pga-v"><?php echo esc_html($viewModel['order_no']); ?></p>
                <?php if ($viewModel['date'] !== '') : ?>
                    <p class="pga-k"><?php esc_html_e('Date', 'sscpay-gateway-aggregator'); ?></p>
                    <p class="pga-v" style="margin-bottom:0"><?php echo esc_html($viewModel['date']); ?></p>
                <?php endif; ?>
            </div>
            <div class="pga-col">
                <h2 class="pga-sec pga-serif"><?php esc_html_e('ii. Billed to', 'sscpay-gateway-aggregator'); ?></h2>
                <p class="pga-name pga-serif"><?php echo esc_html($viewModel['customer_name']); ?></p>
                <address>
                    <?php foreach ($viewModel['address_lines'] as $line) : ?>
                        <?php echo esc_html($line); ?><br>
                    <?php endforeach; ?>
                    <span class="pga-m"><?php echo esc_html($viewModel['customer_email']); ?></span>
                    <?php if ($viewModel['customer_phone'] !== '') : ?>
                        <br><span class="pga-m"><?php echo esc_html($viewModel['customer_phone']); ?></span>
                    <?php endif; ?>
                </address>
            </div>
            <div class="pga-col">
                <h2 class="pga-sec pga-serif"><?php esc_html_e('iii. Summary', 'sscpay-gateway-aggregator'); ?></h2>
                <?php foreach ($viewModel['totals'] as $row) : ?>
                    <?php if (!empty($row['grand'])) : continue; endif; ?>
                    <div class="pga-row">
                        <span><?php echo esc_html($row['label']); ?></span>
                        <span><?php echo esc_html($row['value']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <footer class="pga-foot">
            <?php if ($grandRow) : ?>
                <div class="pga-tot">
                    <span class="pga-tot-lbl pga-serif"><?php esc_html_e('Total', 'sscpay-gateway-aggregator'); ?></span>
                    <span class="pga-tot-num pga-serif"><?php echo esc_html($grandRow['value']); ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($viewModel['actions_html'])) : ?>
                <div class="pga-invoice-actions"><?php echo $viewModel['actions_html']; // phpcs:ignore -- 调用方已拼好的可信HTML ?></div>
            <?php endif; ?>
        </footer>
        <?php
        return ob_get_clean();
    }
}
