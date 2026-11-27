<?php

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Gateways\RefundDriver;

if (!defined('ABSPATH')) { exit; }

/** One provider refund creates one immutable callback and outbox row for the successful payment. */
final class RefundRecords
{
    public static function storageReady(): bool
    {
        return version_compare((string) get_option(Migrator::OPTION_DB_VERSION, '0.0.0'), '1.7.0', '>=');
    }

    public static function assertReady(): void
    {
        if (!self::storageReady()) { throw new \RuntimeException('逐笔退款事务表尚未就绪'); }
    }

    public static function reconcile(array $transaction, ?bool &$complete = null): array
    {
        self::assertReady();
        $complete = true;
        if (!empty($transaction['paid_at'])) {
            try {
                $config = TransactionManager::decryptGatewayConfig($transaction);
                $result = RefundDriver::query($transaction['payment_method'], $config, $transaction);
                RefundInbox::rememberQueryFailures($transaction, $config, $result['evidence']['observations'] ?? []);
                self::record($transaction, $config, $result['refunds']);
                $complete = !in_array($result['kind'], ['lookup', 'pending'], true);
                foreach ($result['evidence']['observations'] ?? [] as $observation) {
                    if (in_array($observation['kind'] ?? '', ['lookup', 'pending'], true)) {
                        $complete = false;
                    }
                }
            } catch (\Throwable $exception) {
                $complete = false;
                LogManager::warning($transaction['s_order_id'], '退款补查暂未完成',
                    ['response_data' => ['error_class' => get_class($exception)]]);
            }
        }
        global $wpdb;
        if ($complete && !empty($transaction['paid_at'])) {
            $inbox = $wpdb->prefix . 'payment_refund_inbox';
            $unresolved = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$inbox} WHERE s_order_id=%s AND status IN ('pending','review') LIMIT 1", $transaction['s_order_id']));
            $complete = !$wpdb->last_error && $unresolved === null;
        }
        return self::forOrder($transaction['s_order_id']);
    }

    public static function forOrder(string $orderId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'payment_refunds';
        $inbox = $wpdb->prefix . 'payment_refund_inbox';
        $rows = $wpdb->get_col($wpdb->prepare("SELECT r.payload FROM {$table} r WHERE r.s_order_id=%s
            AND NOT EXISTS (SELECT 1 FROM {$inbox} i WHERE i.failed_source=r.source_hash) ORDER BY r.id", $orderId));
        if ($wpdb->last_error) { throw new \RuntimeException('逐笔退款凭证读取失败'); }
        return array_map(static function ($payload) { return json_decode($payload, true, 512, JSON_THROW_ON_ERROR); }, $rows ?: []);
    }

    public static function record(array $transaction, array $config, array $refunds): void
    {
        if (!$refunds) { return; }
        self::assertReady();
        $orderId = $transaction['s_order_id'];
        $created = PaymentSessionReferences::withOrderLock($orderId, static function () use ($orderId, $config, $refunds): bool {
            global $wpdb;
            $transaction = TransactionManager::findBySOrderId($orderId);
            if (!$transaction || empty($transaction['paid_at'])
                || ConfigEncryptor::fingerprint(TransactionManager::decryptGatewayConfig($transaction)) !== ConfigEncryptor::fingerprint($config)) {
                throw new \UnexpectedValueException('退款支付交易或渠道快照未确认');
            }
            $created = false;
            $error = null;
            foreach ($refunds as $refund) {
                if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('无法开始退款事务'); }
                try {
                    $payload = self::payload($transaction, $config, $refund);
                    $saved = self::save($payload, ConfigEncryptor::fingerprint($config));
                    $table = $wpdb->prefix . 'payment_refunds';
                    $full = $wpdb->get_var($wpdb->prepare(
                        "SELECT SUM(amount) >= CAST(%s AS DECIMAL(18,4)) FROM {$table} WHERE s_order_id=%s", $transaction['amount'], $orderId
                    ));
                    if ($wpdb->last_error) { throw new \RuntimeException('退款本金汇总失败'); }
                    if ($full && in_array($transaction['status'], [TransactionStateMachine::PAID, TransactionStateMachine::DISPUTING], true)) {
                        TransactionManager::updateStatus($transaction, TransactionStateMachine::REFUNDED);
                    }
                    if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('退款事务提交失败'); }
                    $created = $saved || $created;
                } catch (\Throwable $exception) {
                    $wpdb->query('ROLLBACK');
                    $error = $exception;
                }
            }
            if ($error) { throw $error; }
            return $created;
        });
        if ($created) { QueueManager::wakeWorker(); }
    }

    private static function payload(array $transaction, array $config, array $refund): array
    {
        $id = $refund['refund_id'] ?? null;
        $amount = $refund['amount'] ?? null;
        $currency = $refund['currency'] ?? null;
        $time = $refund['refunded_at'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 255 || !is_string($amount)
            || !preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount) || trim($amount, '0.') === ''
            || !is_string($currency) || $currency !== strtoupper($transaction['currency'])
            || !is_string($time) || !preg_match('/T.*(?:Z|[+-]\d\d:\d\d)$/D', $time)) {
            throw new \UnexpectedValueException('退款金额、币种、标识或时间未确认');
        }
        $refs = $refund['payment_refs'] ?? [];
        $reference = $transaction['transaction_id'] ?? null;
        if (!is_string($reference) || $reference === '') { throw new \RuntimeException('等待收款交易号'); }
        if (!in_array($reference, $refs, true)) {
            throw new \UnexpectedValueException('退款与本单已付交易引用不符');
        }
        $live = PaymentBase::resolveMode($config) === PaymentBase::MODE_LIVE;
        if (isset($refund['livemode']) && $refund['livemode'] !== $live) {
            throw new \UnexpectedValueException('退款环境与支付配置不符');
        }
        return [
            'event' => 'payment_refund', 'status' => 'succeeded', 's_order_id' => $transaction['s_order_id'],
            'payment_method' => $transaction['payment_method'], 'refund_id' => $id, 'payment_reference' => $reference,
            'amount' => $amount, 'currency' => $currency, 'refunded_at' => $time, 'livemode' => $live,
        ];
    }

    private static function save(array $payload, string $configHash): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'payment_refunds';
        $key = hash('sha256', $payload['s_order_id'] . "\0" . $payload['refund_id']);
        $sourceHash = self::sourceHash($payload['payment_method'], $configHash, $payload['refund_id']);
        $inbox = $wpdb->prefix . 'payment_refund_inbox';
        $failed = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$inbox} WHERE failed_source=%s LIMIT 1", $sourceHash));
        if ($wpdb->last_error) { throw new \RuntimeException('退款终态读取失败'); }
        if ($failed !== null) { throw new \UnexpectedValueException('同一退款存在已核实的失败通知，请核对渠道终态与已入账资金'); }
        $prior = $wpdb->get_var($wpdb->prepare("SELECT payload FROM {$table} WHERE refund_key=%s FOR UPDATE", $key));
        if ($wpdb->last_error) { throw new \RuntimeException('逐笔退款凭证读取失败'); }
        if ($prior !== null) {
            $old = json_decode($prior, true, 512, JSON_THROW_ON_ERROR);
            foreach (['currency', 'livemode', 'amount', 'payment_reference', 'payment_method'] as $field) {
                if ($field === 'amount' && self::decimal($old[$field]) === self::decimal($payload[$field])) { continue; }
                if ($old[$field] !== $payload[$field]) { throw new \UnexpectedValueException('同一退款号的资金凭证冲突'); }
            }
            return false;
        }
        $owner = $wpdb->get_var($wpdb->prepare("SELECT s_order_id FROM {$table} WHERE source_hash=%s FOR UPDATE", $sourceHash));
        if ($wpdb->last_error) { throw new \RuntimeException('退款所属原单读取失败'); }
        if ($owner !== null && $owner !== $payload['s_order_id']) { throw new \UnexpectedValueException('同账户退款号关联多个订单'); }
        $inserted = $wpdb->insert($table, [
            'refund_key' => $key, 'source_hash' => $sourceHash,
            's_order_id' => $payload['s_order_id'], 'amount' => $payload['amount'],
            'payload' => wp_json_encode($payload),
        ]);
        if ($inserted === false) { throw new \RuntimeException('逐笔退款保存失败'); }
        QueueManager::enqueue($payload['s_order_id'], 'payment_refund', $payload);
        return true;
    }

    public static function hasFailedNotice(string $orderId, string $id): bool
    {
        global $wpdb;
        $refunds = $wpdb->prefix . 'payment_refunds';
        $inbox = $wpdb->prefix . 'payment_refund_inbox';
        $failed = $wpdb->get_var($wpdb->prepare("SELECT i.id FROM {$refunds} r INNER JOIN {$inbox} i ON i.failed_source=r.source_hash WHERE r.refund_key=%s LIMIT 1",
            hash('sha256', $orderId . "\0" . $id)));
        if ($wpdb->last_error) { throw new \RuntimeException('退款终态读取失败'); }
        return $failed !== null;
    }

    public static function sourceHash(string $method, string $configHash, string $id): string
    {
        $family = in_array($method, ['paypal', 'paypal_js', 'paypal_rest'], true) ? 'paypal' : $method;
        return hash('sha256', $family . "\0" . $configHash . "\0" . $id);
    }

    /** Only authenticated order queries or verified payment-linked notices can create this mapping. */
    public static function findConfirmed(string $method, string $configHash, string $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'payment_refunds';
        $rows = $wpdb->get_col($wpdb->prepare("SELECT payload FROM {$table} WHERE source_hash=%s LIMIT 2", self::sourceHash($method, $configHash, $id)));
        if ($wpdb->last_error) { throw new \RuntimeException('原交易退款索引读取失败'); }
        if (count($rows ?: []) > 1) { throw new \UnexpectedValueException('同账户退款号关联多个订单'); }
        return $rows ? json_decode($rows[0], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private static function decimal(string $amount): string
    {
        return strpos($amount, '.') === false ? $amount : rtrim(rtrim($amount, '0'), '.');
    }
}
