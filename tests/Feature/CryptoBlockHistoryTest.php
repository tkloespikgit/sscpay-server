<?php

namespace Tests\Feature;

use App\Services\CryptoBlockHistoryService;
use App\Services\TatumChainReadClient;
use App\Services\TatumEvmRpcClient;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoHistory;
use Tests\TestCase;

class CryptoBlockHistoryTest extends TestCase
{
    use CreatesCryptoHistory;
    use RefreshDatabase;

    #[DataProvider('utxoNetworks')]
    public function test_complete_utxo_block_discovers_all_output_addresses_without_treating_decimal_hint_values_as_money(string $network, string $asset): void
    {
        $this->freezeTime();
        [$block, $genesis] = $this->utxoFixture();
        $this->fakeUtxo($network, $block, $genesis);
        $reader = app(CryptoBlockHistoryService::class);

        $reader->assertNetwork($network, $genesis);
        $head = $reader->head($network);
        $result = $reader->block($network, 100, false);

        $this->assertSame(100, $head['height']);
        $this->assertSame($block['hash'], $result['header']['hash']);
        $this->assertSame([
            ['hash' => str_repeat('c', 64), 'recipient' => 'wallet-one', 'asset' => $asset, 'contract' => null],
            ['hash' => str_repeat('c', 64), 'recipient' => 'wallet-two', 'asset' => $asset, 'contract' => null],
        ], $result['hints']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', str_ends_with($network, '-mainnet') ? 'main-fixture-key' : 'test-fixture-key'));
    }

    public static function utxoNetworks(): array
    {
        return [['bitcoin-mainnet', 'BTC'], ['bitcoin-testnet', 'BTC'], ['litecoin-mainnet', 'LTC'], ['litecoin-testnet', 'LTC']];
    }

    #[DataProvider('incompleteUtxo')]
    public function test_incomplete_utxo_block_never_becomes_history_coverage(string $case): void
    {
        $this->freezeTime();
        [$block, $genesis] = $this->utxoFixture();
        switch ($case) {
            case 'count': $block['nTx'] = 2;
                break;
            case 'missing count': unset($block['nTx']);
                break;
            case 'nonverbose': $block['tx'] = [str_repeat('c', 64)];
                break;
            case 'wrong height': $block['height'] = 101;
                break;
            case 'missing address': unset($block['tx'][0]['vout'][0]['scriptPubKey']['address']);
                break;
            case 'output gap': $block['tx'][0]['vout'][1]['n'] = 3;
                break;
            case 'duplicate': $block['nTx'] = 2;
                $block['tx'][] = $block['tx'][0];
                break;
        }
        $this->fakeUtxo('bitcoin-mainnet', $block, $genesis);
        $this->expectException(DomainException::class);

        app(CryptoBlockHistoryService::class)->block('bitcoin-mainnet', 100, false);
    }

    public static function incompleteUtxo(): array
    {
        return array_map(fn (string $case) => [$case], ['count', 'missing count', 'nonverbose', 'wrong height', 'missing address', 'output gap', 'duplicate']);
    }

    #[DataProvider('tronNetworks')]
    public function test_tron_reads_all_block_receipts_and_normalizes_token_recipients(string $network): void
    {
        $this->freezeTime();
        [$block, $infos, $genesis] = $this->tronFixture();
        $this->fakeTron($network, $block, $infos, $genesis);
        $reader = app(CryptoBlockHistoryService::class);

        $reader->assertNetwork($network, $genesis['blockID']);
        $this->assertSame(100, $reader->head($network)['height']);
        $result = $reader->block($network, 100, false);

        $this->assertSame([['hash' => str_repeat('c', 64), 'recipient' => '41'.str_repeat('a', 40), 'asset' => 'USDT', 'contract' => '41'.str_repeat('1', 40)]], $result['hints']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://'.$network.'.gateway.tatum.io/wallet/gettransactioninfobyblocknum'
            && $request['num'] === 100);
    }

    public static function tronNetworks(): array
    {
        return [['tron-mainnet'], ['tron-shasta']];
    }

    #[DataProvider('incompleteTron')]
    public function test_incomplete_or_wrong_tron_receipts_do_not_advance_history(string $case): void
    {
        $this->freezeTime();
        [$block, $infos, $genesis] = $this->tronFixture();
        switch ($case) {
            case 'missing receipt': $infos = [];
                break;
            case 'wrong transaction': $infos[0]['id'] = str_repeat('e', 64);
                break;
            case 'wrong height': $infos[0]['blockNumber'] = 101;
                break;
            case 'wrong time': $infos[0]['blockTimeStamp']--;
                break;
            case 'wrong topic': $infos[0]['log'][0]['topics'][2] = str_repeat('f', 64);
                break;
            case 'error object': $infos = ['Error' => 'provider failure'];
                break;
        }
        $this->fakeTron('tron-shasta', $block, $infos, $genesis);
        $this->expectException(DomainException::class);

        app(CryptoBlockHistoryService::class)->block('tron-shasta', 100, false);
    }

    public static function incompleteTron(): array
    {
        return array_map(fn (string $case) => [$case], ['missing receipt', 'wrong transaction', 'wrong height', 'wrong time', 'wrong topic', 'error object']);
    }

    public function test_wrong_genesis_is_rejected_before_scanning_a_target_block(): void
    {
        $this->freezeTime();
        [$block, $genesis] = $this->utxoFixture();
        $this->fakeUtxo('bitcoin-mainnet', $block, $genesis);
        try {
            app(CryptoBlockHistoryService::class)->assertNetwork('bitcoin-mainnet', str_repeat('f', 64));
            $this->fail('Wrong network accepted.');
        } catch (DomainException) {
            Http::assertSentCount(1);
        }
    }

    public function test_truncated_ethereum_trace_list_is_rejected_even_when_logs_succeed(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $rpc['blocks'][100]['transactions'][] = [...$rpc['blocks'][100]['transactions'][0], 'hash' => '0x'.str_repeat('e', 64), 'transactionIndex' => '0x1'];
        $rpc['block_traces_override'] = [];
        $this->fakeHistoryRpc($rpc);
        $this->expectException(DomainException::class);

        app(CryptoBlockHistoryService::class)->block('ethereum-sepolia', 100, true);
    }

    #[DataProvider('listMethods')]
    public function test_rpc_object_is_not_mistaken_for_a_successful_empty_history_list(string $family, string $method): void
    {
        config(['services.tatum.testnet_key' => 'test-fixture-key']);
        Http::preventStrayRequests();
        Http::fake(['*.gateway.tatum.io/*' => Http::response($family === 'evm'
            ? ['jsonrpc' => '2.0', 'id' => 1, 'result' => (object) []] : '{}')]);
        $this->expectException(DomainException::class);

        if ($family === 'evm') {
            app(TatumEvmRpcClient::class)->read('ethereum-sepolia', $method);
        } else {
            app(TatumChainReadClient::class)->tron('tron-shasta', 'wallet', $method);
        }
    }

    public static function listMethods(): array
    {
        return [['evm', 'eth_getLogs'], ['evm', 'debug_traceBlockByNumber'], ['tron', 'gettransactioninfobyblocknum']];
    }

    private function utxoFixture(): array
    {
        return [[
            'hash' => str_repeat('b', 64), 'height' => 100, 'previousblockhash' => str_repeat('a', 64), 'time' => now()->getTimestamp(), 'nTx' => 1,
            'tx' => [['txid' => str_repeat('c', 64), 'vout' => [
                ['n' => 0, 'value' => 9999999.99999999, 'scriptPubKey' => ['hex' => '76a914'.str_repeat('1', 40).'88ac', 'address' => 'wallet-one']],
                ['n' => 1, 'value' => 0.00000001, 'scriptPubKey' => ['hex' => '76a914'.str_repeat('2', 40).'88ac', 'addresses' => ['wallet-two']]],
                ['n' => 2, 'value' => 0, 'scriptPubKey' => ['hex' => '6a00']],
            ]]],
        ], str_repeat('0', 64)];
    }

    private function fakeUtxo(string $network, array $block, string $genesis): void
    {
        config(['services.tatum.api_key' => 'main-fixture-key', 'services.tatum.testnet_key' => 'test-fixture-key']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($network, $block, $genesis) {
            $this->assertSame('https://'.$network.'.gateway.tatum.io/', $request->url());
            $result = match ($request['method']) {
                'getblockhash' => $request['params'][0] === 0 ? $genesis : str_repeat('b', 64),
                'getblock' => $block,
                'getblockchaininfo' => ['chain' => str_ends_with($network, '-mainnet') ? 'main' : 'test', 'initialblockdownload' => false,
                    'blocks' => 100, 'bestblockhash' => str_repeat('b', 64)],
                default => throw new DomainException('Unexpected test method.'),
            };

            return Http::response(['id' => 1, 'result' => $result, 'error' => null]);
        });
    }

    private function tronFixture(): array
    {
        $time = now()->getTimestampMs();
        $block = ['blockID' => str_pad('64', 16, '0', STR_PAD_LEFT).str_repeat('b', 48),
            'block_header' => ['raw_data' => ['number' => 100, 'timestamp' => $time, 'parentHash' => str_repeat('a', 64)]],
            'transactions' => [['txID' => str_repeat('c', 64)]]];
        $infos = [['id' => str_repeat('c', 64), 'blockNumber' => 100, 'blockTimeStamp' => $time, 'log' => [
            ['address' => str_repeat('1', 40), 'topics' => ['ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                str_repeat('0', 24).str_repeat('d', 40), str_repeat('0', 24).str_repeat('a', 40)], 'data' => str_repeat('0', 63).'1'],
        ]]];
        $genesis = ['blockID' => str_repeat('0', 16).str_repeat('1', 48), 'block_header' => ['raw_data' => ['timestamp' => 1530010000000]]];

        return [$block, $infos, $genesis];
    }

    private function fakeTron(string $network, array $block, array $infos, array $genesis): void
    {
        config(['services.tatum.api_key' => 'main-fixture-key', 'services.tatum.testnet_key' => 'test-fixture-key']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($network, $block, $infos, $genesis) {
            $this->assertStringStartsWith('https://'.$network.'.gateway.tatum.io/wallet/', $request->url());
            $result = match (basename($request->url())) {
                'getnowblock' => $block,
                'getblockbynum' => $request['num'] === 0 ? $genesis : $block,
                'gettransactioninfobyblocknum' => $infos,
                default => throw new DomainException('Unexpected test method.'),
            };

            return Http::response($result);
        });
    }
}
