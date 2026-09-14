<?php

namespace App\Services;

use App\Events\OrderEventsSyncCompleted;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 订单日志同步服务：对应 doc/s-system-payment-status-notify.md 第八节，
 * 逐笔调用插件 POST /order-logs 接口，把某笔订单在插件侧的完整日志
 * （下单、发起支付、Webhook 回调、争议事件、重试等）落到本地 order_events
 * 表，供后台排查使用。
 *
 * 这个接口是"按 s_order_id 单笔查询"，不是全局增量 feed，所以本服务的同步
 * 单位是"订单"而不是"事件"：每轮遍历一批"活跃订单"——近期新建的，或状态
 * 仍可能变化（pending/paid/disputing）的——对每笔订单调用一次 /order-logs，
 * 取回的日志按 (order_no, external_log_id) 幂等写入。
 *
 * 鉴权用该订单锁定的支付方式（Order::payment_method -> PaymentMethod）上
 * 配置的站点 WooCommerce REST API 密钥（domain_client_id / domain_client_sk，
 * Consumer Key / Secret + Basic Auth），因为不同支付方式可能对接不同的 WordPress
 * 站点（PaymentMethod::domain）。没有配置这三项的支付方式直接跳过，不重试。
 *
 * 【明确不做的事】order-logs 只返回人工可读的日志文本，没有结构化状态字段，
 * 不适合用来判断"订单是否已支付"。订单状态流转（pending -> paid 等）由
 * payment_status webhook 回调驱动（文档第一节，见 PaymentGatewayWebhookController /
 * OrderPaymentStatusService），本服务只做日志归档，不修改订单状态、不触发入账。
 */
class OrderEventSyncService
{
    /**
     * 视为"仍可能产生新日志"的订单状态，不受下面的时间窗口限制，每轮都会重新查询。
     */
    private const ACTIVE_STATUSES = ['pending', 'paid', 'disputing'];

    /**
     * 默认活跃窗口（天）：即使订单已经是终态，创建时间在这个窗口内也仍然参与本轮同步，
     * 避免终态订单前一分钟才写完最后一条日志、下一分钟就永久从同步范围里消失。
     */
    private const DEFAULT_ACTIVE_WINDOW_DAYS = 3;

    public function __construct(private readonly PaymentGatewayService $paymentGateway) {}

    public function sync(): array
    {
        if (! SystemConfig::getBool('order_event.sync_enabled', true)) {
            return ['skipped' => true, 'reason' => 'sync_disabled'];
        }

        $windowDays = (int) SystemConfig::get('order_event.active_window_days', self::DEFAULT_ACTIVE_WINDOW_DAYS);
        $cutoff = now()->subDays($windowDays);

        $stats = [
            'orders_checked' => 0,
            'orders_skipped_no_credentials' => 0,
            'orders_failed' => 0,
            'logs_fetched' => 0,
            'logs_written' => 0,
            'logs_skipped' => 0,
        ];

        /** @var array<string, PaymentMethod|null> $methodCache 按 "merchant_id:method_code" 缓存，避免同一支付方式反复查库 */
        $methodCache = [];

        Order::query()
            ->withoutGlobalScopes()
            ->where(function ($query) use ($cutoff) {
                $query->where('created_at', '>=', $cutoff)
                    ->orWhereIn('status', self::ACTIVE_STATUSES);
            })
            ->chunkById(200, function ($orders) use (&$stats, &$methodCache) {
                foreach ($orders as $order) {
                    $this->syncOrder($order, $methodCache, $stats);
                }
            });

        event(new OrderEventsSyncCompleted($stats['logs_fetched'], $stats['logs_written'], $stats['logs_skipped']));

        return array_merge(['success' => true], $stats);
    }

    /**
     * 单笔即时同步：供 payment_status webhook 在收到 disputing/confused/refunded
     * 时按需触发（文档第五节），复用批量同步内部同一套请求/落库逻辑，
     * 不需要为单笔场景单独维护一份统计口径。
     *
     * @return array{orders_checked:int,orders_skipped_no_credentials:int,orders_failed:int,logs_fetched:int,logs_written:int,logs_skipped:int}
     */
    public function syncOrderNow(Order $order): array
    {
        $methodCache = [];
        $stats = [
            'orders_checked' => 0,
            'orders_skipped_no_credentials' => 0,
            'orders_failed' => 0,
            'logs_fetched' => 0,
            'logs_written' => 0,
            'logs_skipped' => 0,
        ];

        $this->syncOrder($order, $methodCache, $stats);

        return $stats;
    }

    private function syncOrder(Order $order, array &$methodCache, array &$stats): void
    {
        $stats['orders_checked']++;

        $method = $this->resolvePaymentMethod($order, $methodCache);

        if (! $method || empty($method->domain) || empty($method->domain_client_id) || empty($method->domain_client_sk)) {
            $stats['orders_skipped_no_credentials']++;

            return;
        }

        try {
            $result = $this->paymentGateway
                ->withConnection(
                    rtrim($method->domain, '/').'/wp-json/payment-plugin/v1',
                    $method->domain_client_id,
                    $method->domain_client_sk,
                )
                ->orderLogs($order->order_no);
        } catch (PaymentGatewayException $e) {
            $stats['orders_failed']++;
            Log::warning('OrderEventSyncService: order-logs request failed, skipped', [
                'order_no' => $order->order_no,
                'pga_code' => $e->pgaCode,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($result['logs'] ?? [] as $logEntry) {
            $stats['logs_fetched']++;
            $written = $this->writeLog($order, $logEntry);
            $stats[$written ? 'logs_written' : 'logs_skipped']++;
        }
    }

    /**
     * 按 "merchant_id:method_code" 缓存解析结果，同一轮同步里同一个支付方式
     * 只查一次库（活跃订单可能大量集中在少数几个支付方式上）。
     */
    private function resolvePaymentMethod(Order $order, array &$methodCache): ?PaymentMethod
    {
        $cacheKey = $order->merchant_id.':'.$order->payment_method;

        if (! array_key_exists($cacheKey, $methodCache)) {
            $methodCache[$cacheKey] = $order->paymentMethodConfig();
        }

        return $methodCache[$cacheKey];
    }

    private function writeLog(Order $order, array $logEntry): bool
    {
        if (empty($logEntry['id']) || empty($logEntry['created_at'])) {
            Log::warning('OrderEventSyncService: log entry missing id/created_at, skipped', [
                'order_no' => $order->order_no,
                'log' => $logEntry,
            ]);

            return false;
        }

        // created_at 是插件后台 LogViewer 同款的朴素格式（Y-m-d H:i:s），按文档说明是 UTC 时间。
        $occurredAt = Carbon::createFromFormat('Y-m-d H:i:s', $logEntry['created_at'], 'UTC');

        OrderEvent::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'order_no' => $order->order_no,
                'external_log_id' => $logEntry['id'],
            ],
            [
                'merchant_id' => $order->merchant_id,
                'level' => $logEntry['level'] ?? 'INFO',
                'message' => $logEntry['message'] ?? '',
                'payment_method' => $logEntry['payment_method'] ?? null,
                'wp_order_id' => $logEntry['wp_order_id'] ?? null,
                'request_payload' => $logEntry['request_data'] ?? null,
                'response_payload' => $logEntry['response_data'] ?? null,
                'callback_payload' => $logEntry['callback_data'] ?? null,
                'ip' => $logEntry['ip'] ?? null,
                'user_agent' => $logEntry['user_agent'] ?? null,
                'occurred_at' => $occurredAt,
            ]
        );

        return true;
    }
}
