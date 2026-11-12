<?php

namespace App\Support;

use DomainException;

/** Geth callTracer paths are stable transaction-local economic identities. */
final class CryptoEvmTrace
{
    public static function assertRoot(array $root, array $tx): void
    {
        if (CryptoEvm::address($root['from'] ?? null) !== CryptoEvm::address($tx['from'] ?? null)
            || (isset($tx['to']) && CryptoEvm::address($root['to'] ?? null) !== CryptoEvm::address($tx['to']))
            || CryptoEvm::quantity($root['value'] ?? null) !== CryptoEvm::quantity($tx['value'] ?? null)
            || ! in_array($root['type'] ?? null, isset($tx['to']) ? ['CALL'] : ['CREATE', 'CREATE2'], true)) {
            throw new DomainException('Trace root does not match its transaction.');
        }
    }

    /** @return list<array{recipient: string, position: string, units: string}> */
    public static function transfers(array $trace): array
    {
        $result = [];
        $count = 0;
        self::walk($trace, [], false, $result, $count);

        return $result;
    }

    /** @param list<int> $path
     * @param  list<array{recipient: string, position: string, units: string}>  $result
     */
    private static function walk(array $frame, array $path, bool $reverted, array &$result, int &$count): void
    {
        if (++$count > 10000 || count($path) > 24) {
            throw new DomainException('Trace exceeds the supported bounds.');
        }
        $type = $frame['type'] ?? null;
        if (! in_array($type, ['CALL', 'CALLCODE', 'DELEGATECALL', 'STATICCALL', 'CREATE', 'CREATE2', 'SELFDESTRUCT'], true)) {
            throw new DomainException('Unsupported EVM trace frame.');
        }
        $reverted = $reverted || isset($frame['error']);
        $from = CryptoEvm::address($frame['from'] ?? null);
        $to = isset($frame['to']) ? CryptoEvm::address($frame['to']) : null;
        $movesValue = in_array($type, ['CALL', 'CREATE', 'CREATE2', 'SELFDESTRUCT'], true);
        $units = $movesValue ? CryptoEvm::quantity($frame['value'] ?? null) : '0';
        if (! $reverted && $movesValue && $to === null) {
            throw new DomainException('Successful value trace has no recipient.');
        }
        if (! $reverted && $movesValue && $to !== $from && $units !== '0') {
            $result[] = ['recipient' => $to, 'position' => $path === [] ? 'native:0' : 'trace:'.implode('.', $path), 'units' => $units];
        }
        $children = $frame['calls'] ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw new DomainException('Invalid trace children.');
        }
        foreach ($children as $index => $child) {
            if (! is_array($child)) {
                throw new DomainException('Invalid trace child.');
            }
            self::walk($child, [...$path, $index], $reverted, $result, $count);
        }
    }
}
