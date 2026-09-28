<?php

namespace App\Filament\Resources\CheckoutLinkResource\Concerns;

use App\Models\CheckoutLink;
use Filament\Notifications\Notification;

/**
 * 收款链接的金额一致性校验（需求 3）：
 *
 *   配置了商品明细 / 运费 / 税费 / 折扣中的任意一项
 *     → 金额模式必须是「固定金额」
 *     → 且 Σ(单价 × 数量) + 运费 - 折扣 + 税费 必须严格等于固定金额
 *
 * 为什么放在页面的 before 钩子里，而不是字段的 ->rules()：商品明细是
 * Repeater + relationship，它的值不在单个字段的校验上下文里，只有整份表单
 * 状态（$this->data）才拿得到；字段级规则里读不到同级 Repeater 的最新状态。
 *
 * 这是第一道闸门，给商户友好提示。第二道在 OrderCreationService（2.1 节铁律），
 * 下单时会用同一条公式再算一遍——后台改过数据、或有人绕过表单直接写库，
 * 都会在真正建单时被拦住，不会产生金额对不上的订单。
 */
trait ValidatesCheckoutLinkAmounts
{
    protected function beforeCreate(): void
    {
        $this->assertAmountsAreConsistent();
    }

    protected function beforeSave(): void
    {
        $this->assertAmountsAreConsistent();
    }

    private function assertAmountsAreConsistent(): void
    {
        $data = $this->data;
        $items = array_values($data['items'] ?? []);

        $shipping = $this->money($data['shipping_fee'] ?? 0);
        $tax = $this->money($data['tax'] ?? 0);
        $discount = $this->money($data['discount'] ?? 0);

        $hasItemised = $items !== []
            || bccomp($shipping, '0', 2) !== 0
            || bccomp($tax, '0', 2) !== 0
            || bccomp($discount, '0', 2) !== 0;

        if (! $hasItemised) {
            return;
        }

        if (($data['amount_mode'] ?? null) !== CheckoutLink::MODE_FIXED) {
            $this->failWith(__('admin.checkout_link.errors.requires_fixed_amount'));
        }

        $subtotal = array_reduce(
            $items,
            fn (string $carry, array $item) => bcadd(
                $carry,
                bcmul($this->money($item['unit_price'] ?? 0), (string) (int) ($item['quantity'] ?? 0), 2),
                2
            ),
            '0.00'
        );

        // 与 Order::isAmountValid() 同一条公式，顺序也一致：
        // subtotal + shipping - discount + tax
        $expected = bcadd(bcsub(bcadd($subtotal, $shipping, 2), $discount, 2), $tax, 2);
        $fixed = $this->money($data['fixed_amount'] ?? 0);

        if (bccomp($expected, $fixed, 2) !== 0) {
            $this->failWith(__('admin.checkout_link.errors.amount_mismatch', [
                'expected' => $expected,
                'actual' => $fixed,
            ]));
        }
    }

    /**
     * 用 persistent 通知而不是 danger 一闪而过：这条提示带着"应该是多少"的
     * 具体数字，商户需要照着改，弹一下就消失等于没说。
     */
    private function failWith(string $message): never
    {
        Notification::make()
            ->danger()
            ->title(__('admin.checkout_link.errors.title'))
            ->body($message)
            ->persistent()
            ->send();

        $this->halt();
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value === '' || $value === null ? 0 : $value), '0', 2);
    }
}
