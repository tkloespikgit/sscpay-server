<?php

namespace App\Services;

use App\Support\CryptoEvm;
use App\Support\CryptoTronAddress;
use Carbon\CarbonImmutable;
use DomainException;

final class CryptoTronVerificationService
{
    private const TRANSFER = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(private readonly TatumChainReadClient $rpc) {}

    /** @return array{state: string, confirmed: bool, block?: array{hash: string, height: int, time: CarbonImmutable}, transfers: list<array{position: string, units: string}>} */
    public function observe(string $network, string $address, string $txid, string $contract, string $genesis, int $minimum): array
    {
        if (! in_array($network, ['tron-mainnet', 'tron-shasta'], true) || $minimum < 1 || $minimum > 100000) {
            throw new DomainException('Invalid TRON verification policy.');
        }
        $txid = $this->hash($txid);
        $recipient = CryptoTronAddress::hex($address);
        $contractHex = CryptoTronAddress::hex($contract);
        if ($network === 'tron-mainnet' && $contract !== 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t') {
            throw new DomainException('Unsupported mainnet USDT contract.');
        }
        $root = $this->rpc->tron($network, 'wallet', 'getblockbynum', ['num' => 0]);
        if ($this->hash($root['blockID'] ?? null) !== $this->hash($genesis)) {
            throw new DomainException('TRON genesis does not match the frozen network.');
        }
        $info = $this->rpc->tron($network, 'wallet', 'gettransactioninfobyid', ['value' => $txid]);
        if ($info === []) {
            return ['state' => 'pending', 'confirmed' => false, 'transfers' => []];
        }
        if ($this->hash($info['id'] ?? null) !== $txid) {
            throw new DomainException('TRON receipt identity mismatch.');
        }
        $height = $this->integer($info['blockNumber'] ?? null);
        $timestamp = $this->integer($info['blockTimeStamp'] ?? null);
        $block = $this->rpc->tron($network, 'wallet', 'getblockbynum', ['num' => $height]);
        $header = $this->block($block);
        if ($header['height'] !== $height || $header['timestamp'] !== $timestamp
            || ! is_array($block['transactions'] ?? null) || ! array_is_list($block['transactions'])
            || ! in_array($txid, array_column($block['transactions'], 'txID'), true)) {
            throw new DomainException('TRON receipt is not in the canonical block.');
        }
        $observation = ['state' => 'mined', 'confirmed' => false, 'block' => [
            'hash' => $header['hash'], 'height' => $height, 'time' => CarbonImmutable::createFromTimestampMs($timestamp),
        ], 'transfers' => []];
        $tx = $this->rpc->tron($network, 'wallet', 'gettransactionbyid', ['value' => $txid]);
        $raw = $tx['raw_data_hex'] ?? null;
        if ($this->hash($tx['txID'] ?? null) !== $txid || ! is_string($raw)
            || strlen($raw) > 2000000 || strlen($raw) % 2 !== 0 || ! ctype_xdigit($raw) || hash('sha256', hex2bin($raw)) !== $txid) {
            throw new DomainException('TRON transaction body does not match the ID.');
        }
        $result = $info['receipt']['result'] ?? null;
        if ($result === null && ($info['log'] ?? []) === []) {
            return $observation;
        }
        if (! is_string($result) || $result === '' || ! is_array($tx['ret'] ?? null) || ! array_is_list($tx['ret']) || $tx['ret'] === []) {
            throw new DomainException('TRON execution result is unavailable.');
        }
        foreach ($tx['ret'] as $ret) {
            if (! is_array($ret) || ($ret['contractRet'] ?? null) !== $result) {
                throw new DomainException('TRON transaction and receipt execution results disagree.');
            }
        }
        if ($result !== 'SUCCESS') {
            return [...$observation, 'state' => 'failed'];
        }
        $observation['transfers'] = $this->transfers($info, $recipient, $contractHex);
        if ($observation['transfers'] === []) {
            return $observation;
        }
        $head = $this->block($this->rpc->tron($network, 'wallet', 'getnowblock'));
        $solidHead = $this->block($this->rpc->tron($network, 'walletsolidity', 'getnowblock'));
        if ($head['height'] < $height || $solidHead['height'] > $head['height']) {
            throw new DomainException('TRON chain views disagree.');
        }
        if ($minimum <= $head['height'] - $height + 1 && $solidHead['height'] >= $height) {
            $solidBlock = $this->block($this->rpc->tron($network, 'walletsolidity', 'getblockbynum', ['num' => $height]));
            if ($solidBlock['hash'] !== $header['hash'] || $solidBlock['height'] !== $height || $solidBlock['timestamp'] !== $timestamp) {
                return ['state' => 'reorg', 'confirmed' => false, 'transfers' => []];
            }
            $solidInfo = $this->rpc->tron($network, 'walletsolidity', 'gettransactioninfobyid', ['value' => $txid]);
            if ($solidInfo === []) {
                return $observation;
            }
            if ($this->hash($solidInfo['id'] ?? null) !== $txid || ($solidInfo['blockNumber'] ?? null) !== $height
                || ($solidInfo['blockTimeStamp'] ?? null) !== $timestamp || ($solidInfo['receipt']['result'] ?? null) !== 'SUCCESS'
                || $this->transfers($solidInfo, $recipient, $contractHex) !== $observation['transfers']) {
                throw new DomainException('Solidified TRON receipt does not match the observed transfer.');
            }
            $observation['confirmed'] = true;
        }
        $canonical = $this->block($this->rpc->tron($network, 'wallet', 'getblockbynum', ['num' => $height]));
        if ($canonical['hash'] !== $header['hash'] || $canonical['height'] !== $height) {
            return ['state' => 'reorg', 'confirmed' => false, 'transfers' => []];
        }

        return $observation;
    }

