<?php
/**
 * 日志查看页（对应设计文档v2 第十二节12.2）：按s_order_id检索 + 按级别筛选，
 * 展示时数据已经在落库前经过SensitiveDataFilter过滤，这里直接输出即可，
 * 不需要在展示层再做一次过滤。
 */

namespace PaymentGatewayAggregator\Admin;

use PaymentGatewayAggregator\Core\LogManager;

if (!defined('ABSPATH')) {
    exit;
}

class LogViewer
{
    const PAGE_SLUG = 'pga-logs';

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sOrderId = isset($_GET['s_order_id']) && is_string($_GET['s_order_id'])
            ? sanitize_text_field(wp_unslash($_GET['s_order_id'])) : '';
        $level = isset($_GET['level']) && is_string($_GET['level'])
            ? sanitize_text_field(wp_unslash($_GET['level'])) : '';
        if (!in_array($level, ['INFO', 'WARNING', 'ERROR'], true)) { $level = ''; }
        $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        $result = LogManager::paginate($level ?: null, $page, 50, $sOrderId !== '' ? $sOrderId : null);
        $rows = $result['items'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('支付日志', 'sscpay-gateway-aggregator'); ?></h1>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <input type="hidden" name="paged" value="1">
                <p>
                    <input type="text" name="s_order_id" placeholder="<?php esc_attr_e('按S系统订单号检索', 'sscpay-gateway-aggregator'); ?>" value="<?php echo esc_attr($sOrderId); ?>">
                    <select name="level">
                        <option value=""><?php esc_html_e('全部级别', 'sscpay-gateway-aggregator'); ?></option>
                        <?php foreach (['INFO', 'WARNING', 'ERROR'] as $lvl) : ?>
                            <option value="<?php echo esc_attr($lvl); ?>" <?php selected($level, $lvl); ?>><?php echo esc_html($lvl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e('检索', 'sscpay-gateway-aggregator'); ?></button>
                </p>
            </form>

            <?php self::renderPagination($result, $sOrderId, $level, 'top'); ?>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php esc_html_e('时间', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('级别', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('S订单号', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('支付方式', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('消息', 'sscpay-gateway-aggregator'); ?></th>
                    <th><?php esc_html_e('详情', 'sscpay-gateway-aggregator'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="6"><?php esc_html_e('没有找到日志记录', 'sscpay-gateway-aggregator'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                            <td><span class="pga-log-level pga-log-level-<?php echo esc_attr(strtolower($row['level'])); ?>"><?php echo esc_html($row['level']); ?></span></td>
                            <td><?php echo esc_html($row['s_order_id']); ?></td>
                            <td><?php echo esc_html($row['payment_method'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['message']); ?></td>
                            <td>
                                <?php foreach (['request_data', 'response_data', 'callback_data'] as $field) : ?>
                                    <?php if (!empty($row[$field])) : ?>
                                        <details>
                                            <summary><?php echo esc_html($field); ?></summary>
                                            <pre style="white-space:pre-wrap;max-width:480px;"><?php echo esc_html($row[$field]); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php self::renderPagination($result, $sOrderId, $level, 'bottom'); ?>
        </div>
        <?php
    }

    private static function renderPagination(array $result, $sOrderId, $level, $position)
    {
        $page = $result['page'];
        $totalPages = $result['total_pages'];
        $filters = ['page' => self::PAGE_SLUG, 's_order_id' => $sOrderId, 'level' => $level];
        $inputId = 'pga-log-page-' . $position;
        ?>
        <div class="tablenav <?php echo esc_attr($position); ?>" style="height:auto;min-height:32px">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php printf(esc_html__('共 %1$d 条记录，每页 %2$d 条', 'sscpay-gateway-aggregator'), $result['total'], $result['per_page']); ?></span>
                <?php if ($totalPages > 1) : ?>
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:inline">
                        <?php foreach ($filters as $name => $value) : ?>
                            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
                        <?php endforeach; ?>
                        <span class="pagination-links">
                            <?php self::pageLink(1, __('首页', 'sscpay-gateway-aggregator'), $page <= 1, $filters); ?>
                            <?php self::pageLink($page - 1, __('上一页', 'sscpay-gateway-aggregator'), $page <= 1, $filters); ?>
                            <label class="screen-reader-text" for="<?php echo esc_attr($inputId); ?>"><?php esc_html_e('当前页码', 'sscpay-gateway-aggregator'); ?></label>
                            <span class="paging-input">
                                <input id="<?php echo esc_attr($inputId); ?>" class="current-page" type="number" name="paged" min="1" max="<?php echo (int) $totalPages; ?>" value="<?php echo (int) $page; ?>" style="width:5em" required>
                                / <span class="total-pages"><?php echo (int) $totalPages; ?></span>
                            </span>
                            <button type="submit" class="button"><?php esc_html_e('跳转', 'sscpay-gateway-aggregator'); ?></button>
                            <?php self::pageLink($page + 1, __('下一页', 'sscpay-gateway-aggregator'), $page >= $totalPages, $filters); ?>
                            <?php self::pageLink($totalPages, __('末页', 'sscpay-gateway-aggregator'), $page >= $totalPages, $filters); ?>
                        </span>
                    </form>
                <?php endif; ?>
            </div>
            <br class="clear">
        </div>
        <?php
    }

    private static function pageLink($page, $label, $disabled, array $filters)
    {
        if ($disabled) {
            echo '<span class="tablenav-pages-navspan button disabled" aria-disabled="true">' . esc_html($label) . '</span> ';
            return;
        }
        $url = add_query_arg(array_merge($filters, ['paged' => $page]), admin_url('admin.php'));
        echo '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
    }
}
