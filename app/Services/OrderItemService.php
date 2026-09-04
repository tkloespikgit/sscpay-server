<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\SiteProduct;
use App\Models\SiteProductVariation;
use App\Models\SystemConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 订单商品服务：下单未传商品明细时，从选定支付方式绑定的站点商品
 * 变体（site_product_variations）中自动匹配出一份商品明细。
 *
 * 匹配规则：
 *   - 匹配对象是变体数据（每个变体有独立的 SKU / 价格），父商品只用于
 *     取商品名称与详情页链接；
 *   - 变体价格统一以美金（USD）存储，先按美金金额匹配，
 *     最后再把单价折算成订单交易币种；
 *   - 每一轮从"单价不超过剩余额度"的变体里取出单价最高的 10 个，
 *     再从中随机选 1 个，能拿多少拿多少（数量递增），再加一件就超额时
 *     进入下一轮继续挑选；
 *   - 剩余额度连最便宜的变体都买不起时，末件改价补齐打满目标金额：
 *     优先挑"原价不低于剩余额度且最接近"的变体小幅打折；若折扣深度超过
 *     阈值（order_match.min_price_ratio，默认 0.4）则回溯退掉上一行一件把额度凑大，
 *     最多回溯有限轮次；小额订单无行可退时退化为深折扣兜底；
 *   - 单个变体的件数上限在每次匹配时于 1 ~ order_match.max_item_quantity
 *     （默认 3，0 不限制）之间随机确定：后台配置只是临界值，随机值不会超过它，
 *     让同一单里各商品的件数分布不再千篇一律；随机上限内凑不出下一件时
 *     才放宽到临界值兜底，商品池按临界值计算的总容量不够打满目标金额时直接报错。
 *
 * CREATE 模式（createItems）：
 *   - 逐条按商户下单明细（order_items）的 USD 折算价在站点商品变体中找同价商品，
 *     允许不超过 5% 的汇率折算差额；找不到就取"价格更高且最接近"的变体作模板，
 *     复制一份改价并在 WordPress 站点上同步创建同价商品（变体商品的复制体一律作为简单商品）；
 *   - 因为 /pay 远程建单需要传真实的商品 ID / 链接，创建必须在下单时同步完成，不走队列。
 */
class OrderItemService
{
    /** 每轮挑选时参与随机抽取的候选池大小（价格最高/最低的前 N 个）。 */
    private const RANDOM_POOL_SIZE = 10;

    /** 末件补齐回溯退量的最大轮次，防止小额差额反复退行。 */
    private const MAX_BACKTRACK_ROUNDS = 3;

    /** order_match.min_price_ratio 未配置时的默认值：改价后单价不低于原价的 40%。 */
    private const DEFAULT_MIN_PRICE_RATIO = '0.4';

    /** order_match.max_item_quantity 未配置时的默认值：单品件数随机上限的临界值为 3 件。 */
    private const DEFAULT_MAX_ITEM_QUANTITY = 3;

    /** CREATE 模式同价匹配的相对容差：商品价与目标价差额不超过目标价的 5%。 */
    private const CREATE_PRICE_TOLERANCE = 0.05;

    /** CREATE 模式调用站点 WooCommerce API 的单次请求超时（与商品同步服务保持一致）。 */
    private const CREATE_HTTP_TIMEOUT = 60;

    /** 远端创建商品时 SKU 冲突的重新生成重试次数。 */
    private const CREATE_SKU_RETRIES = 3;

