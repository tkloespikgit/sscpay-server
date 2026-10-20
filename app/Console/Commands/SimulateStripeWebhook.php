<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 模拟 Stripe 支付成功回调中台（本地联调用）。
 *
 * 【为什么不做成 HTTP 路由】
 * 这个命令能触发**和真实回调完全同一条代码路径**（走 HTTP 请求打自己的
 * /api/webhooks/payment-gateway/status），但不会新增对外暴露的入口——
 * 如果做成路由，就必须考虑"生产环境会不会被人随便调用把订单刷成已支付"。
 * 命令只在服务器上手动执行，风险面小得多。
 *
 * 【它调用的是真实接口，不是绕过接口直接改数据库】
 *   本命令 → 构造 PGA 转发后的 payload → 算 X-PGA-Signature
 *          → HTTP POST 到真实 webhook 路由 → 控制器验签 → OrderPaymentStatusService
 * 所以能完整验证：验签、事件过滤、状态流转、余额入账、幂等——这些环节
 * 和 Stripe 真实回调走的是同一套代码，测出来的结果可信。
 *
 * 【用法】
 *   php artisan webhook:simulate-stripe {order_no} [--status=paid] [--url=]
 *
 *   {order_no}  中台订单号（orders.order_no / s_order_id），必填。
 *   --status    模拟的插件侧状态，默认 paid。可选值见
 *               OrderPaymentStatusService::STATUS_MAP：
 *               pending / paid / failed / cancelled / expired /
 *               refunded / disputing / confused（confused → 中台 chargeback）
 *   --url       覆盖目标地址，默认用本地 APP_URL
 *
 * 【示例】
 *   # 模拟支付成功
 *   php artisan webhook:simulate-stripe ORD202610201121518524
 *
 *   # 模拟支付失败
 *   php artisan webhook:simulate-stripe ORD202610201121518524 --status=failed
 *
 *   # 模拟拒付（插件侧叫 confused，中台映射成 chargeback）
 *   php artisan webhook:simulate-stripe ORD202610201121518524 --status=confused
 */
class SimulateStripeWebhook extends Command
{
    protected $signature = 'webhook:simulate-stripe
                            {order_no : 中台订单号（orders.order_no / s_order_id）}
                            {--status=paid : 模拟的插件侧状态，默认 paid}
                            {--url= : 覆盖目标地址，默认用本地 APP_URL}';

    protected $description = '模拟 Stripe（经 PGA 插件）支付成功回调中台，走真实 webhook 接口与验签';

    public function handle(PaymentGatewayService $paymentGateway): int
    {
        $orderNo = (string) $this->argument('order_no');
        $status = (string) $this->option('status');

        $order = Order::query()->withoutGlobalScopes()->where('order_no', $orderNo)->first();

        if (! $order) {
            $this->error("找不到订单：{$orderNo}");
            $this->line('  用下面这条命令看可测试的订单：');
            $this->line("  php artisan tinker --execute=\"\Illuminate\Support\Facades\DB::table('orders')->where('status','pending')->pluck('order_no')\"");

            return self::FAILURE;
        }

        // 构造 PGA 转发后的 payment_status 回调体。
        // 字段与 doc/s-system-payment-status-notify.md 第二节一致：
        // s_order_id 是中台 order_no，transaction_id 是 Stripe 的 pi_xxx。
        $payload = [
            'event' => 'payment_status',
            's_order_id' => $order->order_no,
            'status' => $status,
            'wp_order_id' => (int) ($order->wp_order_id ?? 0),
            'transaction_id' => 'pi_sim_'.substr(bin2hex(random_bytes(8)), 0, 12),
            'payment_method' => (string) $order->payment_method,
            'gateway' => 'stripe',
            'amount' => (string) $order->amount,
            'currency' => (string) $order->currency,
            'fee_amount' => number_format(
                bcsub((string) $order->converted_amount, (string) $order->settlement_amount, 2),
                2,
                '.',
                ''
            ),
            'occurred_at' => now()->toIso8601String(),
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $rawBody, (string) config('payment_gateway.webhook_secret'));

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/')
            .'/api/webhooks/payment-gateway/status';

        $this->line('=== 模拟 Stripe 回调 ===');
        $this->line("  目标订单  : {$order->order_no}（当前状态 {$order->status}）");
        $this->line("  模拟状态  : {$status}");
        $this->line("  请求地址  : {$url}");
        $this->line('  payload   : '.$rawBody);
        $this->newLine();

        // 用 withBody() 发**未经解析的原始字符串**，不能用 post($url, $payload)。
        // 验签对象是 raw body：控制器里 $request->getContent() 拿到什么，
        // 我们就算什么签；如果用 post($url, $array) 让 Laravel 重新序列化，
        // 键顺序/转义都可能变化，签出来对不上，必然 401。
        $response = Http::withHeaders([
            'X-PGA-Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->withBody($rawBody, 'application/json')
            ->timeout(15)
            ->withOptions(['verify' => false]) // 本地自签证书，避免再撞 cURL error 60
            ->post($url);

        $this->line('=== 回调响应 ===');
        $this->line('  HTTP '.$response->status());
        $this->line('  '.$response->body());
        $this->newLine();

        if ($response->failed()) {
            $this->error('回调被拒绝。若是 401，请检查 PGA_WEBHOOK_SECRET 是否为空。');

            return self::FAILURE;
        }

        // 打印订单与资金的最终状态，方便确认这条回调到底产生了什么效果。
        $order->refresh();

        $this->line('=== 回调后状态 ===');
        $this->line('  订单状态    : '.$order->status);
        $this->line('  paid_at     : '.($order->paid_at ?? '(空)'));
        $this->line('  transaction : '.($order->transaction_id ?? '(空)'));

        $ledger = DB::table('merchant_balance_transactions')
            ->where('order_id', $order->id)
            ->get();

        if ($ledger->isEmpty()) {
            $this->line('  资金流水    : （无）');
        } else {
            $this->line('  资金流水    :');
            foreach ($ledger as $row) {
                $this->line(sprintf(
                    '    %-22s %10s  (%s → %s)',
                    $row->type,
                    $row->amount,
                    $row->balance_before,
                    $row->balance_after
                ));
            }
        }

        $this->newLine();
        $this->line('提示：重复执行同一条命令即可验证幂等——order_paid 流水不会重复增加。');

        return self::SUCCESS;
    }
}
