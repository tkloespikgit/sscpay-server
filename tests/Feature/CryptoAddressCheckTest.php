<?php

namespace Tests\Feature;

use App\Jobs\QueryCryptoAddressCheck;
use App\Models\CryptoAddress;
use App\Models\CryptoAddressCheck;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\User;
use App\Services\CryptoAddressCheckService;
use App\Services\CryptoAddressMonitorService;
use App\Services\CryptoReceiptVerificationService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesCryptoHistory;
use Tests\TestCase;

class CryptoAddressCheckTest extends TestCase
{
    use CreatesCryptoHistory;
    use RefreshDatabase;

    public function test_first_cancellation_fixes_two_slots_and_renewal_does_not_reset_them(): void
    {
        [$monitor] = $this->historyFixture();
        $service = app(CryptoAddressCheckService::class);
        $first = now()->toImmutable();
        $service->schedule($monitor->merchant_id, $monitor->paymentRequest->order_id, $first);
        $service->schedule($monitor->merchant_id, $monitor->paymentRequest->order_id, $first->addDays(2));
        $this->assertDatabaseCount('crypto_address_checks', 2);
        $this->assertTrue(CryptoAddressCheck::where('kind', 'day3')->sole()->due_at->equalTo($first->addHours(72)));
        $this->assertTrue(CryptoAddressCheck::where('kind', 'day7')->sole()->due_at->equalTo($first->addHours(168)));
    }

    public function test_scheduler_only_dispatches_due_slots_and_does_not_repeat_completed_checks(): void
    {
        [$monitor] = $this->historyFixture();
        Queue::fake();
        Http::preventStrayRequests();
        $service = app(CryptoAddressCheckService::class);
        $service->schedule($monitor->merchant_id, $monitor->paymentRequest->order_id, now()->toImmutable());
        $this->travel(71)->hours();
        $this->artisan('crypto:check-addresses')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->travel(1)->hours();
        $this->artisan('crypto:check-addresses')->assertSuccessful();
        $this->artisan('crypto:check-addresses')->assertSuccessful();
        Queue::assertPushed(QueryCryptoAddressCheck::class, 1);
        CryptoAddressCheck::where('kind', 'day3')->update(['status' => 'completed']);
        $this->travel(4)->days();
        $this->artisan('crypto:check-addresses')->assertSuccessful();
        Queue::assertPushed(QueryCryptoAddressCheck::class, 2);
        CryptoAddressCheck::where('kind', 'day7')->update(['status' => 'completed']);
        $this->travel(30)->days();
        $this->artisan('crypto:check-addresses')->assertSuccessful();
        Queue::assertPushed(QueryCryptoAddressCheck::class, 2);
        Http::assertNothingSent();
    }

    public function test_deployment_initialization_uses_earliest_actual_cancellation_and_is_repeatable(): void
    {
        [$monitor] = $this->historyFixture();
        $cancelled = now()->subDays(10)->toImmutable();
        $monitor->update(['status' => 'cold', 'cancelled_at' => $cancelled]);
        Http::preventStrayRequests();
        $this->artisan('crypto:check-addresses --initialize')->expectsOutput('Initialized address check schedules for 1 order(s).')->assertSuccessful();
        $this->artisan('crypto:check-addresses --initialize')->expectsOutput('Initialized address check schedules for 0 order(s).')->assertSuccessful();
        $this->assertDatabaseCount('crypto_address_checks', 2);
        $this->assertTrue(CryptoAddressCheck::where('kind', 'day3')->sole()->due_at->equalTo($cancelled->addDays(3)));
        Http::assertNothingSent();
    }