    /**
     * 从支付方式的站点商品变体中随机匹配出一份订单商品明细。
     *
     * @param  PaymentMethod  $paymentMethod  选定的支付方式（商品按其站点配置同步）
     * @param  string  $targetGoodsAmount  目标商品金额（订单币种，即订单应达到的 subtotal）
     * @param  string  $actualRate  含汇损的实际汇率：1 订单币种 = ? USD
     * @return array{items: list<array>, subtotal: string, overflow: string}
     *               items：字段结构同下单 items（单价/小计为订单币种，
     *               converted_unit_price 为美金原价）；
     *               subtotal：匹配明细行小计之和（订单币种）；
     *               overflow：保留字段，末件改价补齐后恒为 0
     */
    public function matchItems(PaymentMethod $paymentMethod, string $targetGoodsAmount, string $actualRate): array
    {
        if (bccomp($targetGoodsAmount, '0', 2) <= 0) {
            throw new RuntimeException('可用于匹配的商品金额必须大于 0，无法自动匹配商品');
        }

        if (bccomp($actualRate, '0', 6) <= 0) {
            throw new RuntimeException('汇率不合法，无法自动匹配商品');
        }

        $candidates = SiteProductVariation::query()
            ->whereHas('siteProduct', fn ($query) => $query->where('payment_method_id', $paymentMethod->id))
            ->where('price', '>', 0)
            ->with('siteProduct')
            ->get()
            ->shuffle()
            ->values();

        if ($candidates->isEmpty()) {
            throw new RuntimeException("支付方式 {$paymentMethod->method_code} 没有可用于匹配的站点商品变体，请先同步商品");
        }

        // 站点商品价格均为美金：先把目标金额折算成美金再匹配。
        $targetUsd = bcmul($targetGoodsAmount, $actualRate, 2);

        $lines = $this->fillByGreedy($candidates, $targetUsd);

        // 把美金匹配结果折算回订单币种，组装成与下单 items 一致的明细结构。
        $items = [];
        $subtotal = '0';

        foreach ($lines as $line) {
            if ($line['price_adjusted']) {
                // 改价补齐的行：单价直接取"目标金额 - 已有明细小计"，
                // 保证匹配小计与订单商品金额分毫不差，不产生溢出折扣。
                $unitPrice = bcsub($targetGoodsAmount, $subtotal, 2);
            } else {
                $unitPrice = bcdiv($line['price'], $actualRate, 2);
            }
            $totalPrice = bcmul($unitPrice, (string) $line['quantity'], 2);
            $subtotal = bcadd($subtotal, $totalPrice, 2);

            $variation = $line['variation'];
            $product = $variation->siteProduct;

            // 改价行的商品名追加折扣文案（按美金口径计算折扣百分比），
            // 让商品名与改价后的金额自洽，避免"原价 16 美金卖 0.6"的观感。
            $productName = $product->name;

            if ($line['price_adjusted'] && $line['original_price'] !== null) {
                $discountPercent = (int) round(
                    (float) bcdiv(bcsub($line['original_price'], $line['price'], 4), $line['original_price'], 4) * 100
                );

                if ($discountPercent > 0) {
                    $productName .= " ({$discountPercent}% discount)";
                }
            }

            $items[] = [
                'product_sku' => $variation->sku,
                'product_id' => (string) $variation->woo_variation_id,
                'product_url' => $product->permalink,
                'product_name' => $productName,
                'product_description' => null,
                'unit_price' => $unitPrice,
                'quantity' => $line['quantity'],
                'total_price' => $totalPrice,
                // 改价行的"原价"即改价后的美金折算价，保持单价×汇率口径一致。
                'converted_unit_price' => $line['price_adjusted']
                    ? bcmul($unitPrice, $actualRate, 2)
                    : $line['price'],
            ];
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            // 末件改价补齐后匹配小计与目标金额完全一致，不再有溢出折扣。
            'overflow' => '0',
        ];
    }

