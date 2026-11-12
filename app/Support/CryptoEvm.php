<?php

namespace App\Support;

use DomainException;

/** Strict Ethereum encodings; quantities never pass through float or hexdec(). */
final class CryptoEvm
{
    public static function quantity(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^0x(?:0|[1-9a-fA-F][0-9a-fA-F]{0,63})$/D', $value)) {
            throw new DomainException('Invalid EVM quantity.');
        }

        return self::digits(substr($value, 2));
    }

    public static function word(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^0x[0-9a-fA-F]{64}$/D', $value)) {
            throw new DomainException('Invalid EVM word.');
        }

        return self::digits(substr($value, 2));
    }

    public static function integer(mixed $value): int
    {
        $number = self::quantity($value);
        if (bccomp($number, (string) PHP_INT_MAX, 0) > 0) {
            throw new DomainException('EVM integer exceeds the supported range.');
        }

        return (int) $number;
    }

    public static function hash(mixed $value): string
    {
        self::word($value);

        return strtolower($value);
    }

    public static function address(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^0x[0-9a-fA-F]{40}$/D', $value)) {
            throw new DomainException('Invalid EVM address.');
        }

        return strtolower($value);
    }

    public static function topicAddress(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^0x0{24}[0-9a-fA-F]{40}$/D', $value)) {
            throw new DomainException('Invalid EVM address topic.');
        }

        return '0x'.strtolower(substr($value, -40));
    }

    private static function digits(string $hex): string
    {
        $number = '0';
        foreach (str_split(strtolower($hex)) as $digit) {
            $number = bcadd(bcmul($number, '16', 0), (string) strpos('0123456789abcdef', $digit), 0);
        }

        return CryptoAmount::units($number);
    }
}
