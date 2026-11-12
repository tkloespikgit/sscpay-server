<?php

namespace App\Support;

use InvalidArgumentException;

final class CryptoAssetRegistry
{
    /** @return array{network: string, asset: string, decimals: int} */
    public function get(string $key): array
    {
        [$network, $asset] = array_pad(explode(':', $key, 2), 2, '');
        $decimals = match (true) {
            in_array($network, ['bitcoin-mainnet', 'bitcoin-testnet'], true) && $asset === 'BTC' => 8,
            in_array($network, ['litecoin-mainnet', 'litecoin-testnet'], true) && $asset === 'LTC' => 8,
            in_array($network, ['ethereum-mainnet', 'ethereum-sepolia'], true) && $asset === 'ETH' => 18,
            in_array($network, ['ethereum-mainnet', 'ethereum-sepolia', 'tron-mainnet', 'tron-shasta'], true) && $asset === 'USDT' => 6,
            default => throw new InvalidArgumentException('Unsupported crypto network or asset.'),
        };

        return compact('network', 'asset', 'decimals');
    }

    /** Input must already be verified by the chain adapter; this only canonicalizes its identity. */
    public function addressKey(string $network, string $address): string
    {
        if ($address === '' || trim($address) !== $address || strlen($address) > 128) {
            throw new InvalidArgumentException('Invalid crypto address.');
        }

        if (str_starts_with($network, 'ethereum-')) {
            if (! preg_match('/^0x[0-9a-fA-F]{40}$/D', $address)) {
                throw new InvalidArgumentException('Invalid Ethereum address.');
            }
            $address = strtolower($address);
        } elseif (preg_match('/^(bc1|tb1|ltc1|tltc1)/i', $address)) {
            if ($address !== strtolower($address) && $address !== strtoupper($address)) {
                throw new InvalidArgumentException('Mixed-case witness address.');
            }
            $address = strtolower($address);
        }

        return hash('sha256', $network.':'.$address);
    }
}
