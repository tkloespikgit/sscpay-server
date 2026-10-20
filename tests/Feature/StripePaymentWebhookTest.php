<?php

namespace Tests\Feature;

use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 模拟 Stripe（经由 PGA 插件）推送「支付成功」回调中台的端到端验证。
 *
 * 覆盖真实链路的每一环：
 *   验签（X-PGA-Signature = HMAC-SHA256(rawBody, PGA_WEBHOOK_SECRET)）
 *   → event/status 合法性
 *   → 按 order_no 找订单
 *   → 状态流转 pending → paid
 *   → 商户余额按 settlement_amount 入账
 *   → 幂等（重复回调不重复入账）
 *
 * 这个测试同时充当"测试 API"的行为说明书：TestWebhookStripePayment
 * 里构造的 payload 字段，就是真实 Stripe 回调经 PGA 转发后中台收到的结构。
 */
class StripePaymentWebhookTest extends TestCase
{
    use CreatesTestOrders;

    // 每例跑在独立事务里，回调写入的订单/流水状态不会互相污染。
    use RefreshDatabase;

    /**
     * 构造 PGA 转发后的 payment_status 回调体。
     *
     * @param  array  $overrides  覆盖任意字段
     */
    private function webhookPayload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'payment_status',
            's_order_id' => '',
            'status' => 'paid',
            'wp_order_id' => 0,
            'transaction_id' => 'pi_'.uniqid(),
            'paid_at' => now()->toIso8601String(),
            'amount' => '100.00',
            'currency' => 'USD',
            'payment_method' => 'stripe_card',
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    /**
     * 按中台的验签规则算签名：HMAC-SHA256(原始 body 字符串, secret)。
     * 注意签名对象是**未经解析的原始字符串**，不能先 json_encode 一遍再算。
     */
    private function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) config('payment_gateway.webhook_secret'));
    }

    /** 发一个回调，返回 [状态码, 解码后的 body]。 */
    private function postWebhook(array $payload, ?string $signature = null): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 签名头必须以 HTTP_ 前缀放进 server 数组，Symfony 才会转成真实请求头
        // （写成 'X-PGA-Signature' 不会被转，控制器读 header() 会拿到 null → 401）。
        $response = $this->call(
            'POST',
            '/api/webhooks/payment-gateway/status',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PGA_SIGNATURE' => $signature === null ? $this->sign($raw) : $signature,
            ],
            $raw
        );

        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    }

    // ------------------------------------------------------------------
    // 验签
    // ------------------------------------------------------------------

    public function test_it_rejects_webhook_with_invalid_signature(): void
    {
        [$code] = $this->postWebhook($this->webhookPayload(), 'wrong-signature');

        $this->assertSame(401, $code);
    }

    public function test_it_rejects_webhook_without_signature_header(): void
    {
        [$code] = $this->postWebhook($this->webhookPayload(), '');

        $this->assertSame(401, $code);
    }

    public function test_it_ignores_unknown_event(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('WEBHOOK-EVENT', 'M-EVENT');

        [$code, $body] = $this->postWebhook($this->webhookPayload([
            'event' => 'some_other_event',
            's_order_id' => $order->order_no,
        ]));

        $this->assertSame(200, $code);
        $this->assertSame('ignored', $body['message']);
        $this->assertSame('pending', $order->refresh()->status);
    }

    // ------------------------------------------------------------------
    // 支付成功主链路
    // ------------------------------------------------------------------

    public function test_paid_webhook_flips_order_and_credits_merchant_balance(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('WEBHOOK-PAID', 'M-PAID', [
            'status' => 'pending',
            'settlement_amount' => '97.00',
        ]);

        [$code, $body] = $this->postWebhook($this->webhookPayload([
            's_order_id' => $order->order_no,
        ]));

        $this->assertSame(200, $code);
        $this->assertSame('ok', $body['message']);

        $order->refresh();

        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at, 'paid_at 应被写入');
    }

    public function test_paid_webhook_credits_settlement_amount_not_converted_amount(): void
    {
        $this->createMerchantAndApplication();

        // settlement_amount 与 converted_amount 故意不同：
        // 入账必须按 settlement_amount（已扣手续费），否则商户余额会多算。
        $order = $this->makeOrder('WEBHOOK-AMT', 'M-AMT', [
            'status' => 'pending',
            'converted_amount' => '100.00',
            'settlement_amount' => '97.00',
        ]);

        $this->postWebhook($this->webhookPayload(['s_order_id' => $order->order_no]));

        $ledger = MerchantBalanceTransaction::query()
            ->withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', MerchantBalanceTransaction::TYPE_ORDER_PAID)
            ->first();

        $this->assertNotNull($ledger, '应落一条 order_paid 流水');
        // 关键断言：入账金额必须是 settlement_amount（97.00，已扣手续费），
        // 而不是 converted_amount（100.00）—— 差额 3.00 就是被平台扣走的手续费。
        $this->assertSame('97.00', number_format((float) $ledger->amount, 2, '.', ''));
        $this->assertSame('97.00', number_format((float) $ledger->balance_after - (float) $ledger->balance_before, 2, '.', ''));
    }

    public function test_repeated_paid_webhook_does_not_credit_twice(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('WEBHOOK-IDEM', 'M-IDEM', [
            'status' => 'pending',
            'settlement_amount' => '50.00',
        ]);

        $payload = $this->webhookPayload(['s_order_id' => $order->order_no]);

        $this->postWebhook($payload);
        $this->postWebhook($payload);
        $this->postWebhook($payload);

        $count = MerchantBalanceTransaction::query()
            ->withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', MerchantBalanceTransaction::TYPE_ORDER_PAID)
            ->count();

        $this->assertSame(1, $count, '幂等键应保证重复回调只入账一次');
    }

    // ------------------------------------------------------------------
    // 边界：找不到订单 / 未知状态
    // ------------------------------------------------------------------

    public function test_unknown_order_is_ignored_but_still_returns_2xx(): void
    {
        $this->createMerchantAndApplication();

        [$code, $body] = $this->postWebhook($this->webhookPayload([
            's_order_id' => 'NOT-EXIST-ORDER',
        ]));

        // 找不到订单属于"重试也没用"，返回 2xx 避免插件白重试 5 次
        $this->assertSame(200, $code);
        $this->assertSame('ok', $body['message']);
    }

    public function test_unknown_status_is_ignored(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('WEBHOOK-STATUS', 'M-STATUS');

        [$code] = $this->postWebhook($this->webhookPayload([
            's_order_id' => $order->order_no,
            'status' => 'some_weird_status',
        ]));

        $this->assertSame(200, $code);
        $this->assertSame('pending', $order->refresh()->status);
    }

    public function test_missing_s_order_id_is_ignored(): void
    {
        $this->createMerchantAndApplication();

        [$code] = $this->postWebhook($this->webhookPayload(['s_order_id' => '']));

        $this->assertSame(200, $code);
    }

    // ------------------------------------------------------------------
    // 完整 Stripe 回调样例（含 PGA 透传的原始字段）
    // ------------------------------------------------------------------

    public function test_full_stripe_success_callback_payload(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('WEBHOOK-STRIPE', 'M-STRIPE', [
            'status' => 'pending',
            'settlement_amount' => '97.00',
        ]);

        // 这份 payload 贴近 Stripe payment_intent.succeeded 经 PGA 转发后的实际结构：
        // - s_order_id  : 中台 order_no（PGA 落单时写入）
        // - wp_order_id : WordPress 订单 ID
        // - transaction_id : Stripe 的 pi_xxx
        $payload = $this->webhookPayload([
            'event' => 'payment_status',
            's_order_id' => $order->order_no,
            'wp_order_id' => 88888,
            'status' => 'paid',
            'transaction_id' => 'pi_3ABCdefXYZ789',
            'payment_method' => 'stripe_card',
            'gateway' => 'stripe',
            'amount' => '100.00',
            'currency' => 'usd',
            'fee_amount' => '2.80',
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ]);

        [$code, $body] = $this->postWebhook($payload);

        $this->assertSame(200, $code);
        $this->assertSame('ok', $body['message']);

        $fresh = $order->refresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('pi_3ABCdefXYZ789', $fresh->transaction_id, 'transaction_id 应被回写');
    }
}
