<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Support\CryptoAmount;

final class CryptoOrderDisplay
{
    public const RELATIONS = ['cryptoIncome.cryptoPaymentRequest', 'latestCryptoRequest', 'latestCryptoResolution'];

    /** Current recognized amounts, preserving the original income voucher after corrections. */
    public static function totals(Order $order): ?array
    {
        $resolution = $order->latestCryptoResolution;
        $income = $order->cryptoIncome;
        if ($resolution) {
            return ['gross' => $resolution->gross_after_units, 'fee' => $resolution->fee_after_units,
                'net' => $resolution->net_after_units, 'network' => $resolution->network, 'asset' => $resolution->asset];
        }
        if ($income) {
            return ['gross' => $income->gross_units, 'fee' => $income->fee_units, 'net' => $income->amount_units,
                'network' => $income->network, 'asset' => $income->asset];
        }

        return null;
    }

    public static function amount(Order $order, string $field = 'net'): string
    {
        $totals = self::totals($order);
        $request = $order->cryptoIncome?->cryptoPaymentRequest ?? $order->latestCryptoRequest;
        if (! $request || ! $totals) {
            return __('crypto.order.uncredited');
        }

        return CryptoAmount::format($totals[$field], $request->decimals).' '.$totals['asset'];
    }

    public static function denomination(Order $order): string
    {
        $request = $order->cryptoIncome?->cryptoPaymentRequest ?? $order->latestCryptoRequest;

        return $request ? $request->asset.' / '.__('crypto.finance.networks.'.$request->network) : __('crypto.order.preparing');
    }

    public static function rate(Order $order): string
    {
        $quote = $order->cryptoIncome?->fee_snapshot ?? $order->latestCryptoRequest?->quote_snapshot;
        if (! $quote) {
            return '—';
        }

        return '1 '.$quote['currency'].' = '.$quote['fiat_usd_rate'].' USD; 1 '.$quote['asset'].' = '.$quote['price_usd'].' USD; '
            .__('crypto.order.markup', ['rate' => $quote['markup_percent']]);
    }

    public static function state(Order $order): string
    {
        if ($order->cryptoRequests->contains(fn ($r) => $r->review_reason !== null || $r->status === 'review')) {
            return __('crypto.order.review');
        }
        if ($order->latestCryptoResolution) {
            return $order->latestCryptoResolution->net_after_units === '0' ? __('crypto.order.reversed') : __('crypto.order.adjusted');
        }

        return $order->cryptoIncome ? __('crypto.finance.request_states.credited') : __('crypto.order.uncredited');
    }
}
