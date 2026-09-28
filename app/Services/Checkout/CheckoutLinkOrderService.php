<?php

namespace App\Services\Checkout;

use App\Models\CheckoutLink;
use App\Models\CheckoutLinkItem;
use App\Models\Order;
use App\Rules\InternationalPhone;
use App\Services\OrderCreationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 收款链接落地页下单。定位与 ManualOrderService 完全一致：本身不实现任何
 * 下单逻辑，只把"链接配置 + 客户填的表单"翻译成 OrderCreationService 认识的
 * 数据结构，金额公式、汇率快照、风控选通道、手续费一律由后者负责。
 *
 * ⚠️ 为什么不走 HTTP 调 /api/order/create：那个接口用 App-ID + HMAC 签名鉴权，
 * api_key 一旦下发到浏览器就等于公开，任何人都能伪造下单。落地页是公开页面，
 * 只能在服务端完成建单。
 */
class CheckoutLinkOrderService
{
    public function __construct(
        private readonly OrderCreationService $orderCreationService,
    ) {}

    /**
     * @param  array  $customer  已通过 CreateCheckoutOrderRequest 校验的客户数据，
     *                           含 first_name / last_name / email / phone /
     *                           address_line1 / address_line2 / city / state /
     *                           country / zip / amount（range 模式才有），
     *                           以及 customer_ip / user_agent / accept_language
     */
    public function createOrder(CheckoutLink $link, array $customer): Order
    {
        $link->loadMissing(['merchant', 'application', 'paymentGroup', 'items']);

        $amount = $link->resolveAmount($customer['amount'] ?? null);
        $items = $this->buildItems($link, $amount);

        // 商品小计必须等于 Σ(明细)，且 amount = subtotal + shipping - discount + tax。
        // 两条公式 OrderCreationService 都会再算一遍，这里必须严格对齐，
        // 否则会被 ITEMS_SUBTOTAL_MISMATCH / AMOUNT_MISMATCH 拒掉。
        $subtotal = $this->sumItems($items);

        $data = [
            'checkout_link_id' => $link->id,
            'merchant_order_no' => $this->generateMerchantOrderNo($link),
            'platform' => CheckoutLink::ORDER_PLATFORM,
            'currency' => $link->currency,
            'group_key' => $link->paymentGroup->group_key,

            'subtotal' => $subtotal,
            'shipping_fee' => $this->money($link->shipping_fee),
            'discount' => $this->money($link->discount),
            'tax' => $this->money($link->tax),
            'amount' => $amount,

            'customer_first_name' => $customer['first_name'],
            'customer_last_name' => $customer['last_name'],
            'customer_email' => $customer['email'],
            'customer_phone' => InternationalPhone::normalize((string) $customer['phone']),

            'shipping_address_line1' => $customer['address_line1'],
            'shipping_address_line2' => $customer['address_line2'] ?? null,
            'shipping_city' => $customer['city'],
            'shipping_state' => $customer['state'] ?? null,
            'shipping_country' => strtoupper((string) $customer['country']),
            'shipping_zip' => $customer['zip'] ?? null,

            'items' => $items,

            // 回跳地址由系统生成，指回本系统自己的路由（见 OrderCreationService
            // 第 4 步的注释：收款链接来源会跳过应用绑定域名的一致性校验）。
            'return_url' => $this->pageUrl($link, 'checkout.success'),
            'cancel_url' => $this->pageUrl($link, 'checkout.cancelled'),

            // 收款链接不向商户发送额外的订单回调。
            'notify_url' => null,

            'customer_ip' => $customer['customer_ip'] ?? null,
            'user_agent' => $customer['user_agent'] ?? null,
            'accept_language' => $customer['accept_language'] ?? null,

            // 收款链接的客户是当场就要跳去付款的，不需要再补一封付款链接邮件。
            'send_mail' => false,
        ];

        $order = $this->orderCreationService->createOrder(
            data: $data,
            merchant: $link->merchant,
            application: $link->application,
            source: CheckoutLink::ORDER_SOURCE,
        );

        if ($order->wasRecentlyCreated) {
            // 用原子自增而不是读出来加一再存：同一条链接会被很多客户同时提交，
            // 读-改-写会丢计数。
            DB::table('checkout_links')->where('id', $link->id)->increment('orders_count');
        }

        return $order;
    }

    /**
     * 构造 order_items。
     *
     * 商户配了商品就按配置来；没配（range 模式，或纯固定金额没挂商品）时**必须**
     * 合成一条兜底明细——OrderCreationService 第 3 步会直接读 $data['items']
     * 并要求小计与 subtotal 相等，传空数组会让小计变成 0 而与 amount 对不上。
     *
     * @return list<array<string, mixed>>
     */
    private function buildItems(CheckoutLink $link, string $amount): array
    {
        if ($link->items->isEmpty()) {
            return [[
                'product_sku' => null,
                'product_id' => 'CL-'.$link->id,
                'product_url' => $link->url(),
                'product_name' => $link->title,
                'unit_price' => $amount,
                'quantity' => 1,
            ]];
        }

        return $link->items->map(fn (CheckoutLinkItem $item) => [
            'product_sku' => $item->product_sku,
            // product_id 在 order_items 上是必填的商户侧商品标识。收款链接的商品
            // 未必对应真实站点商品，用明细自增 ID 合成一个稳定值，保证同一条链接
            // 每次下单的同一行商品拿到相同的 product_id（便于商户侧对账）。
            'product_id' => $item->product_sku ?: 'CLI-'.$item->id,
            'product_url' => $item->product_url ?: $link->url(),
            'product_name' => $item->product_name,
            'unit_price' => $this->money($item->unit_price),
            'quantity' => (int) $item->quantity,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sumItems(array $items): string
    {
        return array_reduce(
            $items,
            fn (string $carry, array $item) => bcadd(
                $carry,
                bcmul((string) $item['unit_price'], (string) $item['quantity'], 2),
                2
            ),
            '0.00'
        );
    }

    /**
     * 商户订单号。收款链接没有"商户自己的订单号"这个概念，但 merchant_order_no
     * 是 OrderCreationService 的幂等键（merchant_id + merchant_order_no 唯一），
     * 必须生成一个。
     *
     * 每次提交都生成新的随机值 —— 收款链接的语义就是"同一条链接反复下单"，
     * 如果按链接固定或按客户邮箱固定，第二个客户会命中幂等分支拿到第一个客户的
     * 订单（连带别人的收货地址），是严重的信息泄露。
     */
    private function generateMerchantOrderNo(CheckoutLink $link): string
    {
        return 'CL'.$link->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }

    /**
     * 生成落地页相关页面的绝对地址。
     *
     * 不能直接用 route()——route() 按 APP_URL 拼域名，而收款链接跑在商户自有
     * 域名上。客户在商户域名上付完款却被跳回平台域名，品牌体验是断裂的，
     * 有些支付网关还会因为回跳域名与下单域名不一致而告警。
     */
    private function pageUrl(CheckoutLink $link, string $routeName): string
    {
        $path = route($routeName, ['slug' => $link->slug], absolute: false);

        if ($link->domain && $link->domain->isReady()) {
            return 'https://'.$link->domain->host.$path;
        }

        return rtrim((string) config('app.url'), '/').$path;
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }
}
