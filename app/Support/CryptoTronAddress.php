<?php

namespace App\Support;

use DomainException;

final class CryptoTronAddress
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function hex(string $base58): string
    {
        if (! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $base58)) {
            throw new DomainException('Invalid TRON Base58Check address.');
        }
        $number = '0';
        foreach (str_split($base58) as $character) {
            $number = bcadd(bcmul($number, '58', 0), (string) strpos(self::ALPHABET, $character), 0);
        }
        $bytes = '';
        while (bccomp($number, '0', 0) > 0) {
            $bytes = chr((int) bcmod($number, '256', 0)).$bytes;
            $number = bcdiv($number, '256', 0);
        }
        $payload = substr($bytes, 0, 21);
        if (strlen($bytes) !== 25 || ord($payload[0]) !== 0x41
            || ! hash_equals(substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4), substr($bytes, 21))) {
            throw new DomainException('Invalid TRON address checksum.');
        }

        return bin2hex($payload);
    }

    public static function logAddress(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(?:41)?[0-9a-fA-F]{40}$/D', $value)) {
            throw new DomainException('Invalid TRON log address.');
        }

        return '41'.strtolower(substr($value, -40));
    }
}
