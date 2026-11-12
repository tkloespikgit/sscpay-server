<?php

namespace App\Support;

use DomainException;

final class CryptoVerificationPolicy
{
    public static function version(string $network): string
    {
        return match ($network) {
            'ethereum-mainnet', 'ethereum-sepolia' => 'evm-finalized-v1',
            'bitcoin-mainnet', 'bitcoin-testnet', 'litecoin-mainnet', 'litecoin-testnet' => 'utxo-canonical-v1',
            'tron-mainnet', 'tron-shasta' => 'tron-solidified-v1',
            default => throw new DomainException('Unsupported verification network.'),
        };
    }

    /** @return array{version: string, minimum_confirmations: mixed, genesis_hash?: mixed} */
    public static function snapshot(string $network): array
    {
        $version = self::version($network);
        $prefix = $version === 'evm-finalized-v1' ? 'evm' : ($version === 'utxo-canonical-v1' ? 'utxo' : 'tron');
        $policy = ['version' => $version, 'minimum_confirmations' => config('services.tatum.'.$prefix.'_min_confirmations.'.$network)];
        if ($version !== 'evm-finalized-v1') {
            $policy['genesis_hash'] = config('services.tatum.genesis_hashes.'.$network);
        } else {
            $policy['internal_transfers'] = true;
        }

        return $policy;
    }
}