    public function test_manual_check_is_immediate_deduplicated_and_does_not_consume_the_two_slots(): void
    {
        [$monitor] = $this->historyFixture();
        Queue::fake();
        $user = $this->queryUser($monitor->merchant_id);
        $service = app(CryptoAddressCheckService::class);
        $order = $monitor->paymentRequest->order;
        $service->schedule($monitor->merchant_id, $order->id, now()->toImmutable());
        $first = $service->requestManual($user, $order);
        $this->assertSame($first->id, $service->requestManual($user, $order)->id);
        $this->assertTrue($first->due_at->equalTo(now()));
        $this->assertSame(2, CryptoAddressCheck::whereIn('kind', ['day3', 'day7'])->where('status', 'pending')->count());
        Queue::assertPushed(QueryCryptoAddressCheck::class, 1);
    }

    public function test_manual_check_rejects_foreign_merchant_and_rechecks_permissions_in_worker(): void
    {
        [$monitor] = $this->historyFixture();
        Queue::fake();
        $user = $this->queryUser($monitor->merchant_id);
        $service = app(CryptoAddressCheckService::class);
        $order = $monitor->paymentRequest->order;
        $foreign = $this->queryUser(Merchant::create(['name' => 'Foreign', 'merchant_no' => 'foreign', 'status' => true])->id);
        try {
            $service->requestManual($foreign, $order);
            $this->fail('Foreign merchant could query this address.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('crypto_address_checks', 0);
        }
        $check = $service->requestManual($user, $order);
        $user->revokePermissionTo(Permissions::ORDERS_VIEW);
        Http::preventStrayRequests();
        try {
            $service->process($monitor->merchant_id, $check->id);
            $this->fail('Revoked user could run a queued query.');
        } catch (AuthorizationException) {
            $this->assertSame('access_revoked', $check->fresh()->last_error_code);
            Http::assertNothingSent();
        }
    }

    public function test_address_check_discovers_missing_callback_and_credits_net_once_without_full_block_reads(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $monitor->paymentRequest->update(['current_monitor_id' => null]);
        Queue::fake();
        $this->fakeHistoryRpc($rpc);
        $service = app(CryptoAddressCheckService::class);
        $check = $service->requestManual($this->queryUser($monitor->merchant_id), $monitor->paymentRequest->order);
        $this->assertTrue($service->process($monitor->merchant_id, $check->id));
        $this->assertTrue($service->process($monitor->merchant_id, $check->id));
        $this->assertSame('completed', $check->fresh()->status);
        $this->assertSame('40392000000000000', CryptoLedgerEntry::query()->sole()->amount_units);
        $this->assertNotNull($monitor->paymentRequest->order->fresh()->paid_at);
        $this->assertSame('0.00', $this->merchant->fresh()->balance);
        $this->assertNull(CryptoWebhookReceipt::query()->sole()->crypto_address_monitor_id);
        Http::assertNotSent(fn ($r): bool => ($r->data()['method'] ?? null) === 'debug_traceBlockByNumber'
            || (($r->data()['method'] ?? null) === 'eth_getBlockByNumber' && ($r['params'][1] ?? null) === true));
    }

    public function test_failure_does_not_complete_a_round_and_retry_resumes_without_duplicate_income(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        Queue::fake();
        $rpc['fail_address_history'] = true;
        $this->fakeHistoryRpc($rpc);
        $service = app(CryptoAddressCheckService::class);
        $check = $service->requestManual($this->queryUser($monitor->merchant_id), $monitor->paymentRequest->order);
        try {
            $service->process($monitor->merchant_id, $check->id);
            $this->fail('Provider failure completed a round.');
        } catch (\DomainException) {
            $this->assertNull($check->fresh()->completed_at);
            $this->assertSame('error', $check->fresh()->status);
            $this->assertDatabaseCount('crypto_ledger_entries', 0);
        }
        unset($rpc['fail_address_history']);
        $this->assertTrue($service->process($monitor->merchant_id, $check->id));
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
    }

    public function test_overdue_day7_covers_day3_and_late_payment_is_reviewed_without_automatic_credit(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        Queue::fake();
        $rpc['blocks'][100]['transactions'] = [];
        $service = app(CryptoAddressCheckService::class);
        $service->schedule($monitor->merchant_id, $monitor->paymentRequest->order_id, now()->toImmutable());
        $this->travel(7)->days();
        $rpc['head'] = 105;
        $rpc['finalized'] = 105;
        $rpc['blocks'][103]['timestamp'] = '0x'.dechex(now()->getTimestamp() - 10);
        $rpc['blocks'][105]['timestamp'] = '0x'.dechex(now()->getTimestamp());
        $this->addHistoryTransfer($rpc, $monitor, 103, str_repeat('f', 64), $monitor->paymentRequest->due_units);
        $this->fakeHistoryRpc($rpc);
        $seventh = CryptoAddressCheck::where('kind', 'day7')->sole();
        $this->assertSame(1, $service->dispatchDue(100));
        Queue::assertPushed(QueryCryptoAddressCheck::class, fn ($job): bool => $job->checkId === $seventh->id);
        $this->assertTrue($service->process($monitor->merchant_id, $seventh->id));
        $this->assertSame(2, CryptoAddressCheck::where('status', 'completed')->count());
        $this->assertSame($seventh->id, CryptoAddressCheck::where('kind', 'day3')->sole()->result['covered_by_check_id']);
        $this->assertSame('review', $monitor->paymentRequest->fresh()->status);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertSame('review', CryptoWebhookReceipt::query()->sole()->status);
    }

    public function test_schedule_runs_every_three_hours_and_no_network_scan_is_scheduled(): void
    {
        $events = app(Schedule::class)->events();
        $checks = collect($events)->filter(fn ($event) => str_contains($event->command ?? '', 'crypto:check-addresses'));
        $this->assertCount(1, $checks);
        $this->assertSame('0 */3 * * *', $checks->first()->expression);
        $this->assertFalse(collect($events)->contains(fn ($event) => str_contains($event->command ?? '', 'crypto:scan-history')));
    }

    public function test_repeated_provider_failures_stop_automatic_requests_until_a_new_manual_query(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        Queue::fake();
        $rpc['fail_address_history'] = true;
        $this->fakeHistoryRpc($rpc);
        $service = app(CryptoAddressCheckService::class);
        $user = $this->queryUser($monitor->merchant_id);
        $check = $service->requestManual($user, $monitor->paymentRequest->order);
        foreach (range(1, 3) as $attempt) {
            try {
                $service->process($monitor->merchant_id, $check->id);
                $this->fail('Provider failure was accepted.');
            } catch (\DomainException) {
                $this->assertSame($attempt, $check->fresh()->failure_count);
            }
        }
        $calls = Http::recorded()->count();
        $this->travel(4)->hours();
        $this->assertSame(0, $service->dispatchDue(100));
        $this->assertTrue($service->process($monitor->merchant_id, $check->id));
        $this->assertSame($calls, Http::recorded()->count());
        $this->assertNull($check->fresh()->completed_at);
        $this->assertNotSame($check->id, $service->requestManual($user, $monitor->paymentRequest->order)->id);
    }

    public function test_worker_failure_preserves_audit_and_cannot_resurrect_a_completed_query(): void
    {
        [$monitor] = $this->historyFixture();
        $check = CryptoAddressCheck::factory()->create(['merchant_id' => $monitor->merchant_id,
            'order_id' => $monitor->paymentRequest->order_id, 'status' => 'running']);
        $job = new QueryCryptoAddressCheck($monitor->merchant_id, $check->id);
        $job->failed(new \RuntimeException('Provider detail must not be stored'));
        $this->assertSame('worker_failed', $check->fresh()->last_error_code);
        $this->assertSame(1, $check->fresh()->failure_count);
        $this->assertNull($check->fresh()->completed_at);
        $check->update(['status' => 'completed', 'completed_at' => now()]);
        $job->failed(new \RuntimeException('stale worker'));
        $this->assertSame('completed', $check->fresh()->status);
        $this->assertSame(1, $check->fresh()->failure_count);
    }

    public function test_webhook_receipt_without_subscription_cannot_use_the_local_query_exception(): void
    {
        [$monitor] = $this->historyFixture();
        Http::preventStrayRequests();
        $receipt = CryptoWebhookReceipt::factory()->create(['merchant_id' => $monitor->merchant_id,
            'crypto_payment_request_id' => $monitor->crypto_payment_request_id, 'crypto_address_monitor_id' => null,
            'signature_mode' => 'raw']);
        $this->assertFalse(app(CryptoReceiptVerificationService::class)->process($monitor->merchant_id, $receipt->id));
        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
        $this->assertDatabaseCount('crypto_chain_events', 0);
        Http::assertNothingSent();
    }

    public function test_disabling_history_pauses_queued_work_without_exhausting_its_retries(): void
    {
        [$monitor] = $this->historyFixture();
        Queue::fake();
        $service = app(CryptoAddressCheckService::class);
        $check = $service->requestManual($this->queryUser($monitor->merchant_id), $monitor->paymentRequest->order);
        $this->setCryptoSettings(['crypto.history_enabled' => false]);
        Http::preventStrayRequests();
        $this->assertTrue($service->process($monitor->merchant_id, $check->id));
        $this->assertSame('pending', $check->fresh()->status);
        $this->assertSame(0, $check->fresh()->failure_count);
        $this->assertNull($check->fresh()->completed_at);
        Http::assertNothingSent();
        $this->setCryptoSettings(['crypto.history_enabled' => true]);
        $this->travel(3)->hours();
        $this->assertSame(1, $service->dispatchDue(100));
    }

    private function queryUser(int $merchantId): User
    {
        Permission::findOrCreate(Permissions::ORDERS_VIEW, 'web');
        $user = User::factory()->create(['merchant_id' => $merchantId, 'status' => true, 'is_super_admin' => false]);
        $user->givePermissionTo(Permissions::ORDERS_VIEW);

        return $user;
    }

    public function test_manual_query_reads_all_quote_addresses_before_deciding_whether_to_credit(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        Queue::fake();
        $original = $monitor->paymentRequest;
        $address = CryptoAddress::factory()->create([
            'merchant_id' => $monitor->merchant_id, 'order_id' => $original->order_id, 'payment_method_id' => $original->payment_method_id,
            'network' => $original->network, 'scan_from_block' => 100, 'address' => '0x'.str_repeat('e', 40),
        ]);
        $second = CryptoPaymentRequest::factory()->create([
            ...$original->only(['merchant_id', 'order_id', 'payment_method_id', 'network', 'asset', 'decimals', 'quote_snapshot', 'due_units', 'fee_units', 'net_units']),
            'replaces_request_id' => $original->id, 'crypto_address_id' => $address->id,
        ]);
        $other = app(CryptoAddressMonitorService::class)->prepare($monitor->merchant_id, $second->id, historyOnly: true);
        $this->addHistoryTransfer($rpc, $other, 100, str_repeat('d', 64), $second->due_units);
        $this->fakeHistoryRpc($rpc);
        $service = app(CryptoAddressCheckService::class);
        $check = $service->requestManual($this->queryUser($monitor->merchant_id), $original->order);
        $this->assertTrue($service->process($monitor->merchant_id, $check->id));
        $this->assertCount(2, $check->fresh()->result);
        $this->assertSame('multiple_requests_received', $original->fresh()->review_reason);
        $this->assertSame('multiple_requests_received', $second->fresh()->review_reason);
        $this->assertNull($original->order->fresh()->paid_at);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_empty_migration_can_roll_back_and_existing_audit_blocks_destructive_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_18_111740_create_crypto_address_checks_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('crypto_address_checks'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('crypto_payment_requests', 'history_query_state'));
        [$monitor] = $this->historyFixture();
        $check = CryptoAddressCheck::factory()->create(['merchant_id' => $monitor->merchant_id, 'order_id' => $monitor->paymentRequest->order_id]);
        try {
            $migration->down();
            $this->fail('Rollback destroyed check audit.');
        } catch (\RuntimeException) {
            $this->assertNotNull($check->fresh());
            $this->assertTrue(Schema::hasColumn('crypto_payment_requests', 'history_query_state'));
        }
    }
}
