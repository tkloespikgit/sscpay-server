<?php

namespace Tests\Feature;

use App\Models\CryptoChainEvent;
use App\Models\CryptoWebhookReceipt;
use App\Models\User;
use App\Services\CryptoQuoteService;
use App\Services\CryptoReceiptVerificationService;
use App\Services\TatumEvmRpcClient;
use App\Services\TatumWebhookService;
use App\Support\CryptoAmount;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class CryptoEvmVerificationTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    private const TX = '0xcccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private const BLOCK = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const CONTRACT = '0x1111111111111111111111111111111111111111';

    private const TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function test_native_transfer_uses_exact_rpc_units_and_never_trusts_webhook_amount_or_marks_order_paid(): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertTrue($this->process($receipt));

        $event = CryptoChainEvent::query()->sole();
        $this->assertSame('9007199254740993', $event->amount_units);
        $this->assertSame('native:0', $event->event_position);
        $this->assertSame(substr(self::TX, 2), $event->transaction_hash);
        $this->assertSame(self::BLOCK, $event->block_hash);
        $this->assertSame(100, $event->block_height);
        $this->assertNotNull($event->verified_at);
        $this->assertNotNull($event->confirmed_at);
        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertSame('preparing', $monitor->paymentRequest->fresh()->status);
        $this->assertSame('pending', $monitor->paymentRequest->order->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ethereum-sepolia.gateway.tatum.io/'
            && $request->hasHeader('x-api-key', 'test-testnet-credential'));
    }

    public function test_multiple_callbacks_for_the_same_transaction_have_one_economic_event(): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt();
        $duplicate = app(TatumWebhookService::class)->receive($monitor,
            ['subscriptionType' => 'ADDRESS_EVENT', 'txId' => self::TX, 'address' => $monitor->address->address], 'different signed body', 'raw');
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertTrue($this->process($duplicate));

        $this->assertDatabaseCount('crypto_webhook_receipts', 2);
        $this->assertDatabaseCount('crypto_chain_events', 1);
        $this->assertSame('verified', $duplicate->fresh()->status);
    }

    #[DataProvider('unconfirmedHeads')]
    public function test_confirmation_requires_both_frozen_depth_and_finalized_coverage(string $latest, string $finalized): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $rpc['latest']['number'] = $latest;
        $rpc['finalized']['number'] = $finalized;
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));

        $this->assertSame('confirming', $receipt->fresh()->status);
        $this->assertNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $this->assertNotNull($receipt->fresh()->next_attempt_at);
        $this->assertTrue($this->process($receipt));
        Http::assertSentCount(7);
        $this->travel(61)->seconds();
        $rpc['latest']['number'] = '0x68';
        $rpc['finalized']['number'] = '0x64';
        $this->assertTrue($this->process($receipt));
        $this->assertNotNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $this->assertSame('verified', $receipt->fresh()->status);
    }

    public static function unconfirmedHeads(): array
    {
        return ['depth insufficient' => ['0x65', '0x64'], 'not finalized' => ['0x68', '0x63']];
    }

    public function test_erc20_uses_only_frozen_contract_and_incoming_transfer_logs_with_uint256_precision(): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt('USDT');
        $rpc['receipt']['logs'] = [$this->tokenLog('0x3'), $this->tokenLog('0x4')];
        $rpc['receipt']['logs'][1]['data'] = '0x'.str_repeat('f', 64);
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));

        $this->assertSame(['9007199254740993', CryptoAmount::MAX_UNITS], CryptoChainEvent::query()->orderBy('id')->pluck('amount_units')->all());
        $this->assertSame(['log:3', 'log:4'], CryptoChainEvent::query()->orderBy('id')->pluck('event_position')->all());
        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertSame('USDT', CryptoChainEvent::query()->first()->asset);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    #[DataProvider('nonIncomeTokens')]
    public function test_other_contract_outgoing_self_and_zero_token_logs_are_not_income(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt('USDT');
        $log = $this->tokenLog();
        if ($case === 'contract') {
            $log['address'] = '0x'.str_repeat('2', 40);
        } elseif ($case === 'outgoing') {
            $log['topics'][2] = '0x'.str_repeat('0', 24).str_repeat('d', 40);
        } elseif ($case === 'self') {
            $log['topics'][1] = $log['topics'][2];
        } else {
            $log['data'] = '0x'.str_repeat('0', 64);
        }
        $rpc['receipt']['logs'] = [$log];
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));

        $this->assertSame('ignored', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public static function nonIncomeTokens(): array
    {
        return array_combine(['contract', 'outgoing', 'self', 'zero'], [['contract'], ['outgoing'], ['self'], ['zero']]);
    }

    #[DataProvider('badTokenLogs')]
    public function test_malformed_or_removed_token_log_is_retained_for_retry(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt('USDT');
        $log = $this->tokenLog();
        if ($case === 'removed') {
            $log['removed'] = true;
        } elseif ($case === 'word') {
            $log['data'] = '0x1';
        } elseif ($case === 'topic padding') {
            $log['topics'][2] = '0x1'.substr($log['topics'][2], 3);
        } elseif ($case === 'block') {
            $log['blockHash'] = '0x'.str_repeat('e', 64);
        } else {
            $log['topics'][] = '0x'.str_repeat('0', 64);
        }
        $rpc['receipt']['logs'] = [$log];
        if ($case === 'duplicate') {
            $rpc['receipt']['logs'] = [$this->tokenLog(), $this->tokenLog()];
        }
        $this->fakeRpc($rpc);

        $this->assertFalse($this->process($receipt));
        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public static function badTokenLogs(): array
    {
        return array_combine(['removed', 'word', 'topic padding', 'block', 'topics', 'duplicate'], [['removed'], ['word'], ['topic padding'], ['block'], ['topics'], ['duplicate']]);
    }

    #[DataProvider('nonIncomeNative')]
    public function test_failed_outgoing_and_zero_native_transfers_are_not_income(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        if ($case === 'failed') {
            $rpc['receipt']['status'] = '0x0';
        } elseif ($case === 'outgoing') {
            $rpc['tx']['to'] = '0x'.str_repeat('d', 40);
        } elseif ($case === 'self') {
            $rpc['tx']['from'] = $rpc['tx']['to'];
        } elseif ($case === 'creation') {
            $rpc['tx']['to'] = null;
        } else {
            $rpc['tx']['value'] = '0x0';
        }
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('ignored', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public static function nonIncomeNative(): array
    {
        return array_combine(['failed', 'outgoing', 'self', 'creation', 'zero'], [['failed'], ['outgoing'], ['self'], ['creation'], ['zero']]);
    }

    #[DataProvider('invalidRpcFacts')]
    public function test_inconsistent_rpc_facts_cannot_create_events(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        match ($case) {
            'chain' => $rpc['chain'] = '0x1',
            'receipt hash' => $rpc['receipt']['transactionHash'] = '0x'.str_repeat('e', 64),
            'block membership' => $rpc['block']['transactions'] = [],
            'tx block' => $rpc['tx']['blockHash'] = '0x'.str_repeat('e', 64),
            'float value' => $rpc['tx']['value'] = 1.5,
            'noncanonical quantity' => $rpc['tx']['value'] = '0x01',
            'future block' => $rpc['block']['timestamp'] = '0x'.dechex(now()->addHour()->getTimestamp()),
            'bad finalized' => $rpc['finalized']['number'] = '0x69',
            'no finalized' => $rpc['finalized'] = null,
            'tx chain' => $rpc['tx']['chainId'] = '0x1',
        };
        $this->fakeRpc($rpc);

        $this->assertFalse($this->process($receipt));
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public static function invalidRpcFacts(): array
    {
        $cases = ['chain', 'receipt hash', 'block membership', 'tx block', 'float value', 'noncanonical quantity', 'future block', 'bad finalized', 'no finalized', 'tx chain'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    public function test_missing_mempool_receipt_is_retryable_and_does_not_invent_an_event(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $rpc['receipt'] = null;
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertNull($receipt->fresh()->processed_at);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_canonical_reorg_revokes_confirmation_and_later_reinclusion_restores_the_same_event(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $rpc['block']['hash'] = '0x'.str_repeat('e', 64);

        $this->assertTrue($this->process($receipt));

        $event = CryptoChainEvent::query()->sole();
        $this->assertNotNull($event->orphaned_at);
        $this->assertNull($event->confirmed_at);
        $this->assertSame('canonical_block_changed', $receipt->fresh()->last_error_code);
        $this->travel(61)->seconds();
        $rpc['receipt']['blockHash'] = $rpc['block']['hash'];
        $rpc['tx']['blockHash'] = $rpc['block']['hash'];
        $rpc['finalized']['hash'] = $rpc['block']['hash'];
        $this->assertTrue($this->process($receipt));
        $this->assertDatabaseCount('crypto_chain_events', 1);
        $this->assertNull($event->fresh()->orphaned_at);
        $this->assertNotNull($event->fresh()->confirmed_at);
    }

    public function test_inconsistent_amount_of_existing_event_preserves_original_value_and_requires_review(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $rpc['tx']['value'] = '0x10';

        $this->assertTrue($this->process($receipt));

        $this->assertSame('9007199254740993', CryptoChainEvent::query()->sole()->amount_units);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->orphaned_at);
        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_reorg_between_initial_block_read_and_finality_check_cannot_confirm_a_transfer(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $rpc['block_recheck'] = [...$rpc['block'], 'hash' => '0x'.str_repeat('e', 64)];
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));

        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('canonical_block_changed', $receipt->fresh()->last_error_code);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_recheck_failure_reopens_verified_receipt_for_retry_without_rewriting_evidence(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $event = CryptoChainEvent::query()->sole();
        $rpc['chain'] = null;

        $this->assertFalse($this->process($receipt));

        $this->assertSame('confirming', $receipt->fresh()->status);
        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
        $this->assertNotNull($receipt->fresh()->next_attempt_at);
        $this->assertSame($event->amount_units, $event->fresh()->amount_units);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_changed_amount_for_same_event_requires_special_review_not_late_payment_acceptance(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $event = CryptoChainEvent::query()->sole();
        $rpc['tx']['value'] = '0x1';

        $this->assertTrue($this->process($receipt));
        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertSame('economic_event_changed', $receipt->fresh()->last_error_code);
        $this->assertNotNull($event->fresh()->orphaned_at);
        $this->assertSame($event->amount_units, $event->fresh()->amount_units);
    }

    public function test_reorg_after_a_prior_credit_flags_review_without_reversing_balances(): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $monitor->paymentRequest->update(['status' => 'credited', 'credited_at' => now()]);
        $rpc['block']['hash'] = '0x'.str_repeat('e', 64);

        $this->assertTrue($this->process($receipt));

        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->orphaned_at);
        $this->assertNotNull($monitor->paymentRequest->fresh()->credited_at);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    public function test_expired_request_keeps_late_income_evidence_for_review(): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt();
        $this->travel(16)->minutes();
        $rpc['block']['timestamp'] = '0x'.dechex(now()->getTimestamp());
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));

        $this->assertSame('review', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_frozen_policy_and_contract_are_not_replaced_by_current_channel_configuration(): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt('USDT');
        $monitor->paymentRequest->paymentMethod->update(['config' => ['contract_address' => '0x'.str_repeat('9', 40)]]);
        config(['services.tatum.evm_min_confirmations.ethereum-sepolia' => '1000']);
        $rpc['receipt']['logs'] = [$this->tokenLog()];
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('verified', $receipt->fresh()->status);
    }

    public function test_missing_frozen_policy_fails_closed_before_any_rpc_call(): void
    {
        [, $receipt] = $this->arrangeReceipt('ETH', null);
        Http::preventStrayRequests();

        $this->assertFalse($this->process($receipt));
        Http::assertNothingSent();
        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
        $this->assertSame(1, $receipt->fresh()->verification_attempts);
    }

    public function test_cross_merchant_lookup_is_rejected_before_rpc(): void
    {
        [, $receipt] = $this->arrangeReceipt();
        Http::preventStrayRequests();
        $this->expectException(ModelNotFoundException::class);

        app(CryptoReceiptVerificationService::class)->process($receipt->merchant_id + 1, $receipt->id);
    }

    public function test_background_verification_does_not_inherit_an_unrelated_browser_users_scope(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->actingAs(User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]));
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('verified', $receipt->fresh()->status);
    }

    public function test_shared_request_lock_prevents_overlapping_verifications(): void
    {
        [, $receipt] = $this->arrangeReceipt();
        Http::preventStrayRequests();
        $lock = Cache::lock('crypto:verify-request:'.$receipt->crypto_payment_request_id, 180);
        $lock->get();
        try {
            $this->assertFalse($this->process($receipt));
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_command_processes_due_receipts_and_respects_disabled_switch(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        config(['services.tatum.verification_enabled' => false]);
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        Http::assertNothingSent();
        config(['services.tatum.verification_enabled' => true]);

        $this->artisan('crypto:verify-receipts', ['--limit' => 0])->assertExitCode(2);
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        $this->assertSame('verified', $receipt->fresh()->status);
        $count = count(Http::recorded());
        $this->artisan('crypto:verify-receipts')->assertSuccessful();
        Http::assertSentCount($count);
    }

    public function test_rpc_failure_is_sanitized_backed_off_and_command_reports_failure(): void
    {
        [, $receipt] = $this->arrangeReceipt();
        Http::fake(['*' => Http::response(['private_key' => 'secret-must-not-appear'], 500)]);

        $this->artisan('crypto:verify-receipts')->assertFailed();

        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
        $this->assertNotNull($receipt->fresh()->next_attempt_at);
        $this->assertStringNotContainsString('secret-must-not-appear', $receipt->fresh()->toJson());
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_rpc_client_rejects_signing_methods_and_arbitrary_hosts(): void
    {
        Http::preventStrayRequests();
        $this->expectException(DomainException::class);

        app(TatumEvmRpcClient::class)->read('ethereum-sepolia', 'eth_sendRawTransaction', ['0x1234']);
    }

    private function arrangeReceipt(string $asset = 'ETH', ?string $minimum = '3'): array
    {
        $quote = app(CryptoQuoteService::class)->calculate('ethereum-sepolia:'.$asset, '100', 'USD', '1', $asset === 'ETH' ? '2500' : '1', '2', '1', '0');
        $quote['payment_config_snapshot'] = ['network' => 'ethereum-sepolia', 'asset' => $asset, 'contract_address' => $asset === 'USDT' ? self::CONTRACT : null];
        $quote['verification_policy'] = ['version' => 'evm-finalized-v1', 'minimum_confirmations' => $minimum];
        $monitor = $this->createCryptoMonitor(['asset' => $asset, 'decimals' => $asset === 'ETH' ? 18 : 6,
            'quote_snapshot' => $quote, 'due_units' => $quote['due_units'], 'fee_units' => $quote['fee_units'], 'net_units' => $quote['net_units']]);
        config(['services.tatum.verification_enabled' => true]);
        Http::preventStrayRequests();
        $receipt = app(TatumWebhookService::class)->receive($monitor, ['subscriptionType' => 'ADDRESS_EVENT', 'txId' => self::TX,
            'address' => $monitor->address->address, 'amount' => '9999999999999999999999999999'], 'signed test fixture', 'raw');
        $common = ['blockHash' => self::BLOCK, 'blockNumber' => '0x64', 'transactionIndex' => '0x0'];
        $rpc = [
            'chain' => '0xaa36a7',
            'receipt' => [...$common, 'transactionHash' => self::TX, 'status' => '0x1', 'logs' => []],
            'tx' => [...$common, 'hash' => self::TX, 'from' => '0x'.str_repeat('d', 40), 'to' => $monitor->address->address, 'value' => '0x20000000000001'],
            'block' => ['number' => '0x64', 'hash' => self::BLOCK, 'timestamp' => '0x'.dechex(now()->getTimestamp()), 'transactions' => [self::TX]],
            'latest' => ['number' => '0x68', 'hash' => '0x'.str_repeat('e', 64)],
            'finalized' => ['number' => '0x64', 'hash' => self::BLOCK],
        ];

        return [$monitor, $receipt, $rpc];
    }

    private function fakeRpc(array &$rpc): void
    {
        $testTransactionLevel = DB::transactionLevel();
        $blockReads = 0;
        Http::fake(function (Request $request) use (&$rpc, $testTransactionLevel, &$blockReads) {
            $this->assertSame('https://ethereum-sepolia.gateway.tatum.io/', $request->url());
            $this->assertSame($testTransactionLevel, DB::transactionLevel());
            $key = match ($request['method']) {
                'eth_chainId' => 'chain', 'eth_getTransactionReceipt' => 'receipt', 'eth_getTransactionByHash' => 'tx',
                'eth_getBlockByNumber' => match ($request['params'][0]) {
                    'latest' => 'latest', 'finalized' => 'finalized', default => 'block',
                },
                default => throw new DomainException('Unexpected test RPC method.'),
            };
            if ($key === 'block' && ++$blockReads > 1 && isset($rpc['block_recheck'])) {
                $key = 'block_recheck';
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $rpc[$key]]);
        });
    }

    private function tokenLog(string $index = '0x3'): array
    {
        return ['address' => self::CONTRACT, 'topics' => [self::TRANSFER, '0x'.str_repeat('0', 24).str_repeat('d', 40), '0x'.str_repeat('0', 24).str_repeat('a', 40)],
            'data' => '0x'.str_repeat('0', 50).'20000000000001', 'removed' => false, 'transactionHash' => self::TX,
            'blockHash' => self::BLOCK, 'blockNumber' => '0x64', 'transactionIndex' => '0x0', 'logIndex' => $index];
    }

    private function process(CryptoWebhookReceipt $receipt): bool
    {
        return app(CryptoReceiptVerificationService::class)->process($receipt->merchant_id, $receipt->id);
    }
}
