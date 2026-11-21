<?php
/**
 * 交易记录（wp_payment_transactions）的创建/查询/状态更新。
 *
 * 这是"网关无关"的共享逻辑——评审里指出v1把这部分职责错放进了每个Gateway类，
 * 这里统一收敛：PayController只调这个类，不直接操作 $wpdb，也不需要知道
 * 具体是哪个网关。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Helpers\TokenGenerator;
use PaymentGatewayAggregator\Models\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

class TransactionManager
{
    /** DATETIME 字段中的永久有效标记；不参与时间戳解析或过期比较。 */
    public const NEVER_EXPIRES_AT = '9999-12-31 23:59:59';

    private static function table()
    {
        return Transaction::tableName();
    }

    /**
     * 创建一条交易记录。gateway_config 必须是【已经加密好】的字符串——
     * 加密本身由调用方（PayController）通过 ConfigEncryptor 完成，
     * 这里只负责落库，职责单一。
     *
     * @param array $data 见下方字段列表
     * @return array 新建的交易记录（数组形式，已含id/pay_token/expires_at）
     */
    public static function create(array $data)
    {
        global $wpdb;

        $payToken = TokenGenerator::generatePayToken();
        $ttlMinutes = (int) get_option('pga_pay_link_ttl_minutes', 0);
        $expiresAt = $ttlMinutes === 0
            ? self::NEVER_EXPIRES_AT
            : gmdate('Y-m-d H:i:s', time() + max(1, $ttlMinutes) * 60);
        $now = current_time('mysql', true);

        $row = [
            's_order_id' => $data['s_order_id'],
            'wp_order_id' => (int) $data['wp_order_id'],
            'payment_method' => $data['payment_method'],
            // 交易类型/账单号/主题/小计：由S系统必传并已在PayController校验归一化，
            // 这里只做兜底默认值（防御直接调用本方法的其它路径）。
            'trans_type' => $data['trans_type'] ?? Transaction::TRANS_TYPE_MATCH,
            'invoice_number' => $data['invoice_number'] ?? '',
            'subject' => $data['subject'] ?? '',
            'currency' => $data['currency'],
            'amount' => $data['amount'],
            'subtotal' => $data['subtotal'] ?? 0,
            'status' => TransactionStateMachine::PENDING,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'billing_address' => wp_json_encode($data['billing_address'] ?? []),
            'shipping_address' => wp_json_encode($data['shipping_address'] ?? []),
            'items' => wp_json_encode($data['items'] ?? []),
            'shipping_fee' => $data['shipping_fee'] ?? 0,
            'tax_fee' => $data['tax_fee'] ?? 0,
            'discount_fee' => $data['discount_fee'] ?? 0,
            'gateway_config' => $data['gateway_config_encrypted'],
            'gateway_config_id' => $data['gateway_config_id'] ?? null,
            'pay_token' => $payToken,
            'expires_at' => $expiresAt,
            'gateway_session' => null,
            'gateway_session_expires_at' => null,
            'callback_url' => $data['callback_url'],
            'return_url' => $data['return_url'],
            'cancel_url' => $data['cancel_url'],
            // 控制支付结果页是否展示"Return to Store"按钮（见Frontend/PaymentPage.php），
            // 由S系统通过/v1/pay传入，可选，不传按升级前的行为默认Y（一律展示）。
            // 合法性（只能是Y/N）已在PayController::validate()校验过，这里只做兜底。
            'allow_returned_source' => ($data['allow_returned_source'] ?? 'Y') === 'N' ? 'N' : 'Y',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $wpdb->insert(self::table(), $row);

        return self::findBySOrderId($data['s_order_id']);
    }

    /**
     * @param string $sOrderId
     * @return array|null
     */
    public static function findBySOrderId($sOrderId)
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE s_order_id = %s", $sOrderId),
            ARRAY_A
        );

        if (!empty($wpdb->last_error)) { throw new \RuntimeException('交易记录读取失败'); }
        return $row ?: null;
    }

    /**
     * Webhook反查用：按三方交易ID或支付意图ID定位交易记录。
     *
     * @param string $externalRef 三方的payment_intent/order id/transaction id
     * @return array|null
     */
    public static function findByExternalRef($externalRef)
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE payment_intent = %s OR transaction_id = %s LIMIT 1",
                $externalRef,
                $externalRef
            ),
            ARRAY_A
        );

        if (!empty($wpdb->last_error)) { throw new \RuntimeException('交易引用读取失败'); }

        return $row ?: PaymentSessionReferences::find($externalRef);
    }

    /**
     * Candidate credentials for pre-metadata Stripe payments, streamed by primary key.
     * Encryption uses a random IV, so DISTINCT ciphertext is not config deduplication.
     * The caller deduplicates decrypted credentials and verifies before using any key.
     *
     * @return \Generator<array>
     */
    public static function stripeWebhookConfigs()
    {
        global $wpdb;
        $table = self::table();
        $cursor = 0;
        $batchSize = 100;
        $registered = $wpdb->get_results($wpdb->prepare(
            "SELECT config_encrypted AS gateway_config FROM {$wpdb->prefix}payment_gateway_configs
             WHERE payment_method = %s AND status = %s ORDER BY id DESC", 'stripe', 'active'
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) { throw new \RuntimeException('Stripe已注册配置读取失败'); }
        foreach ($registered ?: [] as $row) { yield $row; }
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, gateway_config FROM {$table}
                 WHERE payment_method = 'stripe' AND id > %d ORDER BY id ASC LIMIT %d",
                $cursor, $batchSize
            ), ARRAY_A);
            if (!empty($wpdb->last_error)) { throw new \RuntimeException('Stripe历史配置读取失败'); }
            foreach ($rows ?: [] as $row) {
                $rowId = (int) ($row['id'] ?? 0);
                if ($rowId <= $cursor) { throw new \RuntimeException('Stripe历史配置游标无效'); }
                $cursor = $rowId;
                yield $row;
            }
        } while (count($rows ?: []) === $batchSize);
    }

    /**
     * @param string $payToken
     * @return array|null
     */
    public static function findByPayToken($payToken)
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE pay_token = %s", $payToken),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * 状态迁移的唯一入口——经由 TransactionStateMachine 校验合法性。
     *
     * @param array $transaction 当前交易记录（findBySOrderId的返回值）
     * @param string $newStatus
     * @param array $extraFields 一并更新的字段，如 transaction_id/payment_intent/paid_at
     * @param bool $allowFailedRecovery 已通过验签/查单及金额校验的实际收款确认才可开启
     * @return string 'noop' | 'transition'
     * @throws \RuntimeException 非法迁移
     */
    public static function updateStatus(array $transaction, $newStatus, array $extraFields = [], $allowFailedRecovery = false)
    {
        global $wpdb;

        // 付款通知、主动查单和页面过期检查可能同时处理同一订单。
        // 带上读取时的状态作为更新条件，避免旧快照覆盖已到账状态或重复触发付款副作用。
        $sourceRef = $transaction['payment_intent'] ?? null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($newStatus !== TransactionStateMachine::PAID
                && ($transaction['payment_intent'] ?? null) !== $sourceRef) {
                throw new \RuntimeException('支付会话已切换，忽略旧会话的状态更新');
            }
            // 每次CAS重读后仍检查恢复权限，争议胜诉等来源不能借并发失败恢复成已付款。
            $result = TransactionStateMachine::assertTransition(
                $transaction['status'], $newStatus, $allowFailedRecovery ? $transaction['payment_method'] : null
            );
            if ($result === 'noop') {
                if ($newStatus === TransactionStateMachine::PAID) {
                    $storedRef = $transaction['payment_intent'] ?? '';
                    if ($storedRef !== '' && ($extraFields['payment_intent'] ?? $storedRef) !== $storedRef) {
                        LogManager::warning($transaction['s_order_id'], '另一支付会话确认收款，保留首笔付款身份，请核对重复收款');
                        return 'noop';
                    }
                    if (!empty($transaction['transaction_id']) && !empty($extraFields['transaction_id'])
                        && $transaction['transaction_id'] !== $extraFields['transaction_id']) {
                        LogManager::warning($transaction['s_order_id'], '同一订单出现不同收款交易号，保留首笔交易号，请核对重复收款');
                        unset($extraFields['transaction_id']);
                    }
                }
                // 状态已相同仍可能需要补记Capture ID等字段，但不能重复触发付款副作用。
                $changedFields = array_diff_assoc($extraFields, $transaction);
                if ($changedFields === []) {
                    return $result;
                }
            }

            $fields = array_merge($extraFields, [
                'status' => $newStatus,
                'updated_at' => current_time('mysql', true),
            ]);

            if ($newStatus === TransactionStateMachine::PAID && empty($fields['paid_at'])) {
                $fields['paid_at'] = !empty($transaction['paid_at'])
                    ? $transaction['paid_at'] : current_time('mysql', true);
            }

            $updated = $wpdb->update(
                self::table(),
                $fields,
                [
                    's_order_id' => $transaction['s_order_id'],
                    'status' => $transaction['status'],
                    'payment_intent' => $transaction['payment_intent'] ?? null,
                ]
            );

            if ($updated === false) {
                throw new \RuntimeException('订单状态写入失败');
            }
            if ($updated > 0) {
                return $result;
            }

            $transaction = self::findBySOrderId($transaction['s_order_id']);
            if (!$transaction) {
                throw new \RuntimeException('更新状态时找不到订单');
            }
        }

        if ($transaction['status'] === $newStatus) {
            return 'noop';
        }
        throw new \RuntimeException('订单状态并发变化，请重试');
    }

    /** 只将明确保存的永久标记视为永久，历史记录仍使用原有期限。 */
    public static function isNonExpiring(array $transaction): bool
    {
        return ($transaction['expires_at'] ?? null) === self::NEVER_EXPIRES_AT;
    }

    /** API 保持 ISO 8601 字符串格式；永久标记不依赖平台时间戳范围。 */
    public static function formatExpiresAt(?string $expiresAt): ?string
    {
        if (empty($expiresAt)) {
            return null;
        }
        if ($expiresAt === self::NEVER_EXPIRES_AT) {
            return '9999-12-31T23:59:59Z';
        }
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($expiresAt . ' UTC'));
    }

    /**
     * 支付页面访问时的惰性过期检查（对应8.2流程第4步）。
     *
     * @param array $transaction
     * @return array 可能已更新过status的交易记录
     */
    public static function maybeExpire(array $transaction)
    {
        if ($transaction['status'] !== TransactionStateMachine::PENDING || self::isNonExpiring($transaction)) {
            return $transaction;
        }

        $expiresAt = strtotime($transaction['expires_at'] . ' UTC');
        if ($expiresAt !== false && time() > $expiresAt) {
            try {
                self::updateStatus($transaction, TransactionStateMachine::EXPIRED);
            } catch (\RuntimeException $e) {
                // 读取pending后渠道可能刚好确认收款；此时展示最新状态，不能改回过期。
                $latest = self::findBySOrderId($transaction['s_order_id']);
                if ($latest && $latest['status'] !== TransactionStateMachine::PENDING) {
                    return $latest;
                }
                throw $e;
            }
            $transaction = self::findBySOrderId($transaction['s_order_id'])
                ?: array_merge($transaction, ['status' => TransactionStateMachine::EXPIRED]);
        }

        return $transaction;
    }

    /**
     * 记录网关侧生成的会话/意图标识（如Stripe的Checkout Session id、
     * PayPal的Order id），供后续Webhook反查使用。与状态迁移无关，
     * preparePayment()完成后由PayController调用一次。
     *
     * @param string $sOrderId
     * @param string $externalRef
     */
    public static function setExternalRef($sOrderId, $externalRef)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            [
                'payment_intent' => $externalRef,
                'updated_at' => current_time('mysql', true),
            ],
            ['s_order_id' => $sOrderId]
        );
    }

    /**
     * 部分网关（如Stripe）在发起会话时就能拿到一个"完成态"才会用到的第二标识
     * （Checkout Session的底层PaymentIntent id），提前落库能让Webhook反查
     * 从下单起就同时命中payment_intent/transaction_id两列，不必等到某个
     * 特定事件处理完才补上——避免网关不保证投递顺序时，其它事件先到导致
     * findByExternalRef()反查不到而误判"找不到对应交易记录"。
     * 与状态迁移无关，preparePayment()完成后由PayController按需调用。
     *
     * @param string $sOrderId
     * @param string $transactionId
     */
    public static function setTransactionId($sOrderId, $transactionId)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            [
                'transaction_id' => $transactionId,
                'updated_at' => current_time('mysql', true),
            ],
            ['s_order_id' => $sOrderId]
        );
    }

    /**
     * 主动查单确认已付款后补录缺失的三方交易号，不改变付款状态或触发付款副作用。
     *
     * @return bool 是否实际补录；并发请求已写入交易号或改变状态时不覆盖
     * @throws \RuntimeException 数据库写入失败
     */
    public static function fillMissingPaidTransactionId($sOrderId, $transactionId)
    {
        global $wpdb;

        if (!is_string($transactionId) || trim($transactionId) === '') {
            return false;
        }

        $transaction = self::findBySOrderId($sOrderId);
        $storedId = $transaction['transaction_id'] ?? null;
        if (!$transaction || $transaction['status'] !== TransactionStateMachine::PAID
            || ($storedId !== null && $storedId !== '')) {
            return false;
        }

        // NULL会由wpdb生成IS NULL条件；空字符串则精确匹配，避免覆盖并发到账写入的ID。
        $updated = $wpdb->update(
            self::table(),
            ['transaction_id' => trim($transactionId), 'updated_at' => current_time('mysql', true)],
            ['s_order_id' => $sOrderId, 'status' => TransactionStateMachine::PAID, 'transaction_id' => $storedId]
        );
        if ($updated === false) {
            throw new \RuntimeException('已付款订单交易号写入失败');
        }

        return $updated > 0;
    }

    /**
     * 缓存 Gateway::preparePayment() 的返回值，供支付页面反复渲染时复用，
     * 避免每次访问/刷新支付页面都在三方网关那边重新创建一次支付会话。
     * 由PayController在preparePayment()成功之后立即调用一次。
     *
     * @param string $sOrderId
     * @param array $sessionData
     */
    public static function setGatewaySession($sOrderId, array $sessionData)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            [
                'gateway_session' => wp_json_encode($sessionData),
                'updated_at' => current_time('mysql', true),
            ],
            ['s_order_id' => $sOrderId]
        );
    }

    /** 会话创建/轮换的条件更新，禁止覆盖已付款或已经切换的会话。 */
    public static function savePendingSession(array $expected, array $sessionData, array $extra = []): bool
    {
        global $wpdb;

        $fields = array_merge(array_intersect_key($extra, array_flip([
            'payment_intent', 'transaction_id', 'gateway_session_expires_at',
        ])), [
            'gateway_session' => wp_json_encode($sessionData),
            'updated_at' => current_time('mysql', true),
        ]);
        $updated = $wpdb->update(self::table(), $fields, [
            's_order_id' => $expected['s_order_id'],
            'status' => TransactionStateMachine::PENDING,
            'payment_intent' => $expected['payment_intent'] ?? null,
            'gateway_session' => $expected['gateway_session'] ?? null,
        ]);
        if ($updated === false) {
            throw new \RuntimeException('支付会话写入失败');
        }
        return $updated > 0;
    }

    /**
     * @param array $transaction
     * @return array 解码后的sessionData，从未设置过时返回空数组
     */
    public static function getGatewaySession(array $transaction)
    {
        if (empty($transaction['gateway_session'])) {
            return [];
        }
        $decoded = json_decode($transaction['gateway_session'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 更新物流信息（sync-tracking接口用），与status迁移无关，单独一个方法。
     *
     * @param array $transaction
     * @param array $trackingData tracking_number/carrier_code/is_other_carrier(Y/N)/
     *                            tracking_url/shipped_at（见SyncTrackingController）
     */
    public static function updateTracking(array $transaction, array $trackingData)
    {
        global $wpdb;

        $carrierCode = (string) $trackingData['carrier_code'];

        $wpdb->update(
            self::table(),
            [
                'tracking_number' => $trackingData['tracking_number'],
                'carrier_code' => $carrierCode,
                'is_other_carrier' => ($trackingData['is_other_carrier'] ?? 'N') === 'Y' ? 'Y' : 'N',
                // shipping_company 是改造前的历史字段名，继续同步写入以兼容还在读它的
                // 旧代码（Admin/OrderMetaBox.php后台展示、/v1/order-query响应），
                // 新代码一律改用carrier_code/is_other_carrier。
                'shipping_company' => $carrierCode,
                'tracking_url' => $trackingData['tracking_url'] ?? null,
                'ship_date' => !empty($trackingData['shipped_at'])
                    ? gmdate('Y-m-d H:i:s', strtotime($trackingData['shipped_at']))
                    : null,
                'updated_at' => current_time('mysql', true),
            ],
            ['s_order_id' => $transaction['s_order_id']]
        );
    }

    /**
     * 物流单号是否已被【其它订单】使用过（防重，对应错误码10003）。
     * 同一订单重复同步同一个单号视为幂等更新，不算冲突。
     *
     * @param string $trackingNumber
     * @param string $excludeSOrderId 排除掉这个订单自身
     * @return bool
     */
    public static function trackingNumberUsedByOthers($trackingNumber, $excludeSOrderId)
    {
        global $wpdb;
        $table = self::table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE tracking_number = %s AND s_order_id != %s",
            $trackingNumber,
            $excludeSOrderId
        ));

        return $count > 0;
    }

    /**
     * 标记用户已经通过支付结果页的返回按钮跳回过S系统（见
     * Frontend/PaymentPage.php 的 return-to-shop 路由）。已标记的订单，
     * 结果页不再展示返回按钮，改为自动跳转WordPress的order-received页面。
     * 与status迁移无关，单独一个方法。
     *
     * @param array $transaction
     */
    public static function markReturnedToS(array $transaction)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            [
                'returned_to_s' => 1,
                'updated_at' => current_time('mysql', true),
            ],
            ['s_order_id' => $transaction['s_order_id']]
        );
    }

    /**
     * 是否已经跳回过S系统（returned_to_s标记）。老数据可能没有这个字段，
     * 用!empty()兜底。
     *
     * @param array $transaction
     * @return bool
     */
    public static function isReturnedToS(array $transaction)
    {
        return !empty($transaction['returned_to_s']);
    }

    /**
     * 访客风控绑定：用户第一次打开支付页时，把生成的随机 visitor_token
     * 连同当时的IP/UA写入交易记录（见 Frontend/PaymentPage.php 的
     * ensureVisitorBinding）。只允许首次打开者写入——WHERE条件带上
     * visitor_token为空的限制，并发场景下也只会有一个浏览器绑定成功，
     * 后来者（比如链接被转发给他人）不会覆盖首个打开者的记录。
     *
     * @param array $transaction
     * @param string $visitorToken 随机token（已种进该浏览器cookie）
     * @param string $ip 打开时的客户端IP，仅作审计线索
     * @param string $userAgent 打开时的UA，仅作审计线索
     * @return bool 是否绑定成功（已绑定过则返回false）
     */
    public static function bindVisitor(array $transaction, $visitorToken, $ip, $userAgent)
    {
        global $wpdb;

        $affected = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . '
             SET visitor_token = %s, visitor_ip = %s, visitor_user_agent = %s,
                 updated_at = %s
             WHERE s_order_id = %s AND (visitor_token IS NULL OR visitor_token = %s)',
            $visitorToken,
            $ip,
            $userAgent,
            current_time('mysql', true),
            $transaction['s_order_id'],
            ''
        ));

        return $affected > 0;
    }

    /**
     * 风控校验：当前访问者是不是打开这笔订单的浏览器。依据是cookie里的
     * visitor_token与库中记录是否一致（常数时间比较）。从未绑定过访客的
     * 老订单、或cookie缺失/不匹配，一律返回false，由调用方导去
     * order-received页面。
     *
     * @param array $transaction
     * @param string $givenToken 当前请求cookie里的值（可为空字符串）
     * @return bool
     */
    public static function isOriginalVisitor(array $transaction, $givenToken)
    {
        $stored = $transaction['visitor_token'] ?? '';
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        return TokenGenerator::isEqual($stored, (string) $givenToken);
    }

    /**
     * 解密某笔交易存储的网关配置快照。
     *
     * @param array $transaction
     * @return array
     */
    public static function decryptGatewayConfig(array $transaction)
    {
        return ConfigEncryptor::decrypt($transaction['gateway_config']);
    }
}
