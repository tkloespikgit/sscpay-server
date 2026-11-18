<?php
namespace Sscpay\OpenCart;

final class Text {
    public static function all(string $language, string $channel): array {
        $zh = strpos(strtolower($language), 'zh') === 0;
        $text = [
            'heading_title' => $channel === 'paypal' ? 'SSCPay PayPal' : ($zh ? 'SSCPay 信用卡' : 'SSCPay Credit Card'),
            'text_extension' => $zh ? '扩展' : 'Extensions',
            'text_success' => $zh ? '操作成功。' : 'Operation completed.',
            'text_edit' => $zh ? '支付配置' : 'Payment settings',
            'button_save' => $zh ? '保存' : 'Save', 'button_cancel' => $zh ? '返回' : 'Back',
            'button_confirm' => $zh ? '前往付款' : 'Pay securely',
            'button_refresh' => $zh ? '刷新支付状态' : 'Refresh payment status',
            'button_continue' => $zh ? '继续购物' : 'Continue shopping',
            'text_progress' => $zh ? '支付进度' : 'Payment progress',
            'text_paid' => $zh ? '已付款' : 'Paid', 'text_remaining' => $zh ? '待付款' : 'Remaining',
            'text_status' => $zh ? '状态' : 'Status',
            'text_enabled' => $zh ? '启用' : 'Enabled', 'text_disabled' => $zh ? '停用' : 'Disabled',
            'text_all_zones' => $zh ? '所有区域' : 'All zones',
            'text_key_hint' => $zh ? '留空保留已保存的密钥。' : 'Leave blank to keep the saved key.',
            'text_combined' => $zh ? '组合付款请留空指定渠道，并在支付组开启组合付款。部分付款不会完成商城订单。' : 'For combined payments, leave the channel blank and enable combined payments in the payment group. Partial payments do not complete the store order.',
            'text_status_hint' => $zh ? '已付款状态必须属于系统的处理中或完成状态；其他映射必须属于未付款状态。建议为部分付款、退款和拒付建立独立状态。' : 'Paid must be a configured processing or complete status. All other mappings must be unpaid statuses. Separate partial, refunded and chargeback statuses are recommended.',
            'text_notify_hint' => $zh ? '支付通知地址由插件自动发送。需要接收物流回传时，在 SSCPay 应用中配置以下物流地址。多店铺应使用各店的 HTTPS 域名。' : 'The payment callback is sent automatically. To receive shipment updates, configure this shipment URL on the SSCPay application. Use each store HTTPS domain for multiple stores.',
            'text_tools' => $zh ? '订单查询与物流同步' : 'Order lookup and shipment sync',
            'text_query_hint' => $zh ? '查询仅显示远端进度；商城状态由签名通知或客户回跳时的主动查询更新。物流仅发送给全额付款订单，组合付款逐个同步真实已付款子单。' : 'Lookup displays remote progress. Signed callbacks and verified customer returns update the store order. Shipments require full payment; combined shipments are sent to each real paid child.',
            'text_ship' => $zh ? '同步物流至 SSCPay' : 'Send shipment to SSCPay',
            'text_query' => $zh ? '查询支付' : 'Look up payment',
            'text_updates' => $zh ? '检查新版本' : 'Check for updates',
            'text_update_hint' => $zh ? '检查本项目发布服务中的版本。升级前备份文件和数据库；3.x 重新上传安装包，4.x 解压后覆盖 extension/sscpay 目录，保留配置和订单表。不要先卸载。' : 'Checks this project release service. Back up files and database first. For 3.x, upload the installer again. For 4.x, extract and replace extension/sscpay files while preserving settings and payment tables. Keep both payment methods installed.',
            'text_download' => $zh ? '下载安装包' : 'Download package',
            'error_payment' => $zh ? '支付暂时无法处理，请刷新状态或重试同一订单；如已扣款请联系商店。' : 'Payment could not be processed. Refresh the status or retry the same order. Contact the store if charged.',
            'error_admin' => $zh ? '操作未完成。请检查权限、HTTPS 地址、凭证、支付组、状态映射及订单或物流字段后重试。' : 'Operation failed. Check permissions, HTTPS URLs, credentials, payment group, status mappings, and order or shipment fields.',
        ];
        $fields = [
            'status' => ['状态', 'Status'], 'api_url' => ['SSCPay API 地址', 'SSCPay API base URL'],
            'app_id' => ['App-ID', 'App-ID'], 'api_key' => ['API Key', 'API Key'],
            'group_key' => ['支付组 Key', 'Payment group key'], 'payment_method_key' => ['指定渠道（可选）', 'Channel key (optional)'],
            'platform' => ['平台标识', 'Platform identifier'], 'update_url' => ['下载站地址（留空使用 API 地址）', 'Download site URL (defaults to API URL)'],
            'geo_zone_id' => ['区域', 'Geo zone'], 'sort_order' => ['排序', 'Sort order'],
            'pending_status_id' => ['等待付款', 'Pending payment'], 'partial_status_id' => ['部分付款', 'Partial payment'],
            'paid_status_id' => ['全额付款', 'Fully paid'], 'cancelled_status_id' => ['过期 / 取消', 'Expired / cancelled'],
            'failed_status_id' => ['支付失败', 'Payment failed'], 'refunded_status_id' => ['已退款', 'Refunded'],
            'chargeback_status_id' => ['拒付 / 争议', 'Chargeback / dispute'],
            'order_id' => ['OpenCart 订单 ID', 'OpenCart order ID'], 'logistics_company' => ['承运商代码', 'Carrier code'],
            'tracking_number' => ['运单号', 'Tracking number'], 'tracking_url' => ['追踪地址（可选）', 'Tracking URL (optional)'],
            'shipped_at' => ['发货时间（ISO 8601，含时区）', 'Shipped at (ISO 8601 with timezone)'],
        ];
        foreach ($fields as $key => $labels) {
            $text['entry_' . $key] = $labels[$zh ? 0 : 1];
        }
        return $text;
    }
}
