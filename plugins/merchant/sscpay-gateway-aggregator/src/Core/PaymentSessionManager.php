<?php
/** 预创建、复用和按本站期限轮换平台会话。本站链接期限另由 TransactionManager 管理。 */
namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Core\Exceptions\GatewayAccountForbiddenException;
use PaymentGatewayAggregator\Core\Exceptions\PayPalOrderNotFoundException;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentSessionManager
{
    private const CREATION_RETRY_SECONDS = 3600;

    /** /v1/pay 使用 false 预创建；付款页使用 true 先核实平台再判断是否轮换。 */
    public static function ensure(array $transaction, bool $forPaymentPage = true): array
    {
        global $wpdb;
        $lockName = 'pga_session_' . sha1($wpdb->prefix . '|' . $transaction['s_order_id']);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName)) !== '1') {
            throw new \RuntimeException('支付会话正在处理，请稍后重试');
        }

        try {
            return self::ensureLocked($transaction, $forPaymentPage);
        } catch (\Throwable $e) {
            LogManager::warning($transaction['s_order_id'], '平台会话检查或创建失败：' . $e->getMessage(), [
                'payment_method' => $transaction['payment_method'],
            ]);
            throw $e;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private static function ensureLocked(array $transaction, bool $forPaymentPage): array
    {
        $transaction = self::fresh($transaction);
        $cached = TransactionManager::getGatewaySession($transaction);
        $ttl = self::ttl($transaction['payment_method']);
        $queryExpiredOrder = $forPaymentPage && $ttl !== null
            && $transaction['status'] === TransactionStateMachine::EXPIRED && !empty($transaction['payment_intent']);
        if ($transaction['status'] !== TransactionStateMachine::PENDING && !$queryExpiredOrder) {
            return self::context($transaction);
        }

        $gateway = GatewayFactory::create($transaction['payment_method'], TransactionManager::decryptGatewayConfig($transaction));
        if (!$forPaymentPage && !empty($cached) && empty($cached['_creating'])) {
            return self::context($transaction, $gateway, $cached);
        }

        $state = null;
        if ($ttl !== null && !empty($transaction['payment_intent'])) {
            // 临时查询失败必须中止；已到期的 PayPal 旧订单明确不存在时由 inspect() 单独处理。
            $inspection = self::inspect($gateway, $transaction);
            $transaction = OrderStatusReconciler::applyGatewayResult($transaction, $inspection);
            if ($transaction['status'] !== TransactionStateMachine::PENDING) {
                return self::context($transaction);
            }
            if (($inspection['status'] ?? null) === TransactionStateMachine::PAID) {
                // 金额校验未通过或其他并发冲突时，禁止把已经返回 paid 的会话当成未支付重建。
                throw new \RuntimeException('平台报告已付款，但尚未通过本地核实，请联系商户');
            }
            $state = $inspection['session_state'] ?? 'unknown';
            if (!in_array($state, ['reusable', 'closed', 'requires_approval'], true)) {
                throw new \RuntimeException('平台付款正在处理或状态尚不明确，请稍后重试');
            }
        } elseif ($queryExpiredOrder) {
            return self::context($transaction);
        }

        $transaction = TransactionManager::maybeExpire(self::fresh($transaction));
        if ($transaction['status'] !== TransactionStateMachine::PENDING) {
            return self::context($transaction);
        }

        if (empty($cached) && !empty($transaction['payment_intent'])) {
            throw new \RuntimeException('平台会话缓存缺失，请联系商户核实');
        }

        if ($state === 'requires_approval') {
            $orderId = $transaction['payment_intent'];
            if (!in_array($transaction['payment_method'], ['paypal_js', 'paypal_rest'], true)
                || !empty($cached['_creating']) || ($cached['external_ref'] ?? null) !== $orderId
                || ($cached['order_id'] ?? null) !== $orderId) {
                throw new \RuntimeException('PayPal待确认订单与缓存不一致，请联系商户核实');
            }
            $redirectUrl = $inspection['approval_url'] ?? PayPalPaymentRecovery::redirectUrl($transaction);
            if ($redirectUrl !== null) { $cached['redirect_url'] = $redirectUrl; }
            if ($transaction['payment_method'] === 'paypal_rest' && $redirectUrl === null) {
                throw new \RuntimeException('PayPal没有有效的继续付款地址，请联系商户核实');
            }
            // Keep the same approved Order even beyond its local 2h TTL. It may still
            // be captured; creating another Order here would leave two payable attempts.
            return self::currentContext($transaction, $gateway, $cached);
        }

        if (!empty($cached) && empty($cached['_creating'])) {
            if ($ttl === null) {
                return self::context($transaction, $gateway, $cached);
            }
            if (empty($transaction['payment_intent'])) {
                throw new \RuntimeException('已保存会话缺少平台编号，请联系商户核实');
            }
            $deadline = self::deadline($transaction, $cached, $ttl);
            if (empty($transaction['gateway_session_expires_at']) || !isset($cached['local_expires_at'])) {
                $cached['local_expires_at'] = $deadline;
                if (!TransactionManager::savePendingSession($transaction, $cached, [
                    'gateway_session_expires_at' => gmdate('Y-m-d H:i:s', $deadline),
                ])) {
                    return self::afterConcurrentChange($transaction);
                }
                $transaction = self::fresh($transaction);
                LogManager::info($transaction['s_order_id'], '已补记历史平台会话的本站到期时间', [
                    'payment_method' => $transaction['payment_method'],
                    'response_data' => ['gateway_session_expires_at' => $transaction['gateway_session_expires_at']],
                ]);
            }
            if (time() < $deadline && $state !== 'closed') {
                if (in_array($transaction['payment_method'], ['paypal_js', 'paypal_rest'], true)) {
                    $redirectUrl = PayPalPaymentRecovery::redirectUrl($transaction);
                    if ($redirectUrl !== null) { $cached['redirect_url'] = $redirectUrl; }
                }
                return self::currentContext($transaction, $gateway, $cached);
            }

            // Stripe 的旧收银台可直接付款，先由平台确认退役再发布下一次尝试。
            if ($transaction['payment_method'] === 'stripe' && !$gateway->retirePaymentSession($transaction)) {
                $inspection = self::inspect($gateway, $transaction);
                $transaction = OrderStatusReconciler::applyGatewayResult($transaction, $inspection);
                if ($transaction['status'] !== TransactionStateMachine::PENDING) {
                    return self::context($transaction);
                }
                throw new \RuntimeException('旧 Stripe 会话尚未关闭，暂不创建新会话');
            }
            LogManager::info($transaction['s_order_id'], '平台会话超过本站期限或已关闭，准备创建新会话', [
                'payment_method' => $transaction['payment_method'],
                'request_data' => [
                    'old_external_ref' => $transaction['payment_intent'],
                    'old_expires_at' => gmdate('Y-m-d H:i:s', $deadline),
                    'reason' => $state === 'closed' ? 'platform_closed' : 'local_deadline',
                ],
            ]);
        }

        if (empty($cached['_creating'])) {
            PaymentSessionReferences::remember($transaction);
            $cached = [
                '_creating' => true,
                '_attempt_key' => wp_generate_uuid4(),
                '_attempt_started_at' => time(),
                '_previous_session_ref' => $transaction['payment_intent'] ?? null,
                '_previous_expires_at' => $transaction['gateway_session_expires_at'] ?? null,
            ];
            // 新增Stripe请求字段只对新尝试启用。旧版超时草稿仍使用原参数，
            // 避免同一幂等键跨版本重试时被Stripe判定参数不一致。
            if ($transaction['payment_method'] === 'stripe') {
                $cached['_stripe_metadata_version'] = 1;
            }
            if (!TransactionManager::savePendingSession($transaction, $cached)) {
                return self::afterConcurrentChange($transaction);
            }
            $transaction = self::fresh($transaction);
        } elseif (empty($cached['_attempt_key']) || empty($cached['_attempt_started_at'])
            || time() - (int) $cached['_attempt_started_at'] > self::CREATION_RETRY_SECONDS) {
            throw new \RuntimeException('先前创建请求结果尚不明确，请联系商户核实，不能盲目重复创建');
        }

        $transaction = TransactionManager::maybeExpire(self::fresh($transaction));
        if ($transaction['status'] !== TransactionStateMachine::PENDING) {
            return self::context($transaction);
        }

        $requestTransaction = $transaction;
        $requestTransaction['payment_attempt_key'] = $cached['_attempt_key'];
        if ($transaction['payment_method'] === 'stripe') {
            $requestTransaction['stripe_metadata_version'] = (int) ($cached['_stripe_metadata_version'] ?? 0);
        }
        try {
            $sessionData = $gateway->preparePayment($requestTransaction);
        } catch (GatewayAccountForbiddenException $e) {
            self::markAccountFailure($transaction, $e);
            throw $e;
        }
        if (empty($sessionData['external_ref']) || !is_string($sessionData['external_ref'])) {
            throw new \RuntimeException('平台没有返回有效的支付会话编号');
        }
        $sessionData['_attempt_key'] = $cached['_attempt_key'];
        $sessionData['_attempt_started_at'] = $cached['_attempt_started_at'];
        if ($transaction['payment_method'] === 'stripe') {
            $sessionData['_stripe_metadata_version'] = (int) ($cached['_stripe_metadata_version'] ?? 0);
        }
        $expiresAt = null;
        if ($ttl !== null) {
            $createdAt = !empty($sessionData['provider_created_at'])
                ? (int) $sessionData['provider_created_at'] : (int) $cached['_attempt_started_at'];
            $sessionData['local_expires_at'] = $createdAt + $ttl;
            $expiresAt = gmdate('Y-m-d H:i:s', $sessionData['local_expires_at']);
        }

        PaymentSessionReferences::remember($transaction, $sessionData);
        $latest = TransactionManager::maybeExpire(self::fresh($transaction));
        if ($latest['status'] !== TransactionStateMachine::PENDING) {
            return self::context($latest);
        }
        if (!TransactionManager::savePendingSession($transaction, $sessionData, [
            'payment_intent' => $sessionData['external_ref'],
            'transaction_id' => $sessionData['transaction_id'] ?? null,
            'gateway_session_expires_at' => $expiresAt,
        ])) {
            return self::afterConcurrentChange($transaction);
        }
        $transaction = self::fresh($transaction);
        LogManager::info($transaction['s_order_id'], empty($cached['_previous_session_ref'])
            ? '平台支付会话已创建并保存到期时间' : '平台支付会话已重建并保存新的到期时间', [
            'wp_order_id' => $transaction['wp_order_id'],
            'payment_method' => $transaction['payment_method'],
            'response_data' => [
                'old_external_ref' => $cached['_previous_session_ref'] ?? null,
                'new_external_ref' => $sessionData['external_ref'],
                'old_expires_at' => $cached['_previous_expires_at'] ?? null,
                'gateway_session_expires_at' => $expiresAt,
            ],
        ]);
        return self::currentContext($transaction, $gateway, $sessionData);
    }

    private static function ttl(string $method): ?int
    {
        if ($method === 'stripe') {
            return 23 * 3600;
        }
        return in_array($method, ['paypal_js', 'paypal_rest'], true) ? 2 * 3600 : null;
    }

    private static function inspect(PaymentBase $gateway, array $transaction): array
    {
        try {
            $result = $gateway->inspectPaymentSession($transaction);
        } catch (PayPalOrderNotFoundException $e) {
            // PayPal可能已清理长期未支付的Order。仅在本站旧会话也已到期、无收款记录时
            // 允许走现有轮换流程；不能把所有404或本地pending直接解释为未支付。
            if (!in_array($transaction['payment_method'], ['paypal_js', 'paypal_rest'], true)
                || !in_array($transaction['status'], [TransactionStateMachine::PENDING, TransactionStateMachine::EXPIRED], true)
                || !empty($transaction['transaction_id']) || !empty($transaction['paid_at'])) {
                throw $e;
            }
            $cached = TransactionManager::getGatewaySession($transaction);
            $oldRef = empty($cached['_creating'])
                ? ($cached['external_ref'] ?? ($cached['order_id'] ?? null))
                : ($cached['_previous_session_ref'] ?? null);
            if (empty($oldRef) || $oldRef !== ($transaction['payment_intent'] ?? null)) {
                throw $e;
            }
            // 轮换草稿的开始时间属于新尝试，不能拿它推算旧Order的期限。
            // 正常轮换在写草稿前已补记并保留旧数据库期限；缺失时保持异常，避免盲目重建。
            if (!empty($cached['_creating'])
                && (empty($transaction['gateway_session_expires_at'])
                    || strtotime($transaction['gateway_session_expires_at'] . ' UTC') <= 0)) {
                throw $e;
            }
            $deadline = self::deadline($transaction, $cached, self::ttl($transaction['payment_method']));
            if (time() < $deadline) {
                throw $e;
            }
            LogManager::info($transaction['s_order_id'], 'PayPal旧订单已超过本站会话期限且平台返回不存在，按失效会话处理', [
                'payment_method' => $transaction['payment_method'],
                'response_data' => [
                    'old_external_ref' => $oldRef,
                    'old_expires_at' => gmdate('Y-m-d H:i:s', $deadline),
                    'error' => $e->getMessage(),
                ],
            ]);
            // 不改变订单付款状态；本站链接期限、历史引用、幂等键和并发检查仍走原流程。
            $result = ['status' => null, 'transaction_id' => null, 'amount_minor' => null,
                'currency' => null, 'session_state' => 'closed'];
        }
        if (!is_array($result)) {
            throw new \RuntimeException('平台返回了无效的会话查询结果');
        }
        return $result;
    }

    private static function deadline(array $transaction, array $cached, int $ttl): int
    {
        if (!empty($transaction['gateway_session_expires_at'])) {
            $deadline = strtotime($transaction['gateway_session_expires_at'] . ' UTC');
            if ($deadline !== false) {
                return $deadline;
            }
        }
        if (!empty($cached['local_expires_at'])) {
            return (int) $cached['local_expires_at'];
        }
        $startedAt = $cached['_attempt_started_at'] ?? ($cached['provider_created_at'] ?? null);
        if (empty($startedAt)) {
            $startedAt = strtotime(($transaction['created_at'] ?? '') . ' UTC');
        }
        if (!$startedAt || (int) $startedAt <= 0) {
            throw new \RuntimeException('无法确定历史会话的创建时间，请联系商户核实');
        }
        return (int) $startedAt + $ttl;
    }

    private static function fresh(array $transaction): array
    {
        $latest = TransactionManager::findBySOrderId($transaction['s_order_id']);
        if (!$latest) {
            throw new \RuntimeException('支付订单不存在');
        }
        return $latest;
    }

    private static function afterConcurrentChange(array $transaction): array
    {
        $latest = self::fresh($transaction);
        if ($latest['status'] !== TransactionStateMachine::PENDING) {
            return self::context($latest);
        }
        throw new \RuntimeException('支付会话已更新，请刷新页面');
    }

    private static function currentContext(array $transaction, PaymentBase $gateway, array $sessionData): array
    {
        $latest = TransactionManager::maybeExpire(self::fresh($transaction));
        if ($latest['status'] !== TransactionStateMachine::PENDING) {
            return self::context($latest);
        }
        if (($latest['payment_intent'] ?? null) !== ($transaction['payment_intent'] ?? null)
            || ($latest['gateway_session'] ?? null) !== ($transaction['gateway_session'] ?? null)) {
            throw new \RuntimeException('支付会话已更新，请刷新页面');
        }
        return self::context($latest, $gateway, $sessionData);
    }

    private static function context(array $transaction, ?PaymentBase $gateway = null, array $sessionData = []): array
    {
        return compact('transaction', 'gateway', 'sessionData');
    }

    private static function markAccountFailure(array $transaction, GatewayAccountForbiddenException $e): void
    {
        LogManager::error($transaction['s_order_id'], '创建平台会话失败（网关账户异常）：' . $e->getMessage(), [
            'payment_method' => $transaction['payment_method'],
        ]);
        $latest = self::fresh($transaction);
        if ($latest['status'] === TransactionStateMachine::PENDING) {
            WebhookRouter::applyStatusTransition($latest, TransactionStateMachine::FAILED, [], true);
        }
    }
}
