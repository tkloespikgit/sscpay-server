<?php

namespace Tests\Feature;

use App\Models\GatewayRefund;
use App\Models\Order;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\GatewayRefundService;
use App\Services\OrderPaymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class GatewayRefundReceiptTest extends TestCase
{
    use CreatesTestOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
        Queue::fake();
        Http::preventStrayRequests();
        config(['payment_gateway.webhook_secret' => 'refund-test-secret']);
    }

    public function test_replayed_equal_partial_refunds_and_fee_only_final_receipt(): void
    {
        $order = $this->makePaidOrder();
        foreach ([['re_A', '47.50'], ['re_A', '47.50'], ['re_B', '47.50'], ['re_C', '5.00'], ['re_C', '5.00']] as [$id, $amount]) {
            $this->push($this->payload($order, $id, $amount))->assertOk()->assertJson(['code' => 0, 'received' => true, 'refund_id' => $id]);
        }
        $this->assertSame('100.00', $order->refresh()->refunded_amount);
        $this->assertSame('refunded', $order->status);
        $this->assertSame('-6.00', $this->merchant->refresh()->balance);
        $this->assertSame(['-47.50', '-47.50', '0.00'], $order->balanceTransactions()->where('type', 'refund')->orderBy('id')->pluck('amount')->all());
        $this->assertSame(['-2.00', '-2.00', '-2.00'], $order->balanceTransactions()->where('type', 'refund_fee')->orderBy('id')->pluck('amount')->all());
        $this->assertDatabaseCount('gateway_refunds', 3);
        $this->assertDatabaseCount('order_refunds', 3);
    }

    public function test_conflicts_and_malformed_or_unsigned_receipts_do_not_charge_again(): void
    {
        $order = $this->makePaidOrder();
        $payload = $this->payload($order, 're_A', '20.00');
        $this->push($payload, 'wrong')->assertUnauthorized();
        $this->push(array_diff_key($payload, ['refund_id' => true]))->assertUnprocessable();
        $this->push(array_replace($payload, ['status' => 'pending']))->assertUnprocessable();
        $this->push($payload)->assertOk();
        $this->push(array_replace($payload, ['amount' => '80.00']))->assertOk();
        $this->push(array_replace($payload, ['amount' => '80.00']))->assertOk();
        $other = $this->makePaidOrder('other');
        $this->push($this->payload($other, 're_A', '20.00'))->assertOk();
        $this->assertDatabaseCount('gateway_refund_conflicts', 2);
        $this->assertDatabaseCount('order_refunds', 1);
        $this->assertSame('20.00', $order->refresh()->refunded_amount);
        $this->assertSame('0.00', $other->refresh()->refunded_amount);
        $this->assertSame('168.00', $this->merchant->refresh()->balance);
    }

    public function test_legacy_status_and_retried_old_task_cannot_guess_refund_amount(): void
    {
        $order = $this->makePaidOrder();
        app(OrderPaymentStatusService::class)->handle(['s_order_id' => $order->order_no, 'status' => 'refunded', 'amount' => '100.00']);
        $order->accountingTasks()->where('type', 'refund')->update(['status' => 'pending', 'next_attempt_at' => null]);
        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $order->refresh()->refunded_amount);
        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertDatabaseHas('order_accounting_tasks', ['type' => 'refund', 'status' => 'manual_review']);
    }

    public function test_scheduled_refund_hint_queries_an_already_paid_order_and_releases_partial_hold(): void
    {
        $order = $this->makePaidOrder();
        $order->update(['gateway_status' => 'refunded', 'status' => 'refunded']);
        app(OrderPaymentStatusService::class)->handle(['s_order_id' => $order->order_no, 'status' => 'refunded']);
        Http::fake(['*order-query*' => Http::response(['code' => 0, 'data' => [
            'status' => 'refunded', 'refunds_complete' => true, 'refunds' => [$this->payload($order, 're_found', '20.00')],
        ]])]);
        $this->travel(2)->minutes();
        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->artisan('orders:settle-due')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame('partially_refunded', $order->refresh()->status);
        $this->assertTrue(Order::query()->whereKey($order->id)->withoutUnresolvedGatewayReversal()->exists());
        $this->assertSame('73.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('order_accounting_tasks', ['order_id' => $order->id, 'type' => 'refund', 'status' => 'completed']);
    }

    public function test_detail_from_query_and_callback_share_the_same_receipt(): void
    {
        $order = $this->makePaidOrder();
        $first = $this->payload($order, 're_A', '20.00');
        $this->push($first)->assertOk();
        Http::fake(['*order-query*' => Http::response(['code' => 0, 'data' => [
            'status' => 'refunded', 'refunds' => [$first, $this->payload($order, 're_B', '80.00')],
        ]])]);
        app(OrderPaymentStatusService::class)->queryAndApply($order);
        $this->push($first)->assertOk();
        $this->assertSame('-4.00', $this->merchant->refresh()->balance);
        $this->assertSame(['-20.00', '-75.00'], $order->balanceTransactions()->where('type', 'refund')->orderBy('id')->pluck('amount')->all());
        $this->assertDatabaseCount('order_refunds', 2);
    }

    public function test_authenticated_query_recovers_lost_payment_before_booking_refund(): void
    {
        $method = $this->makePaymentMethod('query');
        $order = $this->makeOrder('query', 'M-query', [
            'status' => 'pending', 'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
            'settlement_mode' => 'immediate', 'settlement_days' => 0, 'settlement_amount' => '95.00', 'refund_fee_snapshot' => '2.00',
        ]);
        $detail = array_replace($this->payload($order, 're_query', '100.00'), ['payment_reference' => 'pi_recovered']);
        Http::fake(['*order-query*' => Http::response(['code' => 0, 'data' => [
            's_order_id' => $order->order_no, 'status' => 'refunded', 'paid_at' => '2026-11-20T11:00:00Z',
            'transaction_id' => 'pi_recovered', 'currency' => 'USD', 'amount' => '100.00',
            'refunds_complete' => true, 'refunds' => [$detail],
        ]])]);
        app(OrderPaymentStatusService::class)->queryAndApply($order);
        app(OrderPaymentStatusService::class)->queryAndApply($order);
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('-2.00', $this->merchant->refresh()->balance);
        $this->assertSame(1, $order->balanceTransactions()->where('type', 'order_paid')->count());
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_scheduler_recovers_a_receipt_whose_original_paid_callback_was_lost(): void
    {
        $order = $this->makeOrder('late', 'M-late', [
            'status' => 'pending', 'payment_method_id' => $this->makePaymentMethod('late')->id,
            'settlement_amount' => '95.00', 'settlement_mode' => 'immediate', 'settlement_days' => 2, 'refund_fee_snapshot' => '2.00',
        ]);
        $detail = array_replace($this->payload($order, 're_late', '20.00'), ['payment_reference' => 'pi_late']);
        $this->push($detail)->assertOk();
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Http::fake(['*order-query*' => Http::response(['code' => 0, 'data' => [
            's_order_id' => 'late', 'status' => 'paid', 'paid_at' => now()->toIso8601String(),
            'transaction_id' => 'pi_late', 'currency' => 'USD', 'amount' => '100.00', 'refunds' => [$detail],
        ]])]);
        $this->travel(6)->minutes();
        $this->artisan('orders:settle-due')->assertSuccessful();
        $this->push($detail)->assertOk();
        $this->assertSame('partially_refunded', $order->refresh()->status);
        $this->assertSame('73.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_wrong_payment_currency_channel_and_overrefund_are_held_without_charging(): void
    {
        $order = $this->makePaidOrder();
        foreach ([
            ['refund_id' => 're_ref', 'payment_reference' => 'different'],
            ['refund_id' => 're_currency', 'currency' => 'EUR'],
            ['refund_id' => 're_method', 'payment_method' => 'antom'],
            ['refund_id' => 're_excess', 'amount' => '100.01'],
        ] as $overrides) {
            $this->push(array_replace($this->payload($order, 'unused', '20.00'), $overrides))->assertOk();
        }
        $this->assertSame(4, GatewayRefund::query()->where('status', 'review')->count());
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_refunds', 0);
    }

    public function test_matching_an_existing_manual_refund_never_posts_another_fee(): void
    {
        $order = $this->makePaidOrder();
        $operator = User::factory()->create();
        $manual = app(BalanceService::class)->refund($order, '30.00', $operator);
        $this->push($this->payload($order, 're_old', '30.00'))->assertOk();
        $receipt = GatewayRefund::query()->sole();
        $this->assertSame('unlinked_refunds', $receipt->last_error);
        app(GatewayRefundService::class)->resolveUnlinked($order, $receipt->id, $manual->id, $operator);
        $this->push($this->payload($order, 're_old', '30.00'))->assertOk();
        $this->push($this->payload($order, 're_new', '30.00'))->assertOk();
        $this->assertSame('31.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseCount('order_refunds', 2);
    }

    private function makePaidOrder(string $no = 'test'): Order
    {
        $method = $this->makePaymentMethod('channel-'.$no);
        $order = $this->makeOrder($no, 'M-'.$no, [
            'status' => 'paid', 'paid_at' => now(), 'transaction_id' => 'pi_'.$no,
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
            'settlement_amount' => '95.00', 'refund_fee_snapshot' => '2.00',
        ]);
        app(BalanceService::class)->creditForPaidOrder($order);

        return $order;
    }

    private function payload(Order $order, string $refundId, string $amount): array
    {
        return [
            'event' => 'payment_refund', 'status' => 'succeeded', 's_order_id' => $order->order_no,
            'payment_method' => 'stripe', 'livemode' => true, 'refund_id' => $refundId,
            'payment_reference' => $order->transaction_id, 'amount' => $amount,
            'currency' => 'USD', 'refunded_at' => '2026-11-25T12:00:00Z',
        ];
    }

    private function push(array $payload, ?string $signature = null): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/webhooks/payment-gateway/status', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PGA_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, 'refund-test-secret'),
        ], $raw);
    }
}
