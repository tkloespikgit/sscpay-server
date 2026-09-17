<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 订单支付状态落地服务（doc/s-system-payment-status-notify.md 第一~七节）。
 * 两个入口共用同一套状态映射/幂等/回退保护逻辑（applyStatus()），区别只是
 * 数据来源：
 *   - handle()：被动接收 payment_status webhook（PaymentGatewayWebhookController
 *     验签通过后调用），第一~五节。
 *   - queryAndApply()：后台"查询最新状态"按钮主动调用 /order-query 接口
 *     （第七节），用于补偿回调丢失、核对状态，也是目前唯一能查到 expired
 *     状态的途径（expired 不会触发 webhook，见第六节）。
 *
 * 状态映射（插件 status -> orders.status，已与业务方对齐）：
 *   - disputing：系统原本没有，新增同名独立状态。
 *   - confused（争议败诉/拒付）：复用系统里已有的 chargeback——两者业务含义一致
 *     （钱被强制扣回），区别只在"谁触发的"（人工后台操作 vs 插件通知），
 *     不需要为此再拆一个状态值。
 *   - failed（支付失败）：系统原本没有对应值，新增独立状态，不与 cancelled
 *     （主动取消）混用，便于后续做失败率/风控分析。
 *   - expired（付款链接过期）：系统原本靠 Order::isPaymentLinkExpired() 动态计算，
 *     没有落库状态；这里新增同名独立状态，只有 queryAndApply() 会用到它
 *     （webhook 永远不会推送这个状态）。
 *
 * 幂等：同一 webhook 事件可能因插件侧重试收到多次相同 payload（文档第一节）。
 * 这里没有额外建一张去重表，而是直接用"目标状态是否等于当前状态"判断——
 * 重复投递的 payload 到达时订单早已是目标状态，直接跳过，不重复触发
 * 入账/通知/Telegram。主动查询场景同理复用这个判断。
 *
 * 回退保护：已经终态的订单（failed/cancelled/expired/refunded/chargeback/
 * completed）不再接受任何状态覆盖；已经收过款的订单族（paid 及其后续流转
 * 状态）不允许被 pending/failed/cancelled/expired 这类"还没收到钱"的状态
 * 往回改——网关不应该把一笔已经到账的订单覆盖成没到账，出现这种数据大概率
 * 是乱序投递或插件侧异常，只记警告日志，不落库。
 *
 * 【明确不做的事】refunded / confused（拒付）两个状态不自动扣减商户余额——
 * 具体退多少/扣多少需要人工核实后再走 BalanceService::refund()/chargeback()
 * （这两个方法本身要求传入操作人和金额，语义上就是"人工审核后的资金动作"，
 * 直接信任一条 payload 自动扣商户钱风险较高）。收到这两个状态（连同
 * disputing 一起，业务方要求这三种状态待遇一致）时，只做：更新订单状态、
 * 重新拉一遍该订单的 /order-logs（让后台第一时间能看到最新上下文）、发 Telegram
 * 提醒人工介入，不触碰余额。
 */
class OrderPaymentStatusService
{
    private const STATUS_MAP = [
        'pending'   => 'pending',
        'paid'      => 'paid',
        'failed'    => 'failed',
        'cancelled' => 'cancelled',
        'expired'   => 'expired',
        'refunded'  => 'refunded',
        'disputing' => 'disputing',
        'confused'  => 'chargeback',
    ];

    /**
     * 已经算"收过款"的状态族：不允许被下面的"还没收到钱"状态往回覆盖。
     */
    private const PAID_FAMILY_STATUSES = ['paid', 'shipped', 'completed', 'partially_refunded', 'disputing'];

    /**
     * 真正终态：到这些状态后不再接受任何后续状态覆盖。
     */
    private const TERMINAL_STATUSES = ['failed', 'cancelled', 'expired', 'refunded', 'chargeback', 'completed'];

