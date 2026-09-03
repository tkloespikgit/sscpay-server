<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
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
        'pending' => 'pending',
        'paid' => 'paid',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
        'refunded' => 'refunded',
        'disputing' => 'disputing',
        'confused' => 'chargeback',
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
    ) {}

    /**
     * @param  array  $payload  已通过签名验证的 payment_status 回调 body（字段见文档第二节）
     */
    public function handle(array $payload): void
    {
        $sOrderId = (string) ($payload['s_order_id'] ?? '');
        $pluginStatus = (string) ($payload['status'] ?? '');

        if ($sOrderId === '' || ! isset(self::STATUS_MAP[$pluginStatus])) {
            Log::warning('payment_status webhook: 缺少 s_order_id 或未知 status，忽略', $payload);

            return;
        }

        $order = Order::query()->withoutGlobalScopes()->where('order_no', $sOrderId)->first();

        if (! $order) {
            Log::warning('payment_status webhook: 找不到对应订单，忽略', ['s_order_id' => $sOrderId]);

            return;
        }

        $this->applyStatus($order, self::STATUS_MAP[$pluginStatus], $payload);
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

        if (! $method || empty($method->domain) || empty($method->order_account) || empty($method->order_password)) {
            throw new \RuntimeException('该订单锁定的支付方式未配置查询所需的订单账户（域名/订单账号/订单密码）。');
        }

        $data = $this->paymentGateway
            ->withConnection(
                rtrim($method->domain, '/').'/wp-json/payment-plugin/v1',
                $method->order_account,
                $method->order_password,
            )
            ->orderQuery($order->order_no);

        $pluginStatus = (string) ($data['status'] ?? '');
        $beforeStatus = $order->status;

        if (! isset(self::STATUS_MAP[$pluginStatus])) {
            Log::warning('order-query: 返回未知 status，忽略状态更新', [
                'order_no' => $order->order_no,
                'status' => $pluginStatus,
            ]);

            return [
                'queried_status' => $pluginStatus,
                'mapped_status' => null,
                'old_status' => $beforeStatus,
                'new_status' => $beforeStatus,
                'changed' => false,
            ];
        }

        $targetStatus = self::STATUS_MAP[$pluginStatus];
        $oldStatus = $this->applyStatus($order, $targetStatus, $data);

        return [
            'queried_status' => $pluginStatus,
            'mapped_status' => $targetStatus,
            'old_status' => $oldStatus ?? $beforeStatus,
            'new_status' => $order->status,
            'changed' => $oldStatus !== null,
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
        $oldStatus = DB::transaction(function () use ($order, $targetStatus, $payload) {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->find($order->id);
            $old = $locked->status;

            if (! $this->shouldApply($old, $targetStatus)) {
                return null;
            }

            $locked->status = $targetStatus;

            if (empty($locked->wp_order_id) && ! empty($payload['wp_order_id'])) {
                $locked->wp_order_id = (int) $payload['wp_order_id'];
            }

            // 三方交易号：直接以网关这次返回的为准覆盖（不是只在为空时回填）——
            // 同一订单后续事件通常复用同一个交易号（见文档第四节示例），网关侧
            // 是这个值的权威来源，没有理由保留本地的旧值。
            if (! empty($payload['transaction_id'])) {
                $locked->transaction_id = (string) $payload['transaction_id'];
            }

            $locked->save();

            return $old;
        });

        if ($oldStatus === null) {
            return null;
        }

        $order->refresh();

        // paid 的二义性（文档第五节第 5 点）：从 disputing 回退到 paid 是"争议胜诉"，
        // 不是首次支付成功，不重复入账、不重复推首单商户通知。
        if ($targetStatus === 'paid' && $oldStatus !== 'disputing') {
            $this->balanceService->creditForPaidOrder($order);
            $this->notificationService->dispatchInitial($order);
        }

        if (in_array($targetStatus, self::ALERT_STATUSES, true)) {
            $this->eventSync->syncOrderNow($order);
        }

        event(new OrderStatusChanged($order, $oldStatus, $targetStatus));

        return $oldStatus;
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
                'old_status' => $oldStatus,
                'target_status' => $targetStatus,
            ]);

            return false;
        }

        if (in_array($oldStatus, self::TERMINAL_STATUSES, true)) {
            Log::warning('payment_status: 订单已是终态，忽略状态覆盖', [
                'old_status' => $oldStatus,
                'target_status' => $targetStatus,
            ]);

            return false;
        }

        if (in_array($oldStatus, self::PAID_FAMILY_STATUSES, true)
            && in_array($targetStatus, ['pending', 'failed', 'cancelled', 'expired'], true)) {
            Log::warning('payment_status: 已收款订单不允许被回退为未支付/失败/取消/过期，忽略', [
                'old_status' => $oldStatus,
                'target_status' => $targetStatus,
            ]);

            return false;
        }

        return true;
    }
}
