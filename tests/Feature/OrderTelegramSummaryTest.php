<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Listeners\SendTelegramNotification;
use App\Models\Merchant;
use App\Models\TelegramBot;
use App\Support\OrderTelegramSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderTelegramSummaryTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_summary_contains_original_currency_amount_and_payment_time_in_system_timezone(): void
    {
        config()->set('app.timezone', 'Asia/Shanghai');
        $this->app->setLocale('zh_CN');
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('summary-method');
        $method->update(['method_name' => '银行卡渠道']);
        $order = $this->makeOrder('SSC-SUMMARY', 'M-SUMMARY', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
            'currency' => 'EUR', 'amount' => '123.45', 'converted_amount' => '135.79',
            'transaction_id' => 'TX-SUMMARY',
            'paid_at' => '2026-11-04 12:05:06',
        ]);

        $message = OrderTelegramSummary::forOrder($order);

        foreach (['系统订单号：SSC-SUMMARY', '商户订单号：M-SUMMARY', '三方交易号：TX-SUMMARY',
            '支付方式名称：银行卡渠道', '付款时间：2026-11-04 12:05:06 CST', '原始交易金额：EUR 123.45'] as $value) {
            $this->assertStringContainsString($value, $message);
        }
        $this->assertStringNotContainsString('135.79', $message);
    }

    public function test_deleted_and_assigned_shared_payment_methods_keep_their_display_name(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('shared-method');
        $method->update(['merchant_id' => null, 'method_name' => 'Shared gateway']);
        $method->assignedMerchants()->attach($this->merchant->id);
        $method->delete();
        $order = $this->makeOrder('SSC-SHARED-SUMMARY', 'M-SHARED-SUMMARY', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);

        $details = OrderTelegramSummary::details($order);

        $this->assertSame('Shared gateway', $details['payment_method_name']);
    }

    public function test_historical_orders_without_a_payment_method_id_resolve_the_name_by_code(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('historical-method');
        $method->update(['method_name' => 'Historical Channel']);
        $method->delete();
        $order = $this->makeOrder('SSC-OLD-SUMMARY', 'M-OLD-SUMMARY', [
            'payment_method_id' => null, 'payment_method' => $method->method_code,
        ]);

        $this->assertSame('Historical Channel', OrderTelegramSummary::details($order)['payment_method_name']);
    }

    public function test_another_merchants_payment_method_name_is_not_loaded(): void
    {
        $this->createMerchantAndApplication();
        $otherMerchant = Merchant::create([
            'name' => 'Other merchant', 'contact_person' => 'Other',
            'contact_phone' => '123456', 'contact_email' => 'other-summary@example.com',
        ]);
        $method = $this->makePaymentMethod('private-method');
        $method->update(['merchant_id' => $otherMerchant->id, 'method_name' => 'Private gateway']);
        $order = $this->makeOrder('SSC-PRIVATE-SUMMARY', 'M-PRIVATE-SUMMARY', [
            'payment_method_id' => $method->id, 'payment_method' => 'original-code',
        ]);

        $details = OrderTelegramSummary::details($order);

        $this->assertSame('original-code', $details['payment_method_name']);
    }

    public function test_pending_snapshot_uses_localized_placeholders_and_escapes_html(): void
    {
        $this->app->setLocale('en');

        $message = OrderTelegramSummary::render([
            'order_no' => 'SSC-<b>001</b>', 'merchant_order_no' => 'M&"001"',
            'currency' => 'GBP', 'amount' => '99.01',
        ]);

        $this->assertStringContainsString('System order: SSC-&lt;b&gt;001&lt;/b&gt;', $message);
        $this->assertStringContainsString('Merchant order: M&amp;&quot;001&quot;', $message);
        $this->assertStringContainsString('Third-party transaction: Unavailable', $message);
        $this->assertStringContainsString('Paid at: Not paid', $message);
        $this->assertStringContainsString('Original transaction amount: GBP 99.01', $message);
        $this->assertStringNotContainsString('<b>', $message);
    }

    public function test_batch_summaries_load_payment_method_names_once_per_merchant_and_preserve_tenant_boundaries(): void
    {
        $this->app->setLocale('en');
        $this->createMerchantAndApplication();
        $otherMerchant = Merchant::create(['name' => 'Batch other merchant']);
        $method = $this->makePaymentMethod('batch-shared');
        $method->update(['merchant_id' => null, 'method_name' => 'Shared historical channel']);
        $method->assignedMerchants()->attach($this->merchant->id);
        $method->delete();
        $privateMethod = $this->makePaymentMethod('batch-private');
        $privateMethod->update(['merchant_id' => $otherMerchant->id, 'method_name' => 'Other private channel']);
        $orders = collect();

        for ($index = 0; $index < 6; $index++) {
            $orders->push($this->makeOrder('SSC-BATCH-'.$index, 'M-BATCH-'.$index, [
                'payment_method_id' => $index % 2 === 0 ? $method->id : null,
                'payment_method' => $method->method_code,
            ]));
        }

        $ownInaccessible = $this->makeOrder('SSC-BATCH-PRIVATE', 'M-BATCH-PRIVATE', [
            'payment_method_id' => $privateMethod->id, 'payment_method' => 'original-private-code',
        ]);
        $otherInaccessible = $this->makeOrder('SSC-BATCH-OTHER-SHARED', 'M-BATCH-OTHER-SHARED', [
            'merchant_id' => $otherMerchant->id, 'payment_method_id' => $method->id,
            'payment_method' => $method->method_code,
        ]);
        $otherOwned = $this->makeOrder('SSC-BATCH-OTHER-OWNED', 'M-BATCH-OTHER-OWNED', [
            'merchant_id' => $otherMerchant->id, 'payment_method_id' => $privateMethod->id,
            'payment_method' => $privateMethod->method_code,
        ]);
        $orders->push($ownInaccessible, $otherInaccessible, $otherOwned);
        $this->expectsDatabaseQueryCount(2);

        $summaries = OrderTelegramSummary::forOrders($orders);

        $this->assertCount(9, $summaries);
        foreach ($orders->take(6) as $order) {
            $this->assertStringContainsString('Payment method name: Shared historical channel', $summaries[$order->id]);
        }
        $this->assertStringContainsString('Payment method name: original-private-code', $summaries[$ownInaccessible->id]);
        $this->assertStringNotContainsString('Other private channel', $summaries[$ownInaccessible->id]);
        $this->assertStringContainsString('Payment method name: batch-shared', $summaries[$otherInaccessible->id]);
        $this->assertStringNotContainsString('Shared historical channel', $summaries[$otherInaccessible->id]);
        $this->assertStringContainsString('Payment method name: Other private channel', $summaries[$otherOwned->id]);
    }

    #[DataProvider('notifiedStatuses')]
    public function test_status_notifications_include_every_common_order_field(string $status): void
    {
        $this->app->setLocale('zh_CN');
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->createMerchantAndApplication();
        TelegramBot::create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'summary-bot',
            'chat_id' => 'summary-chat', 'is_enabled' => true,
        ]);
        $method = $this->makePaymentMethod('status-summary');
        $order = $this->makeOrder('SSC-STATUS-SUMMARY', 'M-STATUS-SUMMARY', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
            'status' => $status, 'transaction_id' => 'TX-STATUS-SUMMARY',
            'paid_at' => '2026-11-04 12:05:06', 'currency' => 'EUR', 'amount' => '87.65',
        ]);

        app(SendTelegramNotification::class)->handleOrderStatusChanged(new OrderStatusChanged($order, 'paid', $status));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'summary-chat'
            && str_contains($request['text'], '系统订单号：SSC-STATUS-SUMMARY')
            && str_contains($request['text'], '商户订单号：M-STATUS-SUMMARY')
            && str_contains($request['text'], '三方交易号：TX-STATUS-SUMMARY')
            && str_contains($request['text'], '支付方式名称：STATUS-SUMMARY')
            && str_contains($request['text'], '付款时间：2026-11-04 12:05:06')
            && str_contains($request['text'], '原始交易金额：EUR 87.65'));
    }

    /** @return array<string, array{string}> */
    public static function notifiedStatuses(): array
    {
        return [
            'disputing' => ['disputing'],
            'refunded' => ['refunded'],
            'chargeback' => ['chargeback'],
        ];
    }
}