    /**
     * 收到这些状态时：更新状态 + 立即重新拉一遍该订单的 /order-logs +（交给
     * OrderStatusChanged 事件的 Telegram 监听器）提醒人工处理，不自动动余额。
     */
    private const ALERT_STATUSES = ['disputing', 'confused', 'refunded'];

    public function __construct(
        private readonly OrderEventSyncService $eventSync,
        private readonly BalanceService $balanceService,
        private readonly OrderNotificationService $notificationService,
        private readonly PaymentGatewayService $paymentGateway,
        private readonly AdConversionService $adConversionService,
        private readonly TelegramNotificationService $telegram,
    ) {
    }

    /**
     * @param  array  $payload  已通过签名验证的 payment_status 回调 body（字段见文档第二节）
     */
    public function handle(array $payload): void
    {
        $sOrderId     = (string) ($payload['s_order_id'] ?? '');
        $pluginStatus = (string) ($payload['status'] ?? '');

        if ($sOrderId === '' || !isset(self::STATUS_MAP[$pluginStatus])) {
            Log::warning('payment_status webhook: 缺少 s_order_id 或未知 status，忽略', $payload);

            return;
        }

        $order = Order::query()->withoutGlobalScopes()->where('order_no', $sOrderId)->first();

        if (!$order) {
            Log::warning('payment_status webhook: 找不到对应订单，忽略', ['s_order_id' => $sOrderId]);

            return;
        }

        $this->disablePaymentMethodIfForbidden($order, $payload);

        $this->applyStatus($order, self::STATUS_MAP[$pluginStatus], $payload);
    }

    /**
     * account_forbidden：插件侧标记该订单锁定支付方式对应的三方账号已无法下单支付
     * （比如被网关封禁/限制），与订单状态迁移无关，收到即禁用本地这条支付通道配置，
     * 避免后续订单继续路由到一个已经下不了单的通道。只在当前仍是启用状态时才处理+
     * 告警，避免同一事件重试多次时重复发 Telegram。
     */
    private function disablePaymentMethodIfForbidden(Order $order, array $payload): void
    {
        if (empty($payload['account_forbidden'])) {
            return;
        }

        $method = $order->paymentMethodConfig();

        if (!$method || !$method->is_active) {
            return;
        }

        $method->is_active = false;
        $method->save();

        Log::warning('payment_status webhook: 支付通道账号已被标记为不可用，自动禁用', [
            'order_no'          => $order->order_no,
            'merchant_id'       => $order->merchant_id,
            'payment_method_id' => $method->id,
            'method_code'       => $method->method_code,
        ]);

        $this->telegram->send($order->merchant_id, __('admin.telegram_notification.payment_method_forbidden', [
            'method_name' => $method->method_name,
            'method_code' => $method->method_code,
            'order_no'    => $order->order_no,
        ]));
    }

    /**
     * 主动查询该订单在插件侧的最新状态并按需更新本地状态（文档第七节），
     * 供后台"查询最新状态"按钮使用。
     *
     * @return array{queried_status:string,mapped_status:?string,old_status:string,new_status:string,changed:bool}
     *
     * @throws PaymentGatewayException 插件侧请求失败（含订单不存在 $e->isOrderNotFound()）
     * @throws \RuntimeException 该订单锁定的支付方式未配置查询所需凭证
     */
    public function queryAndApply(Order $order): array
    {
        $method = $order->paymentMethodConfig();

        if (!$method || empty($method->domain) || empty($method->domain_client_id) || empty($method->domain_client_sk)) {
            throw new \RuntimeException('该订单锁定的支付方式未配置查询所需的凭证（域名/WooCommerce REST API 密钥）。');
        }

        $data = $this->paymentGateway
            ->withConnection(
                rtrim($method->domain, '/').'/wp-json/payment-plugin/v1',
                $method->domain_client_id,
                $method->domain_client_sk,
            )
            ->orderQuery($order->order_no);

        $pluginStatus = (string) ($data['status'] ?? '');
        $beforeStatus = $order->status;

        if (!isset(self::STATUS_MAP[$pluginStatus])) {
            Log::warning('order-query: 返回未知 status，忽略状态更新', [
                'order_no' => $order->order_no,
                'status'   => $pluginStatus,
            ]);

            return [
                'queried_status' => $pluginStatus,
                'mapped_status'  => null,
                'old_status'     => $beforeStatus,
                'new_status'     => $beforeStatus,
                'changed'        => false,
            ];
        }

        $targetStatus = self::STATUS_MAP[$pluginStatus];
        $oldStatus    = $this->applyStatus($order, $targetStatus, $data);

        return [
            'queried_status' => $pluginStatus,
            'mapped_status'  => $targetStatus,
            'old_status'     => $oldStatus ?? $beforeStatus,
            'new_status'     => $order->status,
            'changed'        => $oldStatus !== null,
        ];
    }

