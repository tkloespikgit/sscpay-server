<?php

namespace App\Services;

use App\Support\CryptoEvm;
use App\Support\CryptoEvmTrace;
use App\Support\CryptoTronAddress;
use Carbon\CarbonImmutable;
use DomainException;

/** Reads complete blocks once per network batch. Results are discovery hints, never payment evidence. */
final class CryptoBlockHistoryService
{
    private const TRANSFER = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(private readonly TatumEvmRpcClient $evm, private readonly TatumChainReadClient $chains) {}

    public function assertNetwork(string $network, ?string $genesis): void
    {
        if (str_starts_with($network, 'ethereum-')) {
            $expected = match ($network) {
                'ethereum-mainnet' => '1', 'ethereum-sepolia' => '11155111',
                default => throw new DomainException('Unsupported scan network.'),
            };
            if (CryptoEvm::quantity($this->evm->read($network, 'eth_chainId')) !== $expected) {
                throw new DomainException('History chain mismatch.');
            }

            return;
        }
        $this->hash($genesis);
        $actual = str_starts_with($network, 'tron-') ? $this->header($network, 0)['hash']
            : $this->hash($this->chains->utxo($network, 'getblockhash', [0]));
        if ($actual !== strtolower($genesis)) {
            throw new DomainException('History genesis mismatch.');
        }
    }

    /** @return array{height: int, hash: string, parent: string, time: CarbonImmutable} */
    public function head(string $network): array
    {
        if (str_starts_with($network, 'ethereum-')) {
            return $this->evmHeader($this->evm->read($network, 'eth_getBlockByNumber', ['latest', false]));
        }
        if (str_starts_with($network, 'tron-')) {
            return $this->tronHeader($this->chains->tron($network, 'wallet', 'getnowblock'));
        }
        $info = $this->chains->utxo($network, 'getblockchaininfo');
        $chains = str_ends_with($network, '-mainnet') ? ['main'] : ['test', 'testnet4'];
        if (! is_array($info) || ! in_array($info['chain'] ?? null, $chains, true) || ($info['initialblockdownload'] ?? null) !== false) {
            throw new DomainException('UTXO scan head is unavailable.');
        }
        $header = $this->header($network, $this->integer($info['blocks'] ?? null));
        if ($header['hash'] !== $this->hash($info['bestblockhash'] ?? null)) {
            throw new DomainException('UTXO scan head changed.');
        }

        return $header;
    }

    /** @return array{height: int, hash: string, parent: string, time: CarbonImmutable} */
    public function header(string $network, int $height): array
    {
        if ($height < 0) {
            throw new DomainException('Invalid scan height.');
        }
        if (str_starts_with($network, 'ethereum-')) {
            $header = $this->evmHeader($this->evm->read($network, 'eth_getBlockByNumber', ['0x'.dechex($height), false]));
        } elseif (str_starts_with($network, 'tron-')) {
            $header = $this->tronHeader($this->chains->tron($network, 'wallet', 'getblockbynum', ['num' => $height]));
        } else {
            $hash = $this->hash($this->chains->utxo($network, 'getblockhash', [$height]));
            $header = $this->utxoHeader($this->chains->utxo($network, 'getblock', [$hash, 1]));
            if ($header['hash'] !== $hash) {
                throw new DomainException('UTXO block hash mismatch.');
            }
        }
        if ($header['height'] !== $height) {
            throw new DomainException('Scan height mismatch.');
        }

        return $header;
    }

    /** @return array{header: array{height: int, hash: string, parent: string, time: CarbonImmutable}, hints: list<array{hash: string, recipient: string, asset: string, contract: ?string}>} */
    public function block(string $network, int $height, bool $includeEth): array
    {
        $result = str_starts_with($network, 'ethereum-') ? $this->evmBlock($network, $height, $includeEth)
            : (str_starts_with($network, 'tron-') ? $this->tronBlock($network, $height) : $this->utxoBlock($network, $height));
        if ($result['header']['height'] !== $height || $this->header($network, $height)['hash'] !== $result['header']['hash']) {
            throw new DomainException('Block changed during history discovery.');
        }

        return $result;
    }

