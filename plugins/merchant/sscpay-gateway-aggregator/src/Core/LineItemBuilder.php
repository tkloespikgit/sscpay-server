<?php
/**
 * 交易明细 -> 支付通道行项目 的组装规则，按 trans_type 分四种模式。
 *
 * 放在 Core 而不是某个 Gateway 里，是因为这套规则对所有通道都成立：PayPal 的
 * purchase_units[].items 和 Stripe 的 line_items 用的是同一份结果，各 Gateway
 * 只负责把它翻译成自家 API 的字段名。如果写进各网关，"CREATE 模式要传SKU"
 * 这类规则就得在每个网关里各抄一遍，迟早会抄漏一个。
 */

namespace PaymentGatewayAggregator\Core;

use PaymentGatewayAggregator\Models\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

class LineItemBuilder
{
    /**
     * 按交易的 trans_type 组装行项目。
     *
     * - MATCH  ：name=商品名，sku=商品SKU，数量/单价原样（与引入 trans_type 之前一致）
     * - CREATE ：name=商品SKU（SKU缺失时退回商品名，不至于传个空名字），其余同 MATCH
     * - VIRTUAL：忽略库里存的 items（/v1/pay 仍要求必传，只是不参与通道组装），
     *            固定合成一条：name=subject、sku=S系统订单号、数量=1、单价=subtotal
     * - COPY   ：逐条读 items 并原样拷贝，name=商品名、sku=商品SKU，不做 MATCH
     *            那种名为空退回SKU的兜底，也不像 VIRTUAL 合成单条
     *
     * @param array $transaction wp_payment_transactions 的一行（findBySOrderId()等的返回值）
     * @return array[] 统一形状：['name'=>string,'sku'=>string,'url'=>string,'quantity'=>int,'price'=>float]
     */
    public static function build(array $transaction)
    {
        $transType = Transaction::normalizeTransType($transaction['trans_type'] ?? '');

        if ($transType === Transaction::TRANS_TYPE_VIRTUAL) {
            return [self::buildVirtualItem($transaction)];
        }

        $isCreateMode = $transType === Transaction::TRANS_TYPE_CREATE;
        $isCopyMode   = $transType === Transaction::TRANS_TYPE_COPY;

        $items = [];
        foreach (self::decodeItems($transaction['items'] ?? '') as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $sku  = trim((string) ($item['sku'] ?? ''));

            if ($isCopyMode) {
                // COPY：原样拷贝，name 就是商品名，不做名为空退回SKU的兜底
                $itemName = $name;
            } elseif ($isCreateMode) {
                $itemName = $sku !== '' ? $sku : $name;
            } else {
                $itemName = $name !== '' ? $name : $sku;
            }

            $items[] = [
                'name'     => $itemName,
                'sku'      => $sku,
                // 商品详情页链接：S系统在调 /v1/pay 之前就已经把商品建进站内并把
                // product_url 一起传过来了，直接用它，不再按SKU回查本地商品库——
                // 回查会命中变体（product_variation是public=false的post type，
                // get_permalink()只能拼出 ?post_type=product_variation&p=xx 这种
                // 买家打不开的链接），也会命中草稿商品。
                'url'      => self::normalizeUrl($item['product_url'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'price'    => (float) ($item['price'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * 账单号：所有通道、所有 trans_type 模式下都要带上（各通道的落位见
     * PayPalRestClient::createOrder() 的 invoice_id、StripeGateway/AirwallexGateway
     * 的 metadata）。
     *
     * 长度和字符集在入参校验时已经卡死（Validator::isInvoiceNumber），这里只做 trim。
     * 返回空字符串表示这笔交易没有账单号（1.4.0 之前建的历史记录），调用方应跳过不写，
     * 避免把空值传给三方。
     *
     * @param array $transaction
     * @return string
     */
    public static function invoiceNumber(array $transaction)
    {
        return trim((string) ($transaction['invoice_number'] ?? ''));
    }

    /**
     * VIRTUAL 模式的唯一一条虚拟商品。subject/subtotal 都来自交易记录本身，
     * 不读 items——这个模式表达的语义就是"不按真实商品行收款，按订单主题收一笔总额"。
     *
     * sku 不用S系统内部订单号(s_order_id)标记，改用账单号(invoice_no)——不向支付
     * 通道暴露内部订单号；历史交易没有账单号（1.4.0之前）时留空，不回退到s_order_id。
     *
     * @param array $transaction
     * @return array
     */
    private static function buildVirtualItem(array $transaction)
    {
        return [
            'name'     => trim((string) ($transaction['subject'] ?? '')),
            'sku'      => self::invoiceNumber($transaction),
            // 这一条是合成的，不对应任何真实商品，没有详情页可指，退而给店铺首页。
            'url'      => self::shopUrl(),
            'quantity' => 1,
            'price'    => (float) ($transaction['subtotal'] ?? 0),
        ];
    }

    /**
     * VIRTUAL 那条虚拟商品的链接：店铺首页，取不到就退回站点首页。
     *
     * 刻意不用支付结果页/order-received 页：那两个地址分别带着 pay_token 和
     * WooCommerce order key，都是能力凭证（pay_token 能调公开的 /v1/paypal-js/capture，
     * order key 能让游客看到整张订单），而 items[].url 是要交给通道、并展示在买家
     * 交易详情里的，等于把凭证公开出去。这里只能放不含任何密钥的公开地址。
     *
     * @return string
     */
    private static function shopUrl()
    {
        if (function_exists('wc_get_page_permalink')) {
            $shopUrl = self::normalizeUrl(wc_get_page_permalink('shop'));
            if ($shopUrl !== '') {
                return $shopUrl;
            }
        }

        return self::normalizeUrl(home_url('/'));
    }

    /**
     * S系统传来的 product_url 只做最低限度的合法性检查：必须是 http/https 的绝对地址。
     * 不合法就当没传（返回空串）——商品链接对各通道都是可选字段，为了一个链接让
     * 整笔订单被通道拒掉不值得（PayPal 对 items[].url 会校验格式）。
     *
     * @param mixed $url
     * @return string
     */
    private static function normalizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host   = (string) wp_parse_url($url, PHP_URL_HOST);

        return (in_array($scheme, ['http', 'https'], true) && $host !== '') ? $url : '';
    }

    /**
     * items 在库里是 wp_json_encode 出来的字符串（见 TransactionManager::create()），
     * 但为了兼容直接传数组的调用路径，两种都处理；数组元素本身是JSON字符串的
     * 异常情况也一并解开（和 OrderManager::normalizeItems() 的容错口径一致）。
     *
     * @param mixed $items
     * @return array[]
     */
    private static function decodeItems($items)
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
}
