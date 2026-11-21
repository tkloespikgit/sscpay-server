<?php
/**
 * Webhook统一入口的核心编排逻辑（对应设计文档v2 第七节7.6 / 评审第2、6点）。
 *
 * 所有网关共用同一套"定位交易→验签→去重→校验金额→状态机→转发S系统"流程，
 * 具体的"怎么验签""怎么解析payload"委托给对应Gateway实现，
 * 但流程本身（尤其是安全相关的几步）不下放到Gateway类里，避免每个网关
 * 各自实现一遍、容易漏掉某个安全校验。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Models\WebhookEvent;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookRouter
{
    const CODE_OK = 0;
    const CODE_UNSUPPORTED_GATEWAY = 10016; // 设计文档错误码表之外的补充编号，用于Webhook网关校验
    const CODE_ORDER_NOT_FOUND = 10001;
    const CODE_SIGNATURE_INVALID = 10012;
    const CODE_ALREADY_PROCESSED = 10013;
    const CODE_AMOUNT_MISMATCH = 10014;
    const CODE_PROCESSING_FAILED = 10017;

    /**
     * @param string $paymentMethod URL路径里的{gateway}，paypal为共享Webhook别名
     * @param string $rawBody 原始请求体（未经任何解析/修改）
     * @param array $headers 全部请求头，key统一小写
     * @return array{success:bool,http_status:int,code:int,message:string}
     */
    public static function handle($paymentMethod, $rawBody, array $headers)
    {
        if ($paymentMethod === 'stripe') {
            return self::handleStripe($rawBody, $headers);
        }
        return self::handleEvent($paymentMethod, $rawBody, $headers);
    }

    /** Stripe events, state, Woo writes and the outbound queue commit together. */
    private static function handleStripe($rawBody, array $headers)
    {
        global $wpdb;
        $data = json_decode($rawBody, true);
        if (!is_array($data) || !is_string($data['type'] ?? null)
            || !is_string($data['id'] ?? null) || $data['id'] === '') {
            return self::result(false, 400, self::CODE_SIGNATURE_INVALID, 'Invalid event payload');
        }
        $probe = GatewayFactory::create('stripe', []);
        // This branch never performs business actions or writes an event receipt.
        if (!$probe->supportsWebhookEvent($data['type'])) {
            return self::result(true, 200, self::CODE_OK, 'Event type requires no action');
        }
        $signature = $headers['stripe-signature'] ?? null;
        if (!is_string($signature)
            || !preg_match('/(?:^|,)\s*t=(\d+)(?=,|$)/', $signature, $timestamp)
            || !preg_match('/(?:^|,)\s*v1=[a-fA-F0-9]{64}(?=,|$)/', $signature)
            || abs(time() - (int) $timestamp[1]) > 300) {
            return self::result(false, 400, self::CODE_SIGNATURE_INVALID, 'Signature verification failed');
        }
        try {
            $transaction = null;
            $externalRef = null;
            foreach ($probe->extractExternalRefs($rawBody) as $candidate) {
                $transaction = TransactionManager::findByExternalRef($candidate);
                if ($transaction) { $externalRef = $candidate; break; }
            }
            if (!$transaction) {
                $transaction = PaymentSessionReferences::findByAttemptKey($data['data']['object']['metadata']['pga_attempt_key'] ?? null);
                if ($transaction) { $externalRef = $transaction['_pga_matched_session_ref'] ?? $transaction['payment_intent']; }
            }
            // Old payments have no attempt metadata. Only credentials that verify the
            // incoming signature may be used to resolve their PI via Stripe's API.
            $object = $data['data']['object'] ?? [];
            $pi = strpos($data['type'], 'payment_intent.') === 0 ? ($object['id'] ?? null) : ($object['payment_intent'] ?? null);
            if (!$transaction && is_string($pi) && strpos($pi, 'pi_') === 0) {
                $seenConfigs = [];
                $lookupFailed = false;
                foreach (TransactionManager::stripeWebhookConfigs() as $candidateConfig) {
                    try {
                        $config = TransactionManager::decryptGatewayConfig($candidateConfig);
                    } catch (\RuntimeException $e) {
                        continue; // A corrupt historical snapshot must not hide other valid accounts.
                    }
                    $fingerprint = hash('sha256', serialize($config));
                    if (isset($seenConfigs[$fingerprint])) { continue; }
                    $seenConfigs[$fingerprint] = true;
                    $gateway = GatewayFactory::create('stripe', $config);
                    if (!$gateway->verifyCallbackSignature($rawBody, $headers, $config, false)) { continue; }
                    try {
                        $session = $gateway->findCheckoutSessionForPaymentIntent($pi);
                    } catch (\RuntimeException $e) {
                        // Endpoint signing secrets can survive an API-key rotation.
                        // Try the remaining verified credentials before asking Stripe to retry.
                        $lookupFailed = true;
                        continue;
                    }
                    if ($session) {
                        $transaction = TransactionManager::findByExternalRef($session);
                        if ($transaction) { $externalRef = $session; break; }
                    }
                }
                if (!$transaction && $lookupFailed) { throw new \RuntimeException('Stripe历史支付引用查询失败'); }
            }
            if (!$transaction) {
                LogManager::error(LogManager::SYSTEM_ORDER_ID, 'Stripe Webhook找不到对应交易记录', [
                    'payment_method' => 'stripe',
                    'callback_data' => ['event_id' => $data['id'], 'event_type' => $data['type'], 'object_id' => $object['id'] ?? null],
                ]);
                return self::result(false, 400, self::CODE_ORDER_NOT_FOUND, 'Order not found');
            }
            if ($transaction['payment_method'] !== 'stripe') {
                return self::result(false, 400, self::CODE_UNSUPPORTED_GATEWAY, 'Payment gateway does not match transaction');
            }
            $config = TransactionManager::decryptGatewayConfig($transaction);
            $gateway = GatewayFactory::create('stripe', $config);
            if (!$gateway->verifyCallbackSignature($rawBody, $headers, $config)) {
                return self::result(false, 400, self::CODE_SIGNATURE_INVALID, 'Signature verification failed');
            }
            $event = $gateway->parseCallbackEvent($rawBody, $headers);
            $result = PaymentSessionReferences::withOrderLock($transaction['s_order_id'], function () use ($rawBody, $headers, $transaction, $externalRef, $event, $gateway) {
                global $wpdb;
                if (self::isDisputeEvent($event['event_status'])) {
                    $event = self::refreshStripeDispute($gateway, $event, $rawBody, $headers);
                }
                $fresh = TransactionManager::findBySOrderId($transaction['s_order_id']);
                if (!$fresh) { throw new \RuntimeException('Webhook处理时订单已不存在'); }
                $transaction = array_merge($transaction, $fresh);
                self::assertTransactionalTables();
                if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('无法开始Webhook数据库事务'); }
                try {
                    $result = self::handleEvent('stripe', $rawBody, $headers, compact('transaction', 'externalRef', 'event'));
                    if ($result['http_status'] >= 300) {
                        $wpdb->query('ROLLBACK');
                        return $result;
                    }
                    if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Webhook数据库事务提交失败'); }
                    if (!empty($result['wake_queue'])) { self::clearOrderCache((int) $transaction['wp_order_id']); }
                    return $result;
                } catch (\Throwable $e) {
                    $wpdb->query('ROLLBACK');
                    // Woo objects may have entered the object cache before a rollback.
                    self::clearOrderCache((int) $transaction['wp_order_id']);
                    throw $e;
                }
            });
            if (!empty($result['wake_queue'])) { QueueManager::wakeWorker(); }
            unset($result['wake_queue']);
            return $result;
        } catch (\Throwable $e) {
            LogManager::error($transaction['s_order_id'] ?? LogManager::SYSTEM_ORDER_ID, 'Stripe Webhook处理失败，保留重试：' . $e->getMessage(), ['payment_method' => 'stripe']);
            return self::result(false, 500, self::CODE_PROCESSING_FAILED, 'Webhook processing failed; retry required');
        }
    }

    /** START TRANSACTION succeeds on MyISAM too, but its writes cannot roll back. */
    private static function assertTransactionalTables()
    {
        global $wpdb;
        $required = array_map(function ($suffix) use ($wpdb) { return $wpdb->prefix . $suffix; }, [
            'payment_transactions', 'payment_session_references', 'payment_webhook_events', 'payment_callback_queue',
        ]);
        $tables = array_merge($required, array_map(function ($suffix) use ($wpdb) { return $wpdb->prefix . $suffix; }, [
            'posts', 'postmeta', 'comments', 'commentmeta', 'wc_orders', 'wc_orders_meta',
            'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_order_items',
            'woocommerce_order_itemmeta', 'wc_product_meta_lookup', 'wc_order_stats',
            'wc_order_product_lookup', 'woocommerce_downloadable_product_permissions', 'wc_reserved_stock',
        ]));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SHOW TABLE STATUS WHERE Name IN (' . implode(',', array_fill(0, count($tables), '%s')) . ')', ...$tables
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) { throw new \RuntimeException('无法验证Webhook表事务支持'); }
        $found = [];
        foreach ($rows ?: [] as $row) {
            if (strcasecmp($row['Engine'] ?? '', 'InnoDB') !== 0) {
                throw new \RuntimeException('Webhook事务要求InnoDB表：' . $row['Name']);
            }
            $found[] = $row['Name'];
        }
        if (array_diff($required, $found)) { throw new \RuntimeException('Webhook所需数据表不完整'); }
    }

    private static function clearOrderCache($orderId)
    {
        try {
            if (function_exists('clean_post_cache')) { clean_post_cache($orderId); }
            if (function_exists('wc_delete_shop_order_transients')) { wc_delete_shop_order_transients($orderId); }
            if (function_exists('wc_get_container') && class_exists('Automattic\\WooCommerce\\Caches\\OrderCache')) {
                wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($orderId);
            }
            if (class_exists('WC_Data_Store')) {
                $store = \WC_Data_Store::load('order');
                if ($store->has_callable('clear_cached_data')) { $store->clear_cached_data([$orderId]); }
            }
        } catch (\Throwable $e) {
            // A cache backend failure cannot undo a committed payment/outbox entry.
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'Webhook订单缓存失效失败', [
                'wp_order_id' => $orderId, 'response_data' => ['exception_class' => get_class($e)],
            ]);
        }
    }

    /** Reading current dispute state must also survive rotation of an order's API key. */
    private static function refreshStripeDispute($gateway, array $event, $rawBody, array $headers)
    {
        try { return $gateway->refreshDisputeEvent($event); }
        catch (\RuntimeException $initialError) {
            $seenConfigs = [];
            foreach (TransactionManager::stripeWebhookConfigs() as $candidate) {
                try { $config = TransactionManager::decryptGatewayConfig($candidate); }
                catch (\RuntimeException $e) { continue; }
                $fingerprint = hash('sha256', serialize($config));
                if (isset($seenConfigs[$fingerprint])) { continue; }
                $seenConfigs[$fingerprint] = true;
                $candidateGateway = GatewayFactory::create('stripe', $config);
                if (!$candidateGateway->verifyCallbackSignature($rawBody, $headers, $config, false)) { continue; }
                try { return $candidateGateway->refreshDisputeEvent($event); }
                catch (\RuntimeException $e) { continue; }
            }
            throw $initialError;
        }
    }

    private static function handleEvent($paymentMethod, $rawBody, array $headers, array $stripeContext = [])
    {
        // 后台公布的 /paypal 同时接收两种PayPal模式的通知；它只是Webhook别名，
        // 不加入GatewayFactory的下单方式列表。两种模式共享同一套通知解析逻辑。
        $isPayPalWebhook = in_array($paymentMethod, ['paypal', 'paypal_js', 'paypal_rest'], true);
        $probeMethod = $paymentMethod === 'paypal' ? 'paypal_rest' : $paymentMethod;
        if (!GatewayFactory::isSupported($probeMethod)) {
            return self::result(false, 404, self::CODE_UNSUPPORTED_GATEWAY, 'Unsupported payment gateway');
        }

        // 此时还没有配置，先用一个空配置实例，只用来调用extractExternalRef——
        // 这一步的返回值尚未经过验签，只能用于"查表定位"，不能相信其真实性。
        $probeGateway = GatewayFactory::create($probeMethod, []);
        $externalRef = $stripeContext['externalRef'] ?? $probeGateway->extractExternalRef($rawBody, $headers);

        if (!$externalRef) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, "Webhook({$paymentMethod})未能提取外部交易标识，忽略该事件", [
                'payment_method' => $paymentMethod,
                'request_data' => ['headers' => $headers],
            ]);
            // 不认识的事件类型（比如网关的探测/ping事件），200幂等返回，不重试。
            return self::result(true, 200, self::CODE_OK, 'Event ignored');
        }

        try {
            $transaction = $stripeContext['transaction'] ?? TransactionManager::findByExternalRef($externalRef);
        } catch (\RuntimeException $e) {
            LogManager::error(LogManager::SYSTEM_ORDER_ID, 'Webhook读取支付引用失败：' . $e->getMessage());
            return self::result(false, 500, self::CODE_ORDER_NOT_FOUND, 'Failed to read payment reference');
        }
        if (!$transaction) {
            LogManager::error(LogManager::SYSTEM_ORDER_ID, "Webhook({$paymentMethod})找不到对应交易记录：{$externalRef}", [
                'payment_method' => $paymentMethod,
            ]);
            return self::result(false, 400, self::CODE_ORDER_NOT_FOUND, 'Order not found');
        }

        if ($isPayPalWebhook) {
            $storedMethod = $transaction['payment_method'] ?? '';
            if (!in_array($storedMethod, ['paypal_js', 'paypal_rest'], true)) {
                LogManager::error($transaction['s_order_id'], 'PayPal Webhook支付方式与交易记录不匹配', [
                    'payment_method' => $paymentMethod,
                ]);
                return self::result(false, 400, self::CODE_UNSUPPORTED_GATEWAY, 'Payment gateway does not match transaction');
            }

            // 共享入口和已有的两种模式入口统一使用订单实际通道验签、去重及记录日志，
            // 避免同一事件换一个URL投递就绕过去重。
            $paymentMethod = $storedMethod;
        }

        if (($transaction['payment_method'] ?? '') !== $paymentMethod) {
            return self::result(false, 400, self::CODE_UNSUPPORTED_GATEWAY, 'Payment gateway does not match transaction');
        }

        try {
            $config = TransactionManager::decryptGatewayConfig($transaction);
        } catch (\RuntimeException $e) {
            LogManager::error($transaction['s_order_id'], 'Webhook处理时解密网关配置失败：' . $e->getMessage(), [
                'payment_method' => $paymentMethod,
            ]);
            return self::result(false, 500, self::CODE_SIGNATURE_INVALID, 'Failed to decrypt gateway configuration');
        }

        $gateway = GatewayFactory::create($paymentMethod, $config);

        // ---- 验签：密钥必须来自这笔交易反查到的记录，不能用全局默认密钥 ----
        if (!$gateway->verifyCallbackSignature($rawBody, $headers, $config)) {
            LogManager::error($transaction['s_order_id'], 'Webhook签名验证失败', [
                'payment_method' => $paymentMethod,
            ]);
            return self::result(false, 400, self::CODE_SIGNATURE_INVALID, 'Signature verification failed');
        }

        $event = $stripeContext['event'] ?? $gateway->parseCallbackEvent($rawBody, $headers);

        if ($paymentMethod === 'stripe' && in_array($event['event_status'], ['processing', 'partial_refund'], true)) {
            if ($event['event_status'] === 'partial_refund'
                && (empty($event['currency']) || CurrencyManager::normalize($event['currency']) !== CurrencyManager::normalize($transaction['currency']))) {
                return self::result(false, 400, self::CODE_AMOUNT_MISMATCH, 'Refund currency mismatch');
            }
            $reference = PaymentSessionReferences::findReference($externalRef);
            $sessionRef = $reference['session_ref'] ?? ($transaction['_pga_matched_session_ref'] ?? $transaction['payment_intent']);
            $sessionData = $reference ? PaymentSessionReferences::decodeSessionData($reference) : TransactionManager::getGatewaySession($transaction);
            $sessionData['external_ref'] = $sessionRef;
            if (!empty($event['transaction_id'])) { $sessionData['transaction_id'] = $event['transaction_id']; }
            PaymentSessionReferences::remember($transaction, $sessionData);
            if (self::recordEventOnce($paymentMethod, $event['event_id'], $transaction['s_order_id'], $event['event_status'])) {
                LogManager::info($transaction['s_order_id'], $event['event_status'] === 'processing' ? 'Stripe异步付款处理中，已保存支付引用' : 'Stripe部分退款，保留订单付款状态', ['payment_method' => 'stripe', 'callback_data' => $event]);
            }
            return self::result(true, 200, self::CODE_OK, 'Event recorded without changing payment status');
        }

        if ($event['event_status'] === 'ignored') {
            self::recordEventOnce($paymentMethod, $event['event_id'], $transaction['s_order_id'], 'ignored');
            return self::result(true, 200, self::CODE_OK, 'Event type requires no action');
        }

        if ($paymentMethod === 'stripe' && $event['event_status'] === 'paid'
            && ($event['amount_minor'] === null || $event['currency'] === null)) {
            LogManager::error($transaction['s_order_id'], 'Stripe收款确认缺少金额或币种，拒绝更新状态', [
                'payment_method' => $paymentMethod, 'callback_data' => $event,
            ]);
            return self::result(false, 400, self::CODE_AMOUNT_MISMATCH, 'Amount or currency mismatch');
        }

        // ---- 争议/拒付事件：无论后面的金额校验、状态迁移是否成功，都必须先落一条
        // ERROR级订单日志，让客服/财务能在LogViewer里第一时间看到，不受迁移是否合法影响 ----
        $isDisputeEvent = self::isDisputeEvent($event['event_status']);
        if ($isDisputeEvent) {
            self::logDisputeEvent($paymentMethod, $transaction, $event);
        }

        // ---- 金额/币种校验（仅当事件携带了金额信息时才校验）----
        if ($event['amount_minor'] !== null && $event['currency'] !== null) {
            // PayPal/Stripe争议/拒付通知携带的是涉及金额，可能与原交易总额不同；
            // 保留该金额供日志使用，只核对币种。普通收款仍须核对完整金额。
            $matches = (($isPayPalWebhook || $paymentMethod === 'stripe') && $isDisputeEvent)
                ? CurrencyManager::normalize($transaction['currency']) === CurrencyManager::normalize($event['currency'])
                : CurrencyManager::amountsMatch(
                    $transaction['amount'],
                    $transaction['currency'],
                    $event['amount_minor'],
                    $event['currency']
                );
            if (!$matches) {
                LogManager::error($transaction['s_order_id'], 'Webhook金额/币种与订单记录不一致，拒绝更新状态', [
                    'payment_method' => $paymentMethod,
                    'callback_data' => $event,
                ]);
                return self::result(false, 400, self::CODE_AMOUNT_MISMATCH, 'Amount or currency mismatch');
            }
        }

        $targetStatus = self::mapEventStatus($event['event_status']);
        if (!$targetStatus) {
            return self::result(true, 200, self::CODE_OK, 'Event status requires no update');
        }

        // Refund/dispute can arrive before payment. Do not consume it until the
        // payment event has established the paid identity; Stripe will retry.
        if ($paymentMethod === 'stripe' && ($event['event_status'] === 'refunded' || $isDisputeEvent)
            && in_array($transaction['status'], ['pending', 'failed', 'expired'], true)) {
            return self::result(false, 503, self::CODE_ORDER_NOT_FOUND, 'Payment confirmation pending; retry required');
        }

        if ($paymentMethod === 'stripe' && $event['event_status'] === 'paid'
            && in_array($transaction['status'], ['disputing', 'refunded', 'confused'], true)) {
            self::recordEventOnce($paymentMethod, $event['event_id'], $transaction['s_order_id'], 'paid');
            return self::result(true, 200, self::CODE_OK, 'Payment already accounted for; preserve later financial status');
        }

        try {
            // Separate business rejection from database/order/queue exceptions.
            if ($paymentMethod === 'stripe') {
                TransactionStateMachine::assertTransition($transaction['status'], $targetStatus,
                    $event['event_status'] === 'paid' ? $paymentMethod : null);
            }
        } catch (\RuntimeException $e) {
            return self::result(true, 200, self::CODE_OK, 'Invalid status transition, ignored');
        }
        try {
            $reference = PaymentSessionReferences::findReference($externalRef);
        } catch (\RuntimeException $e) {
            LogManager::error($transaction['s_order_id'], 'Webhook读取支付会话引用失败：' . $e->getMessage());
            return self::result(false, 500, self::CODE_ORDER_NOT_FOUND, 'Failed to read payment reference');
        }
        if ($reference && $reference['s_order_id'] !== $transaction['s_order_id']) {
            return self::result(false, 400, self::CODE_ORDER_NOT_FOUND, 'Payment reference belongs to another order');
        }
        $matchedRef = $reference['session_ref'] ?? ($transaction['_pga_matched_session_ref'] ?? $transaction['payment_intent']);
        $matchedData = $reference ? PaymentSessionReferences::decodeSessionData($reference)
            : ($transaction['_pga_matched_session_data'] ?? TransactionManager::getGatewaySession($transaction));
        $transaction = TransactionManager::findBySOrderId($transaction['s_order_id']) ?: $transaction;
        $oldAttempt = $matchedRef !== ($transaction['payment_intent'] ?? null);
        $hasPaidWinner = !empty($transaction['paid_at']) || in_array($transaction['status'], [
            TransactionStateMachine::PAID, TransactionStateMachine::REFUNDED,
            TransactionStateMachine::DISPUTING, TransactionStateMachine::CONFUSED,
        ], true);
        if ($oldAttempt && ($targetStatus !== TransactionStateMachine::PAID || $hasPaidWinner)) {
            LogManager::warning($transaction['s_order_id'], $targetStatus === TransactionStateMachine::PAID
                ? '另一支付尝试再次到账，保留首笔付款身份，请核查重复付款'
                : '忽略旧支付会话状态通知，保留当前支付尝试', [
                'payment_method' => $paymentMethod, 'callback_data' => $event,
            ]);
            return self::result(true, 200, self::CODE_OK, 'Old payment attempt ignored');
        }
        try {
            $eventSession = array_merge($matchedData, ['external_ref' => $matchedRef]);
            if (!empty($event['transaction_id'])) { $eventSession['transaction_id'] = $event['transaction_id']; }
            PaymentSessionReferences::remember($transaction, $eventSession);
        } catch (\RuntimeException $e) {
            LogManager::error($transaction['s_order_id'], 'Webhook保存支付会话引用失败：' . $e->getMessage());
            return self::result(false, 500, self::CODE_ORDER_NOT_FOUND, 'Failed to preserve payment reference');
        }
        // Consume only after reference persistence, preserving retries on DB errors.
        // 已消费但未能迁移的成功事件，允许过期订单及Stripe失败订单在验证后恢复。
        if (!self::recordEventOnce($paymentMethod, $event['event_id'], $transaction['s_order_id'], $event['event_status'] ?? '')) {
            $canRecover = $transaction['status'] === TransactionStateMachine::EXPIRED
                || ($paymentMethod === 'stripe' && $transaction['status'] === TransactionStateMachine::FAILED
                    && $event['event_status'] === 'paid');
            if ($targetStatus !== TransactionStateMachine::PAID || !$canRecover) {
                return self::result(true, 200, self::CODE_ALREADY_PROCESSED, 'Event already processed (idempotent)');
            }
        }
        $updateFields = [
            'transaction_id' => $event['transaction_id'] ?? $transaction['transaction_id'],
            'payment_intent' => $matchedRef,
        ];
        if ($targetStatus === TransactionStateMachine::PAID
            && ($oldAttempt || !empty(TransactionManager::getGatewaySession($transaction)['_creating']))) {
            $updateFields['gateway_session'] = wp_json_encode($eventSession);
            $deadline = $eventSession['local_expires_at'] ?? null;
            $updateFields['gateway_session_expires_at'] = is_numeric($deadline) && (int) $deadline > 0
                ? gmdate('Y-m-d H:i:s', (int) $deadline) : null;
        }
        try {
            // 只有实际付款确认可恢复Stripe失败订单；争议胜诉不能代替收款通知。
            $updatedTransaction = self::applyStatusTransition(
                $transaction, $targetStatus, $updateFields, false, $event['event_status'] === 'paid', $paymentMethod === 'stripe'
            );
        } catch (\RuntimeException $e) {
            if ($paymentMethod === 'stripe') { throw $e; }
            LogManager::warning($transaction['s_order_id'], 'Webhook触发了非法的状态迁移，已忽略：' . $e->getMessage(), [
                'payment_method' => $paymentMethod,
            ]);
            // 依然200，避免网关因为收到非2xx而不断重试一个我们已经决定拒绝处理的事件。
            return self::result(true, 200, self::CODE_OK, 'Invalid status transition, ignored');
        }

        if ($updatedTransaction === null) {
            // 已经是目标状态了（重复事件的另一种情况），不重复触发订单侧副作用。
            return self::result(true, 200, self::CODE_OK, 'Status unchanged (idempotent)');
        }

        LogManager::info($transaction['s_order_id'], "订单状态已更新为 {$targetStatus}", [
            'payment_method' => $paymentMethod,
            'callback_data' => $event,
        ]);

        $result = self::result(true, 200, self::CODE_OK, 'ok');
        if ($paymentMethod === 'stripe') { $result['wake_queue'] = true; }
        return $result;
    }

    /**
     * 把"某个来源判定出的目标状态"应用到交易记录：状态机合法性校验 -> 落库 ->
     * 触发订单侧副作用（标记WP订单）-> 转发给S系统。Webhook入口（上面的handle()）
     * 和主动核实（Core/OrderStatusReconciler）都调这个方法，保证两条路径的状态迁移
     * 结果、副作用范围完全一致——不会出现"Webhook判定paid会标记WP订单+通知S系统，
     * 主动核实判定paid却不会"这种不一致。
     *
     * @param array $transaction 迁移前的交易记录
     * @param string $targetStatus TransactionStateMachine 状态常量
     * @param array $updateFields 落库时一并更新的字段（transaction_id/payment_intent等）
     * @param bool $accountForbidden 透传给CallbackHandler::forwardPaymentStatus()，
     *     标记这次迁移是否由网关账户级异常触发（见GatewayAccountForbiddenException）。
     * @param bool $allowFailedRecovery 仅供已验证金额的实际收款通知/查单开启Stripe失败恢复
     * @return array|null 迁移后的交易记录；null表示目标状态与当前相同（幂等，未触发副作用）
     * @throws \RuntimeException 非法的状态迁移（调用方决定如何处理，通常是记警告日志后忽略）
     */
    public static function applyStatusTransition(array $transaction, $targetStatus, array $updateFields = [], $accountForbidden = false, $allowFailedRecovery = false, $deferDelivery = false)
    {
        $transitionResult = TransactionManager::updateStatus($transaction, $targetStatus, $updateFields, $allowFailedRecovery);

        if ($transitionResult === 'noop') {
            return null;
        }

        $updatedTransaction = TransactionManager::findBySOrderId($transaction['s_order_id']);
        // 失败写入后可能已被另一收款确认补正；旧迁移不能重复执行新状态的副作用。
        if (!$updatedTransaction) { throw new \RuntimeException('状态更新后无法读取订单'); }
        if ($updatedTransaction['status'] !== $targetStatus) {
            return null;
        }
        self::applyOrderSideEffects($updatedTransaction, $updateFields['transaction_id'] ?? '');

        CallbackHandler::forwardPaymentStatus($updatedTransaction, $accountForbidden, $deferDelivery);

        return $updatedTransaction;
    }

    private static function applyOrderSideEffects(array $transaction, $transactionId)
    {
        switch ($transaction['status']) {
            case TransactionStateMachine::PAID:
                OrderManager::markPaid($transaction['wp_order_id'], (string) $transactionId);
                break;
            case TransactionStateMachine::FAILED:
                OrderManager::markFailed($transaction['wp_order_id']);
                break;
            case TransactionStateMachine::CANCELLED:
                OrderManager::markCancelled($transaction['wp_order_id']);
                break;
        }
    }

    /**
     * @param string $eventStatus 'paid'|'failed'|'refunded'|'cancelled'|'dispute_created'|
     *     'dispute_updated'|'dispute_won'|'dispute_lost'|'capture_reversed'|'ignored'
     * @return string|null 对应TransactionStateMachine的状态常量，null表示不需要变更状态
     */
    private static function mapEventStatus($eventStatus)
    {
        $map = [
            'paid' => TransactionStateMachine::PAID,
            'failed' => TransactionStateMachine::FAILED,
            'refunded' => TransactionStateMachine::REFUNDED,
            'cancelled' => TransactionStateMachine::CANCELLED,
            // 收到客诉/争议中：不管是刚创建还是中间态更新，都指向同一个disputing状态——
            // 若已经是disputing，TransactionStateMachine会把它当noop处理，不重复触发副作用。
            'dispute_created' => TransactionStateMachine::DISPUTING,
            'dispute_updated' => TransactionStateMachine::DISPUTING,
            // 争议胜诉：资金保留，回退回paid。
            'dispute_won' => TransactionStateMachine::PAID,
            // 争议败诉 / 网关直接反转扣款：拒付终态。
            'dispute_lost' => TransactionStateMachine::CONFUSED,
            'capture_reversed' => TransactionStateMachine::CONFUSED,
        ];

        return $map[$eventStatus] ?? null;
    }

    /** @var string[] 属于"支付争议/拒付"事件家族的event_status——这几类事件必须无条件落ERROR级订单日志。 */
    private static $disputeEventStatuses = [
        'dispute_created',
        'dispute_updated',
        'dispute_won',
        'dispute_lost',
        'capture_reversed',
    ];

    private static function isDisputeEvent($eventStatus)
    {
        return in_array($eventStatus, self::$disputeEventStatuses, true);
    }

    /**
     * 把争议/拒付类webhook事件整理成一条可读的订单日志（ERROR级别），
     * 不依赖后续的状态迁移是否成功——客服/财务要能第一时间在LogViewer里看到客诉，
     * 而不是等到状态机判定"合法迁移"之后才知道。
     *
     * @param string $paymentMethod
     * @param array $transaction
     * @param array $event parseCallbackEvent()的返回值
     */
    private static function logDisputeEvent($paymentMethod, array $transaction, array $event)
    {
        $labels = [
            'dispute_created' => '收到新的支付争议（客诉）',
            'dispute_updated' => '支付争议状态更新',
            'dispute_won' => '支付争议已解决：商家胜诉，资金保留',
            'dispute_lost' => '支付争议已解决：商家败诉，资金将被拒付扣回',
            'capture_reversed' => '网关侧发生资金拒付扣回（Chargeback）',
        ];

        $parts = [$labels[$event['event_status']] ?? '支付争议相关事件'];
        $parts[] = '网关：' . $paymentMethod;
        $parts[] = 'S系统订单号：' . $transaction['s_order_id'];

        if (!empty($event['dispute_id'])) {
            $parts[] = '争议单号：' . $event['dispute_id'];
        }
        if (!empty($event['transaction_id'])) {
            $parts[] = '三方交易ID：' . $event['transaction_id'];
        }
        if ($event['amount_minor'] !== null && !empty($event['currency'])) {
            $parts[] = sprintf(
                '涉及金额：%s %s',
                $event['currency'],
                CurrencyManager::fromMinorUnit($event['amount_minor'], $event['currency'])
            );
        }
        if (!empty($event['dispute_reason'])) {
            $parts[] = '原因：' . $event['dispute_reason'];
        }

        LogManager::error($transaction['s_order_id'], implode('；', $parts), [
            'wp_order_id' => $transaction['wp_order_id'],
            'payment_method' => $paymentMethod,
            'callback_data' => $event,
        ]);
    }

    /**
     * 尝试记录一个webhook事件；如果(payment_method, event_id)已存在，返回false（表示重复）。
     *
     * @return bool true=首次记录成功；false=已存在（重复事件）
     */
    private static function recordEventOnce($paymentMethod, $eventId, $sOrderId, $eventType)
    {
        global $wpdb;
        $table = WebhookEvent::tableName();

        // 依赖表上的 UNIQUE KEY(payment_method, event_id) 做并发安全的去重，
        // 而不是先SELECT再INSERT（那样在高并发下有竞态窗口）。
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (payment_method, event_id, s_order_id, event_type, processed_at)
             VALUES (%s, %s, %s, %s, %s)",
            $paymentMethod,
            $eventId,
            $sOrderId,
            $eventType,
            current_time('mysql', true)
        ));

        if ($inserted === false) { throw new \RuntimeException('Webhook事件记录写入失败'); }

        // INSERT IGNORE 在主键/唯一键冲突时返回0行受影响，据此判断是否重复。
        return $inserted > 0;
    }

    private static function result($success, $httpStatus, $code, $message)
    {
        return [
            'success' => $success,
            'http_status' => $httpStatus,
            'code' => $code,
            'message' => $message,
        ];
    }
}
