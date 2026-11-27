<?php

namespace Tests\Feature;

use App\Exceptions\BalanceOperationException;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderAccountingTask;
use App\Models\OrderRefund;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderAccountingService;
use App\Services\OrderLifecycleService;
use App\Services\OrderPaymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 网关推送退款/拒付时的自动入账。
 *
 * 修复的线上问题：网关退款后订单状态改成了 refunded，但商户余额没扣、
 * 也没有 order_refunds 记录，而人工补录的入口又被状态校验挡死，订单彻底卡住
 * （见 OrderPaymentStatusService 类注释）。
 *
 * 断言一律盯 merchants.balance 的前后差值，而不只看记录是否存在——这个 bug 的
 * 本质就是"记录和余额对不上"。
 */
class GatewayRefundChargebackTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const ORDER_AMOUNT = '200.00';

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/order-logs*' => Http::response(['code' => 0, 'data' => []], 200),
        ]);

        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '1000.00']);

        $this->paymentMethod = $this->makePaymentMethod('stripe');
        $this->paymentMethod->update(['refund_fee' => '2.00', 'chargeback_fee' => '15.00']);
    }

    public function test_gateway_refund_deducts_the_balance_and_records_it(): void
    {
        $order = $this->paidOrder();

        $this->receiveRefund($order, self::ORDER_AMOUNT);

        // 余额扣减 = 退款本金 200 + 退款手续费 2
        $this->assertSame('798.00', (string) $this->merchant->refresh()->balance);

        $refund = OrderRefund::query()->withoutGlobalScopes()->sole();
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame(self::ORDER_AMOUNT, (string) $refund->amount);
        // 系统自动入账，不记操作人
        $this->assertNull($refund->operator_id);

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame(self::ORDER_AMOUNT, (string) $order->refunded_amount);

        $this->assertSame(
            ['refund' => '-200.00', 'refund_fee' => '-2.00'],
            $this->ledgerFor($order),
        );
    }

    public function test_gateway_chargeback_deducts_the_balance_and_records_it(): void
    {
        $order = $this->paidOrder();

        $this->pushGatewayStatus($order, 'confused');

        // 余额扣减 = 订单全额 200 + 拒付手续费 15
        $this->assertSame('785.00', (string) $this->merchant->refresh()->balance);
        $this->assertSame('chargeback', $order->refresh()->status);

        $this->assertSame(
            ['chargeback' => '-200.00', 'chargeback_fee' => '-15.00'],
            $this->ledgerFor($order),
        );
    }

    #[TestWith([null, '783.00'])]
    #[TestWith(['12.00', '786.00'])]
    #[TestWith(['0.00', '798.00'])]
    public function test_a_full_gateway_refund_can_be_converted_to_chargeback_with_only_one_additional_fee(?string $feeSnapshot, string $expectedBalance): void
    {
        $order = $this->paidOrder();
        $order->update(['chargeback_fee_snapshot' => $feeSnapshot]);
        $this->receiveRefund($order, self::ORDER_AMOUNT);
        $refundBefore = OrderRefund::query()->withoutGlobalScopes()->sole()->toArray();
        $operator = $this->operator();

        // 使用回调前的旧实例，锁内必须重新读取全额退款事实。
        app(BalanceService::class)->chargeback($order, $operator, '拒付拦截补扣费用');

        $this->assertSame($expectedBalance, (string) $this->merchant->refresh()->balance);
        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('refunded', $order->gateway_status);
        $this->assertSame(self::ORDER_AMOUNT, (string) $order->refunded_amount);
        $this->assertSame($refundBefore, OrderRefund::query()->withoutGlobalScopes()->sole()->toArray());
        $principal = MerchantBalanceTransaction::query()->withoutGlobalScopes()
            ->where('order_id', $order->id)->where('type', MerchantBalanceTransaction::TYPE_CHARGEBACK)->sole();
        $this->assertSame('0.00', $principal->amount);
        $this->assertSame('798.00', $principal->balance_before);
        $this->assertSame('798.00', $principal->balance_after);
        $this->assertSame($operator->id, $principal->operator_id);
        $this->assertSame('拒付拦截补扣费用', $principal->reason);
        $this->assertTrue(BalanceService::hasChargebackLedger($order));
        $this->assertFalse(Order::query()->pendingReversalSettlement()->whereKey($order->id)->exists());

        try {
            app(BalanceService::class)->chargeback($order, $operator, '重复补扣');
            $this->fail('退款转拒付不能重复扣费，包括零手续费订单。');
        } catch (BalanceOperationException $exception) {
            $this->assertSame('该订单已完成拒付扣款，不能重复拒付。', $exception->getMessage());
        }

        $this->assertSame($expectedBalance, (string) $this->merchant->refresh()->balance);
        $this->assertSame(1, MerchantBalanceTransaction::query()->withoutGlobalScopes()
            ->where('order_id', $order->id)->where('type', MerchantBalanceTransaction::TYPE_CHARGEBACK)->count());
    }

    public function test_a_later_gateway_chargeback_after_a_full_refund_only_charges_the_fee_once(): void
    {
        $this->freezeTime();
        $order = $this->paidOrder();
        $this->receiveRefund($order, self::ORDER_AMOUNT);
        $this->travel(1)->minutes();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/order-query*' => Http::response([
                'code' => 0,
                'data' => ['status' => 'confused', 'updated_at' => now()->toDateTimeString()],
            ]),
        ]);

        $this->pushGatewayStatus($order, 'confused');
        $this->pushGatewayStatus($order, 'confused');

        $this->assertSame('783.00', (string) $this->merchant->refresh()->balance);
        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame([
            'refund' => '-200.00', 'refund_fee' => '-2.00',
            'chargeback' => '0.00', 'chargeback_fee' => '-15.00',
        ], $this->ledgerFor($order));
        $this->assertDatabaseHas('order_accounting_tasks', [
            'order_id' => $order->id, 'type' => OrderAccountingTask::TYPE_CHARGEBACK,
            'status' => OrderAccountingTask::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'order_id' => $order->id, 'amount' => '0.00',
            'idempotency_key' => "gateway_chargeback:{$order->id}:principal",
        ]);
        $this->assertDatabaseCount('order_refunds', 1);
        Http::assertSentCount(2);
    }

    public function test_refund_callbacks_and_paid_queries_cannot_undo_a_manual_refund_to_chargeback_conversion(): void
    {
        $order = $this->paidOrder();
        $this->receiveRefund($order, self::ORDER_AMOUNT);
        app(BalanceService::class)->chargeback($order->refresh(), $this->operator(), '拒付拦截');
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/order-query*' => Http::response([
                'code' => 0, 'data' => ['status' => 'paid'],
            ]),
        ]);

        $this->receiveRefund($order, self::ORDER_AMOUNT);
        app(OrderPaymentStatusService::class)->queryAndApply($order);

        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('paid', $order->gateway_status);
        $this->assertSame('783.00', (string) $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_refunds', 1);
        $this->assertSame(4, MerchantBalanceTransaction::query()->withoutGlobalScopes()->where('order_id', $order->id)->where('type', '!=', 'order_paid')->count());
    }

    public function test_an_unaccounted_refund_cannot_be_converted_to_a_fee_only_chargeback(): void
    {
        $order = $this->paidOrder();
        $order->update(['status' => 'refunded']);

        try {
            app(BalanceService::class)->chargeback($order, $this->operator(), '尚未扣款的退款');
            $this->fail('仅标记退款但未扣款的订单必须先完成退款记账。');
        } catch (BalanceOperationException $exception) {
            $this->assertSame('该订单退款尚未完成扣款，请先补录退款，再转拒付。', $exception->getMessage());
        }

        $this->assertSame('1000.00', (string) $this->merchant->refresh()->balance);
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame([], $this->ledgerFor($order));
    }

    /** 插件文档明确会重试投递同一条 payload，重复扣款是这里最不能出的错。 */
    public function test_replaying_the_same_payload_does_not_deduct_twice(): void
    {
        $order = $this->paidOrder();

        $this->receiveRefund($order, self::ORDER_AMOUNT);
        $balanceAfterFirst = (string) $this->merchant->refresh()->balance;

        $this->receiveRefund($order, self::ORDER_AMOUNT);
        $this->receiveRefund($order, self::ORDER_AMOUNT);

        $this->assertSame($balanceAfterFirst, (string) $this->merchant->refresh()->balance);
        $this->assertSame(1, OrderRefund::query()->withoutGlobalScopes()->count());
    }

    public function test_legacy_refund_notice_after_a_manual_partial_refund_requires_detail(): void
    {
        $order = $this->paidOrder();
        app(BalanceService::class)->refund($order, '50.00', $this->operator());
        $this->pushGatewayStatus($order->refresh(), 'refunded');
        $this->assertSame('948.00', $this->merchant->refresh()->balance);
        $this->assertSame('50.00', $order->refresh()->refunded_amount);
        $this->assertSame('partially_refunded', $order->status);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'refund', 'status' => 'manual_review']);
    }

    /**
     * 已部分退款的订单又收到拒付：chargeback() 会拒绝（防重复扣款）。
     * 这时必须退回"只改状态 + 告警"，**不能抛异常**——webhook 控制器不捕获异常，
     * 冒上去会变成 5xx 触发插件退避重试 5 次，而这类失败重试多少次都不会成功。
     */
    public function test_chargeback_on_a_partially_refunded_order_falls_back_without_throwing(): void
    {
        $order = $this->paidOrder();
        app(BalanceService::class)->refund($order, '50.00', $this->operator(), '部分退款');

        $balanceBefore = (string) $this->merchant->refresh()->balance;

        $this->pushGatewayStatus($order->refresh(), 'confused');

        // 状态照常推进，但余额不动，等人工处理
        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame($balanceBefore, (string) $this->merchant->refresh()->balance);
        $this->assertFalse(BalanceService::hasChargebackLedger($order));
    }

    public function test_a_partial_refund_can_be_manually_converted_by_deducting_only_remaining_principal_and_fee(): void
    {
        $order = $this->paidOrder();
        $operator = $this->operator();
        app(BalanceService::class)->refund($order, '50.00', $operator, '部分退款');
        $refundBefore = OrderRefund::query()->withoutGlobalScopes()->sole()->toArray();

        app(BalanceService::class)->chargeback($order, $operator, '确认剩余本金拒付');

        $this->assertSame('783.00', $this->merchant->refresh()->balance);
        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('50.00', $order->refunded_amount);
        $this->assertSame($refundBefore, OrderRefund::query()->withoutGlobalScopes()->sole()->toArray());
        $this->assertSame([
            'refund' => '-50.00', 'refund_fee' => '-2.00',
            'chargeback' => '-150.00', 'chargeback_fee' => '-15.00',
        ], $this->ledgerFor($order));
    }

    public function test_a_gateway_partial_refund_chargeback_can_be_reconciled_manually_without_repeating_principal(): void
    {
        $order = $this->paidOrder();
        $operator = $this->operator();
        app(BalanceService::class)->refund($order, '50.00', $operator);
        $this->pushGatewayStatus($order->refresh(), 'confused');
        $task = OrderAccountingTask::query()->where('order_id', $order->id)->where('type', 'chargeback')->sole();
        $this->assertSame('manual_review', $task->status);
        $this->assertSame(__('admin.finance.chargeback.partial_refund_requires_review'), $task->last_error);

        app(BalanceService::class)->chargeback($order->refresh(), $operator, '人工确认剩余本金及拒付费');
        $this->assertTrue(app(OrderAccountingService::class)->reconcileCompletedTask($task));

        $this->assertSame('completed', $task->refresh()->status);
        $this->assertSame('783.00', $this->merchant->refresh()->balance);
        $this->assertFalse($order->accountingTasks()->unsettledReversals()->exists());
        $this->assertSame('-150.00', $this->ledgerFor($order)['chargeback']);
    }

    public function test_partial_refund_conversion_uses_actual_usd_refunds_including_rounding_remainders(): void
    {
        $order = $this->paidOrder();
        $order->update(['currency' => 'EUR', 'amount' => '3.00', 'converted_amount' => '10.00']);
        $order->balanceTransactions()->where('type', 'order_paid')->update(['amount' => '10.00', 'balance_before' => '990.00']);
        $service = app(BalanceService::class);
        $operator = $this->operator();
        $service->refund($order, '1.00', $operator);
        $service->refund($order->refresh(), '1.00', $operator);

        $service->chargeback($order->refresh(), $operator, '确认剩余本金');

        $this->assertSame('-3.34', $this->ledgerFor($order)['chargeback']);
        $this->assertSame('971.00', $this->merchant->refresh()->balance);
        $this->assertSame('2.00', $order->refresh()->refunded_amount);
        $this->assertDatabaseCount('order_refunds', 2);
    }

    public function test_partial_refund_conversion_rejects_missing_refund_accounting_without_touching_balance(): void
    {
        $order = $this->paidOrder();
        $order->update(['status' => 'partially_refunded', 'refunded_amount' => '50.00']);

        try {
            app(BalanceService::class)->chargeback($order, $this->operator(), '历史退款核对');
            $this->fail('不能仅凭部分退款字段再次扣款。');
        } catch (BalanceOperationException $exception) {
            $this->assertSame(__('admin.finance.chargeback.refund_accounting_mismatch'), $exception->getMessage());
        }

        $this->assertSame('1000.00', $this->merchant->refresh()->balance);
        $this->assertSame('partially_refunded', $order->refresh()->status);
        $this->assertSame([], $this->ledgerFor($order));
    }

    public function test_a_later_refund_notification_does_not_recharge_a_partial_refund_converted_to_chargeback(): void
    {
        $order = $this->paidOrder();
        $operator = $this->operator();
        app(BalanceService::class)->refund($order, '50.00', $operator);
        app(BalanceService::class)->chargeback($order->refresh(), $operator, '确认剩余本金拒付');

        $this->pushGatewayStatus($order->refresh(), 'refunded');

        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertNull($order->gateway_status);
        $this->assertSame('50.00', $order->refunded_amount);
        $this->assertSame('783.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_refunds', 1);
        $task = OrderAccountingTask::query()->where('order_id', $order->id)->where('type', 'refund')->sole();
        $this->assertSame('completed', $task->status);
        $this->assertFalse(app(OrderLifecycleService::class)->isBlocked($order));
        $this->assertTrue(Order::query()->withoutUnresolvedGatewayReversal()->whereKey($order->id)->exists());

        $task->update(['status' => 'manual_review']);
        $this->assertFalse($order->accountingTasks()->unsettledReversals()->exists());
        $this->assertTrue(app(OrderAccountingService::class)->reconcileCompletedTask($task));
        $this->assertSame('completed', $task->refresh()->status);
        $this->assertSame('783.00', $this->merchant->refresh()->balance);
    }

    public function test_partial_refund_conversion_rejects_refund_usd_values_that_disagree_with_the_ledger(): void
    {
        $order = $this->paidOrder();
        $operator = $this->operator();
        app(BalanceService::class)->refund($order, '50.00', $operator);
        OrderRefund::query()->where('order_id', $order->id)->update(['amount_usd' => '49.00']);

        try {
            app(BalanceService::class)->chargeback($order->refresh(), $operator, '核对退款流水');
            $this->fail('退款单与实际扣款不一致时不能自动推算剩余本金。');
        } catch (BalanceOperationException $exception) {
            $this->assertSame(__('admin.finance.chargeback.refund_accounting_mismatch'), $exception->getMessage());
        }

        $this->assertSame('948.00', $this->merchant->refresh()->balance);
        $this->assertSame('partially_refunded', $order->refresh()->status);
        $this->assertDatabaseMissing('merchant_balance_transactions', ['order_id' => $order->id, 'type' => 'chargeback']);
    }

    /** 线上已经卡住的那批订单：状态是 refunded、钱还在，人工必须能补录扣平。 */
    public function test_a_stuck_gateway_flagged_order_can_still_be_settled_manually(): void
    {
        $order = $this->paidOrder();
        // 模拟旧版本行为：只改状态，不动钱
        $order->forceFill(['status' => 'refunded'])->save();

        $balanceBefore = (string) $this->merchant->refresh()->balance;

        app(BalanceService::class)->refund($order, self::ORDER_AMOUNT, $this->operator(), '补录网关退款');

        $this->assertSame(bcsub($balanceBefore, '202.00', 2), (string) $this->merchant->refresh()->balance);
        $this->assertSame(self::ORDER_AMOUNT, (string) $order->refresh()->refunded_amount);
    }

    public function test_a_fully_settled_refund_cannot_be_refunded_again(): void
    {
        $order = $this->paidOrder();
        $this->receiveRefund($order, self::ORDER_AMOUNT);

        $this->expectExceptionMessage('该订单已全额退款并完成扣款，不能重复退款。');

        app(BalanceService::class)->refund($order->refresh(), '10.00', $this->operator(), '重复退款');
    }

    public function test_a_settled_chargeback_cannot_be_charged_back_again(): void
    {
        $order = $this->paidOrder();
        $this->pushGatewayStatus($order, 'confused');

        $this->expectExceptionMessage('该订单已完成拒付扣款，不能重复拒付。');

        app(BalanceService::class)->chargeback($order->refresh(), $this->operator(), '重复拒付');
    }

    public function test_a_settled_chargeback_cannot_be_charged_back_again_after_status_is_corrected_to_paid(): void
    {
        $order = $this->paidOrder();
        $this->pushGatewayStatus($order, 'confused');
        $order->refresh()->forceFill(['status' => 'paid'])->save();

        try {
            app(BalanceService::class)->chargeback($order, $this->operator(), '重复拒付');
            $this->fail('已扣款的拒付订单不能再次扣款。');
        } catch (BalanceOperationException $exception) {
            $this->assertSame('该订单已完成拒付扣款，不能重复拒付。', $exception->getMessage());
        }

        $this->assertSame('785.00', (string) $this->merchant->refresh()->balance);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(
            ['chargeback' => '-200.00', 'chargeback_fee' => '-15.00'],
            $this->ledgerFor($order),
        );
        $this->assertSame(2, MerchantBalanceTransaction::query()->withoutGlobalScopes()->where('order_id', $order->id)->where('type', '!=', 'order_paid')->count());
    }

    public function test_a_settled_chargeback_cannot_be_refunded_after_status_is_corrected_to_paid(): void
    {
        $order = $this->paidOrder();
        $this->pushGatewayStatus($order, 'confused');
        $order->refresh()->forceFill(['status' => 'paid'])->save();

        try {
            app(BalanceService::class)->refund($order, '10.00', $this->operator(), '拒付后退款');
            $this->fail('已扣款的拒付订单不能再次退款。');
        } catch (BalanceOperationException $exception) {
            $this->assertSame('该订单已完成拒付扣款，不能再退款。', $exception->getMessage());
        }

        $this->assertSame('785.00', (string) $this->merchant->refresh()->balance);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame('0.00', (string) $order->refunded_amount);
        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertSame(
            ['chargeback' => '-200.00', 'chargeback_fee' => '-15.00'],
            $this->ledgerFor($order),
        );
        $this->assertSame(2, MerchantBalanceTransaction::query()->withoutGlobalScopes()->where('order_id', $order->id)->where('type', '!=', 'order_paid')->count());
    }

    public function test_pending_settlement_scope_only_matches_unsettled_orders(): void
    {
        $settledRefund = $this->paidOrder('SSC-A');
        $this->receiveRefund($settledRefund, self::ORDER_AMOUNT);

        $settledChargeback = $this->paidOrder('SSC-B');
        $this->pushGatewayStatus($settledChargeback, 'confused');

        // 旧版本遗留的两笔：只改了状态，钱没扣
        $stuckRefund = $this->paidOrder('SSC-C');
        $stuckRefund->forceFill(['status' => 'refunded'])->save();

        $stuckChargeback = $this->paidOrder('SSC-D');
        $stuckChargeback->forceFill(['status' => 'chargeback'])->save();

        $matched = Order::query()->withoutGlobalScopes()->pendingReversalSettlement()->pluck('id');

        $this->assertEqualsCanonicalizing([$stuckRefund->id, $stuckChargeback->id], $matched->all());
    }

    // ------------------------------------------------------------------

    /** 模拟插件推送一条 payment_status 回调。 */
    private function pushGatewayStatus(Order $order, string $gatewayStatus): void
    {
        app(OrderPaymentStatusService::class)->handle([
            'event' => 'payment_status',
            's_order_id' => $order->order_no,
            'status' => $gatewayStatus,
            'amount' => self::ORDER_AMOUNT,
            'currency' => 'USD',
            'transaction_id' => 'txn_1',
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /** @return array<string, string> 流水类型 => 金额 */
    private function ledgerFor(Order $order): array
    {
        return MerchantBalanceTransaction::query()
            ->withoutGlobalScopes()
            ->where('order_id', $order->id)->where('type', '!=', 'order_paid')
            ->pluck('amount', 'type')
            ->map(fn ($amount) => (string) $amount)
            ->all();
    }

    private function paidOrder(string $orderNo = 'SSC-1'): Order
    {
        $order = $this->makeOrder($orderNo, 'M-'.$orderNo, [
            'status' => 'paid',
            'paid_at' => now(), 'transaction_id' => 'txn_1',
            'payment_method' => $this->paymentMethod->method_code,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => self::ORDER_AMOUNT,
            'converted_amount' => self::ORDER_AMOUNT,
            'subtotal' => self::ORDER_AMOUNT,
            'subtotal_converted' => self::ORDER_AMOUNT,
        ]);
        MerchantBalanceTransaction::create([
            'merchant_id' => $order->merchant_id, 'order_id' => $order->id, 'type' => 'order_paid',
            'amount' => self::ORDER_AMOUNT, 'balance_before' => '800.00', 'balance_after' => '1000.00',
        ]);

        return $order;
    }

    private function operator(): User
    {
        return User::firstOrCreate(
            ['email' => 'finance@example.com'],
            ['name' => 'Finance', 'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id],
        );
    }
}
