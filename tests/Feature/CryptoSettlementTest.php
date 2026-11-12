<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Jobs\SendOrderNotificationJob;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoChainEvent;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoWebhookReceipt;
use App\Models\OrderNotificationAttempt;
use App\Services\CryptoHistoryScanService;
use App\Services\CryptoQuoteService;
use App\Services\CryptoSettlementService;
use App\Services\OrderPaymentStatusService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoHistory;
use Tests\TestCase;

class CryptoSettlementTest extends TestCase
{
    use CreatesCryptoHistory;
    use RefreshDatabase;

    #[DataProvider('evmPayments')]
    public function test_missing_webhook_is_backfilled_verified_and_credited_once_with_order_and_notification(string $asset, bool $internal): void
    {
        [$monitor, $rpc] = $this->historyFixture($asset);
        if ($internal) {
            $rpc['blocks'][100]['transactions'] = [];
            $this->addHistoryTransfer($rpc, $monitor, 100, str_repeat('c', 64), $monitor->paymentRequest->due_units, true);
        }
        $monitor->update(['status' => 'cold', 'cancelled_at' => now()->subMinute()]);
        $order = $monitor->paymentRequest->order;
        $order->update(['notify_url' => 'https://shop.example.com/notify', 'settlement_mode' => 'immediate']);
        $fiat = $this->merchant->fresh()->only(['balance', 'frozen_balance', 'pending_settlement_balance', 'pending_withdrawal_balance']);
        Queue::fake();
        Event::fake([OrderStatusChanged::class]);
        $this->fakeHistoryRpc($rpc);
        $settlement = app(CryptoSettlementService::class);

        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia'])->assertSuccessful();
        $this->assertSame('waiting', $settlement->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->artisan('crypto:settle-payments')->assertSuccessful();
        $this->assertSame('credited', $settlement->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));

        $expectedNet = $asset === 'ETH' ? '40392000000000000' : '100980000';
        $this->assertSame($expectedNet, CryptoAssetBalance::query()->sole()->balance_units);
        $this->assertSame($expectedNet, CryptoLedgerEntry::query()->sole()->amount_units);
        $this->assertSame($monitor->paymentRequest->due_units, CryptoLedgerEntry::query()->sole()->gross_units);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertTrue($order->fresh()->paid_at->equalTo($monitor->paymentRequest->created_at));
        $this->assertSame(str_repeat('c', 64), $order->fresh()->transaction_id);
        $this->assertSame('credited', $monitor->paymentRequest->fresh()->status);
        $this->assertSame($fiat, $this->merchant->fresh()->only(array_keys($fiat)));
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('order_accounting_tasks', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        $this->assertDatabaseCount('order_notification_attempts', 1);
        $this->assertSame('paid', OrderNotificationAttempt::query()->sole()->request_payload['status']);
        Queue::assertPushed(SendOrderNotificationJob::class, 1);
        if ($internal) {
            $this->assertSame('trace:0', CryptoChainEvent::query()->sole()->event_position);
        }
    }

    public static function evmPayments(): array
    {
        return ['direct ETH' => ['ETH', false], 'internal ETH' => ['ETH', true], 'ERC20 USDT' => ['USDT', false]];
    }

    #[DataProvider('unsafeEvidence')]
    public function test_incomplete_or_changed_evidence_never_credits(string $case, string $outcome): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->fakeHistoryRpc($rpc);
        $this->completeDiscoveryAndVerification();
        $request = $monitor->paymentRequest->fresh();
        $event = CryptoChainEvent::query()->sole();
        switch ($case) {
            case 'no coverage': $request->update(['history_checked_at' => null]);
                break;
            case 'stale coverage': $this->travel(61)->seconds();
                break;
            case 'future coverage': $request->update(['history_checked_at' => now()->addSecond()]);
                break;
            case 'history error': $request->update(['history_error_code' => 'history_read_failed']);
                break;
            case 'cursor behind': $request->update(['history_to_block' => 99]);
                break;
            case 'unconfirmed': $event->update(['confirmed_at' => null]);
                break;
            case 'unverified': $event->update(['verified_at' => null]);
                break;
            case 'orphan': $event->update(['orphaned_at' => now(), 'confirmed_at' => null]);
                break;
            case 'late': $event->update(['block_time' => $request->expires_at->addSecond()]);
                break;
            case 'before quote': $event->update(['block_time' => $request->created_at->subSecond()]);
                break;
            case 'pending receipt': CryptoWebhookReceipt::query()->sole()->update(['status' => 'pending']);
                break;
            case 'receipt review': CryptoWebhookReceipt::query()->sole()->update(['status' => 'review']);
                break;
            case 'cancelled order': $request->order->update(['status' => 'cancelled']);
                break;
            case 'overpaid':
                CryptoChainEvent::factory()->create(['merchant_id' => $request->merchant_id, 'crypto_payment_request_id' => $request->id,
                    'amount_units' => '1', 'block_height' => 101, 'verified_at' => now(), 'confirmed_at' => now()]);
                break;
        }

        $this->assertSame($outcome, app(CryptoSettlementService::class)->settle($request->merchant_id, $request->id));

        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertDatabaseCount('crypto_asset_balances', 0);
        $this->assertDatabaseCount('order_notification_attempts', 0);
        $this->assertNull($request->order->fresh()->paid_at);
    }