    private function evmBlock(string $network, int $height, bool $includeEth): array
    {
        $block = $this->evm->read($network, 'eth_getBlockByNumber', ['0x'.dechex($height), true]);
        $header = $this->evmHeader($block);
        $transactions = $this->list($block['transactions'] ?? null);
        $ids = [];
        foreach ($transactions as $index => $tx) {
            if (! is_array($tx) || CryptoEvm::hash($tx['blockHash'] ?? null) !== $header['hash']
                || CryptoEvm::integer($tx['blockNumber'] ?? null) !== $height || CryptoEvm::integer($tx['transactionIndex'] ?? null) !== $index) {
                throw new DomainException('Incomplete EVM block transactions.');
            }
            $id = CryptoEvm::hash($tx['hash'] ?? null);
            if (isset($ids[$id])) {
                throw new DomainException('Duplicate EVM transaction.');
            }
            $ids[$id] = $index;
        }
        $hints = [];
        if ($includeEth && $transactions !== []) {
            $traces = $this->list($this->evm->read($network, 'debug_traceBlockByNumber', ['0x'.dechex($height), ['tracer' => 'callTracer', 'timeout' => '3s']]));
            if (count($traces) !== count($transactions)) {
                throw new DomainException('Incomplete block traces.');
            }
            foreach ($traces as $index => $trace) {
                $tx = $transactions[$index];
                if (! is_array($trace) || ! is_array($trace['result'] ?? null) || isset($trace['error'])
                    || CryptoEvm::hash($trace['txHash'] ?? null) !== strtolower($tx['hash'])) {
                    throw new DomainException('Trace identity mismatch.');
                }
                $root = $trace['result'];
                CryptoEvmTrace::assertRoot($root, $tx);
                foreach (CryptoEvmTrace::transfers($root) as $transfer) {
                    $hints[] = ['hash' => substr(strtolower($tx['hash']), 2), 'recipient' => $transfer['recipient'], 'asset' => 'ETH', 'contract' => null];
                }
            }
        }
        $logs = $this->list($this->evm->read($network, 'eth_getLogs', [['blockHash' => $header['hash'], 'topics' => ['0x'.self::TRANSFER]]]));
        $seen = [];
        foreach ($logs as $log) {
            if (! is_array($log) || ($log['removed'] ?? null) !== false || CryptoEvm::hash($log['blockHash'] ?? null) !== $header['hash']
                || CryptoEvm::integer($log['blockNumber'] ?? null) !== $height) {
                throw new DomainException('Invalid history token log.');
            }
            $hash = CryptoEvm::hash($log['transactionHash'] ?? null);
            if (! isset($ids[$hash]) || CryptoEvm::integer($log['transactionIndex'] ?? null) !== $ids[$hash]) {
                throw new DomainException('History log is absent from its block.');
            }
            $position = CryptoEvm::integer($log['logIndex'] ?? null);
            if (isset($seen[$position])) {
                throw new DomainException('Duplicate history log.');
            }
            $seen[$position] = true;
            $topics = $this->list($log['topics'] ?? null);
            if (($topics[0] ?? null) !== '0x'.self::TRANSFER) {
                throw new DomainException('Unexpected history log signature.');
            }
            if (count($topics) !== 3) {
                continue;
            }
            $hints[] = ['hash' => substr($hash, 2), 'recipient' => CryptoEvm::topicAddress($topics[2]), 'asset' => 'USDT', 'contract' => CryptoEvm::address($log['address'] ?? null)];
        }

        return ['header' => $header, 'hints' => $hints];
    }

    private function utxoBlock(string $network, int $height): array
    {
        $hash = $this->hash($this->chains->utxo($network, 'getblockhash', [$height]));
        $block = $this->chains->utxo($network, 'getblock', [$hash, 2]);
        $header = $this->utxoHeader($block);
        $transactions = $this->list($block['tx'] ?? null);
        if ($header['hash'] !== $hash || $this->integer($block['nTx'] ?? null) !== count($transactions)) {
            throw new DomainException('Incomplete UTXO block.');
        }
        $hints = [];
        $seen = [];
        foreach ($transactions as $tx) {
            if (! is_array($tx)) {
                throw new DomainException('Missing verbose UTXO transaction.');
            }
            $id = $this->hash($tx['txid'] ?? null);
            if (isset($seen[$id])) {
                throw new DomainException('Duplicate UTXO transaction.');
            }
            $seen[$id] = true;
            foreach ($this->list($tx['vout'] ?? null) as $index => $output) {
                if (! is_array($output) || ($output['n'] ?? null) !== $index || ! is_array($output['scriptPubKey'] ?? null)) {
                    throw new DomainException('Incomplete UTXO outputs.');
                }
                $script = $output['scriptPubKey'];
                $hex = $script['hex'] ?? null;
                if (! is_string($hex) || strlen($hex) > 20000 || strlen($hex) % 2 !== 0 || ($hex !== '' && ! ctype_xdigit($hex))) {
                    throw new DomainException('Invalid history script.');
                }
                $addresses = isset($script['address']) ? [$script['address']] : ($script['addresses'] ?? []);
                $addresses = $this->list($addresses);
                if ($addresses === [] && preg_match('/^(76a914[0-9a-f]{40}88ac|a914[0-9a-f]{40}87|0014[0-9a-f]{40}|0020[0-9a-f]{64}|5120[0-9a-f]{64})$/iD', $hex)) {
                    throw new DomainException('Node omitted a standard output recipient.');
                }
                foreach ($addresses as $recipient) {
                    if (! is_string($recipient) || strlen($recipient) > 128 || $recipient === '') {
                        throw new DomainException('Invalid UTXO recipient.');
                    }
                    $hints[] = ['hash' => $id, 'recipient' => $recipient, 'asset' => str_starts_with($network, 'bitcoin-') ? 'BTC' : 'LTC', 'contract' => null];
                }
            }
        }

        return ['header' => $header, 'hints' => $hints];
    }

