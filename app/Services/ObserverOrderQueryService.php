<?php

namespace App\Services;

use App\Models\Observer;
use Illuminate\Database\Eloquent\Builder;

class ObserverOrderQueryService
{
    /** 按显示金额反推原币金额区间，保留 scaleAmount() 的两位截断口径。 */
    public function filterByDisplayedAmount(Builder $query, Observer $observer, string $amount): Builder
    {
        $amount = trim($amount);

        if ($amount === '') {
            return $query;
        }

        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) {
            return $query->whereRaw('1 = 0');
        }

        $ratioUnits = bcmul((string) $observer->amount_display_ratio, '100', 0);

        if (bccomp($ratioUnits, '0', 0) === 0) {
            return bccomp($amount, '0', 2) === 0 ? $query : $query->whereRaw('1 = 0');
        }

        $displayCents = bcmul($amount, '100', 0);
        $rounding = bcsub($ratioUnits, '1', 0);
        $minimumCents = bcdiv(bcadd(bcmul($displayCents, '10000', 0), $rounding, 0), $ratioUnits, 0);
        $maximumCents = bcdiv(bcadd(bcmul(bcadd($displayCents, '1', 0), '10000', 0), $rounding, 0), $ratioUnits, 0);

        return $query
            ->where('orders.amount', '>=', bcdiv($minimumCents, '100', 2))
            ->where('orders.amount', '<', bcdiv($maximumCents, '100', 2));
    }
}
