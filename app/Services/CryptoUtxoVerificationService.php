<?php

namespace App\Services;

use App\Support\CryptoAssetRegistry;
use App\Support\CryptoUtxoTransaction;
use Carbon\CarbonImmutable;
use DomainException;

final class CryptoUtxoVerificationService
{
    public function __construct(private readonly TatumChainReadClient $rpc, private readonly CryptoAssetRegistry $assets) {}

    /** @return array{state: string, confirmed: bool, block?: array{hash: string, height: int, time: CarbonImmutable}, transfers: list<array{position: string, units: string}>} */
    public function observe(string $network, string $asset, string $address, string $txid, string $genesis, int $minimum): array
    {
        if (! in_array($network, ['bitcoin-mainnet', 'bitcoin-testnet', 'litecoin-mainnet', 'litecoin-testnet'], true)
            || $asset !== (str_starts_with($network, 'bitcoin-') ? 'BTC' : 'LTC') || $minimum < 1 || $minimum > 100000) {
            throw new DomainException('Invalid UTXO verification policy.');
        }
        $txid = $this->hash($txid);
        if ($this->hash($this->rpc->utxo($network, 'getblockhash', [0])) !== $this->hash($genesis)) {
            throw new DomainException('UTXO genesis does not match the frozen network.');
        }
        $info = $this->rpc->utxo($network, 'getblockchaininfo');
        $expectedChains = str_ends_with($network, '-mainnet') ? ['main'] : ['test', 'testnet4'];
        if (! is_array($info) || ! in_array($info['chain'] ?? null, $expectedChains, true) || ($info['initialblockdownload'] ?? null) !== false) {
            throw new DomainException('UTXO node is not ready on the expected network.');
        }
        $tip = $this->integer($info['blocks'] ?? null);
        $validation = $this->rpc->utxo($network, 'validateaddress', [$address]);
        if (! is_array($validation) || ($validation['isvalid'] ?? null) !== true || ! is_string($validation['scriptPubKey'] ?? null)
            || ! is_string($validation['address'] ?? null)
            || $this->assets->addressKey($network, $validation['address']) !== $this->assets->addressKey($network, $address)
            || strlen($validation['scriptPubKey']) > 20000 || strlen($validation['scriptPubKey']) % 2 !== 0
            || ! ctype_xdigit($validation['scriptPubKey'])) {
            throw new DomainException('UTXO address script could not be validated.');
        }
        $script = strtolower($validation['scriptPubKey']);
        $tx = $this->rpc->utxo($network, 'getrawtransaction', [$txid, true]);
        if ($tx === null || (is_array($tx) && ! isset($tx['blockhash']))) {
            return ['state' => 'pending', 'confirmed' => false, 'transfers' => []];
        }
        if (! is_array($tx) || $this->hash($tx['txid'] ?? null) !== $txid) {
            throw new DomainException('UTXO transaction identity mismatch.');
        }
        $decoded = CryptoUtxoTransaction::decode($tx['hex'] ?? null);
        if ($decoded['txid'] !== $txid || $decoded['coinbase']) {
            throw new DomainException('Invalid payment transaction or unsupported coinbase.');
        }
        $blockHash = $this->hash($tx['blockhash']);
        $block = $this->rpc->utxo($network, 'getblock', [$blockHash, 1]);
        if (! is_array($block) || $this->hash($block['hash'] ?? null) !== $blockHash) {
            throw new DomainException('UTXO block is unavailable.');
        }
        $height = $this->integer($block['height'] ?? null);
        if ($this->hash($this->rpc->utxo($network, 'getblockhash', [$height])) !== $blockHash) {
            return ['state' => 'reorg', 'confirmed' => false, 'transfers' => []];
        }
        if (! is_array($block['tx'] ?? null) || ! array_is_list($block['tx']) || ! in_array($txid, $block['tx'], true)) {
            throw new DomainException('UTXO transaction is absent from the canonical block.');
        }
        $timestamp = $this->integer($block['time'] ?? null);
        if ($timestamp < 1 || $timestamp > now()->getTimestamp() + 300 || $tip < $height) {
            throw new DomainException('UTXO block time or chain height is invalid.');
        }
        $observation = ['state' => 'mined', 'confirmed' => false,
            'block' => ['hash' => $blockHash, 'height' => $height, 'time' => CarbonImmutable::createFromTimestampUTC($timestamp)], 'transfers' => []];
        $maxMoney = $asset === 'BTC' ? '2100000000000000' : '8400000000000000';
        $total = '0';
        foreach ($decoded['outputs'] as $index => $output) {
            $total = bcadd($total, $output['units'], 0);
            if (bccomp($total, $maxMoney, 0) > 0) {
                throw new DomainException('UTXO value exceeds the asset supply.');
            }
            if ($output['script'] === $script && $output['units'] !== '0') {
                $observation['transfers'][] = ['position' => 'vout:'.$index, 'units' => $output['units']];
            }
        }
        if ($observation['transfers'] === []) {
            return $observation;
        }
        $previousIds = array_unique(array_column($decoded['inputs'], 'txid'));
        if (count($previousIds) > 16) {
            throw new DomainException('UTXO input verification exceeds the bounded read budget.');
        }
        $previous = [];
        foreach ($previousIds as $previousId) {
            $data = $this->rpc->utxo($network, 'getrawtransaction', [$previousId, true]);
            $parsed = CryptoUtxoTransaction::decode(is_array($data) ? ($data['hex'] ?? null) : null);
            if ($parsed['txid'] !== $previousId) {
                throw new DomainException('Previous UTXO transaction is unavailable or mismatched.');
            }
            $previous[$previousId] = $parsed;
        }
        foreach ($decoded['inputs'] as $input) {
            $prevout = $previous[$input['txid']]['outputs'][$input['vout']] ?? null;
            if ($prevout === null) {
                throw new DomainException('Previous UTXO output is unavailable.');
            }
            if ($prevout['script'] === $script) {
                $observation['transfers'] = [];

                return $observation;
            }
        }
        $finalInfo = $this->rpc->utxo($network, 'getblockchaininfo');
        if (! is_array($finalInfo) || ! in_array($finalInfo['chain'] ?? null, $expectedChains, true)
            || ($finalInfo['initialblockdownload'] ?? null) !== false || $this->integer($finalInfo['blocks'] ?? null) < $height) {
            throw new DomainException('UTXO head recheck is unavailable.');
        }
        if ($this->hash($this->rpc->utxo($network, 'getblockhash', [$height])) !== $blockHash) {
            return ['state' => 'reorg', 'confirmed' => false, 'transfers' => []];
        }
        $observation['confirmed'] = $minimum <= $finalInfo['blocks'] - $height + 1;

        return $observation;
    }

    private function hash(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-fA-F0-9]{64}$/D', $value)) {
            throw new DomainException('Invalid UTXO hash.');
        }

        return strtolower($value);
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new DomainException('Invalid UTXO integer.');
        }

        return $value;
    }
}
