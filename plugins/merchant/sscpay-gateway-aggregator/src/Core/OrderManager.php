<?php
/**
 * WordPress/WooCommerce订单管理——网关无关的部分统一放这里
 * （评审指出v1把订单创建职责错放进了各Gateway类，这里收敛）。
 *
 * 依赖WooCommerce（非功能需求：WooCommerce >= 5.0），订单创建统一走
 * wc_create_order() 等官方API，不使用自定义文章类型。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class OrderManager
{
    /**
     * @throws \RuntimeException WooCommerce未启用
     */
    public static function assertWooCommerceActive()
    {
        if (!class_exists('WC_Order') || !function_exists('wc_create_order')) {
            throw new \RuntimeException('WOOCOMMERCE_NOT_ACTIVE');
        }
    }

    /**
     * 根据S系统传入的订单数据创建一个WooCommerce订单，状态为pending。
     *
     * @param  array  $sOrderData  已通过参数校验的完整请求体（见PayController）
     * @return int wp_order_id
     */
    public static function createOrder(array $sOrderData)
    {
        self::assertWooCommerceActive();

        $order = wc_create_order(['status' => 'pending']);
        $order->set_customer_ip_address($sOrderData['customer_ip'] ?? '');

        // 客户信息
        $order->set_billing_email($sOrderData['customer']['email']);
        $order->set_billing_first_name($sOrderData['customer']['name']);
        if (!empty($sOrderData['customer']['phone'])) {
            $order->set_billing_phone($sOrderData['customer']['phone']);
        }

        self::applyAddress($order, 'billing', $sOrderData['billing_address']);
        self::applyAddress($order, 'shipping', $sOrderData['shipping_address']);

        // 商品行：根据product_id/sku解析出站内对应的WooCommerce商品并挂接，
        // 后台订单详情即可展示商品图片；解析不到时用Fee行项目容错。
        foreach (self::normalizeItems($sOrderData['items']) as $item) {
            self::addLineItem($order, $item);
        }

        // 订单折扣：挂优惠券行项目记录，并把折扣额按比例分摊到各商品行的total上，
        // 须在运费/税费加入前处理，避免折扣侵蚀运费和税费。
        if (!empty($sOrderData['discount_fee']) && (float) $sOrderData['discount_fee'] > 0) {
            self::applyDiscount($order, (float) $sOrderData['discount_fee']);
        }

        if (!empty($sOrderData['shipping_fee']) && (float) $sOrderData['shipping_fee'] > 0) {
            $shippingItem = new \WC_Order_Item_Shipping();
            $shippingItem->set_method_title('Shipping');
            $shippingItem->set_total((string) $sOrderData['shipping_fee']);
            $order->add_item($shippingItem);
        }

        // 税费：以Fee行项目体现；tax_status置为none，防止被税率规则重复计税。
        if (!empty($sOrderData['tax_fee']) && (float) $sOrderData['tax_fee'] > 0) {
            $taxFee = new \WC_Order_Item_Fee();
            $taxFee->set_name('Tax');
            $taxFee->set_total((string) $sOrderData['tax_fee']);
            $taxFee->set_tax_status('none');
            $order->add_item($taxFee);
        }

        $order->set_currency($sOrderData['currency']);
        $order->update_meta_data('_s_order_id', $sOrderData['s_order_id']);
        $order->update_meta_data('_pga_payment_method', $sOrderData['payment_method']);
        $order->add_order_note(sprintf('由支付网关聚合插件创建，S系统订单号：%s', $sOrderData['s_order_id']));

        $order->calculate_totals();

        // 兜底：正常情况下 商品小计+运费+税费-折扣 === amount，若分摊截断/舍入产生
        // 偏差，把订单总额强制对齐S系统金额，保证订单金额与实际收款一致。
        $expectedTotal = (float) ($sOrderData['amount'] ?? 0);
        if ($expectedTotal > 0 && abs((float) $order->get_total() - $expectedTotal) > 0.01) {
            $order->add_order_note(sprintf(
                'WooCommerce计算的订单总额(%s)与S系统金额(%s)不一致，已强制对齐',
                $order->get_total(),
                $expectedTotal
            ));
            $order->set_total($expectedTotal);
        }

        $order->save();

        return $order->get_id();
    }

    /**
     * @param  \WC_Order  $order
     * @param  string  $type  'billing' | 'shipping'
     * @param  array  $address
     */
    private static function applyAddress($order, $type, array $address)
    {
        $setter = 'set_'.$type.'_address';
        if (method_exists($order, $setter)) {
            $order->{$setter}([
                'country'   => $address['country'] ?? '',
                'state'     => $address['state'] ?? '',
                'city'      => $address['city'] ?? '',
                'address_1' => $address['address'] ?? '',
                'postcode'  => $address['zip'] ?? '',
            ]);
            return;
        }

        // 兼容不同WC版本的字段setter命名
        $prefix = $type.'_';
        $map    = [
            'country'   => $address['country'] ?? '',
            'state'     => $address['state'] ?? '',
            'city'      => $address['city'] ?? '',
            'address_1' => $address['address'] ?? '',
            'postcode'  => $address['zip'] ?? '',
        ];
        foreach ($map as $field => $value) {
            $method = 'set_'.$prefix.$field;
            if ($value !== '' && method_exists($order, $method)) {
                $order->{$method}($value);
            }
        }
    }

    /**
     * 归一化S系统传入的商品列表：items整体或其元素可能是JSON字符串，统一转成数组。
     *
     * @param  mixed  $items
     * @return array
     */
    private static function normalizeItems($items)
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items   = is_array($decoded) ? $decoded : [];
        }

        $normalized = [];
        foreach ((array) $items as $item) {
            if (is_string($item)) {
                $decoded = json_decode($item, true);
                $item    = is_array($decoded) ? $decoded : [];
            }
            if (is_array($item) && $item !== []) {
                $normalized[] = $item;
            }
        }
        return $normalized;
    }

    /**
     * @param  \WC_Order  $order
     * @param  array  $item  ['product_id'=>..,'sku'=>..,'name'=>..,'quantity'=>..,'price'=>..]
     */
    private static function addLineItem($order, array $item)
    {
        $quantity  = max(1, (int) ($item['quantity'] ?? 1));
        $lineTotal = (float) ($item['price'] ?? 0) * $quantity;

        $product = self::resolveProduct($item);

        if ($product) {
            $orderItem = new \WC_Order_Item_Product();
            $orderItem->set_product($product);
            $orderItem->set_quantity($quantity);
            $orderItem->set_subtotal((string) $lineTotal);
            $orderItem->set_total((string) $lineTotal);
            $order->add_item($orderItem);
            return;
        }

        // SKU/product_id在本地商品库找不到：不阻断下单，退化成一个具名的Fee行，
        // 保留商品名称、数量、金额信息，便于人工核对。
        $order->add_order_note(sprintf(
            '商品 %s（SKU: %s，product_id: %s）在站内商品库未找到，该行降级为Fee行项目',
            $item['name'] ?? 'Unknown item',
            $item['sku'] ?? 'N/A',
            $item['product_id'] ?? 'N/A'
        ));
        $fee = new \WC_Order_Item_Fee();
        $fee->set_name(sprintf(
            '%s (SKU: %s) x%d',
            $item['name'] ?? 'Unknown item',
            $item['sku'] ?? 'N/A',
            $quantity
        ));
        $fee->set_total((string) $lineTotal);
        $order->add_item($fee);
    }

    /**
     * 根据S系统的商品数据解析站内对应商品（简单商品或变体）。
     *
     * S系统字段语义：product_id为简单商品的商品ID、变体商品的父商品ID；
     * sku为简单商品的商品SKU、变体商品的变体SKU。实际字段值可能与约定有出入，
     * 因此以SKU精确匹配为主（可唯一定位简单商品或变体），product_id兜底。
     *
     * @param  array  $item
     * @return \WC_Product|null 找不到返回null（调用方走Fee容错）
     */
    private static function resolveProduct(array $item)
    {
        $sku            = trim((string) ($item['sku'] ?? ''));
        $givenProductId = absint($item['product_id'] ?? 0);

        // 1) SKU匹配：简单商品返回商品ID，变体商品返回变体ID。
        if ($sku !== '') {
            $productId = wc_get_product_id_by_sku($sku);
            // wc_get_product_id_by_sku()依赖wc_product_meta_lookup表，批量导入的商品
            // 可能未同步该表导致查不到，退化为直接查_sku postmeta。
            if (!$productId) {
                $productId = self::findProductIdBySkuMeta($sku);
            }
            if ($productId && ($product = wc_get_product($productId))) {
                return $product;
            }
        }

        // 2) product_id兜底：变体直接用；可变商品在其子变体中按SKU匹配；
        // 其余（简单商品）直接使用。
        if ($givenProductId > 0) {
            $candidate = wc_get_product($givenProductId);
            if ($candidate) {
                if ($sku === '') {
                    return $candidate;
                }
                if ($candidate->is_type('variation')) {
                    return $candidate;
                }
                if ($candidate->is_type('variable')) {
                    foreach ((array) $candidate->get_children() as $childId) {
                        $child = wc_get_product($childId);
                        if ($child && strcasecmp((string) $child->get_sku(), $sku) === 0) {
                            return $child;
                        }
                    }
                    return null;
                }
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 按SKU查商品ID的兜底方案：直接查postmeta，不经过wc_product_meta_lookup表，
     * 覆盖product和product_variation两种post_type，SKU重复时优先变体。
     *
     * @param  string  $sku
     * @return int 找不到为0
     */
    private static function findProductIdBySkuMeta($sku)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
             WHERE pm.meta_value = %s
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')
             ORDER BY p.post_type DESC, p.ID ASC
             LIMIT 1",
            $sku
        ));
    }

    /**
     * 订单折扣处理：为订单挂一张优惠券行项目记录折扣，并把折扣额按各行小计比例
     * 分摊到商品行total上（WooCommerce的折扣原生表现就是行total小于行subtotal，
     * calculate_totals()按 小计-total 之差得出折扣额并计入订单总额）。
     *
     * @param  \WC_Order  $order
     * @param  float  $discountFee  折扣金额（正数）
     */
    private static function applyDiscount($order, $discountFee)
    {
        $couponItem = new \WC_Order_Item_Coupon();
        $couponItem->set_code('Discount');
        $couponItem->set_discount((string) $discountFee);
        $order->add_item($couponItem);

        $decimals = wc_get_price_decimals();

        // 折扣只在商品行（含解析失败退化的Fee行）之间分摊；此时运费/税费尚未加入订单。
        $items = [];
        $bases = [];
        foreach ($order->get_items(['line_item', 'fee']) as $item) {
            $base = (float) ($item instanceof \WC_Order_Item_Fee ? $item->get_total() : $item->get_subtotal());
            if ($base > 0) {
                $items[] = $item;
                $bases[] = $base;
            }
        }

        $baseTotal = array_sum($bases);
        if ($baseTotal <= 0) {
            return;
        }

        $allocated = 0.0;
        $lastIndex = count($items) - 1;
        foreach ($items as $i => $item) {
            if ($i === $lastIndex) {
                // 最后一行兜底剩余部分，保证分摊总额恰好等于折扣额。
                $share = $discountFee - $allocated;
            } else {
                $share     = round($discountFee * ($bases[$i] / $baseTotal), $decimals, PHP_ROUND_HALF_DOWN);
                $allocated += $share;
            }
            // 单行分摊不能超过该行自身金额，避免行total变成负数。
            $share = min(max($share, 0.0), $bases[$i]);
            if ($share <= 0) {
                continue;
            }
            $item->set_total((string) round($bases[$i] - $share, $decimals, PHP_ROUND_HALF_DOWN));
        }
    }

    /**
     * 支付成功后调用，把订单状态推进为处理中/已完成，具体状态可在设置页配置，
     * 默认用 'processing'（表示"已付款，待发货"，符合大多数电商场景）。
     *
     * @param  int  $wpOrderId
     * @param  string  $transactionId
     */
    public static function markPaid($wpOrderId, $transactionId)
    {
        self::assertWooCommerceActive();

        $order = wc_get_order($wpOrderId);
        if (!$order) {
            throw new \RuntimeException('WP_ORDER_NOT_FOUND');
        }

        if ($order->payment_complete($transactionId) === false) {
            throw new \RuntimeException('WooCommerce订单付款更新失败');
        }
        $order->add_order_note(sprintf('支付成功，三方交易ID：%s', $transactionId));
    }

    public static function markFailed($wpOrderId, $reason = '')
    {
        self::assertWooCommerceActive();

        $order = wc_get_order($wpOrderId);
        if (!$order) {
            throw new \RuntimeException('WP_ORDER_NOT_FOUND');
        }

        if ($order->update_status('failed', $reason ? ('支付失败：'.$reason) : '支付失败') === false) {
            throw new \RuntimeException('WooCommerce订单失败状态更新失败');
        }
    }

    public static function markCancelled($wpOrderId, $reason = '')
    {
        self::assertWooCommerceActive();

        $order = wc_get_order($wpOrderId);
        if (!$order) {
            throw new \RuntimeException('WP_ORDER_NOT_FOUND');
        }

        if ($order->update_status('cancelled', $reason ?: '订单已取消') === false) {
            throw new \RuntimeException('WooCommerce订单取消状态更新失败');
        }
    }

    /**
     * 物流信息同步（对应 /v1/sync-tracking）。
     *
     * @param  int  $wpOrderId
     * @param  array  $trackingData tracking_number/carrier_code/is_other_carrier(Y/N)/
     *                              tracking_url/shipped_at（见SyncTrackingController）
     */
    public static function applyTracking($wpOrderId, array $trackingData)
    {
        self::assertWooCommerceActive();

        $order = wc_get_order($wpOrderId);
        if (!$order) {
            throw new \RuntimeException('WP_ORDER_NOT_FOUND');
        }

        $carrierCode = (string) $trackingData['carrier_code'];
        $isOtherCarrier = ($trackingData['is_other_carrier'] ?? 'N') === 'Y';

        $order->update_meta_data('_tracking_number', $trackingData['tracking_number']);
        $order->update_meta_data('_carrier_code', $carrierCode);
        $order->update_meta_data('_is_other_carrier', $isOtherCarrier ? 'Y' : 'N');
        // _shipping_company 是改造前的历史meta key，继续同步写入以兼容基于它展示的
        // 旧逻辑；新代码一律改用 _carrier_code / _is_other_carrier。
        $order->update_meta_data('_shipping_company', $carrierCode);
        if (!empty($trackingData['tracking_url'])) {
            $order->update_meta_data('_tracking_url', $trackingData['tracking_url']);
        }
        if (!empty($trackingData['shipped_at'])) {
            $order->update_meta_data('_ship_date', $trackingData['shipped_at']);
        }
        $order->save();

        $note = sprintf(
            '已发货：%s（承运商：%s%s）%s',
            $trackingData['tracking_number'],
            $carrierCode,
            $isOtherCarrier ? '，其它承运商' : '',
            !empty($trackingData['remark']) ? ' 备注：'.$trackingData['remark'] : ''
        );
        $order->add_order_note($note);
    }
}
