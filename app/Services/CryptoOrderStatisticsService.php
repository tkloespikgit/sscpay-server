<?php

namespace App\Services;

use App\Support\CryptoAssetRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CryptoOrderStatisticsService
{
    /** Current recognized income for the selected orders, not turnover or a fiat cash balance. */
    public function summarize(Builder $orders): array
    {
        $rows = [];
        $filtered = (clone $orders)->reorder()->withoutEagerLoads()->where('orders.payment_type', 'crypto')->select('orders.id');
        $query = DB::query()->fromSub($filtered->toBase(), 'crypto_orders')
            ->join('crypto_ledger_entries as crypto_income', 'crypto_income.order_id', '=', 'crypto_orders.id')
            ->leftJoin('crypto_payment_resolutions as crypto_current', function ($join): void {
                $join->on('crypto_current.order_id', '=', 'crypto_orders.id')->where('crypto_current.id', '=', function ($sub): void {
                    $sub->from('crypto_payment_resolutions as crypto_latest')->selectRaw('MAX(crypto_latest.id)')
                        ->whereColumn('crypto_latest.order_id', 'crypto_orders.id')->whereIn('crypto_latest.action', ['accept', 'adjust']);
                });
            })
            ->select('crypto_orders.id', 'crypto_income.network', 'crypto_income.asset')
            ->selectRaw('COALESCE(crypto_current.gross_after_units, crypto_income.gross_units) AS gross')
            ->selectRaw('COALESCE(crypto_current.fee_after_units, crypto_income.fee_units) AS fee')
            ->selectRaw('COALESCE(crypto_current.net_after_units, crypto_income.amount_units) AS net');
        foreach ($query->lazyById(500, 'crypto_orders.id', 'id') as $record) {
            $totals = (array) $record;
            $key = $totals['network'].':'.$totals['asset'];
            $rows[$key] ??= ['network' => $totals['network'], 'asset' => $totals['asset'], 'count' => 0, 'gross' => '0', 'fee' => '0', 'net' => '0'];
            $rows[$key]['count']++;
            foreach (['gross', 'fee', 'net'] as $field) {
                $rows[$key][$field] = bcadd($rows[$key][$field], $totals[$field], 0);
            }
        }
        ksort($rows);
        foreach ($rows as $key => &$row) {
            $decimals = app(CryptoAssetRegistry::class)->get($key)['decimals'];
            foreach (['gross', 'fee', 'net'] as $field) {
                // A report total can exceed one address's uint256 cap. Format with exact division.
                $row[$field] = bcdiv($row[$field], '1'.str_repeat('0', $decimals), $decimals).' '.$row['asset'];
            }
        }

        return array_values($rows);
    }
}
