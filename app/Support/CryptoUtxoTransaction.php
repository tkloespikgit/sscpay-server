<?php

namespace App\Support;

use DomainException;

/** Bounded Bitcoin/Litecoin legacy and SegWit decoding; amounts come from uint64 bytes. */
final class CryptoUtxoTransaction
{
    private int $offset = 0;

    private function __construct(private readonly string $bytes) {}

    /** @return array{txid: string, coinbase: bool, inputs: list<array{txid: string, vout: int}>, outputs: list<array{units: string, script: string}>} */
    public static function decode(mixed $hex): array
    {
        if (! is_string($hex) || strlen($hex) < 20 || strlen($hex) > 8 * 1024 * 1024
            || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            throw new DomainException('Invalid serialized UTXO transaction.');
        }

        return (new self(hex2bin($hex)))->parse();
    }

    /** @return array{txid: string, coinbase: bool, inputs: list<array{txid: string, vout: int}>, outputs: list<array{units: string, script: string}>} */
    private function parse(): array
    {
        $stripped = $this->take(4);
        $witness = false;
        if (ord($this->bytes[$this->offset]) === 0) {
            $this->take(1);
            if ($this->take(1) !== "\x01") {
                throw new DomainException('Unsupported witness or Litecoin extension flags.');
            }
            $witness = true;
        }
        $start = $this->offset;
        $inputCount = $this->compact();
        if ($inputCount < 1) {
            throw new DomainException('Transaction has no inputs.');
        }
        $inputs = [];
        $seen = [];
        for ($i = 0; $i < $inputCount; $i++) {
            $txid = bin2hex(strrev($this->take(32)));
            $vout = (int) self::littleEndian($this->take(4));
            $key = $txid.':'.$vout;
            if (isset($seen[$key])) {
                throw new DomainException('Duplicate UTXO input.');
            }
            $seen[$key] = true;
            $inputs[] = compact('txid', 'vout');
            $this->take($this->compact());
            $this->take(4);
        }
        $outputCount = $this->compact();
        if ($outputCount < 1) {
            throw new DomainException('Transaction has no outputs.');
        }
        $outputs = [];
        for ($i = 0; $i < $outputCount; $i++) {
            $units = self::littleEndian($this->take(8));
            $script = bin2hex($this->take($this->compact()));
            $outputs[] = compact('units', 'script');
        }
        $stripped .= substr($this->bytes, $start, $this->offset - $start);
        if ($witness) {
            $nonempty = false;
            for ($i = 0; $i < $inputCount; $i++) {
                $items = $this->compact();
                $nonempty = $nonempty || $items > 0;
                for ($j = 0; $j < $items; $j++) {
                    $this->take($this->compact());
                }
            }
            if (! $nonempty) {
                throw new DomainException('Superfluous witness encoding.');
            }
        }
        $stripped .= $this->take(4);
        if ($this->offset !== strlen($this->bytes)) {
            throw new DomainException('Trailing transaction data or unsupported extension.');
        }
        $txid = bin2hex(strrev(hash('sha256', hash('sha256', $stripped, true), true)));
        $coinbase = count($inputs) === 1 && $inputs[0]['txid'] === str_repeat('0', 64) && $inputs[0]['vout'] === 4294967295;

        return compact('txid', 'coinbase', 'inputs', 'outputs');
    }

    private function compact(): int
    {
        $tag = ord($this->take(1));
        if ($tag < 253) {
            return $tag;
        }
        $size = match ($tag) {
            253 => 2, 254 => 4, 255 => 8
        };
        $value = self::littleEndian($this->take($size));
        $minimum = match ($tag) {
            253 => '253', 254 => '65536', 255 => '4294967296'
        };
        if (bccomp($value, $minimum, 0) < 0 || bccomp($value, '100000', 0) > 0) {
            throw new DomainException('Noncanonical or oversized compact integer.');
        }

        return (int) $value;
    }

    private function take(int $length): string
    {
        if ($length < 0 || $length > strlen($this->bytes) - $this->offset) {
            throw new DomainException('Truncated UTXO transaction.');
        }
        $result = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $result;
    }

    private static function littleEndian(string $bytes): string
    {
        $result = '0';
        foreach (str_split(strrev($bytes)) as $byte) {
            $result = bcadd(bcmul($result, '256', 0), (string) ord($byte), 0);
        }

        return CryptoAmount::units($result);
    }
}
