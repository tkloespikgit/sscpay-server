<?php

namespace App\Services;

use App\Exceptions\CombinedPaymentException;
use App\Exceptions\MinimumAmountNotMetException;
use App\Models\CombinedOrder;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Support\CryptoCheckoutGuard;
use Illuminate\Support\Collection;
use Random\Randomizer;

class CombinedPaymentRouter
{
    public function __construct(
        private readonly PaymentCapacityService $capacity,
        private readonly OrderFinancialService $financial,
        private readonly PaymentService $payments,
        private readonly Randomizer $randomizer = new Randomizer,
    ) {}

    /** 普通单可使用链上渠道及 CREATE/COPY；全部单笔限额不足且有法币渠道时尝试组合。 */
    public function required(PaymentGroup $group, string $totalUsd, ?string $cryptoTotalUsd = null): bool
    {
        $methods = $this->methods($group);

        return $methods->contains(fn (PaymentMethod $method): bool => $method->payment_type === 'fiat')
            && $methods->every(fn (PaymentMethod $method): bool => ! $method->isUnlimited('max_amount_per_transaction')
                && bccomp($method->payment_type === 'crypto' ? ($cryptoTotalUsd ?? $totalUsd) : $totalUsd,
                    (string) $method->max_amount_per_transaction, 2) > 0);
    }

    /** @return array{payment_method_id: int, payment_method: string, amount: string, converted_amount: string} */
    public function quote(CombinedOrder $parent): array
    {
        $group = PaymentGroup::query()->forMerchant($parent->merchant_id)
            ->where('is_active', true)->find($parent->payment_group_id);
        if (! $group) {
            throw new CombinedPaymentException('COMBINE_PAYMENT_UNAVAILABLE');
        }
        $merchant = Merchant::query()->withoutGlobalScopes()->findOrFail($parent->merchant_id);
        $methods = $this->methods($group);
        $supported = $methods->filter(fn (PaymentMethod $method): bool => $this->supportsProductMode($method));
        $previous = $supported->firstWhere('id', $this->previousPaymentMethodId($parent));
        if ($previous !== null) {
            if ($this->eligible($previous, $merchant, $parent->remaining_converted_amount)) {
                return $this->result($previous, $parent->remaining_amount, $parent->remaining_converted_amount);
            }
            $bounds = $this->splitBounds($parent, $previous, $merchant);
            if ($bounds !== null) {
                return $this->randomPayment($parent, $previous, $bounds);
            }
        }

        $full = $supported->filter(fn (PaymentMethod $method): bool => $this->eligible($method, $merchant, $parent->remaining_converted_amount));
        if ($full->isNotEmpty()) {
            $excluded = $methods->pluck('id')->diff($full->pluck('id'))->all();
            $method = $this->payments->resolvePaymentMethod($group, $parent->remaining_converted_amount, $excluded);

            return $this->result($method, $parent->remaining_amount, $parent->remaining_converted_amount);
        }

        $candidates = [];
        foreach ($supported as $method) {
            $bounds = $this->splitBounds($parent, $method, $merchant);
            if ($bounds !== null) {
                $candidates[] = ['method' => $method, 'bounds' => $bounds];
            }
        }
        usort($candidates, fn (array $a, array $b): int => bccomp((string) $b['method']->max_amount_per_transaction, (string) $a['method']->max_amount_per_transaction, 2)
                ?: $a['method']->id <=> $b['method']->id);
        if ($candidates === []) {
            throw new CombinedPaymentException('COMBINE_PAYMENT_UNAVAILABLE');
        }
        $selected = $candidates[$this->randomizer->getInt(0, min(3, count($candidates)) - 1)];

        return $this->randomPayment($parent, $selected['method'], $selected['bounds']);
    }

    public function supportsProductMode(PaymentMethod $method): bool
    {
        return $method->payment_type === 'fiat' && in_array($method->product_match_mode ?: PaymentMethod::MODE_MATCH,
            [PaymentMethod::MODE_MATCH, PaymentMethod::MODE_VIRTUAL], true);
    }

    public function convertedAmount(CombinedOrder $parent, string $amount): string
    {
        return bcsub(
            bcmul(bcadd($parent->paid_amount, $amount, 2), $parent->exchange_rate, 2),
            $parent->paid_converted_amount, 2,
        );
    }

