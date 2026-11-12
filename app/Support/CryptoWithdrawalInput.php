<?php

namespace App\Support;

use App\Exceptions\BalanceOperationException;
use DomainException;
use InvalidArgumentException;

final class CryptoWithdrawalInput
{
    public static function quantity(string $value, int $decimals, bool $positive = false): string
    {
        try {
            $units = CryptoAmount::parse($value, $decimals);
            if ($positive && $units === '0') {
                throw new InvalidArgumentException;
            }

            return $units;
        } catch (InvalidArgumentException) {
            throw new BalanceOperationException(__('crypto.finance.errors.quantity', ['decimals' => $decimals]));
        }
    }

    /** Local format validation only. Offline handlers must verify the address in their wallet. */
    public static function address(string $network, string $address): string
    {
        $valid = match ($network) {
            'ethereum-mainnet', 'ethereum-sepolia' => preg_match('/^0x[0-9a-fA-F]{40}$/D', $address)
                && strtolower($address) !== '0x'.str_repeat('0', 40),
            'tron-mainnet', 'tron-shasta' => self::tronAddress($address),
            'bitcoin-mainnet' => preg_match('/^(?:[13][1-9A-HJ-NP-Za-km-z]{25,34}|bc1[ac-hj-np-z02-9]{11,87})$/D', $address),
            'bitcoin-testnet' => preg_match('/^(?:[mn2][1-9A-HJ-NP-Za-km-z]{25,34}|tb1[ac-hj-np-z02-9]{11,87})$/D', $address),
            'litecoin-mainnet' => preg_match('/^(?:[LM3][1-9A-HJ-NP-Za-km-z]{25,34}|ltc1[ac-hj-np-z02-9]{11,86})$/D', $address),
            'litecoin-testnet' => preg_match('/^(?:[mnQ2][1-9A-HJ-NP-Za-km-z]{25,34}|tltc1[ac-hj-np-z02-9]{11,85})$/D', $address),
            default => false,
        };
        if (! $valid) {
            throw new BalanceOperationException(__('crypto.finance.errors.address'));
        }

        return $address;
    }

    public static function text(?string $text, int $maximum, bool $required = false): ?string
    {
        $text = trim($text ?? '');
        if (($required && $text === '') || mb_strlen($text) > $maximum || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $text)) {
            throw new BalanceOperationException(__('crypto.finance.errors.details'));
        }

        return $text === '' ? null : $text;
    }

    private static function tronAddress(string $address): bool
    {
        try {
            CryptoTronAddress::hex($address);

            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