    /**
     * CREATE 模式：逐条按商户下单明细（order_items）的 USD 折算价在站点商品变体中
     * 找同价商品（相对差 ≤ 5%）；找不到就取"价格更高且最接近"的变体作模板，
     * 复制一份改价并在 WordPress 站点上同步创建同价商品。返回结构与 matchItems() 一致，
     * 明细额外携带 source_variation_id / auto_created 两个字段。
     *
     * @param  PaymentMethod  $paymentMethod  选定的支付方式（商品按其站点配置同步/创建）
     * @param  Order  $order  订单（取商户下单明细，明细自带下单时快照的 USD 折算价）
     * @param  Collection<int, \App\Models\OrderMatchedItem>|null  $reusableCreated
     *         幂等补单重试时上一轮已自动创建的商品行，按 USD 单价复用，避免站点堆积重复商品。
     */
    public function createItems(PaymentMethod $paymentMethod, Order $order, ?Collection $reusableCreated = null): array
    {
        $orderItems = $order->items()->get();

        if ($orderItems->isEmpty()) {
            throw new RuntimeException('订单没有商品明细，CREATE 模式无法匹配商品');
        }

        $candidates = SiteProductVariation::query()
            ->whereHas('siteProduct', fn ($query) => $query->where('payment_method_id', $paymentMethod->id))
            ->where('price', '>', 0)
            ->with('siteProduct')
            ->get();

        if ($candidates->isEmpty()) {
            throw new RuntimeException("支付方式 {$paymentMethod->method_code} 没有可用于匹配的站点商品，请先同步商品");
        }

        // 补单重试复用池：按"美金单价"索引上一轮已创建的商品行。
        $reusable = collect();

        foreach ($reusableCreated ?? [] as $row) {
            if (filled($row->product_id)) {
                $reusable->put(number_format((float) $row->converted_unit_price, 2, '.', ''), $row);
            }
        }

        $createdThisRun = []; // 本次运行内同价商品复用（targetUsd => 创建结果）
        $items = [];
        $subtotal = '0';

        foreach ($orderItems as $orderItem) {
            $targetUsd = number_format((float) $orderItem->converted_unit_price, 2, '.', '');

            if (bccomp($targetUsd, '0', 2) <= 0) {
                throw new RuntimeException('订单明细折算后的美金单价必须大于 0，CREATE 模式无法匹配商品');
            }

            $subtotal = bcadd($subtotal, (string) $orderItem->total_price, 2);

            // 1. 同价匹配：先随机打乱再按价差稳定排序，同价差时随机命中，
            // 避免总是命中同一件商品；取价差在容差内且最小的一个。
            $diff = fn (SiteProductVariation $variation) => abs((float) $variation->price - (float) $targetUsd);

            $hit = $candidates->shuffle()
                ->sortBy($diff)
                ->first(fn (SiteProductVariation $variation) => $diff($variation) <= (float) $targetUsd * self::CREATE_PRICE_TOLERANCE);

            if ($hit !== null) {
                $items[] = $this->buildLineFromOrderItem($orderItem, [
                    'product_sku' => $hit->sku,
                    'product_id' => (string) $hit->woo_variation_id,
                    'product_url' => $hit->siteProduct->permalink,
                    'product_name' => $hit->siteProduct->name,
                ], $hit->id, false);

                continue;
            }

            // 2. 找不到同价：取价格更高且最接近的变体作复制模板；没有更贵的直接报错。
            $template = $candidates
                ->filter(fn (SiteProductVariation $variation) => bccomp($this->variationPrice($variation), $targetUsd, 2) > 0)
                ->sortBy(fn (SiteProductVariation $variation) => (float) $variation->price)
                ->first();

            if ($template === null) {
                throw new RuntimeException("支付方式 {$paymentMethod->method_code} 站点没有单价高于 {$targetUsd} 美金的商品，CREATE 模式无法复制改价创建商品");
            }

            // 3. 复用顺序：本次运行内同价已创建的（多行补单时后续行必须指向同一商品）
            // > 补单重试上一轮已创建的 > 真正调站点 API 新建。
            $reuseRow = $reusable->get($targetUsd);

            if (isset($createdThisRun[$targetUsd])) {
                $created = $createdThisRun[$targetUsd];
            } elseif ($reuseRow !== null) {
                $created = [
                    'woo_product_id' => (int) $reuseRow->product_id,
                    'sku' => $reuseRow->product_sku,
                    'name' => $reuseRow->product_name,
                    'permalink' => $reuseRow->product_url,
                ];
            } else {
                $created = $createdThisRun[$targetUsd] = $this->createRemoteProduct($paymentMethod, $template, $targetUsd);
            }

            $items[] = $this->buildLineFromOrderItem($orderItem, [
                'product_sku' => $created['sku'],
                'product_id' => (string) $created['woo_product_id'],
                'product_url' => $created['permalink'],
                'product_name' => $created['name'],
            ], $template->id, true);
        }

        return ['items' => $items, 'subtotal' => $subtotal, 'overflow' => '0'];
    }

