<?php

namespace App\Services;

use App\Support\CryptoEvm;
use App\Support\CryptoEvmTrace;
use Carbon\CarbonImmutable;
use DomainException;

/** Independently observes canonical EVM receipts; does not trust webhook amounts or credit balances. */
final class CryptoEvmVerificationService
{
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(private readonly TatumEvmRpcClient $rpc) {}

    /**
     * @return array{state: string, confirmed: bool, block?: array{hash: string, height: int, time: CarbonImmutable}, transfers: list<array{position: string, units: string}>}
     */
    public function observe(string $network, string $asset, string $address, string $transactionHash, ?string $contract, int $minimumConfirmations, bool $includeInternal = false): array
    {
        $expectedChain = match ($network) {
            'ethereum-mainnet' => '1', 'ethereum-sepolia' => '11155111',
            default => throw new DomainException('Unsupported EVM network.'),
        };
        if (! in_array($asset, ['ETH', 'USDT'], true) || $minimumConfirmations < 1 || $minimumConfirmations > 100000) {
            throw new DomainException('Invalid EVM verification policy.');
        }
        $address = CryptoEvm::address($address);
        $hash = CryptoEvm::hash('0x'.$transactionHash);
        if ($asset === 'USDT') {
            $contract = CryptoEvm::address($contract);
            if ($network === 'ethereum-mainnet' && $contract !== '0xdac17f958d2ee523a2206206994597c13d831ec7') {
                throw new DomainException('Unsupported USDT contract.');
            }
        }
        if (CryptoEvm::quantity($this->rpc->read($network, 'eth_chainId')) !== $expectedChain) {
            throw new DomainException('RPC chain does not match the payment network.');
        }
        $receipt = $this->rpc->read($network, 'eth_getTransactionReceipt', [$hash]);
        if ($receipt === null) {
            return ['state' => 'pending', 'confirmed' => false, 'transfers' => []];
        }
        if (! is_array($receipt) || CryptoEvm::hash($receipt['transactionHash'] ?? null) !== $hash) {
            throw new DomainException('Receipt does not match the transaction.');
        }
        $height = CryptoEvm::integer($receipt['blockNumber'] ?? null);
        $blockHash = CryptoEvm::hash($receipt['blockHash'] ?? null);
        $index = CryptoEvm::integer($receipt['transactionIndex'] ?? null);
        $status = CryptoEvm::quantity($receipt['status'] ?? null);
        if (! in_array($status, ['0', '1'], true)) {
            throw new DomainException('Invalid transaction execution status.');
        }
        $block = $this->rpc->read($network, 'eth_getBlockByNumber', [$receipt['blockNumber'], false]);
        if (! is_array($block) || CryptoEvm::integer($block['number'] ?? null) !== $height) {
            throw new DomainException('Canonical block is unavailable.');
        }
        if (CryptoEvm::hash($block['hash'] ?? null) !== $blockHash) {
            return ['state' => 'reorg', 'confirmed' => false, 'transfers' => []];
        }
        if (! is_array($block['transactions'] ?? null) || ! array_is_list($block['transactions'])
            || CryptoEvm::hash($block['transactions'][$index] ?? null) !== $hash) {
            throw new DomainException('Transaction is missing from the canonical block.');
        }
        $timestamp = CryptoEvm::integer($block['timestamp'] ?? null);
        if ($timestamp <= 0 || $timestamp > now()->getTimestamp() + 300) {
            throw new DomainException('Invalid canonical block timestamp.');
        }
        $observation = ['state' => $status === '0' ? 'failed' : 'mined', 'confirmed' => false,
            'block' => ['hash' => $blockHash, 'height' => $height, 'time' => CarbonImmutable::createFromTimestampUTC($timestamp)], 'transfers' => []];
        if ($status === '0') {
            return $observation;
        }
        $tx = $this->rpc->read($network, 'eth_getTransactionByHash', [$hash]);
        if (! is_array($tx) || CryptoEvm::hash($tx['hash'] ?? null) !== $hash
            || CryptoEvm::hash($tx['blockHash'] ?? null) !== $blockHash || CryptoEvm::integer($tx['blockNumber'] ?? null) !== $height
            || CryptoEvm::integer($tx['transactionIndex'] ?? null) !== $index) {
            throw new DomainException('Transaction and receipt disagree.');
        }
        if (isset($tx['chainId']) && CryptoEvm::quantity($tx['chainId']) !== $expectedChain) {
            throw new DomainException('Transaction chain does not match.');
        }
        if ($asset === 'ETH') {
            $from = CryptoEvm::address($tx['from'] ?? null);
            $to = isset($tx['to']) ? CryptoEvm::address($tx['to']) : null;
            $units = CryptoEvm::quantity($tx['value'] ?? null);
            if ($to === $address && $from !== $address && $units !== '0') {
                $observation['transfers'][] = ['position' => 'native:0', 'units' => $units];
            }
            if ($includeInternal) {
                $trace = $this->rpc->read($network, 'debug_traceTransaction', [$hash, ['tracer' => 'callTracer', 'timeout' => '3s']]);
                if (! is_array($trace) || isset($trace['error'])) {
                    throw new DomainException('Successful transaction trace is unavailable.');
                }
                CryptoEvmTrace::assertRoot($trace, $tx);
                $observation['transfers'] = [];
                foreach (CryptoEvmTrace::transfers($trace) as $transfer) {
                    if ($transfer['recipient'] === $address) {
                        $observation['transfers'][] = ['position' => $transfer['position'], 'units' => $transfer['units']];
                    }
                }
            }
        } else {
            $observation['transfers'] = $this->tokenTransfers($receipt, $hash, $blockHash, $height, $index, $address, $contract);
        }
        if ($observation['transfers'] === []) {
            return $observation;
        }
        $latest = $this->rpc->read($network, 'eth_getBlockByNumber', ['latest', false]);
        $finalized = $this->rpc->read($network, 'eth_getBlockByNumber', ['finalized', false]);
        if (! is_array($latest) || ! is_array($finalized)) {
            throw new DomainException('Finality information is unavailable.');
        }
        $latestHeight = CryptoEvm::integer($latest['number'] ?? null);
        $finalizedHeight = CryptoEvm::integer($finalized['number'] ?? null);
        CryptoEvm::hash($latest['hash'] ?? null);
        CryptoEvm::hash($finalized['hash'] ?? null);
        if ($latestHeight < $height || $finalizedHeight > $latestHeight
            || ($latestHeight === $height && strtolower($latest['hash']) !== $blockHash)
            || ($finalizedHeight === $height && strtolower($finalized['hash']) !== $blockHash)) {
            throw new DomainException('RPC head and canonical receipt disagree.');
        }
        $observation['confirmed'] = $latestHeight - $height + 1 >= $minimumConfirmations && $finalizedHeight >= $height;
        $canonical = $this->rpc->read($network, 'eth_getBlockByNumber', [$receipt['blockNumber'], false]);
        if (! is_array($canonical) || CryptoEvm::integer($canonical['number'] ?? null) !== $height) {
            throw new DomainException('Canonical block recheck is unavailable.');
        }
        if (CryptoEvm::hash($canonical['hash'] ?? null) !== $blockHash) {
            return ['state' => 'reorg', 'confirmed' => false, 'transfers' => []];
        }

        return $observation;
    }