    public static function unsafeEvidence(): array
    {
        $cases = [];
        foreach (['no coverage', 'stale coverage', 'future coverage', 'history error', 'cursor behind', 'unconfirmed', 'unverified', 'orphan', 'pending receipt'] as $case) {
            $cases[$case] = [$case, 'waiting'];
        }
        foreach (['late', 'before quote', 'receipt review', 'cancelled order', 'overpaid'] as $case) {
            $cases[$case] = [$case, 'review'];
        }

        return $cases;
    }

    public function test_split_payment_stays_partial_then_credits_exact_combined_amount_after_confirmations(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $rpc['blocks'][100]['transactions'] = [];
        $this->addHistoryTransfer($rpc, $monitor, 100, str_repeat('c', 64), '20400000000000000');
        $this->fakeHistoryRpc($rpc);
        Queue::fake();
        $this->completeDiscoveryAndVerification();
        $this->assertSame('partial', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->travel(61)->seconds();
        $rpc['head'] = 105;
        $rpc['finalized'] = 105;
        $this->addHistoryTransfer($rpc, $monitor, 103, str_repeat('e', 64), '20400000000000000');
        $this->completeDiscoveryAndVerification();

        $this->assertSame('credited', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertDatabaseCount('crypto_chain_events', 2);
        $this->assertSame('40800000000000000', CryptoLedgerEntry::query()->sole()->gross_units);
        $this->assertSame('40392000000000000', CryptoAssetBalance::query()->sole()->balance_units);
    }

    public function test_notification_record_failure_rolls_back_balance_ledger_request_and_order_then_can_retry(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $monitor->paymentRequest->order->update(['notify_url' => 'https://shop.example.com/notify']);
        Queue::fake();
        Event::fake([OrderStatusChanged::class]);
        $this->fakeHistoryRpc($rpc);
        $this->completeDiscoveryAndVerification();
        $failNotification = true;
        Event::listen('eloquent.creating: '.OrderNotificationAttempt::class, function () use (&$failNotification): void {
            if ($failNotification) {
                throw new DomainException('Notification storage unavailable.');
            }
        });
        try {
            app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id);
            $this->fail('Failed atomic payment was committed.');
        } catch (DomainException) {
            $this->assertDatabaseCount('crypto_ledger_entries', 0);
            $this->assertDatabaseCount('crypto_asset_balances', 0);
            $this->assertNull($monitor->paymentRequest->fresh()->credited_at);
            $this->assertSame('pending', $monitor->paymentRequest->order->fresh()->status);
            Queue::assertNothingPushed();
        } finally {
            $failNotification = false;
        }

        $this->assertSame('credited', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
        $this->assertDatabaseCount('order_notification_attempts', 1);
    }

    public function test_post_credit_reorg_is_flagged_without_deleting_ledger_or_double_crediting(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->fakeHistoryRpc($rpc);
        Queue::fake();
        $this->completeDiscoveryAndVerification();
        app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id);
        $rpc['blocks'][100]['hash'] = '0x'.str_repeat('f', 64);
        $rpc['blocks'][101]['hash'] = '0x'.str_repeat('e', 64);
        $rpc['blocks'][102]['hash'] = '0x'.str_repeat('d', 64);
        $this->travel(61)->seconds();

        app(CryptoHistoryScanService::class)->scan('ethereum-sepolia');

        $this->assertSame('review', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertSame('credited_chain_reorg', $monitor->paymentRequest->fresh()->review_reason);
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
        $this->assertSame('40392000000000000', CryptoAssetBalance::query()->sole()->balance_units);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->orphaned_at);
        $this->assertNotNull($monitor->paymentRequest->order->fresh()->paid_at);
    }

    public function test_on_time_transfer_can_finish_confirming_after_quote_expires(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $this->fakeHistoryRpc($rpc);
        Queue::fake();
        $this->completeDiscoveryAndVerification();
        $this->travel(16)->minutes();
        $rpc['head'] = 103;
        $rpc['blocks'][103]['timestamp'] = '0x'.dechex(now()->getTimestamp());
        app(CryptoHistoryScanService::class)->scan('ethereum-sepolia');

        $this->assertSame('credited', app(CryptoSettlementService::class)->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
    }

    public function test_cross_merchant_and_gateway_paths_cannot_bypass_crypto_accounting(): void
    {
        [$monitor] = $this->historyFixture();
        Http::preventStrayRequests();
        $order = $monitor->paymentRequest->order;
        app(OrderPaymentStatusService::class)->handle(['s_order_id' => $order->order_no, 'status' => 'paid']);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('order_gateway_status_events', 0);
        try {
            app(OrderPaymentStatusService::class)->queryAndApply($order);
            $this->fail('Crypto order queried a fiat gateway.');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
        $this->expectException(ModelNotFoundException::class);
        app(CryptoSettlementService::class)->settle($monitor->merchant_id + 1, $monitor->crypto_payment_request_id);
    }

    public function test_fiat_pending_order_poll_does_not_claim_or_query_blockchain_payments(): void
    {
        [$monitor] = $this->historyFixture();
        $this->travel(91)->minutes();
        Http::preventStrayRequests();

        $this->artisan('orders:sync-pending-status')
            ->expectsOutput('待支付订单状态查询结果：{"orders_checked":0,"orders_changed":0,"orders_failed":0}')
            ->assertSuccessful();

        $this->assertSame(0, $monitor->paymentRequest->order->fresh()->pending_status_query_count);
        Http::assertNothingSent();
    }

    public function test_disabled_settlement_and_invalid_batch_size_do_not_change_funds(): void
    {
        $this->historyFixture();
        config(['services.tatum.settlement_enabled' => false]);
        Http::preventStrayRequests();
        $this->artisan('crypto:settle-payments')->assertSuccessful();
        config(['services.tatum.settlement_enabled' => true]);
        $this->artisan('crypto:settle-payments', ['--limit' => 501])->assertExitCode(2);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('otherAssets')]
    public function test_confirmed_non_evm_payments_credit_their_own_network_asset_ledger(string $network, string $asset, string $address, string $price, string $net): void
    {
        $quote = app(CryptoQuoteService::class)->calculate($network.':'.$asset, '100', 'USD', '1', $price, '2', '1', '0');
        $monitor = $this->createCryptoMonitor(['network' => $network, 'asset' => $asset, 'decimals' => $quote['decimals'],
            'quote_snapshot' => $quote, 'due_units' => $quote['due_units'], 'fee_units' => $quote['fee_units'], 'net_units' => $quote['net_units']],
            ['network' => $network, 'address' => $address]);
        $request = $monitor->paymentRequest;
        $request->update(['history_to_block' => 100, 'history_to_hash' => str_repeat('b', 64), 'history_to_time' => now(), 'history_checked_at' => now()]);
        CryptoChainEvent::factory()->create(['merchant_id' => $request->merchant_id, 'crypto_payment_request_id' => $request->id,
            'network' => $network, 'asset' => $asset, 'amount_units' => $request->due_units, 'block_height' => 100,
            'block_time' => now(), 'verified_at' => now(), 'confirmed_at' => now()]);
        config(['services.tatum.verification_enabled' => true, 'services.tatum.settlement_enabled' => true,
            'services.tatum.history_max_head_age_seconds' => '300']);
        Queue::fake();
        Http::preventStrayRequests();

        $this->assertSame('credited', app(CryptoSettlementService::class)->settle($request->merchant_id, $request->id));

        $balance = CryptoAssetBalance::query()->sole();
        $this->assertSame($network, $balance->network);
        $this->assertSame($asset, $balance->asset);
        $this->assertSame($net, $balance->balance_units);
        $this->assertSame('paid', $request->order->fresh()->status);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Http::assertNothingSent();
    }

    public static function otherAssets(): array
    {
        return [
            ['bitcoin-testnet', 'BTC', 'mw5McnxAx9Qqf4uLjWrvV14WwzmfgiQ9PX', '50000', '201960'],
            ['litecoin-testnet', 'LTC', 'mw5McnxAx9Qqf4uLjWrvV14WwzmfgiQ9PX', '100', '100980000'],
            ['tron-shasta', 'USDT', 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL', '1', '100980000'],
        ];
    }

    public function test_committed_payment_recovers_pending_notification_without_new_credit_or_new_attempt(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $monitor->paymentRequest->order->update(['notify_url' => 'https://shop.example.com/notify']);
        Queue::fake();
        $this->fakeHistoryRpc($rpc);
        $this->completeDiscoveryAndVerification();
        $service = app(CryptoSettlementService::class);
        $service->settle($monitor->merchant_id, $monitor->crypto_payment_request_id);
        $attempt = OrderNotificationAttempt::query()->sole();
        $this->travel(301)->seconds();

        $this->assertSame('credited', $service->settle($monitor->merchant_id, $monitor->crypto_payment_request_id));
        $service->settle($monitor->merchant_id, $monitor->crypto_payment_request_id);

        Queue::assertPushed(SendOrderNotificationJob::class, 2);
        $this->assertDatabaseCount('order_notification_attempts', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
        $this->assertTrue($attempt->fresh()->scheduled_at->equalTo(now()));
    }

    private function completeDiscoveryAndVerification(): void
    {
        $this->artisan('crypto:scan-history', ['network' => 'ethereum-sepolia'])->assertSuccessful();
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
    }
}