    /**
     * 状态落库 + 副作用（入账/通知/日志重拉/Telegram），handle() 与
     * queryAndApply() 共用。事务内加行锁读取最新���态并落库，避免并发下的竞态；
     * 成功后把 $order 刷新为最新数据，方便调用方直接用同一个实例继续操作。
     *
     * @return string|null 实际发生变化时返回变化前的状态，被幂等/回退保护跳过时返回 null
     */
    private function applyStatus(Order $order, string $targetStatus, array $payload): ?string
    {
        // 订单当前处于人工审核事件中时，shouldApply() 会把这条网关消息整个吞掉
        // （人工审核结果优先，见该方法内的守卫注释）——但如果这条被吞的消息恰好
        // 就是这次审核在等的最终结果（网关那边争议已经判下来了），审核期间冻结的
        // 资金不会自动解冻，必须有人手动去关闭审核事件才会释放。这里单独记一下
        // 这种情况，事务提交后（不占用行锁）发条 Telegram 提醒，避免运营/财务
        // 只能靠自己盯着网关后台才知道"这时候该去把审核事件关掉放钱了"。
        $ignoredDuringDisputeReview = false;

        $oldStatus = DB::transaction(function () use ($order, $targetStatus, $payload, &$ignoredDuringDisputeReview) {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->find($order->id);
            $old    = $locked->status;

            if ($old === Order::STATUS_DISPUTE_REVIEW) {
                $ignoredDuringDisputeReview = true;
            }

            if (!$this->shouldApply($old, $targetStatus)) {
                return null;
            }

            $locked->status = $targetStatus;

            // 支付成功时间：首次进入"已收款状态族"时落一次快照，之后永不覆盖。
            // 用状态族而不是只判断 targetStatus === 'paid'，是因为网关可能直接
            // 推送 paid 之后的状态（如争议/退款），这些状态同样意味着钱已经收到过。
            // 争议胜诉回退到 paid（BalanceService::releaseForDisputeEvent）不会走到这里，
            // 即便走到，empty() 判断也保证不会把首次支付时间改写成回退时间。
            if (empty($locked->paid_at) && in_array($targetStatus, self::PAID_FAMILY_STATUSES, true)) {
                $locked->paid_at = now();
            }

            if (empty($locked->wp_order_id) && !empty($payload['wp_order_id'])) {
                $locked->wp_order_id = (int) $payload['wp_order_id'];
            }

            // 三方交易号：直接以网关这次返回的为准覆盖（不是只在为空时回填）——
            // 同一订单后续事件通常复用同一个交易号（见文档第四节示例），网关侧
            // 是这个值的权威来源，没有理由保留本地的旧值。
            if (!empty($payload['transaction_id'])) {
                $locked->transaction_id = (string) $payload['transaction_id'];
            }

            $locked->save();

            return $old;
        });

        if ($ignoredDuringDisputeReview) {
            $this->alertDisputeReviewGatewayStatusIgnored($order, $targetStatus);
        }

        if ($oldStatus === null) {
            return null;
        }

        $order->refresh();

        // paid 的二义性（文档第五节第 5 点）：从 disputing 回退到 paid 是"争议胜诉"，
        // 不是首次支付成功，不重复入账、不重复推首单商户通知。
        if ($targetStatus === 'paid' && $oldStatus !== 'disputing') {
            $this->balanceService->creditForPaidOrder($order);
            $this->notificationService->dispatchInitial($order);
            // 订单带了广告追踪参数且对应平台配置了转化 API 凭证时，服务端直接同步
            // 转化事件给广告方，与支付方式是否允许返回源站无关（见 AdConversionService 注释）。
            $this->adConversionService->dispatchInitial($order);
        }

        if (in_array($targetStatus, self::ALERT_STATUSES, true)) {
            $this->eventSync->syncOrderNow($order);
        }

        event(new OrderStatusChanged($order, $oldStatus, $targetStatus));

        return $oldStatus;
    }

