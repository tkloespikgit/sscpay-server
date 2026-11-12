<?php

namespace App\Services;

use App\Support\CryptoAmount;
use App\Support\CryptoAssetRegistry;
use InvalidArgumentException;

final class CryptoQuoteService
{
    public function __construct(private readonly CryptoAssetRegistry $assets) {}

    /**
     * Pure calculation; callers must supply a verified USD market price and the original fiat rate.
     *
     * @return array{version: int, network: string, asset: string, decimals: int, currency: string, fiat_amount: string, fiat_usd_rate: string, price_usd: string, markup_percent: string, fee_percent: string, fee_fixed_usd: string, due_units: string, fee_units: string, net_units: string, rounding: string}
     */
    public function calculate(string $assetKey, string $amount, string $currency, string $fiatUsdRate, string $priceUsd, string $markupPercent, string $feePercent, string $feeFixedUsd): array
    {
        $asset = $this->assets->get($assetKey);
        [$a, $ad] = CryptoAmount::fraction($amount);
        [$r, $rd] = CryptoAmount::fraction($fiatUsdRate);
        [$p, $pd] = CryptoAmount::fraction($priceUsd);
        [$m, $md] = CryptoAmount::fraction($markupPercent);
        [$f, $fd] = CryptoAmount::fraction($feePercent);

        if (! preg_match('/^[A-Z]{3}$/D', $currency) || $a === '0' || $r === '0' || $p === '0'
            || ($currency === 'USD' && bccomp($r, $rd, 0) !== 0)
            || bccomp($f, bcmul('100', $fd, 0), 0) >= 0
            || bccomp($m, bcmul('100', $md, 0), 0) > 0) {
            throw new InvalidArgumentException('Invalid crypto quote inputs.');
        }

        $scale = '1'.str_repeat('0', $asset['decimals']);
        $numerator = bcmul(bcmul(bcmul(bcmul($a, $r, 0), $pd, 0), bcadd(bcmul('100', $md, 0), $m, 0), 0), $scale, 0);
        $denominator = bcmul(bcmul(bcmul(bcmul($ad, $rd, 0), $p, 0), '100', 0), $md, 0);
        $due = CryptoAmount::ceilRatio($numerator, $denominator);

        $fee = $this->feeForGross($due, ['fee_percent' => $feePercent, 'fee_fixed_usd' => $feeFixedUsd,
            'price_usd' => $priceUsd, 'decimals' => $asset['decimals']]);

        if (bccomp($fee, $due, 0) >= 0) {
            throw new InvalidArgumentException('Crypto service fee must be less than the payment.');
        }

        return [
            'version' => 1,
            ...$asset,
            'currency' => $currency,
            'fiat_amount' => $amount,
            'fiat_usd_rate' => $fiatUsdRate,
            'price_usd' => $priceUsd,
            'markup_percent' => $markupPercent,
            'fee_percent' => $feePercent,
            'fee_fixed_usd' => $feeFixedUsd,
            'due_units' => $due,
            'fee_units' => $fee,
            'net_units' => bcsub($due, $fee, 0),
            'rounding' => 'due_ceil_fee_total_ceil_v1',
        ];
    }

    /** Apply the frozen contract once to cumulative actual receipts; never charge more than received. */
    public function feeForGross(string $gross, array $quote): string
    {
        CryptoAmount::units($gross);
        if ($gross === '0') {
            return '0';
        }
        [$f, $fd] = CryptoAmount::fraction($quote['fee_percent']);
        [$fixed, $fixedD] = CryptoAmount::fraction($quote['fee_fixed_usd']);
        [$p, $pd] = CryptoAmount::fraction($quote['price_usd']);
        $scale = '1'.str_repeat('0', $quote['decimals']);
        $percentD = bcmul('100', $fd, 0);
        $fixedDenominator = bcmul($fixedD, $p, 0);
        $numerator = bcadd(bcmul(bcmul($gross, $f, 0), $fixedDenominator, 0),
            bcmul(bcmul(bcmul($fixed, $pd, 0), $scale, 0), $percentD, 0), 0);
        $fee = CryptoAmount::ceilRatio($numerator, bcmul($percentD, $fixedDenominator, 0));

        return bccomp($fee, $gross, 0) > 0 ? $gross : $fee;
    }
}
