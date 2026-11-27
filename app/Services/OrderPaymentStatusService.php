<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Exceptions\BalanceOperationException;
use App\Jobs\SendOrderNotificationJob;
use App\Models\CryptoChainEvent;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\GatewayRefund;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderAccountingTask;
use App\Models\OrderGatewayStatusEvent;
use App\Models\OrderNotificationAttempt;
use App\Models\PaymentMethod;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 接收网关支付事实，在订单锁内保存审计和可靠记账待办。
 * 本地主状态由 OrderLifecycleService 根据售后、审核、结算和物流事实推导；
 * 资金处理在状态事务之后按商户→订单锁序执行，失败由持久化待办补偿。
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

    private const ALERT_STATUSES = ['disputing', 'chargeback', 'refunded'];

    private const REVERSAL_STATUSES = ['refunded', 'chargeback'];

    public function __construct(
        private readonly OrderEventSyncService $eventSync,
        private readonly BalanceService $balanceService,
        private readonly OrderNotificationService $notificationService,
        private readonly PaymentGatewayService $paymentGateway,
        private readonly AdConversionService $adConversionService,
        private readonly TelegramNotificationRouter $telegramRoutes,
        private readonly PaymentChannelTelegramNotifier $channelTelegram,
        private readonly OrderAccountingService $accountingService,
        private readonly GatewayRefundService $refunds,
        private readonly OrderLifecycleService $lifecycle,
        private readonly CombinedPaymentStatusService $combinedPayments,
    ) {}

    /**
     * @param  array  $payload  已通过签名验证的 payment_status 回调 body（字段见文档第二节）
     */
    public function handle(array $payload): void
    {
        $sOrderId = (string) ($payload['s_order_id'] ?? '');
        $pluginStatus = (string) ($payload['status'] ?? '');

        if ($sOrderId === '' || ! isset(self::STATUS_MAP[$pluginStatus])) {
            Log::warning('payment_status webhook: 缺少 s_order_id 或未知 status，忽略', ['s_order_id' => $sOrderId, 'status' => $pluginStatus]);

            return;
        }

        $order = Order::query()->withoutGlobalScopes()
            ->where('payment_type', 'fiat')
            ->where(fn ($query) => $query->where('gateway_order_no', $sOrderId)
                ->orWhere(fn ($legacy) => $legacy->whereNull('gateway_order_no')->where('order_no', $sOrderId)))
            ->first();

        if (! $order) {
            Log::warning('payment_status webhook: 找不到当前网关尝试对应的订单，忽略过期或未知回调', ['s_order_id' => $sOrderId]);

            return;
        }

        $this->applyStatus($order, self::STATUS_MAP[$pluginStatus], $payload, $sOrderId, checkForbiddenAccount: true, queryOnRejection: true);
    }

    /**
     * account_forbidden：插件侧标记该订单锁定支付方式对应的三方账号已无法下单支付
     * （比如被网关封禁/限制），与订单状态迁移无关，收到即禁用本地这条支付通道配置，
     * 避免后续订单继续路由到一个已经下不了单的通道。只在当前仍是启用状态时才处理+
     * 告警，避免同一事件重试多次时重复发 Telegram。
     */
    private function disablePaymentMethodIfForbidden(Order $order, array $payload): ?PaymentMethod
    {
        if (empty($payload['account_forbidden'])) {
            return null;
        }

        $method = $order->paymentMethodConfig();

        if (! $method || ! $method->is_active) {
            return null;
        }

        $method->is_active = false;
        $method->save();

        return $method;
    }

    private function notifyForbiddenPaymentMethod(Order $order, PaymentMethod $method): void
    {
        Log::warning('payment_status webhook: 支付通道账号已被标记为不可用，自动禁用', [
            'order_no' => $order->order_no,
            'merchant_id' => $order->merchant_id,
            'payment_method_id' => $method->id,
            'method_code' => $method->method_code,
            'owner_id' => $method->owner_id,
        ]);

        $this->channelTelegram->notify(TelegramNotificationKind::PaymentMethodForbidden, $method, [
            'method_name' => $method->method_name,
            'method_code' => $method->method_code,
            'order_no' => $order->order_no,
        ], $order);
    }

    /**
     * 主动查询该订单在插件侧的最新状态并按需更新本地状态（文档第七节），
     * 供后台"查询最新状态"按钮和定时补查使用。返回 paid 时确认支付事实，
     * 本地主状态仍由结算、售后及人工审核事实推导。
     *
     * @return array{queried_status:string,mapped_status:?string,old_status:string,new_status:string,changed:bool}
     *
     * @throws PaymentGatewayException 插件侧请求失败（含订单不存在 $e->isOrderNotFound()）
     * @throws \RuntimeException 该订单锁定的支付方式未配置查询所需凭证
     */
    public function queryAndApply(Order $order): array
    {
        $order->refresh();
        if ($order->payment_type !== 'fiat') {
            throw new \DomainException('Blockchain payments must use verified chain evidence.');
        }
        $gatewayReference = $order->gatewayOrderReference();
        $expectedVersion = (int) $order->gateway_status_version;
        $method = $order->paymentMethodConfig();

        if (! $method || empty($method->domain) || empty($method->domain_client_id) || empty($method->domain_client_sk)) {
            throw new \RuntimeException('该订单锁定的支付方式未配置查询所需的凭证（域名/WooCommerce REST API 密钥）。');
        }

        $data = $this->paymentGateway
            ->withConnection(
                rtrim($method->domain, '/').'/wp-json/payment-plugin/v1',
                $method->domain_client_id,
                $method->domain_client_sk,
            )
            ->orderQuery($gatewayReference);

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

        $details = is_array($data['refunds'] ?? null) ? $data['refunds'] : [];
        // 旧支付通知可能丢失。只有认证查单同时提供原收款时间、交易号和匹配金额时才恢复付款事实。
        if ($targetStatus === 'refunded' && ($order->paid_at === null || blank($order->transaction_id))
            && collect($details)->contains(fn ($refund) => is_array($refund) && ($refund['status'] ?? null) === 'succeeded'
                && ($refund['s_order_id'] ?? null) === $gatewayReference && ($refund['payment_reference'] ?? null) === ($data['transaction_id'] ?? null)
                && ($refund['payment_method'] ?? null) === $method->configMap?->payment_config_tag && ($refund['currency'] ?? null) === $order->currency)
            && ($data['s_order_id'] ?? null) === $gatewayReference && filled($data['paid_at'] ?? null)
            && filled($data['transaction_id'] ?? null) && $this->combinedPayments->amountMatches($order, $data)) {
            $this->applyStatus($order, 'paid', $data, $gatewayReference, allowPaidOverride: true, expectedVersion: $expectedVersion);
        }
        $this->applyStatus($order, $targetStatus, $data, $gatewayReference, allowPaidOverride: true, expectedVersion: $expectedVersion);

        $allBooked = ! empty($details) && ($data['refunds_complete'] ?? false) === true;
        foreach ($details as $refund) {
            if (! is_array($refund) || ($refund['s_order_id'] ?? null) !== $gatewayReference) {
                $allBooked = false;

                continue;
            }
            $receipt = $this->refunds->receive($refund);
            $allBooked = $allBooked && $receipt->status === 'booked' && $receipt->order_id === $order->id
                && ! DB::table('gateway_refund_conflicts')->where('gateway_refund_id', $receipt->id)->exists();
        }
        if ($allBooked) {
            $this->accountingService->completeRefundHint($order);
        }
        $order->refresh();

        return [
            'queried_status' => $pluginStatus,
            'mapped_status' => $targetStatus,
            'old_status' => $beforeStatus,
            'new_status' => $order->status,
            'changed' => $beforeStatus !== $order->status,
        ];
    }

    /** 先尝试已有付款证据；处理顺序颠倒且收款引用仍缺时，通过认证查单恢复。 */
    public function recoverRefund(GatewayRefund $receipt): bool
    {
        if ($this->refunds->process($receipt, onlyDue: true)) {
            return true;
        }
        $receipt->refresh();
        if ($receipt->status !== 'pending' || $receipt->last_error !== 'awaiting_payment_reference') {
            return false;
        }

        $order = Order::forMerchant($receipt->merchant_id)->find($receipt->order_id);
        if (! $order || ! $order->payment_method_id) {
            return false;
        }
        // 领取下一次补查时点再访问外部；支付回调到达时可以立即处理，不必等到该时点。
        $claimed = GatewayRefund::forMerchant($receipt->merchant_id)->whereKey($receipt->id)
            ->where('status', 'pending')->where('last_error', 'awaiting_payment_reference')
            ->where('next_attempt_at', $receipt->next_attempt_at)
            ->update(['last_error' => 'querying_payment_reference', 'next_attempt_at' => now()->addHour()]);
        if (! $claimed) {
            return false;
        }

        $this->queryAndApply($order);

        return $receipt->refresh()->status === 'booked';
    }

    /** 分批补查缺明细的历史通知。发送 HTTP 前领取查询资格，避免多台调度器同时请求。 */
    public function queryRefundHint(OrderAccountingTask $task): bool
    {
        $claimed = OrderAccountingTask::forMerchant($task->merchant_id)->whereKey($task->id)
            ->where('type', OrderAccountingTask::TYPE_REFUND)->where('status', OrderAccountingTask::STATUS_MANUAL_REVIEW)
            ->where('last_error', OrderAccountingTask::REFUND_DETAIL_REQUIRED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['attempts' => DB::raw('attempts + 1'), 'last_attempted_at' => now(),
                'next_attempt_at' => now()->addMinutes(min(1440, 5 * (2 ** min(9, $task->attempts))))]);
        if (! $claimed) {
            return false;
        }
        $order = Order::forMerchant($task->merchant_id)->find($task->order_id);
        if (! $order || ! $order->payment_method_id) {
            return false;
        }

        try {
            $this->queryAndApply($order);
        } catch (\Throwable $exception) {
            Log::warning('退款明细补查失败，已安排下次重试', ['order_id' => $order->id, 'exception' => $exception::class]);

            return false;
        }

        return true;
    }

    /** Caller holds merchant/order/request locks and has credited the ledger in this same transaction. */
    public function confirmCryptoPayment(Order $order, CryptoPaymentRequest $request, CryptoLedgerEntry $ledger, CryptoChainEvent $lastTransfer, bool $acceptExpired = false): void
    {
        if (DB::transactionLevel() < 1 || $order->payment_type !== 'crypto' || ! in_array($order->status, $acceptExpired ? ['pending', 'expired'] : ['pending'], true) || $order->paid_at !== null
            || $request->status !== 'credited' || $request->credited_at === null
            || (int) $request->merchant_id !== (int) $order->merchant_id || (int) $request->order_id !== (int) $order->id
            || (int) $ledger->merchant_id !== (int) $order->merchant_id || (int) $ledger->order_id !== (int) $order->id
            || (int) $ledger->crypto_payment_request_id !== (int) $request->id || $ledger->type !== 'order_paid'
            || (int) $lastTransfer->crypto_payment_request_id !== (int) $request->id || $lastTransfer->confirmed_at === null) {
            throw new \DomainException('Crypto payment facts require an atomic confirmed ledger credit.');
        }
        $old = $order->status;
        $order->paid_at = $lastTransfer->block_time;
        $order->risk_reservation_status = 'consumed';
        $order->transaction_id = $lastTransfer->transaction_hash;
        $order->normal_status = $this->lifecycle->normalStatus($order);
        $order->status = $this->lifecycle->resolve($order);
        $order->save();
        $this->notificationService->dispatchInitial($order);
        $this->adConversionService->dispatchInitial($order);
        $event = new OrderStatusChanged(clone $order, $old, $order->status, true);
        DB::afterCommit(fn () => event($event));
    }

    /** Requeue the same durable notification if a worker/queue failed after the payment transaction committed. */
    public function recoverCryptoNotification(Order $order): void
    {
        if (DB::transactionLevel() < 1 || $order->payment_type !== 'crypto' || $order->paid_at === null) {
            throw new \DomainException('Notification recovery requires a locked paid crypto order.');
        }
        $attempts = OrderNotificationAttempt::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)
            ->where('notify_type', 'trade_result')->where('status', 'pending')->where('scheduled_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit(5)->lockForUpdate()->get();
        foreach ($attempts as $attempt) {
            $attempt->update(['scheduled_at' => now()]);
            SendOrderNotificationJob::dispatch($attempt->id)->afterCommit();
        }
    }

    /**
     * 状态落库 + 副作用（入账/通知/日志重拉/Telegram），handle() 与
     * queryAndApply() 共用。事务内加行锁读取最新状态并落库，避免并发下的竞态；
     * 成功后把 $order 刷新为最新数据，方便调用方直接用同一个实例继续操作。
     * webhook 被状态机拒绝时，释放行锁后仅主动查询一次；查询失败向上传播以便回调重试。
     *
     * @return string|null 实际发生变化时返回变化前的状态，被幂等/回退保护跳过时返回 null
     */
    private function applyStatus(
        Order $order,
        string $targetStatus,
        array $payload,
        string $gatewayReference,
        bool $allowPaidOverride = false,
        bool $checkForbiddenAccount = false,
        ?int $expectedVersion = null,
        bool $queryOnRejection = false,
    ): ?string {
        $disabledMethod = null;
        $source = $allowPaidOverride ? 'query' : 'webhook';
        $result = DB::transaction(function () use ($order, $targetStatus, $payload, $gatewayReference, $source, $checkForbiddenAccount, $expectedVersion, &$disabledMethod): array {
            Merchant::query()->withoutGlobalScopes()->whereKey($order->merchant_id)->lockForUpdate()->firstOrFail();
            $locked = Order::query()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)->lockForUpdate()->findOrFail($order->id);
            $old = $locked->status;
            $timestamp = null;
            $invalidTimestamp = false;
            if (filled($payload['updated_at'] ?? null)) {
                try {
                    $timestamp = Carbon::parse($payload['updated_at'], 'UTC')->setTimezone(config('app.timezone'));
                } catch (\Throwable) {
                    $invalidTimestamp = true;
                }
            }

            $outcome = $invalidTimestamp ? 'conflict' : $this->gatewayOutcome($locked, $targetStatus, $timestamp, $source, $expectedVersion);
            if ($locked->gatewayOrderReference() !== $gatewayReference
                || (filled($payload['s_order_id'] ?? null) && (string) $payload['s_order_id'] !== $gatewayReference)) {
                $outcome = 'obsolete_reference';
            }

            if ($outcome !== 'obsolete_reference' && $targetStatus === 'paid' && $locked->combined_order_id
                && in_array($outcome, ['accepted', 'duplicate'], true)
                && ! $this->combinedPayments->amountMatches($locked, $payload)) {
                $outcome = 'amount_mismatch';
                $this->combinedPayments->rejectAmount($locked);
            }

            OrderGatewayStatusEvent::query()->create([
                'merchant_id' => $locked->merchant_id,
                'order_id' => $locked->id,
                'gateway_reference' => $gatewayReference,
                'source' => $source,
                'status' => $targetStatus,
                'gateway_updated_at' => $timestamp,
                'outcome' => $targetStatus === 'refunded' && in_array($outcome, ['accepted', 'duplicate'], true) ? 'refund_detail_required' : $outcome,
                'payload' => array_intersect_key($payload, array_flip([
                    'status', 's_order_id', 'wp_order_id', 'transaction_id', 'amount', 'currency', 'paid_at', 'updated_at',
                ])),
            ]);

            if ($outcome === 'obsolete_reference') {
                return ['old' => $old, 'accepted' => false, 'first' => false, 'changed' => false, 'outcome' => $outcome];
            }

            if ($targetStatus === 'refunded') {
                // 历史插件和补查可能只提供一个订单级状态，无法据此确定本次退款金额。
                $this->accountingService->recordForStatus($locked, 'refunded');

                return ['old' => $old, 'accepted' => true, 'first' => false, 'changed' => false];
            }

            if ($checkForbiddenAccount) {
                $disabledMethod = $this->disablePaymentMethodIfForbidden($locked, $payload);
            }

            if (! in_array($outcome, ['accepted', 'duplicate'], true)) {
                Log::warning('payment_status: 网关状态未覆盖本地事实，需核对审计记录', [
                    'order_id' => $locked->id, 'target_status' => $targetStatus, 'outcome' => $outcome,
                ]);

                return ['old' => $old, 'accepted' => false, 'first' => false, 'changed' => false, 'outcome' => $outcome];
            }

            $firstPayment = $targetStatus === 'paid' && $locked->paid_at === null
                && in_array($old, Order::NEVER_PAID_STATUSES, true);
            $locked->normal_status = $this->lifecycle->normalStatus($locked);
            $locked->gateway_status = $targetStatus;
            if ($timestamp !== null) {
                $locked->gateway_status_updated_at = $timestamp;
            }
            if ($outcome === 'accepted') {
                $locked->gateway_status_version = (int) $locked->gateway_status_version + 1;
            }

            if ($locked->paid_at === null && ! in_array($targetStatus, Order::NEVER_PAID_STATUSES, true)) {
                $locked->paid_at = filled($payload['paid_at'] ?? null)
                    ? Carbon::parse($payload['paid_at'], 'UTC')->setTimezone(config('app.timezone')) : now();
            }
            if ($locked->failed_at === null && $targetStatus === 'failed') {
                $locked->failed_at = now();
            }
            if (empty($locked->wp_order_id) && ! empty($payload['wp_order_id'])) {
                $locked->wp_order_id = (int) $payload['wp_order_id'];
            }
            if ($targetStatus === 'paid' && ($firstPayment || blank($locked->transaction_id)) && filled($payload['transaction_id'] ?? null)) {
                $locked->transaction_id = (string) $payload['transaction_id'];
            }

            $locked->status = $this->lifecycle->resolve($locked, $targetStatus);
            if ($locked->paid_at !== null) {
                $locked->risk_reservation_status = 'consumed';
            } elseif (in_array($targetStatus, ['cancelled', 'expired'], true)) {
                $locked->risk_reservation_status = 'released';
            }
            $locked->save();
            $parent = $this->combinedPayments->apply($locked, $firstPayment);
            $this->accountingService->recordForStatus($locked, $targetStatus, $firstPayment);
            if ($firstPayment) {
                $this->notificationService->dispatchInitial($locked);
                if ($parent === null) {
                    $this->adConversionService->dispatchInitial($locked);
                } elseif ($parent->status === 'paid') {
                    $adOrder = clone $locked;
                    $adOrder->forceFill($parent->only(['order_no', 'merchant_order_no', 'amount', 'converted_amount']));
                    $this->adConversionService->dispatchInitial($adOrder);
                }
            }

            return ['old' => $old, 'accepted' => true, 'first' => $firstPayment, 'changed' => $old !== $locked->status];
        }, 3);

        $order->refresh();
        if ($disabledMethod) {
            $this->notifyForbiddenPaymentMethod($order, $disabledMethod);
        }
        if (! $result['accepted']) {
            if ($queryOnRejection && ! in_array($result['outcome'], ['obsolete_reference', 'amount_mismatch'], true)) {
                $queryResult = $this->queryAndApply($order);

                return $queryResult['changed'] ? $queryResult['old_status'] : null;
            }

            return null;
        }

        if ($result['changed'] || $result['first']) {
            $event = new OrderStatusChanged(clone $order, $result['old'], $order->status, $result['first']);
            DB::afterCommit(fn () => event($event));
        }

        $this->processAccounting($order, $targetStatus, $payload);
        if ($targetStatus === 'paid') {
            $order->gatewayRefunds()->withoutGlobalScopes()->where('merchant_id', $order->merchant_id)
                ->where('status', 'pending')->orderBy('id')->each(fn ($receipt) => $this->refunds->process($receipt));
            $this->advancePreRecordedShipment($order);
        }

        if ($result['changed'] && in_array($targetStatus, self::ALERT_STATUSES, true)) {
            $this->eventSync->syncOrderNow($order);
        }
        if ($order->status === Order::STATUS_DISPUTE_REVIEW && $targetStatus === 'paid') {
            $this->alertDisputeReviewGatewayStatusIgnored($order, $targetStatus);
        }

        $order->refresh();

        return $result['old'] !== $order->status ? $result['old'] : null;
    }

    /** 时间相同的冲突由一次无并发变更的主动查询裁决；无版本的旧插件保留收款及反向事件兼容。 */
    private function gatewayOutcome(Order $order, string $target, ?Carbon $timestamp, string $source, ?int $expectedVersion): string
    {
        if ($source === 'query' && $expectedVersion !== (int) $order->gateway_status_version) {
            return 'query_race';
        }
        if (in_array($order->status, ['failed', 'cancelled', 'expired'], true)
            && (in_array($target, Order::NEVER_PAID_STATUSES, true) || ($target === 'paid' && $source !== 'query'))) {
            return 'protected';
        }
        if ($order->gateway_status_updated_at !== null) {
            if ($timestamp === null) {
                return $target === $order->gateway_status ? 'duplicate' : ($source === 'query' ? 'accepted' : 'conflict');
            }
            if ($timestamp->lt($order->gateway_status_updated_at)) {
                return 'stale';
            }
            if ($timestamp->eq($order->gateway_status_updated_at) && $target !== $order->gateway_status && $source !== 'query') {
                return 'conflict';
            }
        }

        if (in_array($target, Order::NEVER_PAID_STATUSES, true)
            && ($order->paid_at !== null || ! in_array($order->status, Order::NEVER_PAID_STATUSES, true))) {
            return 'protected';
        }
        if ($target === 'paid' && $source !== 'query' && $timestamp === null
            && ($order->gateway_status === 'disputing' || $order->status === 'disputing')) {
            return 'conflict';
        }
        if (in_array($order->gateway_status, self::REVERSAL_STATUSES, true)
            && $target !== $order->gateway_status && $source !== 'query') {
            return 'protected';
        }
        if ($target === $order->gateway_status && ($timestamp === null || $timestamp->eq($order->gateway_status_updated_at))) {
            return 'duplicate';
        }

        return 'accepted';
    }

    private function processAccounting(Order $order, string $targetStatus, array $payload): void
    {
        if (in_array($targetStatus, self::REVERSAL_STATUSES, true)) {
            $this->alertIfPayloadAmountMismatches($order, $payload);
        }

        try {
            $this->accountingService->processPendingForOrder($order);
            $order->refresh();
        } catch (BalanceOperationException $exception) {
            Log::warning('payment_status: 新订单记账需要人工处理', [
                'order_id' => $order->id,
                'merchant_id' => $order->merchant_id,
                'target_status' => $targetStatus,
                'error' => $exception->getMessage(),
            ]);

            $this->telegramRoutes->sendNotification(TelegramNotificationKind::GatewayReversalSettleFailed, [
                'order_no' => $order->order_no,
                'status' => __('admin.order.statuses.'.$targetStatus),
                'error' => $exception->getMessage(),
            ], (int) $order->merchant_id, $order->paymentMethodConfig(), orderDetails: OrderTelegramSummary::details($order));
        }
    }

    /** 支付前预录入物流的订单，支付落地后通过相同的条件更新推进状态。 */
    private function advancePreRecordedShipment(Order $order): void
    {
        $this->balanceService->startSettlementForShippedOrder($order);
        $order->refresh();
    }

    /**
     * payload 带的金额与订单金额不一致时告警。只提醒，不阻断入账——网关是资金
     * 事实的权威方，但金额对不上说明两边有一方的数据不对，需要人工核实。
     */
    private function alertIfPayloadAmountMismatches(Order $order, array $payload): void
    {
        $payloadAmount = $payload['amount'] ?? null;

        if (blank($payloadAmount) || ! is_numeric($payloadAmount)) {
            return;
        }

        if (bccomp((string) $payloadAmount, (string) $order->amount, 2) === 0) {
            return;
        }

        Log::warning('payment_status webhook: 网关回传金额与订单金额不一致', [
            'order_no' => $order->order_no,
            'payload_amount' => (string) $payloadAmount,
            'order_amount' => (string) $order->amount,
        ]);

        $this->telegramRoutes->sendNotification(TelegramNotificationKind::GatewayReversalAmountMismatch, [
            'order_no' => $order->order_no,
            'gateway_amount' => (string) $payloadAmount,
            'order_amount' => (string) $order->amount,
            'currency' => strtoupper((string) $order->currency),
        ], (int) $order->merchant_id, $order->paymentMethodConfig(), orderDetails: OrderTelegramSummary::details($order));
    }

    /** 网关确认已支付不关闭人工审核；按审核事件去重提醒人工核对。 */
    private function alertDisputeReviewGatewayStatusIgnored(Order $order, string $targetStatus): void
    {
        $activeDisputeEventId = $order->activeDisputeEvent?->id ?? $order->id;
        $dedupeKey = "dispute_review_gateway_alert:{$activeDisputeEventId}:{$targetStatus}";

        $settings = $this->telegramRoutes->settings(TelegramNotificationKind::DisputeReviewGatewayStatusIgnored);

        if (! $settings['enabled'] || ! Cache::add($dedupeKey, true, now()->addMinutes($settings['cooldown_minutes']))) {
            return;
        }

        $this->telegramRoutes->sendNotification(TelegramNotificationKind::DisputeReviewGatewayStatusIgnored, [
            'order_no' => $order->order_no,
            'status' => __('admin.order.statuses.'.$targetStatus),
        ], (int) $order->merchant_id, $order->paymentMethodConfig(), orderDetails: OrderTelegramSummary::details($order));
    }
}
