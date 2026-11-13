<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 渠道跨商户额度；已成交统计与未决支付占用分别计算。
 * 锁定读取保证 MySQL REPEATABLE READ 下等待渠道锁后仍读取最新占用，不能使用旧事务快照。
 */
class PaymentCapacityService
{
    /** @return array{day: ?string, month: ?string, count: ?int} */
    public function remaining(PaymentMethod $method, ?int $excludingOrderId = null): array
    {
        $now = Carbon::now(config('app.timezone', 'UTC'));
        $reserved = Order::query()->withoutGlobalScopes()->where('payment_method_id', $method->id)
            ->where('risk_reservation_status', 'held')->whereNull('paid_at')
            ->when($excludingOrderId !== null, fn (Builder $query) => $query->where('id', '!=', $excludingOrderId))->sharedLock();
        $reservedAmount = (string) (clone $reserved)->sum('converted_amount');
        $reservedCount = (clone $reserved)->count();
        $day = $this->paidInWindow($method, $now->copy()->startOfDay(), $now->copy()->endOfDay());

        return [
            'day' => $method->isUnlimited('max_amount_per_day') ? null
                : bcsub(bcsub((string) $method->max_amount_per_day, (string) (clone $day)->sum('converted_amount'), 2), $reservedAmount, 2),
            'month' => $method->isUnlimited('max_amount_per_month') ? null
                : bcsub(bcsub((string) $method->max_amount_per_month,
                    (string) $this->paidInWindow($method, $now->copy()->startOfMonth(), $now->copy()->endOfMonth())->sum('converted_amount'), 2), $reservedAmount, 2),
            'count' => $method->isUnlimited('max_count_per_day') ? null
                : (int) $method->max_count_per_day - $day->count() - $reservedCount,
        ];
    }

    public function permits(PaymentMethod $method, string $amountUsd, ?int $excludingOrderId = null): bool
    {
        if (bccomp($amountUsd, '0', 2) <= 0
            || (! $method->isUnlimited('max_amount_per_transaction')
                && bccomp($amountUsd, (string) $method->max_amount_per_transaction, 2) > 0)) {
            return false;
        }
        $remaining = $this->remaining($method, $excludingOrderId);

        return ($remaining['day'] === null || bccomp($remaining['day'], $amountUsd, 2) >= 0)
            && ($remaining['month'] === null || bccomp($remaining['month'], $amountUsd, 2) >= 0)
            && ($remaining['count'] === null || $remaining['count'] > 0);
    }

    private function paidInWindow(PaymentMethod $method, Carbon $start, Carbon $end): Builder
    {
        return Order::query()->withoutGlobalScopes()->where('payment_method_id', $method->id)
            ->whereNotIn('status', Order::NEVER_PAID_STATUSES)
            ->where(fn (Builder $query) => $query->whereBetween('paid_at', [$start, $end])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('paid_at')->whereBetween('created_at', [$start, $end])))->sharedLock();
    }
}
