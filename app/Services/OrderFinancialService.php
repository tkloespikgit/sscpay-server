<?php

namespace App\Services;

use App\Exceptions\MinimumAmountNotMetException;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Support\Money;

class OrderFinancialService
{
    /** @return array<string, int|string> */
    public function snapshot(PaymentMethod $method, Merchant $merchant, string $amountUsd): array
    {
        $settings = $method->configurationForMerchant($merchant);
        $percentFee = Money::truncate(bcmul($amountUsd, bcdiv($settings['fee_percent'], '100', 8), 8));
        $fixedFee = Money::truncate($settings['fee_fixed']);
        $totalFee = bcadd($percentFee, $fixedFee, 2);
        if (bccomp($totalFee, $amountUsd, 2) > 0) {
            throw new MinimumAmountNotMetException($method->minTransactionAmount($merchant->id), $amountUsd);
        }

        return [
            'fee_percent_amount' => $percentFee,
            'fee_fixed_amount' => $fixedFee,
            'settlement_amount' => Money::truncate(bcsub($amountUsd, $totalFee, 8)),
            'reserve_percent' => $settings['reserve_percent'],
            'reserve_days' => $settings['reserve_days'],
            'refund_fee_snapshot' => $settings['refund_fee'],
            'chargeback_fee_snapshot' => $settings['chargeback_fee'],
            'settlement_mode' => $settings['settlement_mode'],
            'settlement_days' => $settings['settlement_days'],
        ];
    }
}
