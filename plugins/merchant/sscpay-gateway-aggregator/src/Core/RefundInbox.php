<?php

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Gateways\RefundDriver;
use PaymentGatewayAggregator\Helpers\SiteCipher;

if (!defined('ABSPATH')) { exit; }

/** Only verified refund notices enter this inbox. No signed HTTP headers or API keys are copied here. */
final class RefundInbox
{
    public static function receive(string $method, string $body, array $headers): array
    {
        global $wpdb;
        if (!self::hasSignature($method, $headers)) {
            return ['success' => false, 'http_status' => 400, 'code' => WebhookRouter::CODE_SIGNATURE_INVALID, 'message' => 'Missing refund signature'];
        }
        RefundRecords::assertReady();
        $transaction = self::locate($method, RefundDriver::references($method, $body));
        $source = null;
        if ($transaction) {
            $actual = $transaction['payment_method'];
            $config = TransactionManager::decryptGatewayConfig($transaction);
            if (self::verified($actual, $body, $headers, $config)) {
                $source = ['config_source' => 'transaction', 'config_id' => (int) $transaction['id']];
                $method = $actual;
            }
        }
        if (!$source) {
            $methods = self::methods($method);
            $seen = [];
            foreach (self::candidateConfigs($methods) as $candidate) {
                try {
                    $config = ConfigEncryptor::decrypt($candidate['cipher']);
                } catch (\RuntimeException $exception) {
                    continue;
                }
                $fingerprint = ConfigEncryptor::fingerprint($config);
                if (isset($seen[$fingerprint])) { continue; }
                $seen[$fingerprint] = true;
                if (!self::verified($candidate['payment_method'], $body, $headers, $config)) { continue; }
                $source = ['config_source' => $candidate['source'], 'config_id' => (int) $candidate['id']];
                $method = $candidate['payment_method'];
                $transaction = null;
                break;
            }
        }
        if (!$source) {
            return ['success' => false, 'http_status' => 400, 'code' => WebhookRouter::CODE_SIGNATURE_INVALID, 'message' => 'Invalid refund signature'];
        }
        $hash = ConfigEncryptor::fingerprint($config);
        $key = hash('sha256', self::family($method) . '|' . $hash . '|' . $body);
        $table = $wpdb->prefix . 'payment_refund_inbox';
        // Only the temporary, encrypted body of this verified refund can be retried. Remove it after processing.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (receipt_key,payment_method,config_source,config_id,config_hash,s_order_id,verified_body,status,next_attempt_at,created_at)
             VALUES (%s,%s,%s,%d,%s,%s,%s,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            $key, $method, $source['config_source'], $source['config_id'], $hash, $transaction['s_order_id'] ?? '', SiteCipher::encrypt($body)
        ));
        if ($result === false) { throw new \RuntimeException('已验签退款收件保存失败'); }
        $id = (int) $wpdb->insert_id;
        self::process($id);
        return ['success' => true, 'http_status' => 200, 'code' => 0, 'message' => 'Refund received'];
    }

    /** Registered accounts and inline credentials from previous orders can both receive notifications. */
    private static function candidateConfigs(array $methods): \Generator
    {
        global $wpdb;
        $table = $wpdb->prefix . 'payment_gateway_configs';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, payment_method, config_encrypted AS cipher, 'registered' AS source FROM {$table}
             WHERE status='active' AND payment_method IN (%s,%s) ORDER BY id DESC", $methods[0], end($methods)
        ), ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('退款验签配置读取失败'); }
        foreach ($rows ?: [] as $row) { yield $row; }
        $table = $wpdb->prefix . 'payment_transactions';
        $cursor = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, payment_method, gateway_config AS cipher, 'transaction' AS source FROM {$table}
                 WHERE payment_method IN (%s,%s) AND id>%d ORDER BY id LIMIT 100", $methods[0], end($methods), $cursor
            ), ARRAY_A);
            if ($wpdb->last_error) { throw new \RuntimeException('历史退款验签配置读取失败'); }
            foreach ($rows ?: [] as $row) {
                $cursor = (int) $row['id'];
                yield $row;
            }
        } while (count($rows ?: []) === 100);
    }

    private static function methods(string $method): array
    {
        return self::family($method) === 'paypal' ? ['paypal_js', 'paypal_rest'] : [$method];
    }

    private static function family(string $method): string
    {
        return in_array($method, ['paypal', 'paypal_js', 'paypal_rest'], true) ? 'paypal' : $method;
    }

    private static function hasSignature(string $method, array $headers): bool
    {
        $required = [
            'paypal' => ['paypal-transmission-id', 'paypal-transmission-time', 'paypal-cert-url', 'paypal-auth-algo', 'paypal-transmission-sig'],
            'stripe' => ['stripe-signature'], 'airwallex' => ['x-timestamp', 'x-signature'], 'antom' => ['client-id', 'request-time', 'signature'],
        ];
        if ($method === 'stripe') {
            $signature = $headers['stripe-signature'] ?? '';
            if (!is_string($signature) || !preg_match('/(?:^|,)\s*t=(\d+)(?=,|$)/', $signature, $timestamp)
                || !preg_match('/(?:^|,)\s*v1=[a-fA-F0-9]{64}(?=,|$)/', $signature)
                || abs(time() - (int) $timestamp[1]) > 300) { return false; }
        }
        foreach ($required[self::family($method)] as $name) {
            if (!is_string($headers[$name] ?? null) || $headers[$name] === '') { return false; }
        }
        return true;
    }

    private static function verified(string $method, string $body, array $headers, array $config): bool
    {
        if ($method === 'antom' && (empty($config['client_id']) || ($headers['client-id'] ?? '') !== $config['client_id'])) {
            return false;
        }
        return GatewayFactory::create($method, $config)->verifyCallbackSignature($body, $headers, $config);
    }

    private static function locate(string $method, array $references): ?array
    {
        $match = null;
        foreach (array_unique($references) as $ref) {
            if (!is_string($ref) || $ref === '') { continue; }
            $candidate = TransactionManager::findByExternalRef($ref);
            if (!$candidate || !in_array($candidate['payment_method'], self::methods($method), true)) { continue; }
            if ($match && $match['s_order_id'] !== $candidate['s_order_id']) {
                throw new \UnexpectedValueException('退款的多个支付引用对应不同订单');
            }
            $match = $candidate;
        }
        return $match;
    }

    public static function retryDue(): void
    {
        global $wpdb;
        if (!RefundRecords::storageReady()) { return; }
        $table = $wpdb->prefix . 'payment_refund_inbox';
        $ids = $wpdb->get_col("SELECT id FROM {$table} WHERE status = 'pending' AND next_attempt_at <= UTC_TIMESTAMP()
            ORDER BY next_attempt_at,id LIMIT 20");
        foreach ($ids ?: [] as $id) { self::process((int) $id); }
    }

    private static function process(int $id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'payment_refund_inbox';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='pending' AND next_attempt_at <= UTC_TIMESTAMP()", $id), ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('退款收件读取失败'); }
        if (!$row) { return; }
        // Claim before any provider HTTP call; an interrupted worker becomes eligible again after its lease.
        $attempt = (int) $row['attempts'] + 1;
        $claim = $wpdb->query($wpdb->prepare("UPDATE {$table} SET attempts=%d,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)
            WHERE id=%d AND status='pending' AND attempts=%d AND next_attempt_at <= UTC_TIMESTAMP()", $attempt, $id, $row['attempts']));
        if ($claim === false) { throw new \RuntimeException('退款收件领取失败'); }
        if (!$claim) { return; }
        $where = ['id' => $id, 'status' => 'pending', 'attempts' => $attempt];
        $scanAdvanced = false;
        try {
            $method = $row['payment_method'];
            $config = self::loadConfig($row);
            $body = SiteCipher::decrypt($row['verified_body']);
            $refs = RefundDriver::references($method, $body);
            $transaction = $row['s_order_id'] !== ''
                ? TransactionManager::findBySOrderId($row['s_order_id']) : self::locate($method, $refs);
            if ($transaction) { self::checkConfig($row, $transaction); }
            $result = RefundDriver::read($method, $body, [], $config, $transaction);
            if (!$transaction) {
                $refs = array_merge($refs, $result['evidence']['payment_refs'] ?? []);
                foreach ($result['refunds'] as $refund) { $refs = array_merge($refs, $refund['payment_refs']); }
                $transaction = self::locate($method, $refs);
                if ($transaction) {
                    self::checkConfig($row, $transaction);
                    if (empty($result['refunds'])) {
                        $result = RefundDriver::read($method, $body, [], $config, $transaction);
                    }
                }
            }
            if ($transaction && !empty($transaction['paid_at'])) {
                self::rememberQueryFailures($transaction, $config, $result['evidence']['observations'] ?? []);
            }
            $matched = null;
            $evidence = $result['evidence'];
            if (!$transaction && $method === 'antom' && in_array($result['kind'], ['pending', 'lookup'], true) && !empty($evidence['refund_id'])) {
                $matched = self::findIsolatedAntomRefund($row, $config, $evidence, $where, $scanAdvanced);
                if ($matched) {
                    $transaction = TransactionManager::findBySOrderId($matched['s_order_id']);
                    if (!$transaction) { throw new \RuntimeException('等待恢复退款原订单'); }
                    self::checkConfig($row, $transaction);
                    if ((!empty($evidence['amount']) && $matched['amount'] !== $evidence['amount'])
                        || (!empty($evidence['currency']) && $matched['currency'] !== $evidence['currency'])) {
                        throw new \UnexpectedValueException('通知与查单退款金额或币种冲突');
                    }
                }
            }
            if ($matched) {
                $update = ['status' => 'completed', 'verified_body' => null, 's_order_id' => $matched['s_order_id']];
            } elseif (in_array($result['kind'], ['failed', 'ignored'], true)) {
                $update = ['status' => $result['kind'], 'verified_body' => null];
                if ($result['kind'] === 'failed' && !empty($evidence['refund_id'])) {
                    if (isset($evidence['livemode']) && $evidence['livemode'] !== (PaymentBase::resolveMode($config) === PaymentBase::MODE_LIVE)) {
                        throw new \UnexpectedValueException('退款失败通知的环境与支付配置不符');
                    }
                    $update = array_merge($update, self::failedNotice($method, $row['config_hash'], $evidence['refund_id']));
                }
            } else {
                if (!$transaction || empty($transaction['paid_at'])) { throw new \RuntimeException('等待匹配已付款交易'); }
                $finalStatus = 'completed';
                if (in_array($result['kind'], ['pending', 'lookup'], true) && empty($result['refunds'])) {
                    $lookup = RefundDriver::query($method, $config, $transaction);
                    self::rememberQueryFailures($transaction, $config, $lookup['evidence']['observations'] ?? []);
                    $expected = $result['evidence']['refund_id'] ?? null;
                    $found = $expected !== null && in_array($expected, array_column($lookup['refunds'], 'refund_id'), true);
                    RefundRecords::record($transaction, $config, $lookup['refunds']);
                    foreach ($lookup['evidence']['observations'] ?? [] as $observation) {
                        if ($expected !== null && ($observation['refund_id'] ?? null) === $expected
                            && ($observation['kind'] ?? null) === 'failed') {
                            $finalStatus = 'failed';
                        }
                    }
                    if (!$found && $finalStatus !== 'failed') { throw new \RuntimeException('等待渠道确认退款明细'); }
                } else {
                    if (!$result['refunds']) { throw new \RuntimeException('渠道未提供实际退款明细'); }
                    self::recordNotice($method, $transaction, $config, $result['refunds']);
                }
                if (!empty($result['evidence']['observations'])) {
                    foreach ($result['evidence']['observations'] as $observation) {
                        if (in_array($observation['kind'] ?? '', ['pending', 'lookup'], true)) {
                            throw new \RuntimeException('同一付款仍有待确认的退款明细');
                        }
                    }
                }
                $update = ['status' => $finalStatus, 'verified_body' => null, 's_order_id' => $transaction['s_order_id']];
                if ($finalStatus === 'failed') {
                    $update = array_merge($update, self::failedNotice($method, $row['config_hash'], $expected));
                }
            }
        } catch (\Throwable $exception) {
            $delay = $scanAdvanced ? 60 : min(21600, 60 * (2 ** min(9, $attempt - 1)));
            $update = ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay), 'last_error' => get_class($exception)];
            if (in_array($exception->getFile(), [__FILE__, __DIR__ . '/RefundRecords.php'], true)) {
                $update['last_error'] = $exception->getMessage();
            }
            if ($exception instanceof \UnexpectedValueException) { $update['status'] = 'review'; }
            if ($attempt === 1 || isset($update['status'])) {
                LogManager::warning($row['s_order_id'] ?: ($transaction['s_order_id'] ?? LogManager::SYSTEM_ORDER_ID),
                    '退款收件待核对', ['response_data' => ['receipt_id' => $id, 'error' => $update['last_error']]]);
            }
        }
        if ($wpdb->update($table, $update, $where) === false) { throw new \RuntimeException('退款收件处理结果保存失败'); }
    }

    /** A later authenticated provider query can also confirm failure after a success was observed. */
    public static function rememberQueryFailures(array $order, array $config, array $observations): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'payment_refund_inbox';
        $hash = ConfigEncryptor::fingerprint($config);
        foreach ($observations as $item) {
            if (($item['kind'] ?? null) !== 'failed' || empty($item['refund_id'])
                || !in_array($order['transaction_id'], $item['payment_refs'] ?? [], true)) { continue; }
            if (isset($item['livemode']) && $item['livemode'] !== (PaymentBase::resolveMode($config) === PaymentBase::MODE_LIVE)) {
                throw new \UnexpectedValueException('退款查询环境与支付配置不符');
            }
            $source = RefundRecords::sourceHash($order['payment_method'], $hash, $item['refund_id']);
            $key = hash('sha256', 'queried_failure|' . $source . '|' . $order['s_order_id']);
            $seen = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE receipt_key=%s", $key));
            if ($wpdb->last_error) { throw new \RuntimeException('已核实的退款失败记录读取失败'); }
            if ($seen !== null) { continue; }
            $facts = self::failedNotice($order['payment_method'], $hash, $item['refund_id']);
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (receipt_key,failed_source,payment_method,config_source,config_id,config_hash,s_order_id,status,last_error,next_attempt_at,created_at)
                 VALUES (%s,%s,%s,'transaction',%d,%s,%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                $key, $source, $order['payment_method'], $order['id'], $hash, $order['s_order_id'], $facts['status'] ?? 'failed', $facts['last_error'] ?? ''
            ));
            if ($result === false) { throw new \RuntimeException('已核实的退款失败记录保存失败'); }
        }
    }

    private static function failedNotice(string $method, string $configHash, string $id): array
    {
        $update = ['failed_source' => RefundRecords::sourceHash($method, $configHash, $id)];
        $prior = RefundRecords::findConfirmed($method, $configHash, $id);
        if ($prior) {
            $reason = '渠道对已确认的同一退款报告失败，请核对已投递的退款与资金流水';
            $update += ['status' => 'review', 's_order_id' => $prior['s_order_id'], 'last_error' => $reason];
            LogManager::warning($prior['s_order_id'], $reason, ['response_data' => ['refund_id' => $id]]);
        }
        return $update;
    }

    private static function recordNotice(string $method, array $transaction, array $config, array $refunds): void
    {
        $original = $transaction['transaction_id'] ?? '';
        $session = $transaction['payment_intent'] ?? '';
        $details = null;
        foreach ($refunds as $key => $refund) {
            if (in_array($original, $refund['payment_refs'], true) || !in_array($session, $refund['payment_refs'], true)) {
                continue;
            }
            // Some notices only link the parent order. Historical plugins could have overwritten a stored
            // capture ID with a refund ID. Never echo that value as the parent without independent evidence.
            if ($details === null) {
                $details = RefundDriver::query($method, $config, $transaction);
            }
            foreach ($details['refunds'] as $candidate) {
                if ($candidate['refund_id'] !== $refund['refund_id']) { continue; }
                if ($candidate['currency'] !== $refund['currency'] || $candidate['amount'] !== $refund['amount']) {
                    throw new \UnexpectedValueException('退款通知与支付明细冲突');
                }
                if (in_array($original, $candidate['payment_refs'], true)) {
                    $refunds[$key] = $candidate;
                }
            }
        }
        RefundRecords::record($transaction, $config, $refunds);
    }

    /** Standard Antom refund notices omit the payment ID. Only a signed inquiryPayment can prove the parent. */
    private static function findIsolatedAntomRefund(array $row, array $config, array $evidence, array $claim, bool &$scanAdvanced): ?array
    {
        global $wpdb;
        $refundId = $evidence['refund_id'];
        $confirmed = RefundRecords::findConfirmed('antom', $row['config_hash'], $refundId);
        if ($confirmed || (int) $claim['attempts'] <= 1) { return $confirmed; }
        // Limit provider calls per worker pass. Resume the search on the next cron tick.
        $cursor = (int) $row['lookup_cursor'];
        $table = $wpdb->prefix . 'payment_transactions';
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE payment_method='antom' AND paid_at IS NOT NULL AND id>%d ORDER BY id LIMIT 3", $cursor
        ), ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('待核对退款原单读取失败'); }
        foreach ($orders ?: [] as $order) {
            $cursor = (int) $order['id'];
            try {
                if (ConfigEncryptor::fingerprint(TransactionManager::decryptGatewayConfig($order)) !== $row['config_hash']) { continue; }
                $details = RefundDriver::query('antom', $config, $order);
                foreach ($details['refunds'] as $refund) {
                    if ($refund['refund_id'] === $refundId) {
                        if ((!empty($evidence['amount']) && $refund['amount'] !== $evidence['amount'])
                            || (!empty($evidence['currency']) && $refund['currency'] !== $evidence['currency'])) {
                            throw new \UnexpectedValueException('通知与查单退款金额或币种冲突');
                        }
                        RefundRecords::record($order, $config, [$refund]);
                    }
                }
            } catch (\UnexpectedValueException $conflict) {
                throw $conflict;
            } catch (\RuntimeException $exception) {
                // A single unavailable payment must not prevent checking other orders; the cursor wraps on exhaustion.
                continue;
            }
            $confirmed = RefundRecords::findConfirmed('antom', $row['config_hash'], $refundId);
            if ($confirmed) { return $confirmed; }
        }
        if (count($orders ?: []) < 3) { $cursor = 0; }
        $scanAdvanced = $cursor > 0;
        if ($wpdb->update($wpdb->prefix . 'payment_refund_inbox', ['lookup_cursor' => $cursor], $claim) === false) {
            throw new \RuntimeException('待核对退款查单位置保存失败');
        }
        return null;
    }

    private static function loadConfig(array $row): array
    {
        global $wpdb;
        if ($row['config_source'] === 'transaction') {
            $table = $wpdb->prefix . 'payment_transactions';
            $sql = "SELECT gateway_config FROM {$table} WHERE id=%d";
        } else {
            $table = $wpdb->prefix . 'payment_gateway_configs';
            $sql = "SELECT config_encrypted FROM {$table} WHERE id=%d";
        }
        $cipher = $wpdb->get_var($wpdb->prepare($sql, $row['config_id']));
        if (!$cipher) { throw new \RuntimeException('退款绑定配置未找到'); }
        $config = ConfigEncryptor::decrypt($cipher);
        if (!hash_equals($row['config_hash'], ConfigEncryptor::fingerprint($config))) {
            throw new \UnexpectedValueException('原验签配置已变更，需复核账户');
        }
        return $config;
    }

    private static function checkConfig(array $row, array $transaction): void
    {
        if (!in_array($transaction['payment_method'], self::methods($row['payment_method']), true)
            || !hash_equals($row['config_hash'], ConfigEncryptor::fingerprint(TransactionManager::decryptGatewayConfig($transaction)))) {
            throw new \UnexpectedValueException('退款验签账户与交易快照不符');
        }
    }
}
