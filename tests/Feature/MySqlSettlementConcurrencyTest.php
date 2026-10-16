<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Exceptions\BalanceOperationException;
use App\Models\Merchant;
use App\Models\MerchantBalanceTransaction;
use App\Models\MerchantFundFreeze;
use App\Models\MerchantWithdrawal;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\OrderDisputeEventReply;
use App\Models\OrderSettlement;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderDisputeService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;
use Throwable;

class MySqlSettlementConcurrencyTest extends TestCase
{
    use CreatesTestOrders;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires an explicitly created, isolated MySQL test database.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires process forking and local process sockets.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertSame('', (string) config('database.connections.mysql.url'));
        $this->assertSame('', getenv('DB_URL'));
        $this->assertSame('1', getenv('SSCPAY_ALLOW_MYSQL_CONCURRENCY'));
        $this->assertMatchesRegularExpression('/\Asscpay_test_[a-f0-9]{16}\z/', $database);
        $this->assertSame($database, getenv('SSCPAY_CONCURRENCY_DATABASE'));
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS database_name')->database_name);

        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
        Http::preventStrayRequests();
        Event::fake([OrderStatusChanged::class]);
        Queue::fake();
    }

    public function test_concurrent_credit_for_one_order_creates_one_credit_and_one_settlement(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->paidOrder('duplicate-credit');
        $orderId = $order->id;
        $credit = static fn (): bool => app(BalanceService::class)->creditForPaidOrder(Order::findOrFail($orderId)) !== null;

        $results = $this->concurrently([$credit, $credit, $credit, $credit]);

        $this->assertSame(4, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'completed')));
        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['result'] === true)));
        $this->assertSame('95.00', $this->merchant->refresh()->balance);
        $this->assertSame('95.00', $this->merchant->pending_settlement_balance);
        $this->assertSame('0.00', $this->merchant->availableBalance());
        $this->assertSame(1, MerchantBalanceTransaction::where('type', MerchantBalanceTransaction::TYPE_ORDER_PAID)->count());
        $this->assertSame(1, OrderSettlement::where('order_id', $orderId)->count());
    }

    public function test_concurrent_withdrawals_cannot_exceed_the_merchants_available_balance(): void
    {
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '100.00']);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $merchantId = $this->merchant->id;
        $operatorId = $operator->id;
        $withdraw = static fn (): int => app(BalanceService::class)->requestWithdrawal(
            Merchant::findOrFail($merchantId),
            '80.00',
            User::findOrFail($operatorId),
        )->id;

        $results = $this->concurrently([$withdraw, $withdraw]);

        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'completed')));
        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'rejected')));
        $this->assertSame(1, MerchantWithdrawal::where('status', MerchantWithdrawal::STATUS_PENDING)->count());
        $this->assertSame('80.00', (string) MerchantWithdrawal::sum('amount'));
        $this->assertSame('100.00', $this->merchant->refresh()->balance);
        $this->assertSame('80.00', $this->merchant->pending_withdrawal_balance);
        $this->assertSame('20.00', $this->merchant->availableBalance());
    }

    public function test_concurrent_refund_and_settlement_cannot_restore_refunded_funds(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->paidOrder('refund-and-settlement');
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        app(BalanceService::class)->creditForPaidOrder($order);
        $this->travel(2)->days();
        $orderId = $order->id;
        $operatorId = $operator->id;
        $settlementId = OrderSettlement::where('order_id', $orderId)->sole()->id;

        $results = $this->concurrently([
            static fn (): bool => app(BalanceService::class)->settleOrder(OrderSettlement::findOrFail($settlementId)),
            static fn (): int => app(BalanceService::class)->refund(Order::findOrFail($orderId), '100.00', User::findOrFail($operatorId))->id,
        ]);

        $this->assertSame(['completed', 'completed'], array_column($results, 'status'));
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('100.00', $order->refunded_amount);
        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->pending_settlement_balance);
        $this->assertSame('0.00', $this->merchant->frozen_balance);
        $this->assertSame('-5.00', $this->merchant->availableBalance());
        $this->assertSame(1, MerchantBalanceTransaction::where('type', MerchantBalanceTransaction::TYPE_REFUND)->count());
        $this->assertSame(0, MerchantFundFreeze::where('status', MerchantFundFreeze::STATUS_FROZEN)->count());

        $this->assertFalse(app(BalanceService::class)->settleOrder(OrderSettlement::findOrFail($settlementId)));
        $this->assertSame('-5.00', $this->merchant->refresh()->balance);
        $this->assertSame('0.00', $this->merchant->frozen_balance);
    }

    public function test_concurrent_dispute_replies_and_closure_complete_without_reverse_locking(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->paidOrder('dispute-and-reply');
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        app(BalanceService::class)->creditForPaidOrder($order);
        $operatorId = $operator->id;

        for ($round = 0; $round < 3; $round++) {
            $event = app(BalanceService::class)->freezeForDisputeEvent($order->refresh(), $operator, [
                'event_no' => 'concurrent-dispute-'.$round,
                'reason' => 'Concurrent dispute lifecycle',
                'images' => [],
                'final_action' => 'refund',
                'deadline_value' => 3,
                'deadline_unit' => 'days',
                'deadline_hours' => 72,
            ]);
            $eventId = $event->id;

            $results = $this->concurrently([
                static fn (): int => app(OrderDisputeService::class)->reply(
                    OrderDisputeEvent::findOrFail($eventId),
                    User::findOrFail($operatorId),
                    ['content' => '<p>Reply received before closure</p>', 'images' => []],
                )->id,
                static fn (): bool => app(OrderDisputeService::class)->closeManually(
                    OrderDisputeEvent::findOrFail($eventId),
                    User::findOrFail($operatorId),
                ),
            ]);

            $this->assertContains($results[0]['status'], ['completed', 'rejected']);
            $this->assertSame('completed', $results[1]['status']);
            $this->assertTrue($results[1]['result']);
            $this->assertSame(OrderDisputeEvent::STATUS_CLOSED, $event->refresh()->status);
            $this->assertSame($results[0]['status'] === 'completed' ? 1 : 0, OrderDisputeEventReply::where('order_dispute_event_id', $eventId)->count());
            $this->assertSame('paid', $order->refresh()->status);
            $this->assertSame('0.00', $this->merchant->refresh()->frozen_balance);
            $this->assertSame('95.00', $this->merchant->balance);
        }
    }

    /**
     * @param  list<Closure(): mixed>  $operations
     * @return list<array{status: string, result?: mixed, exception?: class-string<Throwable>}>
     */
    private function concurrently(array $operations): array
    {
        DB::disconnect();
        $workers = [];
        $results = [];

        try {
            foreach ($operations as $operation) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

                if ($sockets === false) {
                    throw new RuntimeException('Unable to create process barrier.');
                }

                [$parentSocket, $childSocket] = $sockets;
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork concurrency worker.');
                }

                if ($pid === 0) {
                    fclose($parentSocket);

                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }

                    DB::purge();
                    fwrite($childSocket, "ready\n");
                    fgets($childSocket);

                    try {
                        $result = ['status' => 'completed', 'result' => $operation()];
                    } catch (BalanceOperationException) {
                        $result = ['status' => 'rejected'];
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'exception' => $exception::class];
                    }

                    DB::disconnect();
                    fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
                    fclose($childSocket);
                    exit(0);
                }

                fclose($childSocket);
                stream_set_timeout($parentSocket, 20);
                $workers[] = ['pid' => $pid, 'socket' => $parentSocket];
            }

            foreach ($workers as $worker) {
                $this->assertSame("ready\n", fgets($worker['socket']));
            }

            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }

            foreach ($workers as $worker) {
                $line = fgets($worker['socket']);
                $this->assertIsString($line, 'Concurrency worker timed out or exited without a result.');
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->assertNotSame('error', $result['status'], $result['exception'] ?? 'Unexpected worker error.');
                $results[] = $result;
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                $finished = pcntl_waitpid($worker['pid'], $status, WNOHANG);

                if ($finished === 0) {
                    posix_kill($worker['pid'], SIGKILL);
                    pcntl_waitpid($worker['pid'], $status);
                }
            }

            DB::purge();
        }
    }

    private function paidOrder(string $suffix): Order
    {
        return $this->makeOrder('mysql-'.$suffix, 'merchant-'.$suffix, [
            'status' => 'paid',
            'paid_at' => now(),
            'settlement_amount' => '95.00',
            'settlement_mode' => 'immediate',
            'settlement_days' => 1,
            'reserve_percent' => '10.0000',
            'reserve_days' => 7,
            'refund_fee_snapshot' => '0.00',
            'chargeback_fee_snapshot' => '0.00',
        ]);
    }
}
