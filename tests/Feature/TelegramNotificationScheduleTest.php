<?php

namespace Tests\Feature;

use App\Models\OrderDisputeEvent;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\OrderDisputeService;
use App\Services\TelegramNotificationSettings;
use App\Support\TelegramNotificationKind;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class TelegramNotificationScheduleTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_unshipped_digest_uses_the_configured_days_template_and_enabled_switch(): void
    {
        $this->freezeTime();
        $this->createMerchantAndApplication();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        TelegramBot::create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'test-token',
            'chat_id' => 'merchant-chat', 'is_enabled' => true,
        ]);
        $this->makeOrder('SSC-OLDER', 'M-OLDER', ['status' => 'paid', 'paid_at' => now()->subDays(3)]);
        $this->makeOrder('SSC-SECOND', 'M-SECOND', ['status' => 'paid', 'paid_at' => now()->subDays(4)]);
        $this->makeOrder('SSC-RECENT', 'M-RECENT', ['status' => 'paid', 'paid_at' => now()->subDays(2)]);
        $settings = app(TelegramNotificationSettings::class);
        $rule = [
            'enabled' => false, 'template' => 'Unshipped :count / :hours hours',
            'order_template' => 'Order :order_no / :merchant_order_no', 'unshipped_days' => 3, 'send_time' => '08:45',
        ];
        $settings->save(TelegramNotificationKind::UnshippedOverdue, $rule);

        $this->artisan('orders:notify-unshipped-overdue')->assertSuccessful();
        Http::assertNothingSent();

        $settings->save(TelegramNotificationKind::UnshippedOverdue, [...$rule, 'enabled' => true]);
        $this->artisan('orders:notify-unshipped-overdue')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === 'merchant-chat'
            && str_contains($request['text'], 'Unshipped 2 / 72 hours')
            && str_contains($request['text'], 'Order SSC-OLDER / M-OLDER')
            && str_contains($request['text'], 'Order SSC-SECOND / M-SECOND')
            && ! str_contains($request['text'], 'SSC-RECENT'));
    }

    public function test_dispute_sweep_uses_the_configured_window_and_preserves_skipped_events(): void
    {
        $this->freezeTime();
        $this->createMerchantAndApplication();
        $operator = User::factory()->create();
        $events = [];

        foreach (['inside' => 3, 'boundary' => 6, 'outside' => 7, 'expired' => -1, 'reminded' => 1, 'closed' => 1] as $name => $hours) {
            $order = $this->makeOrder('SSC-'.$name, 'M-'.$name);
            $events[$name] = OrderDisputeEvent::create([
                'merchant_id' => $this->merchant->id, 'order_id' => $order->id, 'order_no' => $order->order_no,
                'event_no' => 'CASE-'.$name, 'status' => $name === 'closed' ? 'closed' : 'processing',
                'reason' => 'Review', 'final_action' => 'refund', 'frozen_amount' => '100.00',
                'deadline_value' => 24, 'deadline_unit' => 'hours', 'deadline_hours' => 24,
                'opened_by' => $operator->id, 'opened_at' => now()->subDay(), 'due_at' => now()->addHours($hours),
                'reminded_at' => $name === 'reminded' ? now()->subHour() : null,
            ]);
        }

        $settings = app(TelegramNotificationSettings::class);
        $rule = ['enabled' => false, 'template' => '', 'reminder_hours' => 6];
        $settings->save(TelegramNotificationKind::OrderDisputeDueSoon, $rule);
        $this->mock(OrderDisputeService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendDueReminder'));
        $this->artisan('order-disputes:send-reminders')->assertSuccessful();
        $this->assertNull($events['inside']->refresh()->reminded_at);

        $settings->save(TelegramNotificationKind::OrderDisputeDueSoon, [...$rule, 'enabled' => true]);
        $this->mock(OrderDisputeService::class, function (MockInterface $mock) use ($events): void {
            foreach (['inside', 'boundary'] as $name) {
                $mock->shouldReceive('sendDueReminder')->once()
                    ->withArgs(fn (OrderDisputeEvent $event): bool => $event->is($events[$name]));
            }
        });
        $this->artisan('order-disputes:send-reminders')->assertSuccessful();
    }

    public function test_daily_digest_schedule_reads_saved_time_changes_in_the_system_timezone(): void
    {
        config(['app.timezone' => 'Asia/Shanghai']);
        $settings = app(TelegramNotificationSettings::class);
        $rule = ['enabled' => true, 'template' => '', 'unshipped_days' => 3, 'send_time' => '08:45'];
        $settings->save(TelegramNotificationKind::UnshippedOverdue, $rule);
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'orders:notify-unshipped-overdue'),
        );

        $this->assertNotNull($event);
        $this->travelTo('2026-11-16 08:44:00');
        $this->assertFalse($event->filtersPass($this->app));
        $this->travelTo('2026-11-16 08:45:00');
        $this->assertTrue($event->filtersPass($this->app));

        $settings->save(TelegramNotificationKind::UnshippedOverdue, [...$rule, 'send_time' => '09:30']);
        $this->assertFalse($event->filtersPass($this->app));
        $this->travelTo('2026-11-16 09:30:00');
        $this->assertTrue($event->filtersPass($this->app));
    }
}