    /** @param array<string, mixed> $receipt
     * @return list<array{position: string, units: string}>
     */
    private function tokenTransfers(array $receipt, string $hash, string $blockHash, int $height, int $txIndex, string $recipient, string $contract): array
    {
        if (! is_array($receipt['logs'] ?? null) || ! array_is_list($receipt['logs']) || count($receipt['logs']) > 10000) {
            throw new DomainException('Invalid receipt logs.');
        }
        $transfers = [];
        $seen = [];
        foreach ($receipt['logs'] as $log) {
            if (! is_array($log)) {
                throw new DomainException('Invalid receipt log.');
            }
            if (CryptoEvm::address($log['address'] ?? null) !== $contract) {
                continue;
            }
            if (! is_array($log['topics'] ?? null) || ! array_is_list($log['topics'])) {
                throw new DomainException('Invalid receipt log topics.');
            }
            if (($log['topics'][0] ?? null) !== self::TRANSFER_TOPIC) {
                continue;
            }
            if (count($log['topics']) !== 3 || ($log['removed'] ?? null) !== false
                || CryptoEvm::hash($log['transactionHash'] ?? null) !== $hash || CryptoEvm::hash($log['blockHash'] ?? null) !== $blockHash
                || CryptoEvm::integer($log['blockNumber'] ?? null) !== $height || CryptoEvm::integer($log['transactionIndex'] ?? null) !== $txIndex) {
                throw new DomainException('Token transfer does not match the canonical receipt.');
            }
            $position = 'log:'.CryptoEvm::integer($log['logIndex'] ?? null);
            if (isset($seen[$position])) {
                throw new DomainException('Duplicate token log index.');
            }
            $seen[$position] = true;
            $from = CryptoEvm::topicAddress($log['topics'][1]);
            $to = CryptoEvm::topicAddress($log['topics'][2]);
            $units = CryptoEvm::word($log['data'] ?? null);
            if ($to === $recipient && $from !== $recipient && $units !== '0') {
                $transfers[] = ['position' => $position, 'units' => $units];
            }
        }

        return $transfers;
    }
}
