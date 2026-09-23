<?php

namespace Tests\Feature;

use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderPaymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        // /order-logs 重拉与 Telegram 发送都会走 HTTP，统一挡掉
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []], 200)]);

        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '1000.00']);

        $this->paymentMethod = $this->makePaymentMethod('stripe');
        $this->paymentMethod->update(['refund_fee' => '2.00', 'chargeback_fee' => '15.00']);
    }

    public function test_gateway_refund_deducts_the_balance_and_records_it(): void
    {
        $order = $this->paidOrder();

        $this->pushGatewayStatus($order, 'refunded');

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

    /** 插件文档明确会重试投递同一条 payload，重复扣款是这里最不能出的错。 */
    public function test_replaying_the_same_payload_does_not_deduct_twice(): void
    {
        $order = $this->paidOrder();

        $this->pushGatewayStatus($order, 'refunded');
        $balanceAfterFirst = (string) $this->merchant->refresh()->balance;

        $this->pushGatewayStatus($order, 'refunded');
        $this->pushGatewayStatus($order, 'refunded');

        $this->assertSame($balanceAfterFirst, (string) $this->merchant->refresh()->balance);
        $this->assertSame(1, OrderRefund::query()->withoutGlobalScopes()->count());
    }

    public function test_gateway_refund_after_a_manual_partial_refund_only_deducts_the_remainder(): void
    {
        $order = $this->paidOrder();
        $operator = $this->operator();

        // 人工先退 50
        app(BalanceService::class)->refund($order, '50.00', $operator, '客户少收一件');
        $balanceAfterPartial = (string) $this->merchant->refresh()->balance;

        $this->pushGatewayStatus($order->refresh(), 'refunded');

        // 只补扣剩余 150 + 一次退款手续费 2
        $this->assertSame(
            bcsub($balanceAfterPartial, '152.00', 2),
            (string) $this->merchant->refresh()->balance,
        );
        $this->assertSame(self::ORDER_AMOUNT, (string) $order->refresh()->refunded_amount);
        $this->assertSame('refunded', $order->status);
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
        $this->pushGatewayStatus($order, 'refunded');

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

    public function test_pending_settlement_scope_only_matches_unsettled_orders(): void
    {
        $settledRefund = $this->paidOrder('SSC-A');
        $this->pushGatewayStatus($settledRefund, 'refunded');

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
            ->where('order_id', $order->id)
            ->pluck('amount', 'type')
            ->map(fn ($amount) => (string) $amount)
            ->all();
    }

    private function paidOrder(string $orderNo = 'SSC-1'): Order
    {
        return $this->makeOrder($orderNo, 'M-'.$orderNo, [
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => $this->paymentMethod->method_code,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => self::ORDER_AMOUNT,
            'converted_amount' => self::ORDER_AMOUNT,
            'subtotal' => self::ORDER_AMOUNT,
            'subtotal_converted' => self::ORDER_AMOUNT,
        ]);
    }

    private function operator(): User
    {
        return User::firstOrCreate(
            ['email' => 'finance@example.com'],
            ['name' => 'Finance', 'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id],
        );
    }
}
