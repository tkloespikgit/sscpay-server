<?php

namespace App\Support;

use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class OrderTelegramSummary
{
    /** @return array<string, string> */
    public static function details(Order $order): array
    {
        $paymentMethodName = null;

        if ($order->payment_method_id || filled($order->payment_method)) {
            $query = PaymentMethod::query()->forMerchant((int) $order->merchant_id)->withTrashed();
            $paymentMethodName = $order->payment_method_id
                ? $query->whereKey($order->payment_method_id)->value('method_name')
                : $query->where('method_code', $order->payment_method)->orderBy('id')->value('method_name');
        }

        return self::buildDetails($order, $paymentMethodName);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<int, string>
     */
    public static function forOrders(Collection $orders): array
    {
        $summaries = [];

        foreach ($orders->groupBy('merchant_id') as $merchantId => $merchantOrders) {
            $methodIds = $merchantOrders->pluck('payment_method_id')->filter()->unique()->values()->all();
            $methodCodes = $merchantOrders->filter(fn (Order $order): bool => ! $order->payment_method_id)
                ->pluck('payment_method')->filter(fn (?string $code): bool => filled($code))->unique()->values()->all();
            $methods = $methodIds !== [] || $methodCodes !== []
                ? PaymentMethod::query()->forMerchant((int) $merchantId)->withTrashed()
                    ->where(fn (Builder $query): Builder => $query->whereIn('id', $methodIds)->orWhereIn('method_code', $methodCodes))
                    ->orderBy('id')->get(['id', 'method_code', 'method_name'])
                : collect();
            $methodsById = $methods->keyBy('id');
            $methodsByCode = $methods->groupBy('method_code')->map(fn (Collection $group): PaymentMethod => $group->first());

            foreach ($merchantOrders as $order) {
                $method = $order->payment_method_id
                    ? $methodsById->get($order->payment_method_id)
                    : $methodsByCode->get($order->payment_method);
                $summaries[(int) $order->id] = self::render(self::buildDetails($order, $method?->method_name));
            }
        }

        return $summaries;
    }

    /** @return array<string, string> */
    private static function buildDetails(Order $order, ?string $paymentMethodName): array
    {
        return [
            'order_no' => (string) $order->order_no,
            'merchant_order_no' => (string) $order->merchant_order_no,
            'transaction_id' => (string) $order->transaction_id,
            'payment_method_name' => (string) ($paymentMethodName ?: $order->payment_method),
            'paid_at' => $order->paid_at?->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s T') ?? '',
            'currency' => strtoupper((string) $order->currency),
            'amount' => (string) $order->amount,
        ];
    }

    /** @param array<string, string> $details */
    public static function render(array $details): string
    {
        $replacements = [];

        foreach (['order_no', 'merchant_order_no', 'transaction_id', 'payment_method_name', 'paid_at', 'currency', 'amount'] as $field) {
            $value = trim((string) ($details[$field] ?? ''));

            if ($value === '') {
                $value = __($field === 'paid_at'
                    ? 'admin.telegram_notification.order_not_paid'
                    : 'admin.telegram_notification.order_detail_unavailable');
            }

            $replacements[$field] = htmlspecialchars(mb_substr($value, 0, 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return __('admin.telegram_notification.order_summary', $replacements);
    }

    public static function forOrder(Order $order): string
    {
        return self::render(self::details($order));
    }
}
