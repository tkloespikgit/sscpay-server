<?php
/** Provider identifiers remain addressable after payment-session replacement. */
namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) { exit; }

class PaymentSessionReferences
{
    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . 'payment_session_references';
    }

    /** Share this lock between session creation/replacement and PayPal capture. */
    public static function withOrderLock($sOrderId, callable $callback)
    {
        global $wpdb;
        $lock = 'pga_session_' . sha1($wpdb->prefix . '|' . $sOrderId);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== '1') {
            throw new \RuntimeException('支付会话正在处理中，请稍后重试');
        }
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function remember(array $transaction, array $sessionData = [])
    {
        $orderId = $transaction['s_order_id'] ?? '';
        if (!is_string($orderId) || $orderId === '' || strlen($orderId) > 64) {
            throw new \RuntimeException('支付会话引用缺少有效的本地订单号');
        }
        $currentRef = $transaction['payment_intent'] ?? '';
        if (is_string($currentRef) && $currentRef !== '') {
            $currentData = TransactionManager::getGatewaySession($transaction);
            // A timed-out renewal keeps the old ID while caching its new draft.
            // The draft must never replace the old attempt's complete metadata.
            if (!empty($currentData['_creating'])) {
                $previous = self::findReference($currentRef);
                $currentData = $previous && $previous['s_order_id'] === $orderId
                    && $previous['session_ref'] === $currentRef ? self::decodeSessionData($previous) : [];
            }
            self::rememberReference($orderId, $currentRef, $currentRef, $currentData);
            self::rememberReference($orderId, $transaction['transaction_id'] ?? null, $currentRef, $currentData);
        }
        $newRef = $sessionData['external_ref'] ?? '';
        if (is_string($newRef) && $newRef !== '') {
            self::rememberReference($orderId, $newRef, $newRef, $sessionData);
            self::rememberReference($orderId, $sessionData['transaction_id'] ?? null, $newRef, $sessionData);
        }
    }

    public static function find($externalRef)
    {
        $reference = self::findReference($externalRef);
        if (!$reference) { return null; }
        $transaction = TransactionManager::findBySOrderId($reference['s_order_id']);
        if (!$transaction || $transaction['s_order_id'] !== $reference['s_order_id']) { return null; }
        $transaction['_pga_matched_session_ref'] = $reference['session_ref'];
        $transaction['_pga_matched_session_data'] = self::decodeSessionData($reference);
        return $transaction;
    }

    /** Metadata is a lookup hint only; the caller must still verify the order's signature. */
    public static function findByAttemptKey($attemptKey)
    {
        if (!is_string($attemptKey) || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $attemptKey)) {
            return null;
        }
        global $wpdb;
        $needle = '%' . $wpdb->esc_like('"_attempt_key":"' . $attemptKey . '"') . '%';
        $references = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::tableName() . ' WHERE session_data LIKE %s', $needle
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) { throw new \RuntimeException('支付尝试引用读取失败'); }
        foreach ($references ?: [] as $reference) {
            if ((self::decodeSessionData($reference)['_attempt_key'] ?? null) !== $attemptKey) { continue; }
            $transaction = self::find($reference['external_ref']);
            if ($transaction && $transaction['payment_method'] === 'stripe') { return $transaction; }
        }
        return null;
    }

    public static function findReference($externalRef)
    {
        global $wpdb;
        if (!is_string($externalRef) || $externalRef === '' || strlen($externalRef) > 255) { return null; }
        $table = self::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ref_hash = %s LIMIT 1", hash('sha256', $externalRef)
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) { throw new \RuntimeException('支付会话历史引用读取失败'); }
        // Hash indexing must not relax raw identifier equality (SQL collation).
        return $row && isset($row['external_ref']) && $row['external_ref'] === $externalRef ? $row : null;
    }

    public static function decodeSessionData(array $reference)
    {
        $decoded = json_decode($reference['session_data'] ?? '', true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function rememberReference($orderId, $externalRef, $sessionRef, array $sessionData)
    {
        global $wpdb;
        if ($externalRef === null || $externalRef === '') { return; }
        if (!is_string($externalRef) || strlen($externalRef) > 255 || strlen($sessionRef) > 255) {
            throw new \RuntimeException('支付会话引用格式无效');
        }
        $table = self::tableName();
        $encoded = wp_json_encode($sessionData);
        if ($encoded === false) { throw new \RuntimeException('支付会话引用序列化失败'); }
        $written = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (ref_hash, external_ref, s_order_id, session_ref, session_data, created_at)
             VALUES (%s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE ref_hash = VALUES(ref_hash)",
            hash('sha256', $externalRef), $externalRef, $orderId, $sessionRef, $encoded, current_time('mysql', true)
        ));
        if ($written === false) { throw new \RuntimeException('支付会话历史引用写入失败'); }
        $stored = self::findReference($externalRef);
        if (!$stored || $stored['s_order_id'] !== $orderId || $stored['session_ref'] !== $sessionRef) {
            throw new \RuntimeException('支付会话引用已属于其他订单或支付尝试');
        }
        if ($sessionData !== [] && ($stored['session_data'] ?? '') !== $encoded) {
            if ($wpdb->update($table, ['session_data' => $encoded], ['ref_hash' => hash('sha256', $externalRef)]) === false) {
                throw new \RuntimeException('支付会话历史数据写入失败');
            }
        }
    }
}
