<?php
/**
 * /v1/order-query 每次被调用都要主动向三方支付渠道核实一次订单真实状态
 * （而不是只读本地缓存的wp_payment_transactions），本类是这个核实动作的落地实现。
 *
 * 不区分本地状态是否已经是终态——不论pending还是paid/failed/cancelled等，
 * 每次查询都会真的去调用一次渠道方API，目的是能发现"Webhook没送达/处理失败
 * 导致本地状态和渠道方real状态出现偏差"这类问题，而不只是等下一次Webhook。
 *
 * 核实出的目标状态与本地不一致时，复用 WebhookRouter::applyStatusTransition()
 * 走一遍和Webhook完全一样的"状态机校验 -> 落库 -> 标记WP订单 -> 回调S系统"流程，
 * 把这次查询当成一次"补漏"的事件来处理，保证两条路径最终产生的副作用一致。
 * 本地已付款但缺少三方交易号时，单独补录该字段，不重复触发付款副作用。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class OrderStatusReconciler
{
    /**
     * 结果页先核实收款，再处理本站链接有效期；兼容expired及Stripe失败后重试的订单。
     * 只有查询未确认付款、记录仍为pending时，maybeExpire才会关闭过期链接。
     *
     * @return array
     */
    public static function reconcileForResultPage(array $transaction)
    {
        if (in_array($transaction['status'], [TransactionStateMachine::PENDING, TransactionStateMachine::EXPIRED], true)
            || ($transaction['payment_method'] === 'stripe' && $transaction['status'] === TransactionStateMachine::FAILED)) {
            $transaction = self::reconcile($transaction);
        }

        return TransactionManager::maybeExpire($transaction);
    }

    /**
     * @param array $transaction findBySOrderId()等的返回值
     * @return array 核实后的最新交易记录，含状态同步或交易号补录结果
     */
    public static function reconcile(array $transaction)
    {
        // 从未成功发起过三方支付会话（比如preparePayment在下单阶段就失败了），
        // 没有任何三方标识可查，没有查询的意义。
        if (empty($transaction['payment_intent']) || !GatewayFactory::isSupported($transaction['payment_method'])) {
            return $transaction;
        }

        try {
            $config = TransactionManager::decryptGatewayConfig($transaction);
            $gateway = GatewayFactory::create($transaction['payment_method'], $config);
            $result = $gateway->queryOrderStatus($transaction);
        } catch (\Throwable $e) {
            // 三方查询本身失败（网络超时/鉴权失败/三方异常）：只记警告，降级返回
            // 本地缓存状态——不能因为三方一次抖动就让order-query接口跟着不可用。
            LogManager::warning($transaction['s_order_id'], '主动核实三方支付状态失败，本次查询已降级为返回本地缓存状态：' . $e->getMessage(), [
                'payment_method' => $transaction['payment_method'],
            ]);
            return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        }

        return self::applyGatewayResult($transaction, $result);
    }

    /** 仅接受本站网关 API 的查询结果，统一金额校验、状态补正和交易号补录。 */
    public static function applyGatewayResult(array $transaction, array $result)
    {
        $targetStatus = $result['status'] ?? null;
        if ($targetStatus === TransactionStateMachine::REFUNDED) {
            // A payment-level status has no individual refund ID or amount.
            // Only confirmed refund records can close the local transaction.
            return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        }
        $transactionId = $result['transaction_id'] ?? null;
        $storedTransactionId = $transaction['transaction_id'] ?? null;
        $fillMissingTransactionId = $targetStatus === TransactionStateMachine::PAID
            && $transaction['status'] === TransactionStateMachine::PAID
            && ($storedTransactionId === null || $storedTransactionId === '')
            && is_string($transactionId) && trim($transactionId) !== '';

        if (!$targetStatus || ($targetStatus === $transaction['status'] && !$fillMissingTransactionId)) {
            // 状态相同也可能缺少三方交易号；只有无需补录时才直接返回。
            return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        }

        // Stripe确认收款必须携带完整金额和币种，不能凭缺失信息恢复失败订单。
        if ($transaction['payment_method'] === 'stripe' && $targetStatus === TransactionStateMachine::PAID
            && (($result['amount_minor'] ?? null) === null || ($result['currency'] ?? null) === null)) {
            LogManager::error($transaction['s_order_id'], '主动核实：Stripe收款确认缺少金额或币种，拒绝更新状态', [
                'payment_method' => 'stripe', 'response_data' => $result,
            ]);
            return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        }

        // 金额校验口径与WebhookRouter::handle()保持一致：三方返回的金额信息和
        // 订单记录对不上时拒绝据此变更状态，避免脏数据污染本地记录；三方这次
        // 其它渠道查询没有返回金额信息（amount_minor/currency任一为空）时跳过校验。
        if ($result['amount_minor'] !== null && $result['currency'] !== null) {
            $matches = CurrencyManager::amountsMatch(
                $transaction['amount'],
                $transaction['currency'],
                $result['amount_minor'],
                $result['currency']
            );
            if (!$matches) {
                LogManager::error($transaction['s_order_id'], '主动核实：三方返回的金额/币种与订单记录不一致，拒绝据此更新状态', [
                    'payment_method' => $transaction['payment_method'],
                    'response_data' => $result,
                ]);
                return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
            }
        }

        if ($fillMissingTransactionId) {
            try {
                if (TransactionManager::fillMissingPaidTransactionId($transaction['s_order_id'], $transactionId)) {
                    LogManager::info($transaction['s_order_id'], '主动核实：已补录已付款订单的三方交易号', [
                        'payment_method' => $transaction['payment_method'],
                        'response_data' => ['transaction_id' => trim($transactionId)],
                    ]);
                }
            } catch (\RuntimeException $e) {
                LogManager::warning($transaction['s_order_id'], '主动核实：三方交易号补录失败：' . $e->getMessage(), [
                    'payment_method' => $transaction['payment_method'],
                ]);
            }

            // 补录只更新元数据，不重复标记付款或通知S系统；查询响应须带上最新交易号。
            return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        }

        try {
            $updateFields = [
                'transaction_id' => $result['transaction_id'] ?? $transaction['transaction_id'],
                'payment_intent' => $transaction['payment_intent'],
            ];
            // 创建响应不明时仍可能查到旧尝试已到账；恢复其完整会话，不能留下轮换草稿。
            if ($targetStatus === TransactionStateMachine::PAID
                && !empty(TransactionManager::getGatewaySession($transaction)['_creating'])) {
                $reference = PaymentSessionReferences::findReference($transaction['payment_intent']);
                if ($reference && $reference['s_order_id'] === $transaction['s_order_id']
                    && $reference['session_ref'] === $transaction['payment_intent']) {
                    $session = PaymentSessionReferences::decodeSessionData($reference);
                    if ($session !== []) {
                        $updateFields['gateway_session'] = wp_json_encode($session);
                        $deadline = $session['local_expires_at'] ?? null;
                        $updateFields['gateway_session_expires_at'] = is_numeric($deadline) && (int) $deadline > 0
                            ? gmdate('Y-m-d H:i:s', (int) $deadline) : null;
                    }
                }
            }
            $updatedTransaction = WebhookRouter::applyStatusTransition(
                $transaction, $targetStatus, $updateFields, false, $targetStatus === TransactionStateMachine::PAID
            );
        } catch (\RuntimeException $e) {
            // 取消、退款等终态或其它通道失败后的冲突仍不强行覆盖，记ERROR留痕核查。
            LogManager::error($transaction['s_order_id'], '主动核实发现三方状态与本地终态矛盾，已忽略此次更新：' . $e->getMessage(), [
                'payment_method' => $transaction['payment_method'],
                'response_data' => $result,
            ]);
            return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        }

        if ($updatedTransaction === null) {
            // 查询期间Webhook可能已先完成付款，必须返回最新记录而不是原来的expired快照。
            return TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        }

        LogManager::info($transaction['s_order_id'], "主动核实：本地状态与三方不一致，已同步更新为 {$targetStatus}", [
            'payment_method' => $transaction['payment_method'],
            'response_data' => $result,
        ]);

        return $updatedTransaction;
    }
}
