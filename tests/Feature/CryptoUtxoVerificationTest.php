<?php

namespace Tests\Feature;

use App\Models\CryptoChainEvent;
use App\Models\CryptoWebhookReceipt;
use App\Services\CryptoQuoteService;
use App\Services\CryptoReceiptVerificationService;
use App\Services\TatumWebhookService;
use App\Support\CryptoUtxoTransaction;
use App\Support\CryptoVerificationPolicy;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class CryptoUtxoVerificationTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    private const SCRIPT = '76a914aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa88ac';

    private const OTHER_SCRIPT = '76a914dddddddddddddddddddddddddddddddddddddddd88ac';

    private const GENESIS = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BLOCK = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[DataProvider('networks')]
    public function test_bitcoin_and_litecoin_confirm_exact_raw_outputs_once(string $network, string $asset): void
    {
        [$monitor, $receipt, $rpc] = $this->arrangeReceipt($network, $asset);
        $this->fakeRpc($rpc, $network);

        $this->assertTrue($this->process($receipt));
        $this->assertTrue($this->process($receipt));

        $events = CryptoChainEvent::query()->orderBy('id')->get();
        $this->assertSame(['100000001', '2'], $events->pluck('amount_units')->all());
        $this->assertSame(['vout:0', 'vout:1'], $events->pluck('event_position')->all());
        $this->assertNotNull($events[0]->confirmed_at);
        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertSame($asset, $events[0]->asset);
        $this->assertSame('preparing', $monitor->paymentRequest->fresh()->status);
        $this->assertSame('pending', $monitor->paymentRequest->order->fresh()->status);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', str_ends_with($network, '-mainnet') ? 'test-mainnet-credential' : 'test-testnet-credential'));
    }

    public static function networks(): array
    {
        return ['BTC main' => ['bitcoin-mainnet', 'BTC'], 'BTC test' => ['bitcoin-testnet', 'BTC'],
            'LTC main' => ['litecoin-mainnet', 'LTC'], 'LTC test' => ['litecoin-testnet', 'LTC']];
    }

    public function test_segwit_transaction_id_excludes_witness_bytes(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt('bitcoin-testnet', 'BTC', true);
        $this->assertNotSame($receipt->transaction_hash, $this->txid($rpc['tx']['hex']));
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertDatabaseCount('crypto_chain_events', 2);
        $this->assertSame('verified', $receipt->fresh()->status);
    }

    public function test_returned_change_from_an_order_address_is_not_new_income(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt('bitcoin-testnet', 'BTC', false, true);
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('ignored', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_not_enough_confirmations_retries_and_preserves_events(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $rpc['head']['blocks'] = 101;
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $this->assertSame('confirming', $receipt->fresh()->status);
        $this->assertNull(CryptoChainEvent::query()->first()->confirmed_at);
        $this->travel(61)->seconds();
        $rpc['head']['blocks'] = 105;

        $this->assertTrue($this->process($receipt));
        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 2);
    }

    public function test_unmined_or_missing_transaction_stays_pending(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        unset($rpc['tx']['blockhash']);
        $this->fakeRpc($rpc);

        $this->assertTrue($this->process($receipt));
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_reorg_revokes_old_events_and_reinclusion_reuses_output_identity(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        $this->fakeRpc($rpc);
        $this->assertTrue($this->process($receipt));
        $rpc['canonical'] = str_repeat('e', 64);

        $this->assertTrue($this->process($receipt));
        $this->assertNotNull(CryptoChainEvent::query()->first()->orphaned_at);
        $this->assertNull(CryptoChainEvent::query()->first()->confirmed_at);
        $this->travel(61)->seconds();
        $rpc['tx']['blockhash'] = $rpc['canonical'];
        $rpc['block']['hash'] = $rpc['canonical'];
        $this->assertTrue($this->process($receipt));
        $this->assertNull(CryptoChainEvent::query()->first()->orphaned_at);
        $this->assertDatabaseCount('crypto_chain_events', 2);
    }

    #[DataProvider('invalidFacts')]
    public function test_unreliable_utxo_facts_do_not_create_events(string $case): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt();
        match ($case) {
            'genesis' => $rpc['genesis'] = str_repeat('e', 64),
            'chain' => $rpc['head']['chain'] = 'main',
            'syncing' => $rpc['head']['initialblockdownload'] = true,
            'address' => $rpc['validation']['isvalid'] = false,
            'wrong address' => $rpc['validation']['address'] = 'unexpected-other-address',
            'raw hash' => $rpc['tx']['hex'] = substr_replace($rpc['tx']['hex'], '02', 0, 2),
            'membership' => $rpc['block']['tx'] = [],
            'future time' => $rpc['block']['time'] = now()->addHour()->getTimestamp(),
            'height type' => $rpc['block']['height'] = 100.5,
            'previous transaction' => $rpc['previous']['hex'] = '00',
        };
        $this->fakeRpc($rpc);

        $this->assertFalse($this->process($receipt));
        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public static function invalidFacts(): array
    {
        $cases = ['genesis', 'chain', 'syncing', 'address', 'wrong address', 'raw hash', 'membership', 'future time', 'height type', 'previous transaction'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('badRawTransactions')]
    public function test_decoder_rejects_malformed_or_unsupported_serialization(string $raw): void
    {
        $this->expectException(DomainException::class);

        CryptoUtxoTransaction::decode($raw);
    }

    public static function badRawTransactions(): array
    {
        return ['truncated' => ['0100000001111111111111'], 'nonhex' => ['zz'],
            'mweb flags' => ['0100000000080100000000'], 'zero inputs' => ['0100000000010000000000'],
            'noncanonical varint' => ['01000000fd0100'.str_repeat('00', 80)]];
    }

    public function test_frozen_utxo_policy_contains_network_pin_and_does_not_inherit_evm_settings(): void
    {
        config(['services.tatum.utxo_min_confirmations.bitcoin-mainnet' => '6', 'services.tatum.genesis_hashes.bitcoin-mainnet' => self::GENESIS]);

        $this->assertSame(['version' => 'utxo-canonical-v1', 'minimum_confirmations' => '6', 'genesis_hash' => self::GENESIS], CryptoVerificationPolicy::snapshot('bitcoin-mainnet'));
    }

    public function test_missing_frozen_genesis_stops_before_rpc(): void
    {
        [, $receipt] = $this->arrangeReceipt(options: ['genesis' => null]);
        Http::preventStrayRequests();

        $this->assertFalse($this->process($receipt));
        Http::assertNothingSent();
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    public function test_large_input_fan_in_is_bounded_before_reading_previous_transactions(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt(options: ['inputs' => 17]);
        $this->fakeRpc($rpc);

        $this->assertFalse($this->process($receipt));
        $this->assertDatabaseCount('crypto_chain_events', 0);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request['method'] === 'getrawtransaction'));
    }

    public function test_raw_output_above_bitcoin_supply_cannot_be_credited(): void
    {
        [, $receipt, $rpc] = $this->arrangeReceipt(options: ['units' => 2100000000000001]);
        $this->fakeRpc($rpc);

        $this->assertFalse($this->process($receipt));
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    private function arrangeReceipt(string $network = 'bitcoin-testnet', string $asset = 'BTC', bool $segwit = false, bool $selfChange = false, array $options = []): array
    {
        $quote = app(CryptoQuoteService::class)->calculate($network.':'.$asset, '100', 'USD', '1', '100', '0', '1', '0');
        $quote['payment_config_snapshot'] = ['network' => $network, 'asset' => $asset];
        $quote['verification_policy'] = ['version' => 'utxo-canonical-v1', 'minimum_confirmations' => '3', 'genesis_hash' => self::GENESIS];
        if (array_key_exists('genesis', $options)) {
            $quote['verification_policy']['genesis_hash'] = $options['genesis'];
        }
        $monitor = $this->createCryptoMonitor(['network' => $network, 'asset' => $asset, 'decimals' => 8,
            'quote_snapshot' => $quote, 'due_units' => $quote['due_units'], 'fee_units' => $quote['fee_units'], 'net_units' => $quote['net_units']],
            ['network' => $network, 'address' => match ($network) {
                'bitcoin-mainnet' => '1GZQKjsC97yasxRj1wtYf5rC61AxpR1zmr',
                'litecoin-mainnet' => 'LanMaxB2DnDe8m7tC5sqw6uxJDYEv7SxvB',
                default => 'mw5McnxAx9Qqf4uLjWrvV14WwzmfgiQ9PX',
            }]);
        config(['services.tatum.verification_enabled' => true]);
        $previous = $this->raw(str_repeat('1', 64), [$selfChange ? self::SCRIPT : self::OTHER_SCRIPT], [200000000]);
        $prevId = $this->txid($previous);
        $stripped = $this->raw($prevId, [self::SCRIPT, self::SCRIPT], [$options['units'] ?? 100000001, 2], $options['inputs'] ?? 1);
        $txid = $this->txid($stripped);
        $raw = $segwit ? substr($stripped, 0, 8).'0001'.substr($stripped, 8, -8).'010100'.substr($stripped, -8) : $stripped;
        $receipt = app(TatumWebhookService::class)->receive($monitor, ['subscriptionType' => 'ADDRESS_EVENT', 'txId' => $txid,
            'address' => $monitor->address->address, 'amount' => '1000000000000000000'], 'signed fixture', 'raw');
        $rpc = ['genesis' => self::GENESIS, 'canonical' => self::BLOCK,
            'head' => ['chain' => str_ends_with($network, '-mainnet') ? 'main' : 'test', 'blocks' => 105, 'initialblockdownload' => false],
            'validation' => ['isvalid' => true, 'address' => $monitor->address->address, 'scriptPubKey' => self::SCRIPT],
            'tx' => ['txid' => $txid, 'hex' => $raw, 'blockhash' => self::BLOCK, 'vout' => [['value' => 999.99]]],
            'previous' => ['txid' => $prevId, 'hex' => $previous],
            'block' => ['hash' => self::BLOCK, 'height' => 100, 'time' => now()->getTimestamp(), 'tx' => [$txid]],
        ];
        Http::preventStrayRequests();

        return [$monitor, $receipt, $rpc];
    }

    private function fakeRpc(array &$rpc, string $network = 'bitcoin-testnet'): void
    {
        $level = DB::transactionLevel();
        Http::fake(function (Request $request) use (&$rpc, $network, $level) {
            $this->assertSame('https://'.$network.'.gateway.tatum.io/', $request->url());
            $this->assertSame($level, DB::transactionLevel());
            $key = match ($request['method']) {
                'getblockhash' => $request['params'][0] === 0 ? 'genesis' : 'canonical',
                'getblockchaininfo' => 'head', 'validateaddress' => 'validation', 'getblock' => 'block',
                'getrawtransaction' => $request['params'][0] === $rpc['tx']['txid'] ? 'tx' : 'previous',
                default => throw new DomainException('Unexpected fixture method.'),
            };

            return Http::response(['id' => 1, 'result' => $rpc[$key], 'error' => null]);
        });
    }

    /** Independent fixture serialization uses PHP binary packing, not the production decoder. */
    private function raw(string $prevId, array $scripts, array $values, int $inputCount = 1): string
    {
        $bytes = pack('V', 1).chr($inputCount);
        for ($index = 0; $index < $inputCount; $index++) {
            $id = $index === 0 ? $prevId : hash('sha256', (string) $index);
            $bytes .= strrev(hex2bin($id)).pack('V', 0)."\x00".pack('V', 4294967295);
        }
        $bytes .= chr(count($scripts));
        foreach ($scripts as $index => $script) {
            $bytes .= pack('P', $values[$index]).chr(strlen($script) / 2).hex2bin($script);
        }

        return bin2hex($bytes.pack('V', 0));
    }

    private function txid(string $raw): string
    {
        return bin2hex(strrev(hash('sha256', hash('sha256', hex2bin($raw), true), true)));
    }

    private function process(CryptoWebhookReceipt $receipt): bool
    {
        return app(CryptoReceiptVerificationService::class)->process($receipt->merchant_id, $receipt->id);
    }
}
