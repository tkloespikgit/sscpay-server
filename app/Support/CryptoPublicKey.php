<?php

namespace App\Support;

use InvalidArgumentException;

/** Parses public BIP32 metadata only; never accepts private extended keys or seeds. */
final class CryptoPublicKey
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function payload(string $key): string
    {
        if (! preg_match('/^(xpub|tpub)[1-9A-HJ-NP-Za-km-z]{107}$/D', $key)) {
            throw new InvalidArgumentException('Only a Base58Check public extended key is accepted.');
        }
        $number = '0';
        foreach (str_split($key) as $character) {
            $number = bcadd(bcmul($number, '58', 0), (string) strpos(self::ALPHABET, $character), 0);
        }
        $decoded = '';
        while (bccomp($number, '0', 0) > 0) {
            $decoded = chr((int) bcmod($number, '256', 0)).$decoded;
            $number = bcdiv($number, '256', 0);
        }
        $payload = substr($decoded, 0, -4);
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (strlen($decoded) !== 82 || ! hash_equals($checksum, substr($decoded, -4))
            || ! in_array(bin2hex(substr($payload, 0, 4)), ['0488b21e', '043587cf'], true)
            || ! in_array(ord($payload[45]), [2, 3], true)) {
            throw new InvalidArgumentException('Invalid public extended key checksum or payload.');
        }

        return $payload;
    }

    public static function fingerprint(string $family, string $key): string
    {
        return hash('sha256', $family.':'.bin2hex(substr(self::payload($key), 13)));
    }
}
