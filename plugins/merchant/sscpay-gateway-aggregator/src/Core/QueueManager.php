<?php
/**
 * 回调重试队列（wp_payment_callback_queue）：负责把 payment_status /
 * tracking_sync 事件可靠地投递给S系统的 callback_url，失败按指数退避重试。
 *
 * 重试节奏：1分钟、5分钟、30分钟、2小时、6小时（对应设计文档第十三节），
 * 即最多重试5次，第5次仍失败则标记为failed，不再自动重试（需人工介入，
 * 可在日志/队列表里看到last_error）。
 *
 * 依赖真实的系统级crontab调用 wp-cron.php 来准时触发——WP默认的
 * "有人访问页面才触发cron"方式在低流量站点上不可靠。DISABLE_WP_CRON 已由插件主文件
 * 内置为 true（可在 wp-config.php 里覆盖），部署时务必在服务器crontab里配置：
 *   *\/1 * * * * curl -s https://site/wp-cron.php >/dev/null 2>&1
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Helpers\HttpClient;
use PaymentGatewayAggregator\Models\CallbackQueue;

if (!defined('ABSPATH')) {
    exit;
}

class QueueManager
{
    const CRON_HOOK = 'pga_process_callback_queue';
    const CRON_HOOK_IMMEDIATE = 'pga_process_callback_queue_immediate';
    const CRON_INTERVAL = 'pga_five_minutes';
    // HTTP 超时为15秒；为异常中断的worker保留5分钟租期，再由cron恢复。
    const PROCESSING_LEASE_SECONDS = 300;

    /** 索引0对应第1次重试的延迟秒数，以此类推，共5档 */
    private static $backoffSeconds = [60, 300, 1800, 7200, 21600];

    private static function table()
    {
        return CallbackQueue::tableName();
    }

    // -------------------- 定时任务注册 --------------------

    public static function registerCronSchedule($schedules)
    {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('每5分钟（支付插件回调队列）', 'sscpay-gateway-aggregator'),
        ];
        return $schedules;
    }

    public static function ensureScheduled()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Commit first, then wake an asynchronous cron request. Explicit spawn is needed
     * because this plugin disables ordinary traffic-triggered WP-Cron by default.
     * Scheduling failure must never turn an already-committed webhook into an error;
     * the durable queue remains available to the regular worker.
     */
    public static function wakeWorker(): void
    {
        try {
            if (!wp_next_scheduled(self::CRON_HOOK_IMMEDIATE)) {
                $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK_IMMEDIATE);
                if ($scheduled === false || is_wp_error($scheduled)) {
                    throw new \RuntimeException('Immediate callback worker could not be scheduled');
                }
            }
            // spawn_cron issues a nonblocking loopback request, not the S-system HTTP call.
            spawn_cron();
        } catch (\Throwable $e) {
            try {
                LogManager::warning(LogManager::SYSTEM_ORDER_ID, '回调队列即时唤醒失败，将由常规定时任务重试', [
                    'response_data' => ['exception_class' => get_class($e)],
                ]);
            } catch (\Throwable $loggingError) {
                // Queue persistence already succeeded; an audit failure cannot undo it.
            }
        }
    }

    public static function unschedule()
    {
        foreach ([self::CRON_HOOK, self::CRON_HOOK_IMMEDIATE] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    // -------------------- 入队 --------------------

    /**
     * @param string $sOrderId
     * @param string $eventType 'payment_status' | 'tracking_sync'
     * @param array $payload
     * @return int 插入行的id，供调用方需要立即投递时传给 deliverNow()
     */
    public static function enqueue($sOrderId, $eventType, array $payload)
    {
        global $wpdb;

        $encodedPayload = wp_json_encode($payload);
        if ($encodedPayload === false) {
            throw new \RuntimeException('回调任务序列化失败');
        }
        $inserted = $wpdb->insert(self::table(), [
            's_order_id' => $sOrderId,
            'event_type' => $eventType,
            'payload' => $encodedPayload,
            'retry_count' => 0,
            'max_retries' => count(self::$backoffSeconds),
            'next_retry_at' => current_time('mysql', true), // 立即可投递
            'status' => 'pending',
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        if ($inserted === false || (int) $wpdb->insert_id <= 0) {
            throw new \RuntimeException('回调任务入队失败');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * 入队后立刻同步投递一次，不等下一次cron轮询——多数情况下S系统当场就能
     * 收到通知，cron退化成"首次投递失败时的兜底重试"，不再是唯一投递路径
     * （原来的实现完全依赖 pga_process_callback_queue 这个WP-Cron钩子，
     * 如果服务器没配置系统级crontab去戳wp-cron.php，通知会一直卡在队列里
     * 发不出去）。
     *
     * 内部复用 deliver()：成功直接标success；失败走一样的指数退避重试逻辑，
     * 把status留在pending，后面cron的processDueBatch()会按原节奏继续重试，
     * 行为和"只走cron"路径完全一致，只是多了这一次立即尝试。
     *
     * @param int $queueId enqueue()的返回值
     */
    public static function deliverNow($queueId)
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $queueId
        ), ARRAY_A);

        if (!$row) {
            return;
        }

        self::deliver($row);
    }

    // -------------------- 处理 --------------------

    /**
     * 由WP-Cron定期调用：取出到期的待投递记录并发送。
     *
     * @param int $batchSize 单次处理的最大条数，避免一次cron跑太久
     */
    public static function processDueBatch($batchSize = 20)
    {
        global $wpdb;
        $table = self::table();

        $leaseSeconds = self::PROCESSING_LEASE_SECONDS;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE (status = 'pending' AND next_retry_at <= %s)
                OR (status = 'processing' AND updated_at < UTC_TIMESTAMP() - INTERVAL {$leaseSeconds} SECOND)
             ORDER BY next_retry_at ASC
             LIMIT %d",
            current_time('mysql', true),
            $batchSize
        ), ARRAY_A);

        foreach ($rows ?: [] as $row) {
            self::deliver($row);
        }
    }

    private static function deliver(array $row)
    {
        global $wpdb;
        $table = self::table();

        // 处理租期过期的任务；CAS同时核对旧更新时间，不能抢走刚被其它worker恢复的任务。
        if ($row['status'] === 'processing' && !empty($row['updated_at'])
            && strtotime($row['updated_at'] . ' UTC') < time() - self::PROCESSING_LEASE_SECONDS) {
            $recovered = $wpdb->update($table, [
                'status' => 'pending', 'updated_at' => current_time('mysql', true),
            ], ['id' => $row['id'], 'status' => 'processing', 'updated_at' => $row['updated_at']]);
            if ($recovered === false) { throw new \RuntimeException('恢复回调任务失败'); }
            if ($recovered === 0) { return; }
            $row['status'] = 'pending';
        }
        if ($row['status'] !== 'pending') { return; }

        // 只有赢得pending->processing的worker可以发送；只按id更新不能防止并发重复发送。
        $claimedAt = current_time('mysql', true);
        $claimed = $wpdb->update($table, ['status' => 'processing', 'updated_at' => $claimedAt], [
            'id' => $row['id'], 'status' => 'pending',
            'retry_count' => $row['retry_count'], 'next_retry_at' => $row['next_retry_at'],
        ]);
        if ($claimed === false) { throw new \RuntimeException('认领回调任务失败'); }
        if ($claimed === 0) { return; }
        $row['updated_at'] = $claimedAt;

        $transaction = TransactionManager::findBySOrderId($row['s_order_id']);
        // Stripe同一支付可在失败后重试成功；旧的失败回调不能在收款后重试，
        // 否则会把S系统已经收到的成功状态覆盖为失败。
        if (($transaction['payment_method'] ?? null) === 'stripe' && $row['event_type'] === 'payment_status') {
            $payload = json_decode($row['payload'], true);
            $hasPaid = !empty($transaction['paid_at']) || in_array($transaction['status'], [
                TransactionStateMachine::PAID, TransactionStateMachine::REFUNDED,
                TransactionStateMachine::DISPUTING, TransactionStateMachine::CONFUSED,
            ], true);
            if (is_array($payload) && ($payload['status'] ?? null) === 'failed' && $hasPaid) {
                $message = 'Stripe订单已确认收款，跳过旧的支付失败回调';
                $wpdb->update($table, [
                    'status' => 'success',
                    'last_error' => $message,
                    'updated_at' => current_time('mysql', true),
                ], self::claimCondition($row));
                LogManager::info($row['s_order_id'], $message, [
                    'payment_method' => $transaction['payment_method'],
                    'callback_data' => $payload,
                    'response_data' => ['current_status' => $transaction['status']],
                ]);
                return;
            }
        }
        if (!$transaction || empty($transaction['callback_url'])) {
            self::markFailedPermanently($row, '找不到对应交易记录或callback_url为空');
            return;
        }

        $logOptions = [
            'payment_method' => $transaction['payment_method'],
            'wp_order_id' => $transaction['wp_order_id'] ?? null,
            'request_data' => [
                'queue_id' => (int) $row['id'],
                'event_type' => $row['event_type'],
                'attempt' => (int) $row['retry_count'] + 1,
            ],
        ];

        try {
            $signature = OutboundSigner::sign($row['payload']);
        } catch (\RuntimeException $e) {
            LogManager::callbackExchange('outbound', $row['s_order_id'], 'S系统回调未发送：签名失败', $row['payload'], [], array_merge($logOptions, [
                'level' => LogManager::LEVEL_WARNING,
                'response_data' => ['http_status' => null, 'error' => $e->getMessage()],
            ]));
            self::scheduleRetry($row, $e->getMessage());
            return;
        }

        $headers = [OutboundSigner::HEADER_NAME => $signature];
        try {
            $result = HttpClient::postRawJson($transaction['callback_url'], $row['payload'], $headers);
        } catch (\Throwable $e) {
            LogManager::callbackExchange('outbound', $row['s_order_id'], 'S系统回调请求异常', $row['payload'], $headers, array_merge($logOptions, [
                'level' => LogManager::LEVEL_ERROR,
                'response_data' => ['http_status' => null, 'error' => $e->getMessage()],
            ]));
            self::scheduleRetry($row, $e->getMessage());
            throw $e;
        }

        $logOptions['response_data'] = [
            'http_status' => $result['status'],
            'body' => $result['body'] ?? $result['raw'] ?? null,
            'error' => $result['error'],
        ];
        $decodedPayload = json_decode($row['payload'], true);
        $logOptions['callback_data'] = is_array($decodedPayload) ? $decodedPayload : [];
        $logOptions['level'] = $result['ok'] ? LogManager::LEVEL_INFO : LogManager::LEVEL_WARNING;
        LogManager::callbackExchange('outbound', $row['s_order_id'],
            $result['ok'] ? '回调转发成功' : 'S系统回调响应失败', $row['payload'], $headers, $logOptions);

        if ($result['ok']) {
            $wpdb->update($table, [
                'status' => 'success',
                'updated_at' => current_time('mysql', true),
            ], self::claimCondition($row));

            return;
        }

        $errorMessage = $result['error'] ?: ('HTTP ' . $result['status']);
        self::scheduleRetry($row, $errorMessage);
    }

    private static function scheduleRetry(array $row, $errorMessage)
    {
        global $wpdb;
        $table = self::table();

        $nextRetryCount = (int) $row['retry_count'] + 1;

        if ($nextRetryCount >= (int) $row['max_retries']) {
            self::markFailedPermanently($row, $errorMessage);
            return;
        }

        $delaySeconds = self::$backoffSeconds[$nextRetryCount - 1] ?? end(self::$backoffSeconds);

        $wpdb->update($table, [
            'retry_count' => $nextRetryCount,
            'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delaySeconds),
            'status' => 'pending',
            'last_error' => $errorMessage,
            'updated_at' => current_time('mysql', true),
        ], self::claimCondition($row));

        LogManager::warning($row['s_order_id'], sprintf(
            '回调转发失败，第%d次重试将在%d秒后进行：%s',
            $nextRetryCount,
            $delaySeconds,
            $errorMessage
        ));
    }

    /** 租期被其它worker接管后，旧worker不能覆盖新结果。 */
    private static function claimCondition(array $row)
    {
        return ['id' => $row['id'], 'status' => 'processing', 'updated_at' => $row['updated_at']];
    }

    private static function markFailedPermanently(array $row, $errorMessage)
    {
        global $wpdb;

        $wpdb->update(self::table(), [
            'status' => 'failed',
            'last_error' => $errorMessage,
            'updated_at' => current_time('mysql', true),
        ], self::claimCondition($row));

        LogManager::error($row['s_order_id'], '回调转发最终失败，已达最大重试次数：' . $errorMessage);
    }
}