    /** @param array<string, mixed> $info
     * @return list<array{position: string, units: string}>
     */
    private function transfers(array $info, string $recipient, string $contract): array
    {
        $logs = $info['log'] ?? [];
        if (! is_array($logs) || ! array_is_list($logs) || count($logs) > 10000) {
            throw new DomainException('Invalid TRON logs.');
        }
        $transfers = [];
        foreach ($logs as $index => $log) {
            if (! is_array($log)) {
                throw new DomainException('Invalid TRON log.');
            }
            if (CryptoTronAddress::logAddress($log['address'] ?? null) !== $contract) {
                continue;
            }
            if (! is_array($log['topics'] ?? null) || ! array_is_list($log['topics'])) {
                throw new DomainException('Invalid TRON log topics.');
            }
            if (strtolower($log['topics'][0] ?? '') !== self::TRANSFER) {
                continue;
            }
            if (count($log['topics']) !== 3) {
                throw new DomainException('Invalid TRC20 Transfer topic count.');
            }
            $from = '41'.substr(CryptoEvm::topicAddress('0x'.$log['topics'][1]), 2);
            $to = '41'.substr(CryptoEvm::topicAddress('0x'.$log['topics'][2]), 2);
            $units = CryptoEvm::word('0x'.($log['data'] ?? ''));
            if ($to === $recipient && $from !== $recipient && $units !== '0') {
                $transfers[] = ['position' => 'log:'.$index, 'units' => $units];
            }
        }

        return $transfers;
    }

    /** @param array<string, mixed> $block
     * @return array{hash: string, height: int, timestamp: int}
     */
    private function block(array $block): array
    {
        $hash = $this->hash($block['blockID'] ?? null);
        $height = $this->integer($block['block_header']['raw_data']['number'] ?? null);
        $timestamp = $this->integer($block['block_header']['raw_data']['timestamp'] ?? null);
        if (substr($hash, 0, 16) !== sprintf('%016x', $height) || $timestamp < 1 || $timestamp > now()->getTimestampMs() + 300000) {
            throw new DomainException('Invalid TRON block identity or timestamp.');
        }

        return compact('hash', 'height', 'timestamp');
    }

    private function hash(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-fA-F0-9]{64}$/D', $value)) {
            throw new DomainException('Invalid TRON hash.');
        }

        return strtolower($value);
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new DomainException('Invalid TRON integer.');
        }

        return $value;
    }
}
