<?php

namespace Tests\Feature;

use App\Models\CryptoChainEvent;
use App\Models\CryptoWebhookReceipt;
use App\Services\CryptoQuoteService;
use App\Services\CryptoReceiptVerificationService;
use App\Services\TatumWebhookService;
use App\Support\CryptoTronAddress;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class CryptoTronVerificationTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    private const ADDRESS = 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL';

    private const ADDRESS_HEX = '418840e6c55b9ada326d211d818c34a994aeced808';

    private const CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const CONTRACT_HEX = 'a614f803b6fd780986a42c78ec9c7f77e6ded13c';

    private const TRANSFER = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    #[DataProvider('networks')]
    public function test_trc20_uses_canonical_solidified_receipt_and_exact_log_units(string $network): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt($network);
        $this->fakeRpc($rpc, $network);

        $this->assertTrue($this->process($receipt));
        $this->assertTrue($this->process($receipt));

        $event = CryptoChainEvent::query()->sole();
        $this->assertSame('9007199254740993', $event->amount_units);
        $this->assertSame('log:0', $event->event_position);
        $this->assertSame($network, $event->network);
        $this->assertNotNull($event->verified_at);
        $this->assertNotNull($event->confirmed_at);
        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertSame('preparing', $monitor->paymentRequest->fresh()->status);
        $this->assertSame('pending', $monitor->paymentRequest->order->fresh()->status);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/walletsolidity/gettransactioninfobyid'));
    }

    public static function networks(): array
    {
        return ['mainnet' => ['tron-mainnet'], 'shasta' => ['tron-shasta']];
    }

    #[DataProvider('confirmingCases')]
    public function test_fullnode_receipt_cannot_confirm_without_depth_and_solidified_evidence(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        if ($case === 'depth') {
            $rpc['head'] = $this->block(101, now()->getTimestampMs());
            $rpc['solidHead'] = $this->block(100, now()->getTimestampMs());
        } elseif ($case === 'solid height') {
            $rpc['solidHead'] = $this->block(99, now()->getTimestampMs());
        } else {
            $rpc['solidInfo'] = [];
        }
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('confirming', $receipt->fresh()->status);
        $this->assertNull(CryptoChainEvent::query()->sole()->confirmed_at);
        $this->assertNotNull($receipt->fresh()->next_attempt_at);
    }

    public static function confirmingCases(): array
    {
        return ['depth' => ['depth'], 'solid height' => ['solid height'], 'no solid receipt' => ['no solid receipt']];
    }

    public function test_multiple_token_logs_keep_their_transaction_local_indexes(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $rpc['info']['log'][] = [...$rpc['info']['log'][0], 'data' => str_repeat('0', 63).'2'];
        $rpc['solidInfo'] = $rpc['info'];
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame(['log:0', 'log:1'], CryptoChainEvent::query()->orderBy('id')->pluck('event_position')->all());
        $this->assertSame(['9007199254740993', '2'], CryptoChainEvent::query()->orderBy('id')->pluck('amount_units')->all());
    }

    #[DataProvider('nonIncomeCases')]
    public function test_failed_other_contract_outgoing_self_and_zero_logs_do_not_become_income(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        if ($case === 'failed') {
            $rpc['info']['receipt']['result'] = 'REVERT';
            $rpc['tx']['ret'][0]['contractRet'] = 'REVERT';
        } elseif ($case === 'contract') {
            $rpc['info']['log'][0]['address'] = str_repeat('1', 40);
        } elseif ($case === 'outgoing') {
            $rpc['info']['log'][0]['topics'][2] = str_repeat('0', 24).str_repeat('d', 40);
        } elseif ($case === 'self') {
            $rpc['info']['log'][0]['topics'][1] = $rpc['info']['log'][0]['topics'][2];
        } else {
            $rpc['info']['log'][0]['data'] = str_repeat('0', 64);
        }
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('ignored', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public static function nonIncomeCases(): array
    {
        $cases = ['failed', 'contract', 'outgoing', 'self', 'zero'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('badFacts')]
    public function test_inconsistent_tron_facts_fail_closed_and_retry(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        match ($case) {
            'genesis' => $rpc['genesis']['blockID'] = str_repeat('c', 64),
            'receipt ID' => $rpc['info']['id'] = str_repeat('c', 64),
            'raw body' => $rpc['tx']['raw_data_hex'] = '00',
            'membership' => $rpc['block']['transactions'] = [],
            'time' => $rpc['info']['blockTimeStamp'] = now()->getTimestampMs() + 1,
            'status mismatch' => $rpc['tx']['ret'][0]['contractRet'] = 'REVERT',
            'word' => $rpc['info']['log'][0]['data'] = '1',
            'padding' => $rpc['info']['log'][0]['topics'][2] = str_repeat('f', 64),
            'solid amount' => $rpc['solidInfo']['log'][0]['data'] = str_repeat('0', 63).'1',
            'height type' => $rpc['info']['blockNumber'] = 100.1,
        };
        $this->fakeRpc($rpc);

        $this->assertFalse($this->process($receipt));
        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public static function badFacts(): array
    {
        $cases = ['genesis', 'receipt ID', 'raw body', 'membership', 'time', 'status mismatch', 'word', 'padding', 'solid amount', 'height type'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    public function test_unavailable_receipt_is_pending_rather_than_failed_or_paid(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $rpc['info'] = [];
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_native_trx_fee_funding_with_no_token_logs_is_not_usdt_income(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $rpc['info']['receipt'] = ['net_usage' => 100];
        $rpc['info']['log'] = [];
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('ignored', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_changed_solidified_block_revokes_the_previous_confirmation(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $rpc['solidBlock']['blockID'] = sprintf('%016x', 100).str_repeat('e', 48);

        $this->assertTrue($this->process($receipt));

        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertNotNull(CryptoChainEvent::query()->sole()->orphaned_at);
        $this->assertNull(CryptoChainEvent::query()->sole()->confirmed_at);
    }

    public function test_base58check_conversion_matches_official_address_example(): void
    {
        $this->assertSame(self::ADDRESS_HEX, CryptoTronAddress::hex(self::ADDRESS));
        $this->assertSame('41'.self::CONTRACT_HEX, CryptoTronAddress::hex(self::CONTRACT));
        $this->expectException(DomainException::class);
        CryptoTronAddress::hex(substr(self::ADDRESS, 0, -1).'M');
    }

    private function arrangeReceipt(string $network = 'tron-shasta'): array
    {
        $quote = app(CryptoQuoteService::class)->calculate($network.':USDT', '100', 'USD', '1', '1', '0', '1', '0');
        $quote['payment_config_snapshot'] = ['network' => $network, 'asset' => 'USDT', 'contract_address' => self::CONTRACT];
        $quote['verification_policy'] = ['version' => 'tron-solidified-v1', 'minimum_confirmations' => '3', 'genesis_hash' => str_repeat('0', 16).str_repeat('a', 48)];
        $monitor = $this->createCryptoMonitor(['network' => $network, 'asset' => 'USDT', 'decimals' => 6, 'quote_snapshot' => $quote,
            'due_units' => $quote['due_units'], 'fee_units' => $quote['fee_units'], 'net_units' => $quote['net_units']],
            ['network' => $network, 'address' => self::ADDRESS]);
        config(['services.tatum.verification_enabled' => true]);
        $raw = '0a02abcd';
        $txid = hash('sha256', hex2bin($raw));
        $receipt = app(TatumWebhookService::class)->receive($monitor, ['subscriptionType' => 'ADDRESS_EVENT', 'txId' => $txid,
            'address' => self::ADDRESS, 'value' => '9999999999999999999'], 'signed fixture', 'raw');
        $timestamp = now()->getTimestampMs();
        $info = ['id' => $txid, 'blockNumber' => 100, 'blockTimeStamp' => $timestamp, 'receipt' => ['result' => 'SUCCESS'], 'log' => [[
            'address' => self::CONTRACT_HEX, 'topics' => [self::TRANSFER, str_repeat('0', 24).str_repeat('d', 40), str_repeat('0', 24).substr(self::ADDRESS_HEX, 2)],
            'data' => str_repeat('0', 50).'20000000000001',
        ]]];
        $block = $this->block(100, $timestamp);
        $block['transactions'] = [['txID' => $txid]];
        $rpc = ['genesis' => ['blockID' => $quote['verification_policy']['genesis_hash']], 'info' => $info, 'solidInfo' => $info,
            'tx' => ['txID' => $txid, 'raw_data_hex' => $raw, 'ret' => [['contractRet' => 'SUCCESS']]],
            'block' => $block, 'solidBlock' => $block, 'head' => $this->block(105, $timestamp), 'solidHead' => $this->block(104, $timestamp)];
        Http::preventStrayRequests();

        return [$monitor, $receipt, $rpc];
    }

    private function block(int $height, int $timestamp): array
    {
        return ['blockID' => sprintf('%016x', $height).str_repeat('b', 48), 'block_header' => ['raw_data' => compact('timestamp') + ['number' => $height]]];
    }

    private function fakeRpc(array &$rpc, string $network = 'tron-shasta'): void
    {
        $level = DB::transactionLevel();
        Http::fake(function (Request $request) use (&$rpc, $network, $level) {
            $this->assertStringStartsWith('https://'.$network.'.gateway.tatum.io/', $request->url());
            $this->assertSame($level, DB::transactionLevel());
            $path = parse_url($request->url(), PHP_URL_PATH);
            $key = match ($path) {
                '/wallet/getblockbynum' => $request['num'] === 0 ? 'genesis' : 'block',
                '/wallet/gettransactioninfobyid' => 'info', '/wallet/gettransactionbyid' => 'tx', '/wallet/getnowblock' => 'head',
                '/walletsolidity/getnowblock' => 'solidHead', '/walletsolidity/getblockbynum' => 'solidBlock',
                '/walletsolidity/gettransactioninfobyid' => 'solidInfo',
                default => throw new DomainException('Unexpected fixture path.'),
            };

            return Http::response($rpc[$key] === [] ? '{}' : $rpc[$key]);
        });
    }

    private function process(CryptoWebhookReceipt $receipt): bool
    {
        return app(CryptoReceiptVerificationService::class)->process($receipt->merchant_id, $receipt->id);
    }
}
