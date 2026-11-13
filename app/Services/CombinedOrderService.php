<?php

namespace App\Services;

use App\Exceptions\CombinedPaymentException;
use App\Exceptions\MinimumAmountNotMetException;
use App\Models\CombinedOrder;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CombinedOrderService
{
    public function __construct(
        private readonly CombinedPaymentRouter $router,
        private readonly PaymentCapacityService $capacity,
        private readonly OrderFinancialService $financial,
        private readonly OrderCreationService $creation,
    ) {}

    /** @return array<string, mixed>|null */
    public function quote(CombinedOrder $parent): ?array
    {
        return DB::transaction(function () use ($parent): ?array {
            $parent = $this->lock($parent);
            $this->assertPayable($parent);
            if ($parent->current_order_id !== null) {
                return null;
            }
            $quote = $parent->current_quote;
            if ($quote && Carbon::parse($quote['expires_at'])->isFuture()) {
                try {
                    $this->validateQuote($parent, $quote);

                    return $quote;
                } catch (CombinedPaymentException|MinimumAmountNotMetException) {
                    // 未发起支付的报价可重新计算；已有真实子单由上面的 current_order_id 保护。
                }
            }
            $quote = $this->router->quote($parent) + [
                'id' => (string) Str::uuid(), 'version' => $parent->version,
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ];
            $parent->update(['current_quote' => $quote, 'last_error_code' => null]);

            return $quote;
        }, 3);
    }

    public function pay(CombinedOrder $parent, string $quoteId): Order
    {
        return Cache::lock('combined-payment:'.$parent->id, 900)->block(10, function () use ($parent, $quoteId): Order {
            $order = DB::transaction(function () use ($parent, $quoteId): Order {
                $parent = $this->lock($parent);
                $this->assertPayable($parent);
                if ($parent->current_order_id !== null) {
                    $current = $parent->children()->forMerchant($parent->merchant_id)->findOrFail($parent->current_order_id);
                    if (! in_array($current->status, ['pending', 'failed'], true) || $current->paid_at !== null) {
                        throw new CombinedPaymentException('PAYMENT_IN_PROGRESS');
                    }

                    return $current;
                }
                $quote = $parent->current_quote;
                if (! $quote || ! hash_equals((string) $quote['id'], $quoteId)
                    || Carbon::parse($quote['expires_at'])->isPast() || (int) $quote['version'] !== $parent->version) {
                    throw new CombinedPaymentException('QUOTE_EXPIRED');
                }
                $method = $this->validateQuote($parent, $quote, true);
                $order = $this->creation->createCombinedChild($parent, $method, $quote);
                $parent->update([
                    'current_order_id' => $order->id, 'current_quote' => null,
                    'next_sequence' => $parent->next_sequence + 1, 'last_error_code' => null,
                ]);

                return $order;
            }, 3);

            return $this->creation->resumePayment($order);
        });
    }

    public function expireDue(int $limit = 500): int
    {
        $expired = 0;
        $parents = CombinedOrder::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('status', ['pending', 'partially_paid'])->where('expires_at', '<=', now())
            ->orderBy('id')->limit($limit)->get(['id', 'merchant_id']);
        foreach ($parents as $parent) {
            $expired += DB::transaction(function () use ($parent): int {
                $parent = $this->lock($parent);
                if (! in_array($parent->status, ['pending', 'partially_paid'], true) || $parent->expires_at->isFuture()) {
                    return 0;
                }
                $parent->update(['status' => 'expired', 'current_quote' => null]);

                return 1;
            }, 3);
        }

        return $expired;
    }

    private function lock(CombinedOrder $parent): CombinedOrder
    {
        Merchant::query()->withoutGlobalScopes()->whereKey($parent->merchant_id)->lockForUpdate()->firstOrFail();

        return CombinedOrder::query()->forMerchant($parent->merchant_id)->lockForUpdate()->findOrFail($parent->id);
    }

    private function assertPayable(CombinedOrder $parent): void
    {
        if (! $parent->isPayable() || ! $parent->application?->status || ! $parent->merchant?->status
            || (int) $parent->application->merchant_id !== (int) $parent->merchant_id) {
            throw new CombinedPaymentException('COMBINE_NOT_PAYABLE');
        }
        if ($parent->children()->forMerchant($parent->merchant_id)
            ->where(fn ($query) => $query->where('refunded_amount', '>', 0)
                ->orWhereIn('status', ['refunded', 'partially_refunded', 'chargeback', 'disputing', 'dispute_review']))->exists()) {
            throw new CombinedPaymentException('COMBINE_REVIEW_REQUIRED');
        }
    }

    private function validateQuote(CombinedOrder $parent, array $quote, bool $lock = false): PaymentMethod
    {
        $group = PaymentGroup::query()->forMerchant($parent->merchant_id)->where('is_active', true)->find($parent->payment_group_id);
        $query = $group?->activePaymentMethods()->forMerchant($parent->merchant_id)->where('payment_methods.id', $quote['payment_method_id']);
        $method = $lock ? $query?->lockForUpdate()->first() : $query?->first();
        if (! $method || bccomp((string) $quote['amount'], $parent->remaining_amount, 2) > 0
            || bccomp($this->router->convertedAmount($parent, (string) $quote['amount']), (string) $quote['converted_amount'], 2) !== 0
            || ! $this->capacity->permits($method, (string) $quote['converted_amount'])) {
            throw new CombinedPaymentException('QUOTE_EXPIRED');
        }
        if (bccomp((string) $quote['amount'], $parent->remaining_amount, 2) !== 0) {
            $limit = (string) $method->max_amount_per_transaction;
            if ($method->isUnlimited('max_amount_per_transaction') || ! $this->capacity->permits($method, $limit)
                || bccomp((string) $quote['converted_amount'], bcmul($limit, '0.70', 8), 8) < 0
                || bccomp((string) $quote['converted_amount'], bcmul($limit, '0.90', 8), 8) > 0) {
                throw new CombinedPaymentException('QUOTE_EXPIRED');
            }
        }
        $this->financial->snapshot($method, $parent->merchant, (string) $quote['converted_amount']);

        return $method;
    }
}
