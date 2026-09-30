<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderMatchedItem;
use App\Models\PaymentMethod;
use App\Models\ReplaceKeyword;
use App\Models\SiteProduct;
use App\Models\SiteProductVariation;
use App\Models\SystemConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 订单商品服务：下单未传商品明细时，从选定支付方式绑定的站点商品
 * 变体（site_product_variations）中自动匹配出一份商品明细。
 *
 * 匹配规则：
 *   - 匹配对象是变体数据（每个变体有独立的 SKU / 价格），父商品只用于
 *     取商品名称与详情页链接；
 *   - 变体原价按汇率折算成订单币种后匹配；每轮从买得起的最高价 10 个候选中随机选取；
 *   - 余额不足一件时，追加最接近余额的商品原价，超额作为订单折扣返回，不修改商品名称或单价；
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
 *
 * COPY 模式（copyItems）：
 *   - 先用商户配置的关键词替换表（ReplaceKeyword）把订单明细商品名忽略大小写替换一遍
 *     （如 Mechanical → MAD），再按"替换后名称完全相同 + USD 折算价 5% 容差内"在站点
 *     商品变体中找同名同价商品；找不到就取"价格相等或更高且最接近"的变体作模板，
 *     复制一份改名（换成替换后的名称）、改价并同步创建，SKU 规则与 CREATE 一致（随机生成）。
 */
class OrderItemService
{
    /** 每轮挑选时参与随机抽取的候选池大小（价格最高/最低的前 N 个）。 */
    private const RANDOM_POOL_SIZE = 10;

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
     *                                                                       items：字段结构同下单 items（单价/小计为订单币种，
     *                                                                       converted_unit_price 为美金原价）；
     *                                                                       subtotal：匹配明细行小计之和（订单币种）；
     *                                                                       overflow：商品小计超出目标金额的部分（订单币种），计入订单 discount
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

        // 先折算单价再匹配，避免逐行换汇截断后商品总额低于目标金额。
        $lines = $this->fillByGreedy($candidates, $targetGoodsAmount, $actualRate);
        $items = [];
        $subtotal = '0.00';

