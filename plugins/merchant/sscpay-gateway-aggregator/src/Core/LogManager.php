<?php
/**
 * 日志管理：所有请求/响应/回调都落到 wp_payment_logs 表（对应F7），
 * 支持按s_order_id检索（供Admin/LogViewer.php使用），敏感字段落库前
 * 统一经过 SensitiveDataFilter（对应F9），不在各Controller里各自处理。
 * S系统回调的raw_body是验签证据，显式保留完整原文，不做解析、脱敏或截断。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Helpers\SensitiveDataFilter;
use PaymentGatewayAggregator\Models\Log;

if (!defined('ABSPATH')) {
    exit;
}

class LogManager
{
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    /** 不属于任何具体订单的系统级日志（如数据库迁移），s_order_id列NOT NULL，用这个占位。 */
    const SYSTEM_ORDER_ID = 'SYSTEM';

    private static function table()
    {
        return Log::tableName();
    }

    /**
     * @param string $level INFO/WARNING/ERROR
     * @param string $sOrderId
     * @param string $message
     * @param array $options 可选键：wp_order_id, payment_method, request_data, response_data, callback_data
     */
    public static function log($level, $sOrderId, $message, array $options = [])
    {
        self::write($level, $sOrderId, $message, $options);
    }

    /**
     * S系统回调的验签证据：原始body必须逐字节保存，不能经过通用字符串截断，
     * 也不能解析后重新编码。只保存签名头，不采集Authorization/Cookie等凭据。
     * 原始body按用户排查验签的需要完整留存；其余日志字段仍执行常规脱敏。
     */
    public static function callbackExchange($direction, $sOrderId, $message, $rawBody, array $headers, array $options = [])
    {
        $signatureHeaders = [];
        foreach ($headers as $name => $value) {
            $name = str_replace('_', '-', strtolower($name));
            if ($name === 'x-pga-signature') {
                $signatureHeaders[$name] = is_array($value) ? implode(',', $value) : $value;
            }
        }
        $options['callback_data'] = array_merge($options['callback_data'] ?? [], [
            'direction' => $direction,
            'body_bytes' => strlen($rawBody),
            'body_sha256' => hash('sha256', $rawBody),
            'signature_headers' => $signatureHeaders,
        ]);
        self::write($options['level'] ?? self::LEVEL_INFO, $sOrderId, $message, $options, $rawBody);
    }

    private static function write($level, $sOrderId, $message, array $options, $rawCallbackBody = null)
    {
        global $wpdb;

        $callbackData = isset($options['callback_data'])
            ? SensitiveDataFilter::filter($options['callback_data']) : null;
        if ($rawCallbackBody !== null) {
            // 非UTF-8的异常请求体也能留下可还原证据，避免JSON编码失败使整条日志丢失。
            $validUtf8 = preg_match('//u', $rawCallbackBody) === 1;
            $callbackData['raw_body'] = $validUtf8 ? $rawCallbackBody : null;
            if (!$validUtf8) {
                $callbackData['raw_body_base64'] = base64_encode($rawCallbackBody);
            }
        }

        $wpdb->insert(
            self::table(),
            [
                's_order_id' => $sOrderId ?: self::SYSTEM_ORDER_ID,
                'wp_order_id' => isset($options['wp_order_id']) ? (int) $options['wp_order_id'] : null,
                'payment_method' => $options['payment_method'] ?? null,
                'level' => $level,
                'message' => $message,
                'request_data' => isset($options['request_data'])
                    ? SensitiveDataFilter::filterToJson($options['request_data']) : null,
                'response_data' => isset($options['response_data'])
                    ? SensitiveDataFilter::filterToJson($options['response_data']) : null,
                'callback_data' => $callbackData !== null ? wp_json_encode($callbackData) : null,
                'ip' => self::clientIp(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                    ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 512) : null,
                'created_at' => current_time('mysql', true),
            ],
            [
                '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            ]
        );
    }

    public static function info($sOrderId, $message, array $options = [])
    {
        self::log(self::LEVEL_INFO, $sOrderId, $message, $options);
    }

    public static function warning($sOrderId, $message, array $options = [])
    {
        self::log(self::LEVEL_WARNING, $sOrderId, $message, $options);
    }

    public static function error($sOrderId, $message, array $options = [])
    {
        self::log(self::LEVEL_ERROR, $sOrderId, $message, $options);
    }

    /** 系统级日志（迁移、定时任务等，不挂靠具体订单）。供Migrator.php调用。 */
    public static function system($message, $level = self::LEVEL_INFO)
    {
        self::log($level, self::SYSTEM_ORDER_ID, $message);
    }

    /**
     * 按s_order_id检索（订单日志API用；后台列表使用paginate）。
     *
     * @param string $sOrderId
     * @param int $limit
     * @return array
     */
    public static function findBySOrderId($sOrderId, $limit = 200)
    {
        global $wpdb;
        $table = self::table();

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE s_order_id = %s ORDER BY created_at DESC, id DESC LIMIT %d",
            $sOrderId,
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * 按级别、订单号组合筛选并分页，供LogViewer使用。
     *
     * @param string|null $level
     * @param int $page
     * @param int $perPage
     * @param string|null $sOrderId
     * @return array{items:array,total:int,page:int,per_page:int,total_pages:int}
     */
    public static function paginate($level = null, $page = 1, $perPage = 50, $sOrderId = null)
    {
        global $wpdb;
        $table = self::table();
        $perPage = max(1, min(200, (int) $perPage));
        $conditions = [];
        $args = [];
        if ($level !== null && $level !== '') {
            $conditions[] = 'level = %s';
            $args[] = $level;
        }
        if ($sOrderId !== null && $sOrderId !== '') {
            $conditions[] = 's_order_id = %s';
            $args[] = $sOrderId;
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $countSql = "SELECT COUNT(*) FROM {$table}{$where}";
        $total = max(0, (int) $wpdb->get_var($args ? $wpdb->prepare($countSql, ...$args) : $countSql));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($totalPages, max(1, (int) $page));
        $offset = ($page - 1) * $perPage;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}{$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            ...array_merge($args, [$perPage, $offset])
        ), ARRAY_A);

        return [
            'items' => $items ?: [], 'total' => $total,
            'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages,
        ];
    }

    // -------------------- 日志保留策略（对应后台设置页"日志保留天数"）--------------------

    const CRON_HOOK = 'pga_purge_old_logs';

    public static function ensurePurgeScheduled()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedulePurge()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /** 由WP-Cron每天调用一次；保留天数配置为0或负数表示永久保留，不清理。 */
    public static function purgeOldLogs()
    {
        global $wpdb;
        $days = (int) get_option('pga_log_retention_days', 90);
        if ($days <= 0) {
            return;
        }

        $table = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }

    public static function clear(): bool
    {
        global $wpdb;

        return $wpdb->query('DELETE FROM ' . self::table()) !== false;
    }

    private static function clientIp()
    {
        // 只取REMOTE_ADDR，不信任X-Forwarded-For等可伪造的头，除非部署时明确知道
        // 前面有可信的反向代理并单独做了处理（不在本插件范围内）。
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return substr($ip, 0, 64);
    }
}