    /**
     * 人工审核期间收到的网关状态被 shouldApply() 吞掉时，单独提醒一下——
     * 不代表争议已经胜诉/败诉，只是告诉人"网关这边已经有动静了，去核实一下
     * 要不要关闭审核事件放钱"，真正的资金动作仍然要走人工关闭审核事件
     * （BalanceService::releaseForDisputeEvent()）+ 视情况后续 refund()/chargeback()。
     *
     * 网关同一个事件可能重试多次投递相同 payload（订单一直停在 dispute_review，
     * 没有"状态已变"这个天然的幂等信号可用），这里用缓存做一次性去重，
     * 避免每次重试都再发一条一模一样的提醒刷屏。
     *
     * 去重 key 特意按"当前这一条处理中的审核事件"（activeDisputeEvent）分组，
     * 不能只按订单 ID——同一笔订单可能先后开过好几轮独立的审核事件（关闭后
     * 因故重新开立），按订单 ID 去重会被上一轮审核期间发过的提醒误伤，导致
     * 这一轮真正该提醒的同样目标状态被误判成"已经提醒过"而漏发。
     */
    private function alertDisputeReviewGatewayStatusIgnored(Order $order, string $targetStatus): void
    {
        $activeDisputeEventId = $order->activeDisputeEvent?->id ?? $order->id;
        $dedupeKey = "dispute_review_gateway_alert:{$activeDisputeEventId}:{$targetStatus}";

        if (!Cache::add($dedupeKey, true, now()->addDay())) {
            return;
        }

        $this->telegram->send($order->merchant_id, __('admin.telegram_notification.dispute_review_gateway_status_ignored', [
            'order_no' => $order->order_no,
            'status'   => __('admin.order.statuses.'.$targetStatus),
        ]));
    }

    private function shouldApply(string $oldStatus, string $targetStatus): bool
    {
        if ($oldStatus === $targetStatus) {
            return false;
        }

        // 订单当前处于人工发起的争议审核事件中（见 OrderDisputeService）：
        // 这套机制与网关 webhook 驱动的 disputing 完全独立，人工审核期间
        // 不允许网关事件静默覆盖订单状态（无论目标状态是什么），人工审核
        // 结果优先。审核结束后订单状态由 BalanceService::releaseForDisputeEvent()
        // 负责改回 paid，不经过这里。
        if ($oldStatus === Order::STATUS_DISPUTE_REVIEW) {
            Log::warning('payment_status: 订单当前处于人工发起的争议审核事件中，忽略网关状态覆盖，人工审核结果优先', [
                'old_status'    => $oldStatus,
                'target_status' => $targetStatus,
            ]);

            return false;
        }

        if (in_array($oldStatus, self::TERMINAL_STATUSES, true)) {
            Log::warning('payment_status: 订单已是终态，忽略状态覆盖', [
                'old_status'    => $oldStatus,
                'target_status' => $targetStatus,
            ]);

            return false;
        }

        if (in_array($oldStatus, self::PAID_FAMILY_STATUSES, true)
            && in_array($targetStatus, ['pending', 'failed', 'cancelled', 'expired'], true)) {
            Log::warning('payment_status: 已收款订单不允许被回退为未支付/失败/取消/过期，忽略', [
                'old_status'    => $oldStatus,
                'target_status' => $targetStatus,
            ]);

            return false;
        }

        return true;
    }
}