    /** 把一条商户下单明细组装成匹配明细行结构（金额沿用订单币种原值）。 */
    private function buildLineFromOrderItem(OrderItem $orderItem, array $product, ?int $sourceVariationId, bool $autoCreated): array
    {
        return [
            'product_sku' => $product['product_sku'],
            'product_id' => $product['product_id'],
            'product_url' => $product['product_url'],
            'product_name' => $product['product_name'],
            'product_description' => $orderItem->product_description,
            'unit_price' => (string) $orderItem->unit_price,
            'quantity' => (int) $orderItem->quantity,
            'total_price' => (string) $orderItem->total_price,
            'converted_unit_price' => number_format((float) $orderItem->converted_unit_price, 2, '.', ''),
            'source_variation_id' => $sourceVariationId,
            'auto_created' => $autoCreated,
        ];
    }

    /**
     * 美金口径的贪心凑单：每一轮从"单价不超过剩余额度"的变体中取单价
     * 最高的 RANDOM_POOL_SIZE 个，随机选 1 个，数量按剩余额度尽量拿满；
     * 剩余额度一件都买不起时，末件改价补齐：优先挑原价最接近剩余额度的变体小幅打折，
     * 折扣深度低于阈值（order_match.min_price_ratio）时回溯退上一行一件凑大额度，
     * 最多回溯 MAX_BACKTRACK_ROUNDS 轮；无行可退时退化为深折扣兜底。
     * 单个变体的件数上限每次匹配时在 1 ~ order_match.max_item_quantity（0 表示不限制）
     * 之间随机确定，配置值只是随机上限的临界值；随机上限内已无可用变体时放宽到临界值。
     *
     * @param  Collection<int, SiteProductVariation>  $candidates  候选变体（价格 > 0）
     * @return list<array{variation: SiteProductVariation, price: string, quantity: int, price_adjusted: bool, original_price: string|null}>
     */
    private function fillByGreedy(Collection $candidates, string $targetUsd): array
    {
        $lines = [];
        $remaining = $targetUsd;

        // 单品件数上限的临界值（0 或配成非正数表示不限制）。
        $hardMax = (int) SystemConfig::get('order_match.max_item_quantity', self::DEFAULT_MAX_ITEM_QUANTITY);

        if ($hardMax <= 0) {
            $hardMax = PHP_INT_MAX;
        }

        // 某变体已分配的件数（variation id => 件数）：同一变体可能在多轮里被选中、
        // 也可能出现在末件改价行，用 map 随行增删同步维护，过滤时 O(1) 查询；
        // 若改成每次遍历 $lines 汇总，多轮凑单下会退化成 O(轮数×候选数×行数) 卡死。
        $allocated = [];

        $allocatedQty = function (SiteProductVariation $variation) use (&$allocated): int {
            return $allocated[$variation->id] ?? 0;
        };

        // 每个变体本次匹配的随机件数上限（variation id => 件数）：首次参与挑选时在
        // 1 ~ 临界值之间随机确定并固定下来，同一单里各商品的件数不再一律打满上限。
        $randomLimits = [];

        $randomLimit = function (SiteProductVariation $variation) use (&$randomLimits, $hardMax): int {
            return $randomLimits[$variation->id] ??= $hardMax === PHP_INT_MAX ? $hardMax : random_int(1, $hardMax);
        };

        // 临界值上限：随机上限内已经凑不出下一件时用它兜底，保证随机件数只影响
        // 明细的件数分布，不会让匹配提前进入改价补齐甚至直接失败。
        $hardLimit = fn (SiteProductVariation $variation) => $hardMax;

        // 未达件数上限的候选变体。
        $poolUnder = fn (callable $limit) => $candidates->filter(
            fn (SiteProductVariation $variation) => $allocatedQty($variation) < $limit($variation)
        );

        // 剩余额度买得起、且未达件数上限的候选池：单价最高的 RANDOM_POOL_SIZE 个。
        $affordableUnder = function (callable $limit) use ($poolUnder, &$remaining) {
            return $poolUnder($limit)
                ->filter(fn (SiteProductVariation $variation) => bccomp($this->variationPrice($variation), $remaining, 2) <= 0)
                ->sortByDesc(fn (SiteProductVariation $variation) => (float) $variation->price)
                ->take(self::RANDOM_POOL_SIZE);
        };

        // 商品池按件数临界值计算的总容量不够打满目标金额时直接报错，
        // 避免硬凑出改价离谱的明细行。
        if ($hardMax !== PHP_INT_MAX) {
            $capacity = '0';

            foreach ($candidates as $variation) {
                $capacity = bcadd($capacity, bcmul($this->variationPrice($variation), (string) $hardMax, 2), 2);
            }

            if (bccomp($targetUsd, $capacity, 2) > 0) {
                throw new RuntimeException("站点商品容量不足：按单品最多 {$hardMax} 件计算，可用商品总额 {$capacity} 美金，低于目标金额 {$targetUsd} 美金，无法自动匹配商品");
            }
        }

        while (bccomp($remaining, '0', 2) > 0) {
            // 从剩余额度买得起且未达件数上限的变体里，取单价最高的 10 个再随机选 1 个，
            // 避免每次都命中同一件最高价商品；优先在随机件数上限内挑，随机上限内
            // 一件都买不起时才放宽到临界值。
            $affordable = $affordableUnder($randomLimit);

            if ($affordable->isEmpty()) {
                $affordable = $affordableUnder($hardLimit);
            }

            if ($affordable->isEmpty()) {
                break;
            }

            $picked = $affordable->random();
            $price = $this->variationPrice($picked);

            // 数量取剩余额度最多能买的件数（再加一件就会超出），且不超过该变体的件数上限：
            // 随机上限还有余量就按随机上限，随机上限已用满（放宽轮次命中）时按临界值。
            $randomLeft = $randomLimit($picked) - $allocatedQty($picked);
            $quantity = min(
                (int) bcdiv($remaining, $price, 0),
                $randomLeft > 0 ? $randomLeft : $hardMax - $allocatedQty($picked)
            );

            $lines[] = [
                'variation' => $picked,
                'price' => $price,
                'quantity' => $quantity,
                'price_adjusted' => false,
                'original_price' => null,
            ];
            $allocated[$picked->id] = ($allocated[$picked->id] ?? 0) + $quantity;
            $remaining = bcsub($remaining, bcmul($price, (string) $quantity, 2), 2);
        }

        // 剩余额度买不起任何一件变体：末件改价补齐打满目标金额。
        // 为避免"16 美金的商品改价到 0.6 美金"这类深折扣异常单，优先挑"原价不低于
        // 剩余额度且最接近"的变体（小幅打折、永不涨价）；若折扣深度仍低于阈值，
        // 回溯退掉最后一行一件把额度凑大，最多回溯 MAX_BACKTRACK_ROUNDS 轮。
        if (bccomp($remaining, '0', 2) > 0) {
            $minRatio = (string) SystemConfig::get('order_match.min_price_ratio', self::DEFAULT_MIN_PRICE_RATIO);
            // 候选池最贵变体的原价：回溯后的额度一旦超过它，再挑就只能涨价了，
            // 此时停止回溯、接受当前折扣偏深的挑选（深折扣优于涨价）。
            $maxPrice = $this->variationPrice($candidates->sortByDesc(fn (SiteProductVariation $v) => (float) $v->price)->first());
            $picked = null;

            for ($attempt = 0; $attempt <= self::MAX_BACKTRACK_ROUNDS; $attempt++) {
                // 末件改价行也是一件，同样要排除已达件数上限的变体：先在随机上限内挑，
                // 挑不出"原价不低于剩余额度"的再放宽到临界值。
                $picked = $this->pickClosestAbove($poolUnder($randomLimit), $remaining)
                    ?? $this->pickClosestAbove($poolUnder($hardLimit), $remaining);

                // 折扣深度可接受（改价/原价 >= 阈值）就用它，停止回溯。
                if ($picked !== null
                    && bccomp(bcdiv($remaining, $this->variationPrice($picked), 4), $minRatio, 4) >= 0) {
                    break;
                }

                // 没有可回溯的行（首件都买不起的小额订单），只能接受兜底。
                if ($lines === []) {
                    break;
                }

                $last = &$lines[count($lines) - 1];
                $nextRemaining = bcadd($remaining, $last['price'], 2);

                // 回溯后额度会超出所有变体原价（再挑只能涨价），停止回溯。
                if (bccomp($nextRemaining, $maxPrice, 2) > 0) {
                    unset($last);

                    break;
                }

                // 回溯：最后一行退一件，把一件的预算还给剩余额度。
                $remaining = $nextRemaining;
                $last['quantity']--;
                $allocated[$last['variation']->id]--;

                if ($last['quantity'] <= 0) {
                    array_pop($lines);
                }

                unset($last);
            }

            if ($picked === null) {
                // 深折扣兜底：所有变体原价都低于剩余额度或无行可退时，
                // 从未达件数上限（随机上限内没有可用变体时放宽到临界值）的
                // 最便宜 10 个里随机挑一件改价补齐。
                $pool = $poolUnder($randomLimit);

                if ($pool->isEmpty()) {
                    $pool = $poolUnder($hardLimit);
                }

                $fallbackPool = $pool
                    ->sortBy(fn (SiteProductVariation $variation) => (float) $variation->price)
                    ->take(self::RANDOM_POOL_SIZE);

                if ($fallbackPool->isEmpty()) {
                    throw new RuntimeException('站点商品均已达到最大匹配件数，无法继续匹配商品');
                }

                $picked = $fallbackPool->random();
            }

            // 单独加一行而不是并入已有同款行，避免同一行里混着原价与改价两种单价；
            // original_price 记录改价前原价，供组装明细时生成折扣文案。
            $lines[] = [
                'variation' => $picked,
                'price' => $remaining,
                'quantity' => 1,
                'price_adjusted' => true,
                'original_price' => $this->variationPrice($picked),
            ];
            $allocated[$picked->id] = ($allocated[$picked->id] ?? 0) + 1;
        }

        return $lines;
    }

