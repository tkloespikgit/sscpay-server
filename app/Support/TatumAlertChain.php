<?php

namespace App\Support;

/** Notification chain names accepted by Tatum. Internal network ids stay unchanged. */
final class TatumAlertChain
{
    public static function name(string $network): string
    {
        return match ($network) {
            'litecoin-mainnet' => 'litecoin-core-mainnet',
            'litecoin-testnet' => 'litecoin-core-testnet',
            'tron-shasta' => 'tron-testnet',
            default => $network,
        };
    }

    public static function matches(string $network, mixed $remote): bool
    {
        return is_string($remote) && in_array($remote, [$network, self::name($network)], true);
    }
}
