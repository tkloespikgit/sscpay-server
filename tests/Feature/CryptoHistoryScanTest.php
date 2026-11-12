<?php

namespace Tests\Feature;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\User;
use App\Services\CryptoAddressMonitorService;
use App\Services\CryptoHistoryScanService;
use App\Services\CryptoReceiptVerificationService;
use App\Services\CryptoSettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCryptoHistory;
use Tests\TestCase;

class CryptoHistoryScanTest extends TestCase
{
    use CreatesCryptoHistory;
    use RefreshDatabase;

    public function test_cold_address_without_webhook_is_discovered_continuously_with_resumable_coverage(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $monitor->update(['status' => 'cold', 'cancelled_at' => now()->subMinute()]);
        $this->fakeHistoryRpc($rpc);
        $scanner = app(CryptoHistoryScanService::class);

        $this->assertTrue($scanner->scan('ethereum-sepolia'));
        $this->assertSame(100, $monitor->paymentRequest->fresh()->history_to_block);
        $this->assertNull($monitor->fresh()->cold_scan_checked_at);
        $receipt = CryptoWebhookReceipt::query()->sole();
        $this->assertSame('history_rpc', $receipt->signature_mode);
        $this->assertSame('pending', $receipt->status);
        $this->assertSame($monitor->merchant_id, $receipt->merchant_id);
        $this->assertDatabaseCount('crypto_chain_events', 0);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);