    /**
     * 从候选变体中挑出"原价不低于剩余额度且最接近剩余额度"的一件，
     * 改价到剩余额度时折扣幅度最小且永不涨价；没有符合条件的返回 null。
     *
     * @param  Collection<int, SiteProductVariation>  $candidates
     */
    private function pickClosestAbove(Collection $candidates, string $remainingUsd): ?SiteProductVariation
    {
        return $candidates
            ->filter(fn (SiteProductVariation $variation) => bccomp($this->variationPrice($variation), $remainingUsd, 2) >= 0)
            ->sortBy(fn (SiteProductVariation $variation) => (float) $variation->price)
            ->first();
    }

    /** 把变体价格规范成两位小数字符串，供 bcmath 比较/计算。 */
    private function variationPrice(SiteProductVariation $variation): string
    {
        return number_format((float) $variation->price, 2, '.', '');
    }

    /**
     * 在支付方式对应的 WordPress 站点上创建一个简单商品（WooCommerce REST API
     * /wc/v3/products，用站点配置的 ck/cs 密钥）：复制模板所属父商品的描述/图片/分类，
     * 名称沿用源商品真实名称（空名称才回退"虚拟商品前缀 + 随机串"），SKU 重新生成，
     * 价格改为目标美金价。源商品是变体商品时，复制体也一律按简单商品创建。
     * 创建成功后把新商品回写本地快照表，之后的订单可直接匹配到它。
     *
     * @return array{woo_product_id: int, sku: string, name: string, permalink: string, image_url: string|null}
     */
    private function createRemoteProduct(PaymentMethod $paymentMethod, SiteProductVariation $template, string $targetUsd): array
    {
        if (blank($paymentMethod->domain) || blank($paymentMethod->domain_client_id) || blank($paymentMethod->domain_client_sk)) {
            throw new RuntimeException("支付方式 {$paymentMethod->method_code} 站点配置不完整：缺少网站域名或 WooCommerce REST API 密钥，无法创建商品");
        }

        $http = Http::withBasicAuth((string) $paymentMethod->domain_client_id, (string) $paymentMethod->domain_client_sk)
            ->withoutVerifying()
            ->acceptJson()
            ->timeout(self::CREATE_HTTP_TIMEOUT);

        $base = rtrim((string) $paymentMethod->domain, '/').'/wp-json/wc/v3';

        // 复制源 = 模板变体所属的父商品（变体商品取父商品的描述/图片/分类）。
        $source = $this->remoteGetJson($http, "{$base}/products/{$template->siteProduct->woo_product_id}");

        $payload = [
            'name' => $this->resolveProductName($paymentMethod, $source),
            // 无论源商品是不是变体商品，复制体一律作为简单商品创建。
            'type' => 'simple',
            'status' => 'publish',
            'virtual' => true,
            'sku' => $this->generateUniqueSku(),
            'regular_price' => $targetUsd,
            'description' => (string) ($source['description'] ?? ''),
            'short_description' => (string) ($source['short_description'] ?? ''),
        ];

        // 同站点复制：优先直接引用已有附件，避免重复上传图片。
        if (! empty($source['images']) && is_array($source['images'])) {
            $payload['images'] = collect($source['images'])
                ->map(fn (array $image) => ! empty($image['id'])
                    ? ['id' => (int) $image['id']]
                    : ['src' => (string) ($image['src'] ?? '')])
                ->values()
                ->all();
        }

        if (! empty($source['categories']) && is_array($source['categories'])) {
            $payload['categories'] = collect($source['categories'])
                ->map(fn (array $category) => (int) ($category['id'] ?? 0))
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->map(fn (int $id) => ['id' => $id])
                ->values()
                ->all();
        }

        // POST 创建；连接层异常时先按 SKU 查远端，确认是否"请求其实已成功、只是响应丢了"。
        $created = null;

        for ($attempt = 1; $attempt <= self::CREATE_SKU_RETRIES && $created === null; $attempt++) {
            try {
                $response = $http->post("{$base}/products", $payload);
            } catch (ConnectionException $e) {
                $created = $this->findRemoteProductBySku($http, $base, (string) $payload['sku']);

                if ($created === null) {
                    throw new RuntimeException("连接站点创建商品失败：{$e->getMessage()}", 0, $e);
                }

                break;
            }

            if ($response->successful()) {
                $created = $response->json();

                break;
            }

            // SKU 撞车：重新生成一个再试。
            if ((string) $response->json('code') === 'product_sku_already_exists') {
                $payload['sku'] = $this->generateUniqueSku();

                continue;
            }

            throw new RuntimeException(sprintf(
                'WooCommerce 创建商品失败（%d）：%s',
                $response->status(),
                mb_substr($response->body(), 0, 200)
            ));
        }

        if ($created === null) {
            throw new RuntimeException('WooCommerce 创建商品失败：SKU 多次冲突，请稍后重试');
        }

        $firstImage = collect($created['images'] ?? [])->first();

        $result = [
            'woo_product_id' => (int) ($created['id'] ?? 0),
            'sku' => (string) (($created['sku'] ?? '') !== '' ? $created['sku'] : $payload['sku']),
            'name' => $this->cleanName((string) ($created['name'] ?? $payload['name'])),
            'permalink' => (string) ($created['permalink'] ?? ''),
            'image_url' => is_array($firstImage) ? ($firstImage['src'] ?? null) : null,
        ];

        if ($result['woo_product_id'] <= 0) {
            throw new RuntimeException('WooCommerce 创建商品返回了非预期的响应：'.mb_substr((string) json_encode($created), 0, 200));
        }

        $this->storeCreatedProduct($paymentMethod, $result, $targetUsd);

        return $result;
    }

