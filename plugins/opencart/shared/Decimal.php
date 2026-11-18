<?php
namespace Sscpay\OpenCart;

use InvalidArgumentException;

/** Decimal strings without a BCMath requirement or floating point arithmetic. */
final class Decimal {
    public static function money($value): string {
        return self::format(self::scaled($value, 2));
    }

    public static function cents($value): string {
        if (!is_string($value) || !preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid protocol amount.');
        }
        return self::scaled($value, 2);
    }

    public static function convert($value, $rate): string {
        $left = self::scaled($value, 8);
        $right = self::scaled($rate, 8);
        $product = str_pad(self::multiply($left, $right), 15, '0', STR_PAD_LEFT);
        $rounded = substr($product, 0, -14);
        if ((int)substr($product, -14, 1) >= 5) {
            $rounded = self::add($rounded, '1');
        }
        return self::format($rounded);
    }

    public static function scaled($value, int $scale): string {
        $value = (string)$value;
        if (!preg_match('/^\d{1,18}(?:\.\d{1,12})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid decimal.');
        }
        $parts = explode('.', $value, 2);
        $fraction = str_pad($parts[1] ?? '', $scale + 1, '0');
        $result = self::trim($parts[0] . substr($fraction, 0, $scale));
        return (int)$fraction[$scale] >= 5 ? self::add($result, '1') : $result;
    }

    public static function format(string $cents): string {
        $value = str_pad(self::trim($cents), 3, '0', STR_PAD_LEFT);
        return substr($value, 0, -2) . '.' . substr($value, -2);
    }

    public static function compare(string $left, string $right): int {
        $left = self::trim($left);
        $right = self::trim($right);
        return strlen($left) === strlen($right) ? strcmp($left, $right) : strlen($left) <=> strlen($right);
    }

    public static function add(string $left, string $right): string {
        $result = '';
        $carry = 0;
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int)$left[$i] : 0) + ($j >= 0 ? (int)$right[$j] : 0) + $carry;
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }
        return self::trim($result);
    }

    public static function subtract(string $left, string $right): string {
        if (self::compare($left, $right) < 0) {
            throw new InvalidArgumentException('Negative amount.');
        }
        $result = '';
        $borrow = 0;
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0; $i--, $j--) {
            $digit = (int)$left[$i] - ($j >= 0 ? (int)$right[$j] : 0) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = ($digit + ($borrow ? 10 : 0)) . $result;
        }
        return self::trim($result);
    }

    public static function multiply(string $left, string $right): string {
        $result = '0';
        foreach (str_split($right) as $digit) {
            $result .= '0';
            for ($i = 0; $i < (int)$digit; $i++) {
                $result = self::add($result, $left);
            }
        }
        return self::trim($result);
    }

    private static function trim(string $value): string {
        return ltrim($value, '0') ?: '0';
    }
}
