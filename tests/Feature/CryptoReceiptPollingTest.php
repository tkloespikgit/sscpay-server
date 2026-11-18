<?php

namespace Tests\Feature;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoWebhookReceipt;
use App\Services\CryptoHistoryScanService;
use App\Services\CryptoPaymentEvidenceService;
use App\Services\CryptoReceiptVerificationService;
use App\Services\CryptoSettlementService;
use App\Services\TatumWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCryptoHistory;
use Tests\TestCase;

class CryptoReceiptPollingTest extends TestCase
{
    use CreatesCryptoHistory;
    use RefreshDatabase;

    public function test_queued_deliveries_for_one_transaction_share_one_chain_observation(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $first = $this->receive($monitor, 'first signed body');
        $second = $this->receive($monitor, 'second signed body');
        $this->fakeHistoryRpc($rpc);

        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->artisan('crypto:verify-receipts')->assertSuccessful();

        $this->assertSame('verified', $first->fresh()->status);
        $this->assertSame('verified', $second->fresh()->status);
        $this->assertSame(1, $first->fresh()->verification_attempts);
        $this->assertSame(1, $second->fresh()->verification_attempts);
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r['method'] === 'eth_getTransactionReceipt'));
        $this->assertDatabaseCount('crypto_webhook_receipts', 2);
        $this->assertDatabaseCount('crypto_chain_events', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_pending_transactions_back_off_and_are_not_queried_before_the_next_attempt(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $rpc['receipts'] = [];
        $this->fakeHistoryRpc($rpc);
        $service = app(CryptoReceiptVerificationService::class);

        $this->assertTrue($service->process($monitor->merchant_id, $receipt->id));
        $this->assertSame('2026-11-11 12:01:00', $receipt->fresh()->next_attempt_at->toDateTimeString());
        $this->assertTrue($service->process($monitor->merchant_id, $receipt->id));
        $this->travel(60)->seconds();
        $this->assertTrue($service->process($monitor->merchant_id, $receipt->id));

        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('2026-11-11 12:03:00', $receipt->fresh()->next_attempt_at->toDateTimeString());
        $this->assertCount(2, Http::recorded(fn (Request $r): bool => $r['method'] === 'eth_getTransactionReceipt'));
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_exhausted_attempt_budget_stops_rpc_and_keeps_the_order_unpaid_for_review(): void
    {
        [$monitor] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $receipt->update(['verification_attempts' => 48]);
        Http::preventStrayRequests();

        $this->assertTrue(app(CryptoReceiptVerificationService::class)->process($monitor->merchant_id, $receipt->id));
        $this->artisan('crypto:verify-receipts')->assertSuccessful();

        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertSame('verification_exhausted', $receipt->fresh()->last_error_code);
        $this->assertNull($receipt->fresh()->next_attempt_at);
        $this->assertSame('review', $monitor->paymentRequest->fresh()->status);
        $this->assertSame('review', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertSame('pending', $monitor->paymentRequest->order->fresh()->status);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        Http::assertNothingSent();
    }

    public function test_last_failed_attempt_moves_all_queued_deliveries_to_review_without_extra_rpc(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $second = $this->receive($monitor, 'second signed body');
        $receipt->update(['verification_attempts' => 47]);
        $rpc['fail_method'] = 'eth_chainId';
        $this->fakeHistoryRpc($rpc);

        $this->artisan('crypto:verify-receipts')->assertFailed();

        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertSame(48, $receipt->fresh()->verification_attempts);
        $this->assertSame('verification_exhausted', $second->fresh()->last_error_code);
        $this->assertSame('review', $second->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
        Http::assertSentCount(1);
    }

    public function test_success_on_the_last_allowed_attempt_keeps_the_verified_result(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $receipt->update(['verification_attempts' => 47]);
        $this->fakeHistoryRpc($rpc);

        $this->assertTrue(app(CryptoReceiptVerificationService::class)->process($monitor->merchant_id, $receipt->id));

        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertSame(48, $receipt->fresh()->verification_attempts);
        $this->assertNull($receipt->fresh()->last_error_code);
        $this->assertNull($monitor->paymentRequest->fresh()->review_reason);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_unfinished_verification_stops_after_twenty_four_hours(): void
    {
        [$monitor] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $receipt->update(['verification_attempts' => 1]);
        $this->travel(24)->hours();
        Http::preventStrayRequests();

        $this->assertTrue(app(CryptoReceiptVerificationService::class)->process($monitor->merchant_id, $receipt->id));

        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertSame('verification_exhausted', $receipt->fresh()->last_error_code);
        $this->assertNull($receipt->fresh()->next_attempt_at);
        Http::assertNothingSent();
    }

    public function test_backoff_never_schedules_an_attempt_beyond_the_review_deadline(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $receipt->update(['verification_attempts' => 7]);
        $rpc['receipts'] = [];
        $this->fakeHistoryRpc($rpc);
        $this->travel(1439)->minutes();
        $service = app(CryptoReceiptVerificationService::class);

        $this->assertTrue($service->process($monitor->merchant_id, $receipt->id));
        $this->assertSame('2026-11-12 12:00:00', $receipt->fresh()->next_attempt_at->toDateTimeString());
        $this->travel(60)->seconds();
        $this->assertTrue($service->process($monitor->merchant_id, $receipt->id));

        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r['method'] === 'eth_getTransactionReceipt'));
    }

    public function test_an_old_unprocessed_delivery_gets_one_observation_before_the_age_limit_is_applied(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $this->travel(25)->hours();
        $this->fakeHistoryRpc($rpc);

        $this->assertTrue(app(CryptoReceiptVerificationService::class)->process($monitor->merchant_id, $receipt->id));

        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r['method'] === 'eth_getTransactionReceipt'));
    }

    public function test_a_delivery_arriving_during_an_observation_is_not_silently_completed_by_it(): void
    {
        [$monitor] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $later = null;
        Http::preventStrayRequests();
        Http::fake(['https://ethereum-sepolia.gateway.tatum.io/' => function (Request $request) use ($monitor, &$later) {
            if ($request['method'] === 'eth_chainId') {
                $later = $this->receive($monitor, 'delivery during RPC');

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0xaa36a7']);
            }
            $this->assertSame('eth_getTransactionReceipt', $request['method']);

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]);
        }]);

        $this->assertTrue(app(CryptoReceiptVerificationService::class)->process($monitor->merchant_id, $receipt->id));

        $this->assertSame(1, $receipt->fresh()->verification_attempts);
        $this->assertSame(0, $later->fresh()->verification_attempts);
        $this->assertSame('pending', $later->fresh()->status);
        $this->assertNull($later->fresh()->last_checked_at);
        Http::assertSentCount(2);
    }

    public function test_fresh_address_query_recovers_exhausted_evidence_for_human_reconciliation(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $receipt->update(['verification_attempts' => 48]);
        $service = app(CryptoReceiptVerificationService::class);
        $this->assertTrue($service->process($monitor->merchant_id, $receipt->id));
        $this->fakeHistoryRpc($rpc);
        $history = app(CryptoHistoryScanService::class);
        $this->assertTrue($history->scan($monitor->network, $monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertTrue($history->scan($monitor->network, $monitor->merchant_id, $monitor->crypto_payment_request_id));
        $fresh = CryptoWebhookReceipt::query()->where('signature_mode', 'address_history')->sole();

        $this->assertTrue($service->process($monitor->merchant_id, $fresh->id));

        $this->assertSame('verified', $fresh->fresh()->status);
        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertSame(hash('sha256', 'first signed body'), $receipt->fresh()->payload_hash);
        $this->assertTrue(app(CryptoPaymentEvidenceService::class)->capture($monitor->paymentRequest->order)['healthy']);
        $this->assertSame('review', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_later_callback_observes_the_chain_again_and_retains_reorg_detection(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = $this->receive($monitor);
        $this->fakeHistoryRpc($rpc);
        $service = app(CryptoReceiptVerificationService::class);
        $this->assertTrue($service->process($monitor->merchant_id, $receipt->id));
        $later = $this->receive($monitor, 'later signed block update');
        $rpc['blocks'][100]['hash'] = '0x'.str_repeat('f', 64);

        $this->assertTrue($service->process($monitor->merchant_id, $later->id));

        $this->assertCount(2, Http::recorded(fn (Request $r): bool => $r['method'] === 'eth_getTransactionReceipt'));
        $this->assertSame('pending', $later->fresh()->status);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->orphaned_at);
        $this->assertNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    private function receive(CryptoAddressMonitor $monitor, string $raw = 'first signed body'): CryptoWebhookReceipt
    {
        return app(TatumWebhookService::class)->receive($monitor, [
            'subscriptionType' => 'ADDRESS_EVENT', 'txId' => str_repeat('c', 64), 'address' => $monitor->address->address,
        ], $raw, 'raw');
    }
}
