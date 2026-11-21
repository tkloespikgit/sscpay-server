<?php
/**
 * 组装转发给S系统的回调payload并可靠入队。默认立刻同步投递一次；
 * Stripe事务内只入队，由WebhookRouter在提交后异步唤醒worker。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class CallbackHandler
{
    /**
     * 支付状态变更事件，webhook处理完成后调用（对应F4）。
     *
     * @param  array  $transaction  最新的交易记录（状态已更新）
     * @param  bool  $accountForbidden  true表示这次FAILED是因为网关账户本身出了问题
     *     （密钥失效/权限不足/账户被限制），而不是普通的下单失败——S系统据此可以
     *     区分"要不要告警渠道账户异常"，而不是当成一次普通支付失败。
     * @param bool $deferDelivery 事务中设为true，只持久化回调，提交后再唤醒worker
     */
    public static function forwardPaymentStatus(array $transaction, bool $accountForbidden = false, bool $deferDelivery = false): void
    {
        $payload = [
            'event'             => 'payment_status',
            's_order_id'        => $transaction['s_order_id'],
            'wp_order_id'       => (int) $transaction['wp_order_id'],
            'payment_method'    => $transaction['payment_method'],
            'status'            => $transaction['status'],
            'transaction_id'    => $transaction['transaction_id'],
            'amount'            => $transaction['amount'],
            'currency'          => $transaction['currency'],
            'paid_at'           => $transaction['paid_at'],
            'updated_at'        => $transaction['updated_at'],
            'account_forbidden' => $accountForbidden ? 1 : 0,
        ];

        $queueId = QueueManager::enqueue($transaction['s_order_id'], 'payment_status', $payload);
        // 先入队（保证有落库的重试记录兜底），再立刻尝试投递一次——多数情况下
        // S系统当场就能收到通知，不用等下一次WP-Cron轮询（那个依赖服务器
        // crontab定期戳wp-cron.php，配置缺失/不准时会让通知延迟发出甚至发不出）。
        // 这次同步调用失败时deliverNow()内部会走一样的指数退避重试逻辑，
        // 交给后续cron按原节奏兜底。
        if (!$deferDelivery) {
            QueueManager::deliverNow($queueId);
        }
    }
}
