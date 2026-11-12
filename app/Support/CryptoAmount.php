<?php

namespace App\Support;

use InvalidArgumentException;

final class CryptoAmount
{
    public const MAX_UNITS = '115792089237316195423570985008687907853269984665640564039457584007913129639935';

    public static function units(string $value): string
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,77})$/D', $value) || bccomp($value, self::MAX_UNITS, 0) > 0) {
            throw new InvalidArgumentException('Invalid unsigned crypto amount.');
        }

        return $value;
    }

    /** @return array{0: string, 1: string} Exact numerator and denominator. */
    public static function fraction(string $value): array
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,39})(?:\.([0-9]{1,36}))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Invalid decimal amount.');
        }

        $digits = $parts[2] ?? '';

        return [ltrim($parts[1].$digits, '0') ?: '0', '1'.str_repeat('0', strlen($digits))];
    }

    public static function ceilRatio(string $numerator, string $denominator): string
    {
        if (bccomp($denominator, '0', 0) <= 0 || bccomp($numerator, '0', 0) < 0) {
            throw new InvalidArgumentException('Invalid crypto ratio.');
        }

        $units = bcdiv($numerator, $denominator, 0);

        return self::units(bcmod($numerator, $denominator, 0) === '0' ? $units : bcadd($units, '1', 0));
    }

    public static function parse(string $value, int $decimals): string
    {
        if ($decimals < 0 || $decimals > 36 || ! preg_match('/^(0|[1-9][0-9]{0,77})(?:\.([0-9]{1,36}))?$/D', $value, $parts)
            || strlen($parts[2] ?? '') > $decimals) {
            throw new InvalidArgumentException('Invalid crypto quantity or precision.');
        }

        return self::units(ltrim($parts[1].str_pad($parts[2] ?? '', $decimals, '0'), '0') ?: '0');
    }

    public static function format(string $units, int $decimals): string
    {
        self::units($units);
        if ($decimals < 0 || $decimals > 36) {
            throw new InvalidArgumentException('Invalid crypto precision.');
        }

        return bcdiv($units, '1'.str_repeat('0', $decimals), $decimals);
    }
}
