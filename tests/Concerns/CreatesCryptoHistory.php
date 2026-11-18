<?php

namespace Tests\Concerns;

use App\Models\CryptoAddressMonitor;
use App\Services\CryptoQuoteService;
use App\Support\CryptoEvm;
use App\Support\CryptoEvmTrace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait CreatesCryptoHistory
{
    use CreatesCryptoMonitor;

    protected function historyFixture(string $asset = 'ETH', array $requestOverrides = []): array
    {
        $quote = app(CryptoQuoteService::class)->calculate('ethereum-sepolia:'.$asset, '100', 'USD', '1', $asset === 'ETH' ? '2500' : '1', '2', '1', '0');
        $contract = '0x'.str_repeat('1', 40);
        $quote['payment_config_snapshot'] = ['network' => 'ethereum-sepolia', 'asset' => $asset, 'contract_address' => $asset === 'USDT' ? $contract : null];
        $quote['verification_policy'] = ['version' => 'evm-finalized-v1', 'minimum_confirmations' => '3', 'internal_transfers' => true];
        $monitor = $this->createCryptoMonitor(['asset' => $asset, 'decimals' => $asset === 'ETH' ? 18 : 6,
            'quote_snapshot' => $quote, 'due_units' => $quote['due_units'], 'fee_units' => $quote['fee_units'], 'net_units' => $quote['net_units'], ...$requestOverrides]);
        $this->setCryptoSettings(['crypto.verification_enabled' => true, 'crypto.history_enabled' => true, 'crypto.settlement_enabled' => true,
            'crypto.history_max_head_age_seconds' => '300']);
        $rpc = ['head' => 102, 'finalized' => 102, 'chain' => '0xaa36a7', 'blocks' => [], 'logs' => [], 'receipts' => [], 'traces' => []];
        foreach (range(99, 105) as $height) {
            $rpc['blocks'][$height] = ['number' => '0x'.dechex($height), 'hash' => $this->historyHash($height),
                'parentHash' => $this->historyHash($height - 1), 'timestamp' => '0x'.dechex(now()->getTimestamp() + $height - 100), 'transactions' => []];
        }
        $this->addHistoryTransfer($rpc, $monitor, 100, str_repeat('c', 64), $quote['due_units']);

        return [$monitor, $rpc];
    }

    protected function addHistoryTransfer(array &$rpc, CryptoAddressMonitor $monitor, int $height, string $hash, string $units, bool $internal = false): void
    {
        $asset = $monitor->paymentRequest->asset;
        $block = &$rpc['blocks'][$height];
        $common = ['blockNumber' => $block['number'], 'blockHash' => $block['hash'], 'transactionIndex' => '0x'.dechex(count($block['transactions']))];
        $tx = [...$common, 'hash' => '0x'.$hash, 'from' => '0x'.str_repeat('d', 40),
            'to' => $internal ? '0x'.str_repeat('b', 40) : $monitor->address->address,
            'value' => $asset === 'ETH' && ! $internal ? '0x'.dechex((int) $units) : '0x0'];
        $block['transactions'][] = $tx;
        $logs = [];
        if ($asset === 'USDT') {
            $log = [...$common, 'transactionHash' => '0x'.$hash, 'removed' => false, 'logIndex' => '0x0',
                'address' => '0x'.str_repeat('1', 40), 'data' => '0x'.str_pad(dechex((int) $units), 64, '0', STR_PAD_LEFT),
                'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                    '0x'.str_repeat('0', 24).str_repeat('d', 40), '0x'.str_repeat('0', 24).substr($monitor->address->address, 2)]];
            $rpc['logs'][$height][] = $log;
            $logs[] = $log;
        }
        $rpc['receipts']['0x'.$hash] = [...$common, 'transactionHash' => '0x'.$hash, 'status' => '0x1', 'logs' => $logs];
        $root = ['type' => 'CALL', 'from' => $tx['from'], 'to' => $tx['to'], 'value' => $tx['value']];
        if ($internal) {
            $root['calls'] = [['type' => 'CALL', 'from' => $tx['to'], 'to' => $monitor->address->address, 'value' => '0x'.dechex((int) $units)]];
        }
        $rpc['traces']['0x'.$hash] = $root;
    }

    protected function fakeHistoryRpc(array &$rpc): void
    {
        Http::preventStrayRequests();
        $baseline = DB::transactionLevel();
        Http::fake(function (Request $request) use (&$rpc, $baseline) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (in_array($path, ['/v4/data/transaction/history', '/v4/data/blockchains/transaction/internal'], true)) {
                $this->assertSame($baseline, DB::transactionLevel(), 'Address HTTP must not hold financial locks.');
                if (! empty($rpc['fail_address_history']) || ($rpc['fail_method'] ?? null) === 'eth_getLogs') {
                    return Http::response(['error' => 'unavailable'], 503);
                }
                $internal = str_ends_with($path, '/internal');
                $address = strtolower($request[$internal ? 'address' : 'addresses']);
                $rows = [];
                foreach ($rpc['blocks'] as $height => $block) {
                    if ($height < $request['blockFrom'] || $height > $request['blockTo']) {
                        continue;
                    }
                    foreach ($block['transactions'] as $tx) {
                        if ($internal) {
                            foreach (CryptoEvmTrace::transfers($rpc['traces'][$tx['hash']]) as $transfer) {
                                if (strtolower($transfer['recipient']) === $address) {
                                    $rows[] = ['blockNumber' => (string) $height, 'hash' => $tx['hash'], 'to' => $address, 'isError' => '0'];
                                }
                            }
                        } elseif ($request['transactionTypes'] === 'native') {
                            if (strtolower($tx['to']) === $address && $tx['value'] !== '0x0') {
                                $rows[] = ['blockNumber' => $height, 'hash' => $tx['hash'], 'address' => $address, 'chain' => 'ethereum-sepolia',
                                    'transactionType' => 'native', 'transactionSubtype' => 'incoming'];
                            }
                        } else {
                            foreach ($rpc['receipts'][$tx['hash']]['logs'] ?? [] as $log) {
                                if (strtolower(CryptoEvm::topicAddress($log['topics'][2])) === $address
                                    && strtolower($log['address']) === strtolower($request['tokenAddress'])) {
                                    $rows[] = ['blockNumber' => $height, 'hash' => $tx['hash'], 'address' => $address, 'chain' => 'ethereum-sepolia',
                                        'transactionType' => 'fungible', 'transactionSubtype' => 'incoming', 'tokenAddress' => $log['address']];
                                }
                            }
                        }
                    }
                }
                $rows = array_slice($rows, (int) $request['offset'], (int) $request['pageSize']);

                return Http::response($internal ? $rows : ['result' => $rows]);
            }
            if (isset($rpc['provider_handler']) && str_starts_with($request->url(), 'https://api.tatum.io/')) {
                $this->assertSame($baseline, DB::transactionLevel(), 'Provider HTTP must not hold financial locks.');

                return $rpc['provider_handler']($request);
            }
            if (isset($rpc['subscription']) && str_starts_with($request->url(), 'https://api.tatum.io/v4/subscription/')) {
                $this->assertSame('https://api.tatum.io/v4/subscription/alert-1', $request->url());
                $this->assertContains($request->method(), ['GET', 'DELETE']);

                return $request->method() === 'DELETE' ? Http::response('', 204) : Http::response($rpc['subscription']);
            }
            $this->assertSame('https://ethereum-sepolia.gateway.tatum.io/', $request->url());
            $this->assertSame($baseline, DB::transactionLevel(), 'RPC must not hold financial locks.');
            $method = $request['method'];
            $params = $request['params'];
            $result = null;
            if (isset($rpc['fail_method']) && $method === $rpc['fail_method']) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -1, 'message' => 'credential must not leak']], 200);
            }
            switch ($method) {
                case 'eth_chainId':
                    $result = $rpc['chain'];
                    break;
                case 'eth_getBlockByNumber':
                    $height = match ($params[0]) {
                        'latest' => $rpc['head'], 'finalized' => $rpc['finalized'], default => hexdec($params[0]),
                    };
                    $result = $rpc['blocks'][$height] ?? null;
                    if ($result !== null && $params[1] === false) {
                        $result['transactions'] = array_column($result['transactions'], 'hash');
                    }
                    break;
                case 'eth_getLogs':
                    $height = array_search($params[0]['blockHash'], array_map(fn (array $block) => $block['hash'], $rpc['blocks']), true);
                    $result = $rpc['logs'][$height] ?? [];
                    break;
                case 'debug_traceBlockByNumber':
                    if (array_key_exists('block_traces_override', $rpc)) {
                        $result = $rpc['block_traces_override'];
                        break;
                    }
                    $result = [];
                    foreach ($rpc['blocks'][hexdec($params[0])]['transactions'] as $tx) {
                        $result[] = ['txHash' => $tx['hash'], 'result' => $rpc['traces'][$tx['hash']]];
                    }
                    break;
                case 'debug_traceTransaction':
                    $result = $rpc['traces'][$params[0]] ?? null;
                    break;
                case 'eth_getTransactionReceipt':
                    $result = $rpc['receipts'][$params[0]] ?? null;
                    break;
                case 'eth_getTransactionByHash':
                    foreach ($rpc['blocks'] as $block) {
                        foreach ($block['transactions'] as $tx) {
                            if ($tx['hash'] === $params[0]) {
                                $result = $tx;
                            }
                        }
                    }
                    break;
                default:
                    $this->fail('Unexpected RPC '.$method);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    protected function historyHash(int $height): string
    {
        return '0x'.str_pad(dechex($height), 64, '0', STR_PAD_LEFT);
    }
}
