<?php

namespace Tests\Feature;

use App\Exceptions\BalanceOperationException;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\OrderDisputeService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderDisputeTelegramNotificationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_opening_a_review_sends_one_notification_with_the_original_order_details(): void
    {
        [$order, $operator] = $this->prepareOrder();

        $event = app(OrderDisputeService::class)->open($order, $operator, $this->input());

        $this->assertSame('processing', $event->status);
        $this->assertSame('dispute_review', $order->refresh()->status);
        $this->assertSame('100.00', $this->merchant->refresh()->frozen_balance);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('order-merchant-chat', $request['chat_id']);
            foreach (['争议审核事件已开立', 'CASE-&lt;review&gt;', 'SSC-REVIEW', 'MERCHANT-REVIEW',
                'TXN-REVIEW', 'Review Channel', '2026-11-01 12:30:00', 'EUR 88.35'] as $value) {
                $this->assertStringContainsString($value, $request['text']);
            }

            return true;
        });
    }

    public function test_a_rolled_back_review_does_not_send_a_message_or_keep_frozen_funds(): void
    {
        [$order, $operator] = $this->prepareOrder();

        try {
            DB::transaction(function () use ($order, $operator): void {
                app(OrderDisputeService::class)->open($order, $operator, $this->input());
                Http::assertNothingSent();
                throw new RuntimeException('Rollback review');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback review', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('order_dispute_events', 0);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame('0.00', $this->merchant->refresh()->frozen_balance);
    }

    public function test_telegram_failure_does_not_fail_the_successfully_opened_review(): void
    {
        [$order, $operator] = $this->prepareOrder();
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->once()
            ->andThrow(new RuntimeException('Notification unavailable'));

        $event = app(OrderDisputeService::class)->open($order, $operator, $this->input());

        $this->assertSame('processing', $event->refresh()->status);
        $this->assertSame('100.00', $this->merchant->refresh()->frozen_balance);
        $this->assertSame('dispute_review', $order->refresh()->status);
    }

    public function test_a_disabled_bot_does_not_send_a_message(): void
    {
        [$order, $operator] = $this->prepareOrder();
        TelegramBot::query()->forMerchant($this->merchant->id)->update(['is_enabled' => false]);
        app(OrderDisputeService::class)->open($order, $operator, $this->input());
        Http::assertNothingSent();

        $this->assertDatabaseCount('order_dispute_events', 1);
    }

    public function test_an_invalid_order_does_not_create_a_review_or_send_a_message(): void
    {
        [$order, $operator] = $this->prepareOrder();
        $order->update(['status' => 'pending']);

        try {
            app(OrderDisputeService::class)->open($order, $operator, $this->input());
            $this->fail('An unpaid order cannot open a review.');
        } catch (BalanceOperationException) {
            $this->assertDatabaseCount('order_dispute_events', 0);
            $this->assertSame('0.00', $this->merchant->refresh()->frozen_balance);
            Http::assertNothingSent();
        }
    }

    public function test_due_reminders_include_the_same_order_details_even_for_a_soft_deleted_order(): void
    {
        [$order, $operator] = $this->prepareOrder();
        $event = app(OrderDisputeService::class)->open($order, $operator, $this->input());
        $order->delete();

        app(OrderDisputeService::class)->sendDueReminder($event, app(TelegramNotificationService::class));

        $this->assertNotNull($event->refresh()->reminded_at);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], '即将到期')
            && str_contains($request['text'], 'TXN-REVIEW')
            && str_contains($request['text'], 'Review Channel')
            && str_contains($request['text'], 'EUR 88.35')
            && str_contains($request['text'], 'MERCHANT-REVIEW'));
    }

    /** @return array{Order, User} */
    private function prepareOrder(): array
    {
        $this->app->setLocale('zh_CN');
        config(['app.timezone' => date_default_timezone_get()]);
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/botreview-test-token/sendMessage' => Http::response(['ok' => true])]);
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '200.00']);
        $method = $this->makePaymentMethod('review-channel');
        $method->update(['method_name' => 'Review Channel']);
        TelegramBot::create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'review-test-token',
            'chat_id' => 'order-merchant-chat', 'is_enabled' => true,
        ]);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $order = $this->makeOrder('SSC-REVIEW', 'MERCHANT-REVIEW', [
            'status' => 'paid', 'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
            'transaction_id' => 'TXN-REVIEW', 'paid_at' => '2026-11-01 12:30:00',
            'currency' => 'EUR', 'amount' => '88.35',
        ]);

        return [$order, $operator];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'event_no' => 'CASE-<review>', 'reason' => '<p>Review payment</p>',
            'final_action' => OrderDisputeEvent::FINAL_ACTION_REFUND,
            'deadline_value' => 3, 'deadline_unit' => OrderDisputeEvent::DEADLINE_UNIT_DAYS,
        ];
    }
}
