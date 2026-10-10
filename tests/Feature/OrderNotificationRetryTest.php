<?php

namespace Tests\Feature;

use App\Jobs\SendOrderNotificationJob;
use App\Models\OrderNotificationAttempt;
use App\Services\OrderNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderNotificationRetryTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
        Bus::fake([SendOrderNotificationJob::class]);
        Http::preventStrayRequests();
    }

    private function failedAttempt(string $suffix): OrderNotificationAttempt
    {
        $order = $this->makeOrder('SSC-'.$suffix, 'M-'.$suffix, ['notify_url' => 'https://merchant.example/callback']);
        $attempt = OrderNotificationAttempt::createInitialAttempt($order, 'trade_result', ['status' => 'paid']);
        $attempt->markFailed(500, 'failed', null);
        $attempt->update(['next_retry_at' => now()->subMinute()]);

        return $attempt;
    }

    public function test_retry_is_claimed_once_even_with_a_stale_model(): void
    {
        $attempt = $this->failedAttempt('one');
        $stale = $attempt->fresh();
        $service = app(OrderNotificationService::class);
        $next = $service->dispatchRetry($attempt);
        $this->assertSame(2, $next->attempt_number);
        $this->assertSame($attempt->request_payload, $next->request_payload);
        $this->assertNull($attempt->fresh()->next_retry_at);
        $this->assertNull($service->dispatchRetry($stale));
        Bus::assertDispatchedTimes(SendOrderNotificationJob::class, 1);
        Bus::assertDispatched(SendOrderNotificationJob::class, fn ($job) => $job->attemptId === $next->id && $job->afterCommit === true);
        $this->assertSame(2, OrderNotificationAttempt::count());
    }

    public function test_command_repairs_old_retry_marker_and_continues_other_orders(): void
    {
        $old = $this->failedAttempt('old');
        $existing = $old->createNextAttempt();
        $existing->markSuccess(200, 'ok');
        $old->update(['next_retry_at' => now()->subDays(10)]);
        $other = $this->failedAttempt('other');
        $deleted = $this->failedAttempt('deleted');
        $deleted->delete();

        $this->artisan('order-notifications:process-due')->expectsOutput('Dispatched 1 retry attempt(s).')->assertSuccessful();
        $this->assertNull($old->fresh()->next_retry_at);
        $this->assertSame('success', $existing->fresh()->status);
        $this->assertNull($other->fresh()->next_retry_at);
        $this->assertDatabaseHas('order_notification_attempts', ['order_id' => $other->order_id, 'attempt_number' => 2, 'status' => 'pending']);
        Bus::assertDispatchedTimes(SendOrderNotificationJob::class, 1);
        $this->artisan('order-notifications:process-due')->assertSuccessful();
        Bus::assertDispatchedTimes(SendOrderNotificationJob::class, 1);
    }

    public function test_retry_waits_until_due_and_stops_at_max_attempts(): void
    {
        $attempt = $this->failedAttempt('chain');
        $attempt->update(['next_retry_at' => now()->addMinute()]);
        $service = app(OrderNotificationService::class);
        $this->assertNull($service->dispatchRetry($attempt));
        Bus::assertNothingDispatched();

        $attempt->update(['next_retry_at' => now()->subMinute()]);
        for ($number = 2; $number <= 5; $number++) {
            $attempt = $service->dispatchRetry($attempt);
            $this->assertSame($number, $attempt->attempt_number);
            $attempt->markFailed(500, 'failed', null);
            if ($number < 5) {
                $attempt->update(['next_retry_at' => now()->subMinute()]);
            }
        }
        $this->assertSame('exhausted', $attempt->fresh()->status);
        $this->assertNull($service->dispatchRetry($attempt));
        Bus::assertDispatchedTimes(SendOrderNotificationJob::class, 4);
        $this->assertSame(5, OrderNotificationAttempt::count());
    }
}
