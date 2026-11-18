<?php

namespace Tests\Feature;

use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Services\CryptoHistoryScanService;
use App\Support\CryptoCheckoutGuard;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCryptoHistory;
use Tests\TestCase;

class CryptoHistoryScanTest extends TestCase
{
    use CreatesCryptoHistory;
    use RefreshDatabase;

    public function test_targeted_history_completes_native_and_internal_pages_without_walking_blocks(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $monitor->update(['status' => 'cold', 'cancelled_at' => now()]);
        $this->fakeHistoryRpc($rpc);
        $request = $monitor->paymentRequest;
        $scanner = app(CryptoHistoryScanService::class);
        $scanner->scan($request->network, $request->merchant_id, $request->id);
        $this->assertNull($request->fresh()->history_checked_at);
        $this->assertSame(1, $request->fresh()->history_query_state['stage']);
        $scanner->scan($request->network, $request->merchant_id, $request->id);
        $this->assertTrue(CryptoCheckoutGuard::covered($request->fresh()));
        $this->assertSame(102, $request->fresh()->history_to_block);
        $this->assertTrue($request->fresh()->history_to_time->equalTo(now()->addSeconds(2)));
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);
        $this->assertNull($request->fresh()->history_next_attempt_at);
        $this->assertNull($monitor->fresh()->cold_scan_checked_at);
        Http::assertNotSent(fn ($r): bool => in_array($r->data()['method'] ?? null, ['eth_getLogs', 'debug_traceBlockByNumber'], true)
            || (($r->data()['method'] ?? null) === 'eth_getBlockByNumber' && ($r['params'][1] ?? null) === true));
    }

    public function test_failed_internal_page_resumes_fixed_anchor_and_does_not_accept_partial_history(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->fakeHistoryRpc($rpc);
        $request = $monitor->paymentRequest;
        $scanner = app(CryptoHistoryScanService::class);
        $scanner->scan($request->network, $request->merchant_id, $request->id);
        $state = $request->fresh()->history_query_state;
        $rpc['fail_address_history'] = true;
        try {
            $scanner->scan($request->network, $request->merchant_id, $request->id);
            $this->fail('Incomplete history was accepted.');
        } catch (DomainException) {
            $this->assertNull($request->fresh()->history_checked_at);
            $this->assertSame($state, $request->fresh()->history_query_state);
            $this->assertFalse(CryptoCheckoutGuard::covered($request->fresh()));
        }
        unset($rpc['fail_address_history']);
        $rpc['head'] = 105;
        $scanner->scan($request->network, $request->merchant_id, $request->id);
        $this->assertSame(102, $request->fresh()->history_to_block);
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);
        $this->assertNull($request->fresh()->history_error_code);
    }

    public function test_full_page_uses_offset_before_completing_the_round(): void
    {
        [$monitor, $rpc] = $this->historyFixture('USDT');
        foreach (range(1, 50) as $index) {
            $this->addHistoryTransfer($rpc, $monitor, 100, str_pad(dechex($index), 64, '0', STR_PAD_LEFT), '1');
        }
        $this->fakeHistoryRpc($rpc);
        $request = $monitor->paymentRequest;
        $scanner = app(CryptoHistoryScanService::class);
        $scanner->scan($request->network, $request->merchant_id, $request->id);
        $this->assertSame(50, $request->fresh()->history_query_state['cursor']);
        $this->assertNull($request->fresh()->history_checked_at);
        $this->assertDatabaseCount('crypto_webhook_receipts', 50);
        $scanner->scan($request->network, $request->merchant_id, $request->id);
        $this->assertDatabaseCount('crypto_webhook_receipts', 51);
        $this->assertTrue(CryptoCheckoutGuard::covered($request->fresh()));
    }

    public function test_chain_anchor_change_mid_query_restarts_and_known_transactions_are_reverified(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->fakeHistoryRpc($rpc);
        $request = $monitor->paymentRequest;
        $this->query($request);
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->assertNotNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $rpc['blocks'][100]['transactions'] = [];
        $rpc['blocks'][100]['hash'] = '0x'.str_repeat('e', 64);
        $rpc['blocks'][102]['hash'] = '0x'.str_repeat('f', 64);
        $scanner = app(CryptoHistoryScanService::class);
        $scanner->scan($request->network, $request->merchant_id, $request->id);
        $this->assertDatabaseCount('crypto_webhook_receipts', 2);
        $rpc['blocks'][102]['hash'] = '0x'.str_repeat('d', 64);
        try {
            $scanner->scan($request->network, $request->merchant_id, $request->id);
            $this->fail('Changed anchor accepted.');
        } catch (DomainException) {
            $this->assertNull($request->fresh()->history_query_state);
            $this->assertNull($request->fresh()->history_checked_at);
        }
        $this->query($request);
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->assertNotNull(CryptoChainEvent::query()->sole()->orphaned_at);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_later_query_rechecks_the_full_address_range_for_delayed_index_entries(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $tx = $rpc['blocks'][100]['transactions'];
        $rpc['blocks'][100]['transactions'] = [];
        $this->fakeHistoryRpc($rpc);
        $this->query($monitor->paymentRequest);
        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
        $rpc['blocks'][100]['transactions'] = $tx;
        $this->travel(61)->seconds();
        $this->query($monitor->paymentRequest);
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);
        $this->assertSame(str_repeat('c', 64), CryptoWebhookReceipt::query()->sole()->transaction_hash);
    }

    public function test_stale_head_fails_without_healthy_proof(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->travel(301)->seconds();
        $rpc['blocks'][102]['timestamp'] = $rpc['blocks'][100]['timestamp'];
        $this->fakeHistoryRpc($rpc);
        $request = $monitor->paymentRequest;
        $this->artisan('crypto:scan-history', ['network' => $request->network, '--merchant' => $request->merchant_id, '--request' => $request->id])->assertFailed();
        $this->assertNull($request->fresh()->history_checked_at);
        $this->assertSame('address_history_failed', $request->fresh()->history_error_code);
        Http::assertNotSent(fn ($r): bool => str_contains($r->url(), '/v4/data/'));
    }

    public function test_explicit_merchant_scope_is_required_and_never_discovers_another_tenants_address(): void
    {
        [$monitor] = $this->historyFixture();
        Http::preventStrayRequests();
        $request = $monitor->paymentRequest;
        $this->artisan('crypto:scan-history', ['network' => $request->network])->assertExitCode(2);
        $this->artisan('crypto:scan-history', ['network' => $request->network, '--merchant' => $request->merchant_id + 1, '--request' => $request->id])->assertFailed();
        Http::assertNothingSent();
        $this->assertNull($request->fresh()->history_error_code);
        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    public function test_command_resumes_pages_and_disabled_or_locked_queries_do_no_http(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $request = $monitor->paymentRequest;
        $args = ['network' => $request->network, '--merchant' => $request->merchant_id, '--request' => $request->id];
        Http::preventStrayRequests();
        $this->setCryptoSettings(['crypto.history_enabled' => false]);
        $this->artisan('crypto:scan-history', $args)->assertSuccessful();
        $this->setCryptoSettings(['crypto.history_enabled' => true]);
        $lock = Cache::lock('crypto:address-history:'.$request->id, 120);
        $lock->get();
        try {
            $this->assertFalse(app(CryptoHistoryScanService::class)->scan($request->network, $request->merchant_id, $request->id));
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
        $this->fakeHistoryRpc($rpc);
        $this->artisan('crypto:scan-history', [...$args, '--limit' => 1])->assertSuccessful();
        $this->assertNotNull($request->fresh()->history_query_state);
        $this->artisan('crypto:scan-history', [...$args, '--limit' => 1])->assertSuccessful();
        $this->assertNull($request->fresh()->history_query_state);
        $this->artisan('crypto:scan-history', [...$args, '--limit' => 201])->assertExitCode(2);
    }

    private function query(CryptoPaymentRequest $request): void
    {
        $this->artisan('crypto:scan-history', ['network' => $request->network, '--merchant' => $request->merchant_id, '--request' => $request->id])->assertSuccessful();
    }
}