        foreach ($lines as $line) {
            $unitPrice = $line['price'];
            $totalPrice = bcmul($unitPrice, (string) $line['quantity'], 2);
            $subtotal = bcadd($subtotal, $totalPrice, 2);
            $variation = $line['variation'];
            $product = $variation->siteProduct;

            $items[] = [
                'product_sku' => $variation->sku,
                'product_id' => (string) $variation->woo_variation_id,
                'product_url' => $product->permalink,
                'product_name' => $product->name,
                'product_description' => null,
                'unit_price' => $unitPrice,
                'quantity' => $line['quantity'],
                'total_price' => $totalPrice,
                'converted_unit_price' => $this->variationPrice($variation),
            ];
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'overflow' => bcsub($subtotal, $targetGoodsAmount, 2),
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
     * @param  Collection<int, OrderMatchedItem>|null  $reusableCreated
     *                                                                   幂等补单重试时上一轮已自动创建的商品行，按 USD 单价复用，避免站点堆积重复商品。
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

    /**
     * COPY 模式：先用商户配置的关键词替换表把订单明细商品名忽略大小写替换一遍，
     * 再按"替换后名称完全相同 + USD 折算价 5% 容差内"在站点商品变体中找同名同价商品；
     * 找不到就取"价格相等或更高且最接近"的变体作模板，复制一份改成替换后的名称、
     * 改价并在 WordPress 站点上同步创建（SKU 随机生成，规则与 CREATE 一致）。
     * 返回结构与 createItems() 一致，明细额外携带 source_variation_id / auto_created 两个字段。
     *
     * @param  PaymentMethod  $paymentMethod  选定的支付方式（商品按其站点配置同步/创建）
     * @param  Order  $order  订单（取商户下单明细，明细自带下单时快照的 USD 折算价）
     * @param  Collection<int, OrderMatchedItem>|null  $reusableCreated
     *                                                                   幂等补单重试时上一轮已自动创建的商品行，按"USD 单价 + 替换后名称"复合键复用，
     *                                                                   避免站点堆积重复商品；不同名字的商品即使同价也不能互相顶替，所以不能像 CREATE
     *                                                                   那样只按价格复用。
     */
    public function copyItems(PaymentMethod $paymentMethod, Order $order, ?Collection $reusableCreated = null): array
    {
        $orderItems = $order->items()->get();

        if ($orderItems->isEmpty()) {
            throw new RuntimeException('订单没有商品明细，COPY 模式无法匹配商品');
        }

        $candidates = SiteProductVariation::query()
            ->whereHas('siteProduct', fn ($query) => $query->where('payment_method_id', $paymentMethod->id))
            ->where('price', '>', 0)
            ->with('siteProduct')
            ->get();

        if ($candidates->isEmpty()) {
            throw new RuntimeException("支付方式 {$paymentMethod->method_code} 没有可用于匹配的站点商品，请先同步商品");
        }

        // 补单重试复用池：按"USD 单价 + 替换后名称（忽略大小写）"复合键索引上一轮已创建的商品行。
        $reusable = collect();

        foreach ($reusableCreated ?? [] as $row) {
            if (filled($row->product_id)) {
                $key = number_format((float) $row->converted_unit_price, 2, '.', '').'|'.mb_strtolower((string) $row->product_name);
                $reusable->put($key, $row);
            }
        }

        $createdThisRun = []; // 本次运行内同名同价商品复用（复合键 => 创建结果）
        $items = [];
        $subtotal = '0';

        foreach ($orderItems as $orderItem) {
            $targetUsd = number_format((float) $orderItem->converted_unit_price, 2, '.', '');

            if (bccomp($targetUsd, '0', 2) <= 0) {
                throw new RuntimeException('订单明细折算后的美金单价必须大于 0，COPY 模式无法匹配商品');
            }

            $subtotal = bcadd($subtotal, (string) $orderItem->total_price, 2);

            // 关键词替换规则是按商户维护的，要用订单所属商户，而不是支付方式的商户：
            // 系统级支付方式 merchant_id 为 NULL，传进去会直接 TypeError。
            $replacedName = mb_substr(
                $this->cleanName(ReplaceKeyword::applyReplacements($orderItem->product_name, $order->merchant_id)),
                0,
                255
            );

            // 1. 同名 + 同价匹配：先按替换后名称过滤，再复用 CREATE 同样的价差排序规则——
            // 打乱后按价差升序排，价格完全相等的候选天然排最前，取价差在容差内且最小的一个。
            $diff = fn (SiteProductVariation $variation) => abs((float) $variation->price - (float) $targetUsd);

            $hit = $candidates
                ->filter(fn (SiteProductVariation $variation) => mb_strtolower($variation->siteProduct->name) === mb_strtolower($replacedName))
                ->shuffle()
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

            // 2. 找不到同名同价：取价格相等或更高且最接近的变体作复制模板；没有更贵的直接报错。
            $template = $candidates
                ->filter(fn (SiteProductVariation $variation) => bccomp($this->variationPrice($variation), $targetUsd, 2) >= 0)
                ->sortBy(fn (SiteProductVariation $variation) => (float) $variation->price)
                ->first();

            if ($template === null) {
                throw new RuntimeException("支付方式 {$paymentMethod->method_code} 站点没有单价不低于 {$targetUsd} 美金的商品，COPY 模式无法复制改价创建商品");
            }

            // 3. 复用顺序：本次运行内同名同价已创建的（多行补单时后续行必须指向同一商品）
            // > 补单重试上一轮已创建的 > 真正调站点 API 新建。
            $reuseKey = $targetUsd.'|'.mb_strtolower($replacedName);
            $reuseRow = $reusable->get($reuseKey);

            if (isset($createdThisRun[$reuseKey])) {
                $created = $createdThisRun[$reuseKey];
            } elseif ($reuseRow !== null) {
                $created = [
                    'woo_product_id' => (int) $reuseRow->product_id,
                    'sku' => $reuseRow->product_sku,
                    'name' => $reuseRow->product_name,
                    'permalink' => $reuseRow->product_url,
                ];
            } else {
                $created = $createdThisRun[$reuseKey] = $this->remoteCreateProduct(
                    $paymentMethod,
                    $template,
                    $targetUsd,
                    fn () => $replacedName,
                );
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
     * 按订单币种原价贪心匹配；尾件保留原价，遵守随机件数上限和配置硬上限。
     *
     * @param  Collection<int, SiteProductVariation>  $candidates
     * @return list<array{variation: SiteProductVariation, price: string, quantity: int}>
     */
    private function fillByGreedy(Collection $candidates, string $targetAmount, string $actualRate): array
    {
        $priceOf = fn (SiteProductVariation $variation) => bcdiv($this->variationPrice($variation), $actualRate, 2);
        $candidates = $candidates->filter(fn (SiteProductVariation $variation) => bccomp($priceOf($variation), '0', 2) > 0);
        if ($candidates->isEmpty()) {
            throw new RuntimeException('站点商品折算后的单价均小于 0.01，无法自动匹配商品');
        }
        $lines = [];
        $remaining = $targetAmount;

        // 单品件数上限的临界值（0 或配成非正数表示不限制）。
        $hardMax = (int) SystemConfig::get('order_match.max_item_quantity', self::DEFAULT_MAX_ITEM_QUANTITY);

        if ($hardMax <= 0) {
            $hardMax = PHP_INT_MAX;
        }

        // 某变体已分配的件数（variation id => 件数）：同一变体可能在多轮里被选中、
        // 也可能出现在末件原价行，用 map 随行增删同步维护，过滤时 O(1) 查询；
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
        // 明细的件数分布，不会让匹配提前进入追加尾件甚至直接失败。
        $hardLimit = fn (SiteProductVariation $variation) => $hardMax;

        // 未达件数上限的候选变体。
        $poolUnder = fn (callable $limit) => $candidates->filter(
            fn (SiteProductVariation $variation) => $allocatedQty($variation) < $limit($variation)
        );

        // 剩余额度买得起、且未达件数上限的候选池：单价最高的 RANDOM_POOL_SIZE 个。
        $affordableUnder = function (callable $limit) use ($poolUnder, &$remaining, $priceOf) {
            return $poolUnder($limit)
                ->filter(fn (SiteProductVariation $variation) => bccomp($priceOf($variation), $remaining, 2) <= 0)
                ->sortByDesc(fn (SiteProductVariation $variation) => (float) $variation->price)
                ->take(self::RANDOM_POOL_SIZE);
        };

        // 商品池按件数临界值计算的总容量不够打满目标金额时直接报错，
        // 避免硬凑出改价离谱的明细行。
        if ($hardMax !== PHP_INT_MAX) {
            $capacity = '0';

            foreach ($candidates as $variation) {
                $capacity = bcadd($capacity, bcmul($priceOf($variation), (string) $hardMax, 2), 2);
            }

            if (bccomp($targetAmount, $capacity, 2) > 0) {
                throw new RuntimeException("站点商品容量不足：按单品最多 {$hardMax} 件计算，可用商品总额 {$capacity}（订单币种），低于目标金额 {$targetAmount}（订单币种），无法自动匹配商品");
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
            $price = $priceOf($picked);

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
            ];
            $allocated[$picked->id] = ($allocated[$picked->id] ?? 0) + $quantity;
            $remaining = bcsub($remaining, bcmul($price, (string) $quantity, 2), 2);
        }

        // 剩余预算不足一件时按原价追加；超出部分由订单级折扣抵扣。
        if (bccomp($remaining, '0', 2) > 0) {
            $picked = $poolUnder($randomLimit)->sortBy($priceOf)->first()
                ?? $poolUnder($hardLimit)->sortBy($priceOf)->first();

            if ($picked === null) {
                throw new RuntimeException('站点商品均已达到最大匹配件数，无法继续匹配商品');
            }

            $lines[] = ['variation' => $picked, 'price' => $priceOf($picked), 'quantity' => 1];
        }

        return $lines;
    }

    /** 把变体价格规范成两位小数字符串，供 bcmath 比较/计算。 */
    private function variationPrice(SiteProductVariation $variation): string
    {
        return number_format((float) $variation->price, 2, '.', '');
    }

    /**
     * CREATE 模式的远程创建：名称沿用源商品真实名称（空名称才回退"虚拟商品前缀 + 随机串"），
     * 其余委托给 remoteCreateProduct()。
     *
     * @return array{woo_product_id: int, sku: string, name: string, permalink: string, image_url: string|null}
     */
    private function createRemoteProduct(PaymentMethod $paymentMethod, SiteProductVariation $template, string $targetUsd): array
    {
        return $this->remoteCreateProduct(
            $paymentMethod,
            $template,
            $targetUsd,
            fn (array $source) => $this->resolveProductName($paymentMethod, $source),
        );
    }

    /**
     * 在支付方式对应的 WordPress 站点上创建一个简单商品（WooCommerce REST API
     * /wc/v3/products，用站点配置的 ck/cs 密钥）：复制模板所属父商品的描述/图片/分类，
     * 名称由调用方通过 $resolveName 决定（CREATE 模式沿用源商品真实名称；COPY 模式固定用
     * 关键词替换后的名称），SKU 随机生成，价格改为目标美金价。源商品是变体商品时，
     * 复制体也一律按简单商品创建。创建成功后把新商品回写本地快照表，之后的订单可直接匹配到它。
     * CREATE / COPY 两种模式共用这份 HTTP 创建逻辑，只有名称来源不同。
     *
     * @param  callable(array): string  $resolveName  接收远端源商品数据，返回新商品名称
     * @return array{woo_product_id: int, sku: string, name: string, permalink: string, image_url: string|null}
     */
    private function remoteCreateProduct(PaymentMethod $paymentMethod, SiteProductVariation $template, string $targetUsd, callable $resolveName): array
    {
        if (blank($paymentMethod->domain) || blank($paymentMethod->domain_client_id) || blank($paymentMethod->domain_client_sk)) {
            throw new RuntimeException("支付方式 {$paymentMethod->method_code} 站点配置不完整：缺少网站域名或 WooCommerce REST API 密钥，无法创建商品");
        }

        // 站点侧认证插件（WooKeyAuthenticator）不认标准 HTTP Basic Auth，明文 Authorization 头
        // 也可能被本地/反代环境的 Web 服务器不转发给 PHP，改用 query 参数最可靠，
        // 见 PaymentGatewayService::client() 的注释。
        $http = Http::withOptions(['query' => [
            'consumer_key' => (string) $paymentMethod->domain_client_id,
            'consumer_secret' => (string) $paymentMethod->domain_client_sk,
        ]])
            ->withoutVerifying()
            ->acceptJson()
            ->timeout(self::CREATE_HTTP_TIMEOUT);

        $base = rtrim((string) $paymentMethod->domain, '/').'/wp-json/wc/v3';

        // 复制源 = 模板变体所属的父商品（变体商品取父商品的描述/图片/分类）。
        $source = $this->remoteGetJson($http, "{$base}/products/{$template->siteProduct->woo_product_id}", $paymentMethod);

        $payload = [
            'name' => mb_substr($resolveName($source), 0, 255),
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

            $this->logRemoteFailure('WooCommerce 创建商品失败', $paymentMethod, $base, $response->status(), $response->body());

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
    private function remoteGetJson(PendingRequest $http, string $url, PaymentMethod $paymentMethod): array
    {
        try {
            $response = $http->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException("连接站点读取源商品失败：{$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            $this->logRemoteFailure('WooCommerce 读取源商品失败', $paymentMethod, $url, $response->status(), $response->body());

            throw new RuntimeException(sprintf(
                'WooCommerce 读取源商品失败（%d）：%s',
                $response->status(),
                mb_substr($response->body(), 0, 200)
            ));
        }

        return $response->json() ?? [];
    }

    /**
     * 请求 WordPress/WooCommerce 站点失败时统一打日志：凭证只记指纹（首尾各 4 位 + 长度），
     * 不落明文，方便核对"这次用的到底是哪个支付方式配的哪一把 ck/cs"，而不用去猜是不是配错了。
     */
    private function logRemoteFailure(string $reason, PaymentMethod $paymentMethod, string $url, int $status, string $body): void
    {
        Log::warning($reason, [
            'payment_method' => $paymentMethod->method_code,
            'url' => $url,
            'consumer_key_fingerprint' => $this->credentialFingerprint((string) $paymentMethod->domain_client_id),
            'consumer_secret_fingerprint' => $this->credentialFingerprint((string) $paymentMethod->domain_client_sk),
            'http_status' => $status,
            'response_body' => mb_substr($body, 0, 500),
        ]);
    }

    /** 凭证指纹：只保留首尾各 4 位和长度，既能核对"是不是同一把 key"，又不落明文。 */
    private function credentialFingerprint(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4).str_repeat('*', $length - 8).substr($value, -4)." (len={$length})";
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

    /** 生成一个本地变体池中尚未使用的唯一 SKU（CREATE / COPY 两种模式共用）。 */
    private function generateUniqueSku(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $sku = strtoupper(Str::random(10));

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
