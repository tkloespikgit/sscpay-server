<?php

namespace Tests\Feature;

use App\Events\LogisticsAnomalyDetected;
use App\Listeners\SendLogisticsAnomalyNotification;
use App\Models\Merchant;
use App\Models\TelegramBot;
use Database\Seeders\PermissionSeeder;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogisticsAnomalyTelegramTest extends TestCase
{
    use RefreshDatabase;

    public function test_anomaly_event_queues_the_telegram_subscriber(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        Queue::fake([CallQueuedListener::class]);

        LogisticsAnomalyDetected::dispatch(17, $this->details());

        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SendLogisticsAnomalyNotification::class
            && $job->method === 'handle'
            && $job->afterCommit === true
            && $job->retryUntil->format('Y-m-d H:i:s') === '2026-11-05 12:00:00'
            && $job->backoff === 60
            && $job->data[0]->merchantId === 17
            && $job->data[0]->details['tracking_number'] === '1Z-ANOMALY');
        Queue::assertPushed(CallQueuedListener::class, 1);
    }

    public function test_anomaly_event_sends_only_to_the_order_merchants_bot(): void
    {
        $this->app->setLocale('zh_CN');
        $merchant = $this->createMerchant('Order Merchant');
        $bot = $this->createBot($merchant, 'order-bot', '111222333');
        $other = $this->createMerchant('Other Merchant');
        $this->createBot($other, 'other-bot', '999888777');
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/botorder-bot/sendMessage' => Http::response(['ok' => true])]);
        $listener = $this->listener();

        $listener->handle(new LogisticsAnomalyDetected($merchant->id, $this->details()));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/botorder-bot/sendMessage'
            && $request['chat_id'] === '111222333'
            && $request['parse_mode'] === 'HTML'
            && str_contains($request['text'], '物流异常提醒')
            && str_contains($request['text'], 'SSC-ANOMALY')
            && str_contains($request['text'], 'MERCHANT-ANOMALY')
            && str_contains($request['text'], '1Z-ANOMALY')
            && str_contains($request['text'], '运输途中'));
        $this->assertNotNull($bot->refresh()->last_sent_at);
        $this->assertNull($other->telegramBot?->last_sent_at);
        $listener->assertNotReleased();
    }

    #[DataProvider('notificationLocales')]
    public function test_notification_translates_the_reason_using_the_captured_threshold(string $locale, string $title, string $reason): void
    {
        $this->app->setLocale($locale);
        $merchant = $this->createMerchant('Localized Merchant');
        $this->createBot($merchant);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/botorder-bot/sendMessage' => Http::response(['ok' => true])]);
        $listener = $this->listener();

        $listener->handle(new LogisticsAnomalyDetected($merchant->id, $this->details(['days' => '3'])));

        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], $title)
            && str_contains($request['text'], $reason)
            && ! str_contains($request['text'], ':days')
            && ! str_contains($request['text'], 'admin.order.'));
        Http::assertSentCount(1);
        $listener->assertNotReleased();
    }

    /** @return array<string, array{string, string, string}> */
    public static function notificationLocales(): array
    {
        return [
            'Chinese' => ['zh_CN', '物流异常提醒', '3 天'],
            'English' => ['en', 'Logistics anomaly', '3 days'],
        ];
    }

    public function test_unknown_status_and_reason_and_order_fields_are_escaped_and_bounded(): void
    {
        $merchant = $this->createMerchant('Escaping Merchant');
        $this->createBot($merchant);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/botorder-bot/sendMessage' => Http::response(['ok' => true])]);
        $dangerous = '<script>'.str_repeat('&', 500).'</script>';
        $listener = $this->listener();

        $listener->handle(new LogisticsAnomalyDetected($merchant->id, $this->details(array_fill_keys([
            'order_no', 'merchant_order_no', 'tracking_number', 'status', 'reason',
        ], $dangerous))));

        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], '&lt;script&gt;')
            && ! str_contains($request['text'], '<script>')
            && ! str_contains($request['text'], $dangerous)
            && mb_strlen($request['text']) < 4096);
        Http::assertSentCount(1);
        $listener->assertNotReleased();
    }

    public function test_missing_bot_does_not_send_or_use_another_merchants_bot(): void
    {
        $merchant = $this->createMerchant('No Bot Merchant');
        $other = $this->createMerchant('Other Merchant');
        $this->createBot($other, 'other-bot', '999888777');
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $listener = $this->listener();

        $listener->handle(new LogisticsAnomalyDetected($merchant->id, $this->details()));

        Http::assertNothingSent();
        $listener->assertNotReleased();
    }

    public function test_disabled_bot_skips_the_notification(): void
    {
        $merchant = $this->createMerchant('Disabled Bot Merchant');
        $this->createBot($merchant)->update(['is_enabled' => false]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $listener = $this->listener();

        $listener->handle(new LogisticsAnomalyDetected($merchant->id, $this->details()));

        Http::assertNothingSent();
        $listener->assertNotReleased();
    }

    public function test_telegram_http_failure_does_not_fail_event_handling(): void
    {
        $merchant = $this->createMerchant('Failed Delivery Merchant');
        $bot = $this->createBot($merchant);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/botorder-bot/sendMessage' => Http::response(['ok' => false], 503)]);
        $listener = $this->listener();

        $listener->handle(new LogisticsAnomalyDetected($merchant->id, $this->details()));

        Http::assertSentCount(1);
        $this->assertNull($bot->refresh()->last_sent_at);
        $listener->assertReleased(60);
    }

    public function test_rate_limited_notification_is_released_and_can_send_in_the_next_minute(): void
    {
        $this->freezeTime();
        $merchant = $this->createMerchant('Rate Limited Merchant');
        $this->createBot($merchant);
        Cache::put('telegram_rate_limit:'.$merchant->id.':'.now()->format('YmdHi'), 30, 65);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/botorder-bot/sendMessage' => Http::response(['ok' => true])]);
        $listener = $this->listener();
        $event = new LogisticsAnomalyDetected($merchant->id, $this->details());

        $listener->handle($event);

        Http::assertNothingSent();
        $listener->assertReleased(60);

        $this->travel(1)->minutes();
        $retry = $this->listener();
        $retry->handle($event);

        Http::assertSentCount(1);
        $retry->assertNotReleased();
    }

    public function test_delivery_failure_does_not_retry_after_the_bot_has_been_disabled(): void
    {
        $merchant = $this->createMerchant('Disabled During Delivery Merchant');
        $bot = $this->createBot($merchant);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/botorder-bot/sendMessage' => function () use ($bot) {
            $bot->update(['is_enabled' => false]);

            return Http::response(['ok' => false], 503);
        }]);
        $listener = $this->listener();

        $listener->handle(new LogisticsAnomalyDetected($merchant->id, $this->details()));

        Http::assertSentCount(1);
        $listener->assertNotReleased();
    }

    private function listener(): SendLogisticsAnomalyNotification
    {
        return app(SendLogisticsAnomalyNotification::class)->withFakeQueueInteractions();
    }

    private function createMerchant(string $name): Merchant
    {
        $this->seed(PermissionSeeder::class);

        return Merchant::create([
            'name' => $name,
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);
    }

    private function createBot(Merchant $merchant, string $token = 'order-bot', string $chatId = '111222333'): TelegramBot
    {
        return TelegramBot::create([
            'merchant_id' => $merchant->id,
            'bot_token' => $token,
            'chat_id' => $chatId,
            'is_enabled' => true,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array{order_no: string, merchant_order_no: string, tracking_number: string, status: string, reason: string, days: string}
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'order_no' => 'SSC-ANOMALY',
            'merchant_order_no' => 'MERCHANT-ANOMALY',
            'tracking_number' => '1Z-ANOMALY',
            'status' => 'InTransit',
            'reason' => 'stale_5d',
            'days' => '5',
        ], $overrides);
    }
}