    private function tronBlock(string $network, int $height): array
    {
        $block = $this->chains->tron($network, 'wallet', 'getblockbynum', ['num' => $height]);
        $header = $this->tronHeader($block);
        $ids = [];
        foreach ($this->list($block['transactions'] ?? []) as $tx) {
            $id = $this->hash($tx['txID'] ?? null);
            if (isset($ids[$id])) {
                throw new DomainException('Duplicate TRON transaction.');
            }
            $ids[$id] = true;
        }
        $infos = $this->list($this->chains->tron($network, 'wallet', 'gettransactioninfobyblocknum', ['num' => $height]));
        if (count($infos) !== count($ids)) {
            throw new DomainException('Incomplete TRON block receipts.');
        }
        $hints = [];
        foreach ($infos as $info) {
            $id = $this->hash($info['id'] ?? null);
            if (! isset($ids[$id]) || ($info['blockNumber'] ?? null) !== $height
                || $this->integer($info['blockTimeStamp'] ?? null) !== $header['time']->getTimestampMs()) {
                throw new DomainException('TRON history receipt mismatch.');
            }
            unset($ids[$id]);
            foreach ($this->list($info['log'] ?? []) as $log) {
                if (! is_array($log)) {
                    throw new DomainException('Invalid TRON history log.');
                }
                $topics = $this->list($log['topics'] ?? null);
                if (strtolower($topics[0] ?? '') !== self::TRANSFER) {
                    continue;
                }
                if (count($topics) !== 3) {
                    continue;
                }
                $hints[] = ['hash' => $id, 'recipient' => '41'.substr(CryptoEvm::topicAddress('0x'.$topics[2]), 2),
                    'asset' => 'USDT', 'contract' => CryptoTronAddress::logAddress($log['address'] ?? null)];
            }
        }

        return ['header' => $header, 'hints' => $hints];
    }

    private function evmHeader(mixed $block): array
    {
        if (! is_array($block)) {
            throw new DomainException('Missing EVM block.');
        }

        return ['height' => CryptoEvm::integer($block['number'] ?? null), 'hash' => CryptoEvm::hash($block['hash'] ?? null),
            'parent' => CryptoEvm::hash($block['parentHash'] ?? null), 'time' => $this->timestamp(CryptoEvm::integer($block['timestamp'] ?? null))];
    }

    private function utxoHeader(mixed $block): array
    {
        if (! is_array($block)) {
            throw new DomainException('Missing UTXO block.');
        }
        $height = $this->integer($block['height'] ?? null);

        return ['height' => $height, 'hash' => $this->hash($block['hash'] ?? null),
            'parent' => $height === 0 ? str_repeat('0', 64) : $this->hash($block['previousblockhash'] ?? null),
            'time' => $this->timestamp($this->integer($block['time'] ?? null))];
    }

    private function tronHeader(array $block): array
    {
        $raw = $block['block_header']['raw_data'] ?? [];
        $hash = $this->hash($block['blockID'] ?? null);
        $height = $this->integer($raw['number'] ?? (str_starts_with($hash, str_repeat('0', 16)) ? 0 : null));
        if (substr($hash, 0, 16) !== str_pad(dechex($height), 16, '0', STR_PAD_LEFT)) {
            throw new DomainException('TRON block ID height mismatch.');
        }

        return ['height' => $height, 'hash' => $hash, 'parent' => $height === 0 ? str_repeat('0', 64) : $this->hash($raw['parentHash'] ?? null),
            'time' => $this->timestamp($this->integer($raw['timestamp'] ?? null), true)];
    }

    private function hash(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-fA-F0-9]{64}$/D', $value)) {
            throw new DomainException('Invalid history hash.');
        }

        return strtolower($value);
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new DomainException('Invalid history integer.');
        }

        return $value;
    }

    private function timestamp(int $value, bool $milliseconds = false): CarbonImmutable
    {
        $time = $milliseconds ? CarbonImmutable::createFromTimestampMsUTC($value) : CarbonImmutable::createFromTimestampUTC($value);
        if ($value <= 0 || $time->gt(now()->addMinutes(5))) {
            throw new DomainException('Invalid history timestamp.');
        }

        return $time;
    }

    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100000) {
            throw new DomainException('Incomplete history list.');
        }

        return $value;
    }
}
