<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderPaymentStatusService;
use App\Services\PaymentMethodFailureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderTelegramAlertsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('zh_CN');
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '1000.00']);
        $this->paymentMethod = $this->makePaymentMethod('alert-channel');
        $this->paymentMethod->update(['method_name' => '交易报警渠道']);
        $this->createBot($this->merchant, 'order-merchant');
    }

    public function test_gateway_amount_mismatch_alert_contains_the_local_original_transaction_details(): void
    {
        $order = $this->paidOrder();
        $this->fakeDelivery();

        $this->pushStatus($order, 'refunded', ['amount' => '75.00']);

        $message = $this->singleMessage();
        $this->assertStringContainsString('网关回传金额与订单金额不一致', $message);
        $this->assertStringContainsString('网关金额：75.00 EUR', $message);
        $this->assertOrderSummary($message, $order);
        $this->assertSame('refunded', $order->refresh()->status);
    }

    public function test_paid_gateway_fact_during_review_keeps_the_review_and_deduplicates_its_alert(): void
    {
        $order = $this->paidOrder();
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $review = app(BalanceService::class)->freezeForDisputeEvent($order, $operator, [
            'event_no' => 'ALERT-REVIEW', 'reason' => '争议审核', 'images' => [], 'final_action' => 'refund',
            'deadline_value' => 3, 'deadline_unit' => 'days', 'deadline_hours' => 72,
        ]);
        $this->fakeDelivery();

        $this->pushStatus($order, 'paid');
        $this->pushStatus($order, 'paid');

        $message = $this->singleMessage();
        $this->assertStringContainsString('网关状态更新（争议审核期间）', $message);
        $this->assertOrderSummary($message, $order);
        $this->assertSame(Order::STATUS_DISPUTE_REVIEW, $order->refresh()->status);
        $this->assertTrue($review->refresh()->isProcessing());
        $this->assertSame('100.00', $this->merchant->refresh()->frozen_balance);
    }

    public function test_failed_chargeback_accounting_alert_keeps_the_original_order_details(): void
    {
        $order = $this->paidOrder();
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        app(BalanceService::class)->refund($order, '10.00', $operator, '部分退款');
        $this->fakeDelivery();

        $this->pushStatus($order->refresh(), 'confused');

        $message = $this->singleMessage();
        $this->assertStringContainsString('网关退款/拒付自动入账失败', $message);
        $this->assertOrderSummary($message, $order);
        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertFalse(BalanceService::hasChargebackLedger($order));
    }

    #[DataProvider('sharedChannelDisableTriggers')]
    public function test_shared_channel_disable_notices_only_include_private_order_details_for_its_merchant(string $trigger): void
    {
        $otherMerchant = Merchant::create(['name' => 'Other recipient']);
        $this->createBot($otherMerchant, 'other-merchant');
        $this->paymentMethod->update(['merchant_id' => null, 'max_failed_times' => 1]);
        $this->paymentMethod->assignedMerchants()->sync([$this->merchant->id, $otherMerchant->id]);
        $order = $trigger === 'forbidden' ? $this->paidOrder() : $this->paidOrder([
            'status' => 'pending', 'paid_at' => null,
        ]);
        $this->fakeDelivery();

        if ($trigger === 'forbidden') {
            $this->pushStatus($order, 'paid', ['account_forbidden' => true]);
        } else {
            app(PaymentMethodFailureService::class)->recordFailure($order, $this->paymentMethod, $order->gatewayOrderReference());
        }

        $messages = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'));
        $this->assertCount(2, $messages);
        $byRecipient = $messages->mapWithKeys(fn (array $entry): array => [$entry[0]['chat_id'] => $entry[0]['text']]);
        $this->assertOrderSummary($byRecipient['order-merchant'], $order);
        foreach ([$order->merchant_order_no, $order->transaction_id, 'EUR', '87.65', '原始交易金额：', '付款时间：'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $byRecipient['other-merchant']);
        }
        $this->assertFalse($this->paymentMethod->refresh()->is_active);
    }

    /** @return array<string, array{string}> */
    public static function sharedChannelDisableTriggers(): array
    {
        return [
            'gateway forbids account' => ['forbidden'],
            'creation failure threshold' => ['consecutive_failures'],
        ];
    }

    private function createBot(Merchant $merchant, string $chatId): void
    {
        TelegramBot::create([
            'merchant_id' => $merchant->id, 'bot_token' => 'alert-test-bot-'.$merchant->id,
            'chat_id' => $chatId, 'is_enabled' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function paidOrder(array $overrides = []): Order
    {
        return $this->makeOrder('SSC-ALERT-SUMMARY', 'MERCHANT-PRIVATE-ALERT', array_merge([
            'status' => 'paid', 'paid_at' => '2026-11-04 12:05:06', 'transaction_id' => 'TX-PRIVATE-ALERT',
            'payment_method' => $this->paymentMethod->method_code, 'payment_method_id' => $this->paymentMethod->id,
            'currency' => 'EUR', 'amount' => '87.65',
        ], $overrides));
    }

    private function fakeDelivery(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            '*/wp-json/payment-plugin/v1/order-logs*' => Http::response(['code' => 0, 'data' => []]),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function pushStatus(Order $order, string $status, array $overrides = []): void
    {
        app(OrderPaymentStatusService::class)->handle(array_merge([
            'event' => 'payment_status', 's_order_id' => $order->gatewayOrderReference(), 'status' => $status,
            'amount' => '87.65', 'currency' => 'EUR', 'transaction_id' => 'TX-PRIVATE-ALERT',
        ], $overrides));
    }

    private function singleMessage(): string
    {
        $requests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'));
        $this->assertCount(1, $requests);
        $this->assertSame('order-merchant', $requests->first()[0]['chat_id']);

        return $requests->first()[0]['text'];
    }

    private function assertOrderSummary(string $message, Order $order): void
    {
        foreach (['系统订单号：SSC-ALERT-SUMMARY', '商户订单号：MERCHANT-PRIVATE-ALERT', '三方交易号：TX-PRIVATE-ALERT',
            '支付方式名称：交易报警渠道', '原始交易金额：EUR 87.65',
            $order->paid_at ? '付款时间：2026-11-04 12:05:06' : '付款时间：未付款'] as $value) {
            $this->assertStringContainsString($value, $message);
        }
    }
}