    private function previousPaymentMethodId(CombinedOrder $parent): ?int
    {
        $quotedMethodId = $parent->current_quote['payment_method_id'] ?? null;
        if ($quotedMethodId !== null) {
            return (int) $quotedMethodId;
        }
        if (! $parent->exists) {
            return null;
        }
        $methodId = $parent->children()->forMerchant($parent->merchant_id)->withTrashed()
            ->whereNotNull('paid_at')->reorder()->orderByDesc('combined_sequence')->value('payment_method_id');

        return $methodId === null ? null : (int) $methodId;
    }

    /** @return array{int, int}|null */
    private function splitBounds(CombinedOrder $parent, PaymentMethod $method, Merchant $merchant): ?array
    {
        if ($method->isUnlimited('max_amount_per_transaction')
            || ! $this->capacity->permits($method, (string) $method->max_amount_per_transaction)) {
            return null;
        }

        return $this->amountBounds($parent, $method, $merchant);
    }

    /**
     * @param  array{int, int}  $bounds
     * @return array{payment_method_id: int, payment_method: string, amount: string, converted_amount: string}
     */
    private function randomPayment(CombinedOrder $parent, PaymentMethod $method, array $bounds): array
    {
        [$low, $high] = $bounds;
        $amount = bcdiv((string) $this->randomizer->getInt($low, $high), '100', 2);

        return $this->result($method, $amount, $this->convertedAmount($parent, $amount));
    }

    /** 将美元区间映射到原币整数分，二分边界避免汇率舍入越界。 @return array{int, int}|null */
    private function amountBounds(CombinedOrder $parent, PaymentMethod $method, Merchant $merchant): ?array
    {
        $limitCents = (int) bcmul((string) $method->max_amount_per_transaction, '100', 0);
        $minimum = bcdiv((string) intdiv($limitCents * 70 + 99, 100), '100', 2);
        $maximum = bcdiv((string) intdiv($limitCents * 90, 100), '100', 2);
        $remainingCents = (int) bcmul($parent->remaining_amount, '100', 0);
        $upper = $remainingCents - 1;
        if ($upper < 1 || bccomp($parent->exchange_rate, '0', 6) <= 0) {
            return null;
        }
        $lower = $this->firstCent($upper + 1, function (int $cents) use ($parent, $minimum, $method, $merchant): bool {
            $usd = $this->convertedAmount($parent, bcdiv((string) $cents, '100', 2));

            return bccomp($usd, $minimum, 2) >= 0 && bccomp($usd, '0', 2) > 0 && $this->feeAllowed($method, $merchant, $usd);
        });
        $end = $this->firstCent($upper + 1, fn (int $cents): bool => bccomp($this->convertedAmount($parent, bcdiv((string) $cents, '100', 2)), $maximum, 2) > 0) - 1;

        return $lower <= $end && $lower <= $upper ? [$lower, min($upper, $end)] : null;
    }

    private function firstCent(int $end, callable $predicate): int
    {
        $start = 1;
        while ($start < $end) {
            $middle = intdiv($start + $end, 2);
            if ($predicate($middle)) {
                $end = $middle;
            } else {
                $start = $middle + 1;
            }
        }

        return $start;
    }

    private function eligible(PaymentMethod $method, Merchant $merchant, string $amount): bool
    {
        return $this->capacity->permits($method, $amount) && $this->feeAllowed($method, $merchant, $amount);
    }

    private function feeAllowed(PaymentMethod $method, Merchant $merchant, string $amount): bool
    {
        try {
            $this->financial->snapshot($method, $merchant, $amount);

            return true;
        } catch (MinimumAmountNotMetException) {
            return false;
        }
    }

    private function methods(PaymentGroup $group): Collection
    {
        return $group->activePaymentMethods()->forMerchant((int) $group->merchant_id)
            ->whereIn('payment_methods.payment_type', CryptoCheckoutGuard::enabled() ? ['fiat', 'crypto'] : ['fiat'])
            ->get();
    }

    /** @return array{payment_method_id: int, payment_method: string, amount: string, converted_amount: string} */
    private function result(PaymentMethod $method, string $amount, string $converted): array
    {
        return ['payment_method_id' => $method->id, 'payment_method' => $method->method_code, 'amount' => $amount, 'converted_amount' => $converted];
    }
}
