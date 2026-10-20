<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    private const MAX_AMOUNT = '999999999999999999.99';

    private const PERKS_WORDS = [
        '极速退款',
        '7天无理由退货',
        '真实工厂',
        '破损包赔',
        '品质保障',
        '跨境无忧',
        '严选晚发必赔',
        '48小时发货',
        '退货包运费',
        '延期必赔',
    ];

    /**
     * @param  array{ordered_at: string, shipping_fee?: string|int|float|null, discount?: string|int|float|null}  $data
     */
    public function generate(PaymentMethod $paymentMethod, User $operator, array $data): PurchaseOrder
    {
        $this->authorize($operator);

        Validator::make($data, [
            'ordered_at' => ['required', 'string', 'date', 'after_or_equal:1000-01-01', 'before:9999-12-24'],
        ], [], [
            'ordered_at' => __('purchase_order.fields.ordered_at'),
        ])->validate();

        $overrides = $this->validateCharges($data);
        $orderedAt = CarbonImmutable::parse($data['ordered_at'])->setTimezone(config('app.timezone'))->startOfSecond();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($paymentMethod, $operator, $orderedAt, $overrides): PurchaseOrder {
                    $productsQuery = $paymentMethod->siteProducts()->withoutGlobalScope(MerchantScope::class);
                    $totalProducts = (clone $productsQuery)->count();

                    if ($totalProducts === 0) {
                        throw ValidationException::withMessages([
                            'ordered_at' => __('purchase_order.errors.no_products'),
                        ]);
                    }

                    $selectedCount = $totalProducts < 30 ? $totalProducts : random_int(30, $totalProducts);
                    $products = $productsQuery->inRandomOrder()->limit($selectedCount)->get();

                    if ($products->isEmpty()) {
                        throw ValidationException::withMessages([
                            'ordered_at' => __('purchase_order.errors.no_products'),
                        ]);
                    }

                    $items = [];
                    $subtotal = '0.00';

                    foreach ($products as $product) {
                        $sourcePrice = $this->validateAmount((string) $product->price_min, 'ordered_at');
                        $unitPrice = $this->roundAmount(bcmul(bcmul($sourcePrice, '7', 2), '0.3', 3));
                        $quantity = random_int(10, 20);
                        $lineTotal = $this->validateAmount(bcmul($unitPrice, (string) $quantity, 2), 'ordered_at');
                        $subtotal = $this->validateAmount(bcadd($subtotal, $lineTotal, 2), 'ordered_at');

                        $items[] = [
                            'site_product_id' => $product->getKey(),
                            'product_name' => filled($product->name_translated) ? $product->name_translated : $product->name,
                            'sku' => $product->sku,
                            'source_price' => $sourcePrice,
                            'unit_price' => $unitPrice,
                            'quantity' => $quantity,
                            'subtotal' => $lineTotal,
                        ];
                    }

                    $shippingFee = $overrides['shipping_fee'] ?? $this->percentage($subtotal, random_int(3, 8));
                    $discount = $overrides['discount'] ?? $this->percentage($subtotal, random_int(10, 20));
                    $total = $this->calculateTotal($subtotal, $shippingFee, $discount);
                    $paidAt = $orderedAt->addSeconds(random_int(1, 3600));
                    $shippedAt = $paidAt->addSeconds(random_int(86400, 172800));
                    $receivedAt = $shippedAt->addSeconds(random_int(259200, 432000));

                    $order = PurchaseOrder::create([
                        'payment_method_id' => $paymentMethod->getKey(),
                        'created_by' => $operator->getKey(),
                        'order_no' => '33'.$this->randomDigits(17),
                        'transaction_id' => $orderedAt->format('YmdHis').$this->randomDigits(20),
                        'currency' => 'CNY',
                        'ordered_at' => $orderedAt,
                        'paid_at' => $paidAt,
                        'shipped_at' => $shippedAt,
                        'received_at' => $receivedAt,
                        'subtotal' => $subtotal,
                        'shipping_fee' => $shippingFee,
                        'discount' => $discount,
                        'total' => $total,
                    ]);

                    $order->items()->createMany($items);

                    return $order->load('items');
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Purchase order generation exhausted its attempts.');
    }

    /**
     * @param  array{shipping_fee?: string|int|float|null, discount?: string|int|float|null}  $data
     */
    public function updateCharges(PurchaseOrder $order, User $operator, array $data): PurchaseOrder
    {
        $this->authorize($operator);
        $charges = $this->validateCharges($data);

        return DB::transaction(function () use ($order, $charges): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $shippingFee = $charges['shipping_fee'] ?? $lockedOrder->shipping_fee;
            $discount = $charges['discount'] ?? $lockedOrder->discount;

            $lockedOrder->update([
                'shipping_fee' => $shippingFee,
                'discount' => $discount,
                'total' => $this->calculateTotal($lockedOrder->subtotal, $shippingFee, $discount),
            ]);

            return $lockedOrder->load('items');
        }, 3);
    }

    /** @return array<int, string> */
    public function initializePerksWords(PurchaseOrder $order, User $operator): array
    {
        $this->authorize($operator);

        if ($order->perks_words !== null) {
            return $order->perks_words;
        }

        $perksWords = DB::transaction(function () use ($order): array {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($lockedOrder->perks_words === null) {
                $keys = array_rand(self::PERKS_WORDS, random_int(4, 8));
                $lockedOrder->perks_words = array_map(
                    static fn (int $key): string => self::PERKS_WORDS[$key],
                    $keys,
                );
                $lockedOrder->timestamps = false;
                $lockedOrder->save();
            }

            return $lockedOrder->perks_words;
        }, 3);

        $order->perks_words = $perksWords;
        $order->syncOriginalAttribute('perks_words');

        return $perksWords;
    }

    private function authorize(User $operator): void
    {
        if (! $operator->is_super_admin || ! $operator->status || $operator->trashed()) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array{shipping_fee?: string|int|float|null, discount?: string|int|float|null}  $data
     * @return array{shipping_fee?: string, discount?: string}
     */
    private function validateCharges(array $data): array
    {
        $validated = Validator::make($data, [
            'shipping_fee' => ['nullable', 'regex:/\A\d{1,18}(?:\.\d{1,2})?\z/'],
            'discount' => ['nullable', 'regex:/\A\d{1,18}(?:\.\d{1,2})?\z/'],
        ], [
            'shipping_fee.regex' => __('purchase_order.errors.invalid_amount'),
            'discount.regex' => __('purchase_order.errors.invalid_amount'),
        ])->validate();
        $charges = [];

        foreach (['shipping_fee', 'discount'] as $field) {
            if (isset($validated[$field]) && $validated[$field] !== '') {
                $charges[$field] = $this->validateAmount((string) $validated[$field], $field);
            }
        }

        return $charges;
    }

    private function validateAmount(string $amount, string $field): string
    {
        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/', $amount) || bccomp($amount, self::MAX_AMOUNT, 2) > 0) {
            throw ValidationException::withMessages([
                $field => __('purchase_order.errors.invalid_amount'),
            ]);
        }

        return bcadd($amount, '0', 2);
    }

    private function calculateTotal(string $subtotal, string $shippingFee, string $discount): string
    {
        if (bccomp($discount, $subtotal, 2) > 0) {
            throw ValidationException::withMessages([
                'discount' => __('purchase_order.errors.invalid_discount'),
            ]);
        }

        return $this->validateAmount(bcsub(bcadd($subtotal, $shippingFee, 2), $discount, 2), 'shipping_fee');
    }

    private function percentage(string $amount, int $percentage): string
    {
        return $this->roundAmount(bcdiv(bcmul($amount, (string) $percentage, 2), '100', 4));
    }

    private function roundAmount(string $amount): string
    {
        return $this->validateAmount(bcadd($amount, '0.005', 2), 'ordered_at');
    }

    private function randomDigits(int $length): string
    {
        $digits = (string) random_int(1, 9);

        for ($index = 1; $index < $length; $index++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
