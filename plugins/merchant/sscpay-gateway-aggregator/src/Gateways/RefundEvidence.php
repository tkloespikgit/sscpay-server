<?php

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\CurrencyManager;

if (!defined('ABSPATH')) {
    exit;
}

/** Financial fields are decimal strings throughout, including JSON numbers from Airwallex. */
final class RefundEvidence
{
    public static function decode(string $body): array
    {
        $json = preg_replace_callback('/"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*F)|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', static function (array $match): string {
            return '"' . $match[0] . '"';
        }, $body);
        $object = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($object)) {
            throw new \RuntimeException('退款数据不是有效 JSON 对象');
        }
        return $object;
    }

    public static function refs(...$values): array
    {
        $refs = [];
        foreach ($values as $value) {
            $value = is_array($value) ? ($value['id'] ?? null) : $value;
            if (is_string($value) && preg_match('/\A[A-Za-z0-9_:.\-]{1,255}\z/', $value)) {
                $refs[] = $value;
            }
        }
        return array_values(array_unique($refs));
    }

    public static function principal($value, string $currency, ?string $minorProvider = null): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (!preg_match('/\A[A-Z]{3}\z/', $currency)) {
            return null;
        }
        if ($minorProvider !== null) {
            if (!preg_match('/\A\d{1,18}\z/', $value)) {
                return null;
            }
            $scale = CurrencyManager::isZeroDecimal($currency) ? 0 : 2;
            if ($minorProvider === 'stripe' && in_array($currency, ['UGX', 'ISK'], true)) {
                $scale = 2;
            } elseif ($minorProvider === 'antom' && in_array($currency, ['ISK', 'UYI'], true)) {
                $scale = 0;
            } elseif ($minorProvider === 'antom' && $currency === 'MGA') {
                $scale = 2;
            } elseif ($minorProvider === 'antom' && in_array($currency, ['CLF', 'UYW'], true)) {
                $scale = 4;
            } elseif (in_array($currency, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], true)) {
                $scale = 3;
            }
            if ($scale > 0) {
                $digits = str_pad($value, $scale + 1, '0', STR_PAD_LEFT);
                $value = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
            }
        }
        if ($minorProvider === null && preg_match('/\A(\d{1,16})(?:\.(\d{1,16}))?[eE]([+-]?\d{1,2})\z/', $value, $scientific)) {
            $digits = $scientific[1] . ($scientific[2] ?? '');
            $point = strlen($scientific[1]) + (int) $scientific[3];
            if ($point <= 0) {
                $value = '0.' . str_repeat('0', -$point) . $digits;
            } elseif ($point < strlen($digits)) {
                $value = substr($digits, 0, $point) . '.' . substr($digits, $point);
            } else {
                $value = str_pad($digits, $point, '0');
            }
        }
        if (!preg_match('/\A(\d{1,13})(?:\.(\d{1,2})0*)?\z/', $value, $parts)) {
            return null;
        }
        $whole = ltrim($parts[1], '0');
        $amount = ($whole === '' ? '0' : $whole) . '.' . str_pad($parts[2] ?? '', 2, '0');
        return $amount === '0.00' ? null : $amount;
    }

    public static function time($value): ?string
    {
        if ((is_string($value) || is_int($value)) && preg_match('/\A[1-9]\d{8,9}\z/', (string) $value)) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $value);
        }
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})\z/i', $value)) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['error_count'] || $errors['warning_count'])) {
                return null;
            }
            return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function result(string $kind, array $evidence = [], array $refunds = []): array
    {
        return ['kind' => $kind, 'evidence' => $evidence, 'refunds' => $refunds];
    }

    public static function observation(string $kind, $id, $value, $currency, array $refs, $time, $status, array $extra = [], ?string $minorProvider = null): array
    {
        $currency = is_string($currency) ? strtoupper($currency) : '';
        $fact = [
            'refund_id' => self::refs($id)[0] ?? null,
            'amount' => self::principal($value, $currency, $minorProvider),
            'currency' => $currency,
            'payment_refs' => $refs,
            'refunded_at' => self::time($time),
            'provider_status' => is_string($status) ? $status : '',
        ] + $extra;
        if ($kind === 'succeeded' && (!$fact['refund_id'] || !$fact['amount'] || !$fact['refunded_at'] || !$refs
            || in_array($fact['refund_id'], $refs, true))) {
            $kind = 'lookup';
        }
        return self::result($kind, $fact, $kind === 'succeeded' ? [$fact] : []);
    }

    /** Keep verified siblings and expose outstanding/failed rows to the caller. */
    public static function collection(array $observations, array $refs): array
    {
        $refunds = [];
        $other = [];
        $kind = 'ignored';
        $priority = ['ignored' => 0, 'failed' => 1, 'pending' => 2, 'succeeded' => 3, 'lookup' => 4];
        foreach ($observations as $observation) {
            if ($priority[$observation['kind']] > $priority[$kind]) {
                $kind = $observation['kind'];
            }
            foreach ($observation['refunds'] as $fact) {
                $refunds[] = $fact;
            }
            if ($observation['kind'] !== 'succeeded') {
                $other[] = $observation['evidence'] + ['kind' => $observation['kind']];
            }
        }
        return self::result($kind, ['payment_refs' => $refs, 'observations' => $other], $refunds);
    }
}