    /** GET 读取远端商品；连接异常/非 2xx 直接报错，避免拿到空模板创建出残缺商品。 */
    private function remoteGetJson(PendingRequest $http, string $url): array
    {
        try {
            $response = $http->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException("连接站点读取源商品失败：{$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'WooCommerce 读取源商品失败（%d）：%s',
                $response->status(),
                mb_substr($response->body(), 0, 200)
            ));
        }

        return $response->json() ?? [];
    }

    /** 按 SKU 查远端商品，返回第一条（找不到/请求失败返回 null，仅供幂等确认用）。 */
    private function findRemoteProductBySku(PendingRequest $http, string $base, string $sku): ?array
    {
        try {
            $response = $http->get("{$base}/products", ['sku' => $sku]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $first = collect($response->json() ?? [])->first();

        return is_array($first) ? $first : null;
    }

    /**
     * 新商品名称：默认沿用源商品真实名称（最贴近真实交易，风控观感最好）；
     * 源名称为空时回退"虚拟商品前缀 + 随机串"（前缀取自支付方式配置）。
     */
    private function resolveProductName(PaymentMethod $paymentMethod, array $source): string
    {
        $name = $this->cleanName((string) ($source['name'] ?? ''));

        if ($name !== '') {
            return mb_substr($name, 0, 255);
        }

        $random = strtoupper(Str::random(8));
        $prefix = trim((string) $paymentMethod->virtual_product_prefix);

        return mb_substr($prefix !== '' ? $prefix.' '.$random : $random, 0, 255);
    }

    /** 生成一个本地变体池中尚未使用的唯一 SKU。 */
    private function generateUniqueSku(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $sku = strtoupper(Str::random(12));

            if (! SiteProductVariation::query()->where('sku', $sku)->exists()) {
                return $sku;
            }
        }

        // 理论上不可能连续撞 5 次；兜底加时间戳保证唯一。
        return strtoupper(Str::random(8)).now()->format('His');
    }

    /**
     * 把站点上新创建的商品回写本地快照：简单商品按"主商品自身作为唯一变体"
     * 的结构写入（与全量同步的口径一致），之后的订单可直接匹配到它，
     * 商品同步也不会把它误删。
     *
     * @param  array{woo_product_id: int, sku: string, name: string, permalink: string, image_url: string|null}  $created
     */
    private function storeCreatedProduct(PaymentMethod $paymentMethod, array $created, string $targetUsd): void
    {
        DB::transaction(function () use ($paymentMethod, $created, $targetUsd) {
            $product = SiteProduct::query()->updateOrCreate(
                [
                    'payment_method_id' => $paymentMethod->id,
                    'woo_product_id' => $created['woo_product_id'],
                ],
                [
                    'merchant_id' => $paymentMethod->merchant_id,
                    'product_type' => 'simple',
                    'name' => mb_substr($created['name'], 0, 500),
                    'sku' => $created['sku'],
                    'price_min' => $targetUsd,
                    'price_max' => $targetUsd,
                    'currency' => 'USD',
                    'image_url' => $created['image_url'],
                    'permalink' => $created['permalink'],
                    'synced_at' => now(),
                ]
            );

            SiteProductVariation::query()->updateOrCreate(
                [
                    'site_product_id' => $product->id,
                    // 简单商品没有变体，与同步口径一致：主商品自身作为唯一变体。
                    'woo_variation_id' => $created['woo_product_id'],
                ],
                [
                    'sku' => $created['sku'],
                    'price' => $targetUsd,
                    'currency' => 'USD',
                ]
            );
        });
    }

    private function cleanName(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/[\r\n\t]+/u', ' ', $name) ?? $name;

        return trim($name);
    }
}