        $this->assertTrue($scanner->scan('ethereum-sepolia'));
        $this->assertTrue($scanner->scan('ethereum-sepolia'));
        $this->assertSame(102, $monitor->fresh()->cold_scan_to_block);
        $this->assertSame($this->historyHash(102), $monitor->fresh()->backfill_to_hash);
        $this->assertNotNull($monitor->fresh()->backfill_completed_at);
        $this->assertNotNull($monitor->paymentRequest->fresh()->history_checked_at);
        $this->assertFalse($scanner->scan('ethereum-sepolia'));
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);

        $this->travel(61)->seconds();
        $rpc['head'] = 103;
        $this->addHistoryTransfer($rpc, $monitor, 103, str_repeat('e', 64), '1');
        $this->assertTrue($scanner->scan('ethereum-sepolia'));
        $this->assertDatabaseCount('crypto_webhook_receipts', 2);
        $this->assertSame(103, $monitor->paymentRequest->fresh()->history_to_block);
    }

    public function test_rpc_failure_keeps_last_complete_block_and_clears_healthy_takeover_proof(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->fakeHistoryRpc($rpc);
        $scanner = app(CryptoHistoryScanService::class);
        $scanner->scan('ethereum-sepolia');
        $rpc['fail_method'] = 'eth_getLogs';
        try {
            $scanner->scan('ethereum-sepolia');
            $this->fail('Incomplete history advanced.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('credential', $exception->getMessage());
        }
        $this->assertSame(100, $monitor->paymentRequest->fresh()->history_to_block);
        $this->assertSame('history_read_failed', $monitor->paymentRequest->fresh()->history_error_code);
        $this->assertNull($monitor->fresh()->cold_scan_checked_at);
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);
        unset($rpc['fail_method']);
        $this->travel(61)->seconds();
        $scanner->scan('ethereum-sepolia');
        $this->assertSame(101, $monitor->paymentRequest->fresh()->history_to_block);
    }

    public function test_one_network_block_is_shared_between_merchants_and_browser_scope_does_not_hide_addresses(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $firstMerchantId = $monitor->merchant_id;
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('second-crypto-order', 'second-crypto-order', ['payment_type' => 'crypto', 'payment_method_id' => $monitor->paymentRequest->payment_method_id]);
        $address = CryptoAddress::factory()->create(['merchant_id' => $this->merchant->id, 'order_id' => $order->id,
            'payment_method_id' => $order->payment_method_id, 'scan_from_block' => 100, 'address' => '0x'.str_repeat('9', 40)]);
        $request = CryptoPaymentRequest::factory()->create([...$monitor->paymentRequest->only(['network', 'asset', 'decimals', 'due_units', 'fee_units', 'net_units', 'quote_snapshot']),
            'merchant_id' => $this->merchant->id, 'order_id' => $order->id, 'payment_method_id' => $order->payment_method_id, 'crypto_address_id' => $address->id]);
        $otherMonitor = CryptoAddressMonitor::factory()->create(['merchant_id' => $this->merchant->id, 'crypto_payment_request_id' => $request->id, 'crypto_address_id' => $address->id]);
        $request->update(['current_monitor_id' => $otherMonitor->id]);
        $this->addHistoryTransfer($rpc, $otherMonitor, 100, str_repeat('e', 64), $request->due_units);
        $this->actingAs(User::factory()->create(['merchant_id' => $firstMerchantId, 'status' => true]));
        $this->fakeHistoryRpc($rpc);

        app(CryptoHistoryScanService::class)->scan('ethereum-sepolia');

        $this->assertSame(100, $request->fresh()->history_to_block);
        $this->assertDatabaseCount('crypto_webhook_receipts', 2);
        $this->assertSame([$firstMerchantId, $this->merchant->id], CryptoWebhookReceipt::withoutGlobalScopes()->orderBy('merchant_id')->pluck('merchant_id')->all());
        $this->assertCount(1, Http::recorded(fn (Request $req) => $req['method'] === 'debug_traceBlockByNumber'));
    }

    public function test_invalid_old_policy_does_not_block_other_requests_in_the_same_block_batch(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $address = CryptoAddress::factory()->create(['merchant_id' => $monitor->merchant_id, 'order_id' => $monitor->paymentRequest->order_id,
            'payment_method_id' => $monitor->paymentRequest->payment_method_id, 'scan_from_block' => 100, 'address' => '0x'.str_repeat('9', 40)]);
        $invalid = CryptoPaymentRequest::factory()->create(['merchant_id' => $monitor->merchant_id, 'order_id' => $address->order_id,
            'payment_method_id' => $address->payment_method_id, 'crypto_address_id' => $address->id, 'quote_snapshot' => []]);
        $invalidMonitor = CryptoAddressMonitor::factory()->create(['merchant_id' => $monitor->merchant_id,
            'crypto_payment_request_id' => $invalid->id, 'crypto_address_id' => $address->id]);
        $invalid->update(['current_monitor_id' => $invalidMonitor->id]);
        $this->fakeHistoryRpc($rpc);

        app(CryptoHistoryScanService::class)->scan('ethereum-sepolia');

        $this->assertSame(100, $monitor->paymentRequest->fresh()->history_to_block);
        $this->assertNull($invalid->fresh()->history_to_block);
        $this->assertSame('invalid_history_policy', $invalid->fresh()->history_error_code);
        $this->assertSame($monitor->crypto_payment_request_id, CryptoWebhookReceipt::query()->sole()->crypto_payment_request_id);
    }

    public function test_reorg_rewinds_to_common_ancestor_and_invalidates_orphaned_evidence(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $rpc['blocks'][100]['transactions'] = [];
        $this->addHistoryTransfer($rpc, $monitor, 101, str_repeat('e', 64), $monitor->paymentRequest->due_units);
        $this->fakeHistoryRpc($rpc);
        $scanner = app(CryptoHistoryScanService::class);
        foreach (range(1, 3) as $_) {
            $scanner->scan('ethereum-sepolia');
        }
        $receipt = CryptoWebhookReceipt::query()->sole();
        $this->assertTrue(app(CryptoReceiptVerificationService::class)->process($receipt->merchant_id, $receipt->id));
        $rpc['blocks'][101]['hash'] = '0x'.str_repeat('f', 64);
        $rpc['blocks'][101]['transactions'] = [];
        $rpc['blocks'][102]['hash'] = '0x'.str_repeat('e', 64);
        $rpc['blocks'][102]['parentHash'] = $rpc['blocks'][101]['hash'];
        $this->travel(61)->seconds();

        $scanner->scan('ethereum-sepolia');

        $this->assertSame(100, $monitor->paymentRequest->fresh()->history_to_block);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->orphaned_at);
        $this->assertNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertNull($monitor->fresh()->backfill_completed_at);
        $this->assertNull($monitor->fresh()->cold_scan_checked_at);
        $this->assertNull($monitor->paymentRequest->fresh()->credited_at);
        $scanner->scan('ethereum-sepolia');
        $this->assertSame(101, $monitor->paymentRequest->fresh()->history_to_block);
    }

    public function test_real_scan_proof_allows_cancellation_and_later_payment_is_still_found_on_original_request(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $rpc['blocks'][100]['transactions'] = [];
        $monitor->update(['status' => 'active', 'subscription_id' => 'alert-1', 'activated_at' => now(), 'expires_at' => now()->addMinutes(15)]);
        $rpc['subscription'] = $this->alertData($monitor);
        $this->travel(16)->minutes();
        $rpc['blocks'][102]['timestamp'] = '0x'.dechex(now()->getTimestamp());
        $this->fakeHistoryRpc($rpc);
        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia'])->assertSuccessful();

        $this->assertTrue(app(CryptoAddressMonitorService::class)->synchronize($monitor->merchant_id, $monitor->id));
        $this->assertSame('cold', $monitor->fresh()->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
        $this->travel(61)->seconds();
        $rpc['head'] = 105;
        $rpc['finalized'] = 105;
        foreach ([103, 104, 105] as $height) {
            $rpc['blocks'][$height]['timestamp'] = '0x'.dechex(now()->getTimestamp());
        }
        $this->addHistoryTransfer($rpc, $monitor, 103, str_repeat('e', 64), $monitor->paymentRequest->due_units);
        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia'])->assertSuccessful();
        $this->artisan('crypto:verify-receipts')->assertSuccessful();

        $receipt = CryptoWebhookReceipt::query()->sole();
        $this->assertSame($monitor->crypto_payment_request_id, $receipt->crypto_payment_request_id);
        $this->assertSame('review', $receipt->status);
        $this->assertSame('review', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertDatabaseCount('crypto_addresses', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_stale_node_head_cannot_refresh_history_health_or_advance_coverage(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->travel(301)->seconds();
        $rpc['blocks'][102]['timestamp'] = $rpc['blocks'][100]['timestamp'];
        $this->fakeHistoryRpc($rpc);

        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia'])->assertFailed();

        $this->assertNull($monitor->paymentRequest->fresh()->history_to_block);
        $this->assertNull($monitor->fresh()->cold_scan_checked_at);
        $this->assertSame('history_read_failed', $monitor->paymentRequest->fresh()->history_error_code);
        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    public function test_command_limit_resumes_next_block_and_disabled_or_locked_scans_do_no_http(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->fakeHistoryRpc($rpc);
        config(['services.tatum.history_enabled' => false]);
        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia'])->assertSuccessful();
        Http::assertNothingSent();
        config(['services.tatum.history_enabled' => true]);
        $lock = Cache::lock('crypto:history:ethereum-sepolia', 600);
        $lock->get();
        try {
            $this->assertFalse(app(CryptoHistoryScanService::class)->scan('ethereum-sepolia'));
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia', '--limit' => 1])->assertSuccessful();
        $this->assertSame(100, $monitor->paymentRequest->fresh()->history_to_block);
        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia', '--limit' => 2])->assertSuccessful();
        $this->assertSame(102, $monitor->paymentRequest->fresh()->history_to_block);
        $this->artisan('crypto:scan-history', ['network' => 'invalid'])->assertExitCode(2);
        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia', '--limit' => 201])->assertExitCode(2);
    }
}
