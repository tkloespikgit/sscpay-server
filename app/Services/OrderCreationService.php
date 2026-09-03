<?php

namespace App\Services;

use App\Exceptions\AmountMismatchException;
use App\Exceptions\CallbackDomainNotAllowedException;
use App\Exceptions\OrderItemsMismatchException;
use App\Exceptions\PaymentMethodDomainMismatchException;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Support\Facades\DB;

/**
 * 下单核心逻辑，API 下单（对外接口）和商户后台手工建单共用同一套流程，
 * 只是 $source 不同（api / manual）、调用方后续动作不同（手工建单还要发付款链接邮件）。
 *
 * 执行顺序（对应文档 2.x 节的铁律）：
 *   1. 幂等检查（merchant_id + merchant_order_no）—— 命中直接返回已存在订单，不新建。
 *   2. 金额公式校验（2.1 节）。
 *   3. 商品明细小计校验（3.8 节：subtotal 必须等于所有明细 total_price 之和）。
 *   4. 回调域名白名单校验（notify_url / return_url / cancel_url）。
 *   5. 汇率 + 汇损快照计算（2.3 节），换算出 USD 金额。
 *   6. 锁定唯一支付方式（2.4 节，最新决定：不返回列表，直接锁死）：默认按支付组做
 *      加权均匀分配 + 风控阈值筛选；商户传了 payment_method_key 时直接使用该渠道
 *      （跳过组内路由与限额风控），代价是强制校验三个回跳地址都落在该渠道绑定的
 *      电商网站域名上。
 *   7. 事务内创建订单 + 商品明细。
 *   8. 调支付网关插件 /pay 远程创建支付订单，拿到收银台支付链接（pay_url）回填订单。
 *
 * 注意：第 1~6 步都在数据库事务之外完成，只有第 7 步才开事务——这样风控检查、
 * 汇率读取这些"只读"操作不会持有不必要的事务/锁时间，失败时也不需要回滚任何东西。
 * 第 8 步同样在事务外（HTTP 调用不占事务）：远程失败时订单已落库，
 * 重新下单命中幂等分支会自动补创建（插件 /pay 对同一 s_order_id 幂等）。
 */
class OrderCreationService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentGatewayService $paymentGateway,
        private readonly OrderItemService $orderItemService,
    ) {
    }

    /**
     * @param  array  $data  已经过 FormRequest / 手工建单表单校验和字段展平的数据，
     *                       形如：merchant_order_no, platform, currency, group_key,
     *                       payment_method_key（可选，API 指定支付渠道）, subtotal,
     *                       shipping_fee, discount, tax, amount, customer_* ,
     *                       shipping_* , notify_url, return_url, cancel_url,
     *                       items（数组，每项含 product_sku/product_id/product_url/product_name/unit_price/quantity 等）
     *
     * @throws AmountMismatchException
     * @throws OrderItemsMismatchException
     * @throws CallbackDomainNotAllowedException
     * @throws PaymentMethodNotAvailableException 指定的 payment_method_key 不存在/不属于该商户/已停用
     * @throws PaymentMethodDomainMismatchException 指定渠道时回跳地址与该渠道绑定域名不一致
     * @throws PaymentGatewayException 远程创建支付订单失败（站点/凭证未配齐或插件返回业务错误）
     * @throws \App\Exceptions\NoAvailablePaymentMethodException
     */
    public function createOrder(
        array $data,
        Merchant $merchant,
        int $applicationId,
        string $source,
    ): Order {
        // 1. 幂等检查
        $existing = Order::query()
            ->forMerchant($merchant->id)
            ->where('merchant_order_no', $data['merchant_order_no'])
            ->first();

        if ($existing) {
            // 幂等命中：上次下单若在远程创建支付订单这一步失败过（订单已落库但没有
            // pay_url），这里自动补一次——插件 /pay 对同一 s_order_id 幂等，不会重复创建。
            if (blank($existing->pay_url)) {
                $this->createRemotePayment($existing, $existing->paymentMethodConfig());
            }

            return $existing;
        }

        // 2. 金额公式铁律
        if (!Order::isAmountValid($data['subtotal'], $data['shipping_fee'], $data['discount'], $data['tax'],
            $data['amount'])) {
            $expected = bcadd(bcsub(bcadd((string) $data['subtotal'], (string) $data['shipping_fee'], 2),
                (string) $data['discount'], 2), (string) $data['tax'], 2);

            throw new AmountMismatchException($expected, (string) $data['amount']);
        }

        // 3. 商品明细小计校验
        $itemsSum = OrderItem::sumTotalPrice($data['items']);
        if (bccomp($itemsSum, (string) $data['subtotal'], 2) !== 0) {
            throw new OrderItemsMismatchException((string) $data['subtotal'], $itemsSum);
        }

        // 4. 回调域名白名单
        foreach (['notify_url', 'return_url', 'cancel_url'] as $field) {
            if (!empty($data[$field]) && !$merchant->isDomainAllowed($data[$field])) {
                throw new CallbackDomainNotAllowedException($field, $data[$field]);
            }
        }

        // 5. 汇率 + 汇损快照
        $rate = \App\Models\ExchangeRate::getRateWithSurcharge($data['currency'], 'USD');

        $convertedAmount      = bcmul((string) $data['amount'], (string) $rate['actual_rate'], 2);
        $subtotalConverted    = bcmul((string) $data['subtotal'], (string) $rate['actual_rate'], 2);
        $shippingFeeConverted = bcmul((string) $data['shipping_fee'], (string) $rate['actual_rate'], 2);
        $discountConverted    = bcmul((string) $data['discount'], (string) $rate['actual_rate'], 2);
        $taxConverted         = bcmul((string) $data['tax'], (string) $rate['actual_rate'], 2);
        $surchargeFee         = bcmul((string) $data['amount'], (string) $rate['surcharge_amount'], 2);

        // 6. 锁定唯一支付方式。group_key 两种模式下都必填：即便商户点名了渠道，
        // 也仍然校验支付组存在且启用，并把组 ID 记在订单上（对账/统计口径不变）。
        $group = PaymentGroup::query()
            ->forMerchant($merchant->id)
            ->where('group_key', $data['group_key'])
            ->where('is_active', true)
            ->firstOrFail(); // 找不到组本身就是配置错误，直接抛 404 类异常由上层处理

        // 传了 payment_method_key 就直接用该渠道（不走组内路由、不查限额风控）；
        // 没传则维持原有的加权均匀分配 + 风控筛选。
        $paymentMethod = filled($data['payment_method_key'] ?? null)
            ? $this->resolveDesignatedPaymentMethod($merchant, (string) $data['payment_method_key'], $data)
            : $this->paymentService->resolvePaymentMethod($group, (float) $convertedAmount);

        // 7. 事务内创建订单 + 商品明细；第 8 步（远程创建支付订单）在事务外执行，
        // 避免 HTTP 调用占用事务时间。
        $order = DB::transaction(function () use (
            $data,
            $merchant,
            $applicationId,
            $source,
            $group,
            $paymentMethod,
            $rate,
            $convertedAmount,
            $subtotalConverted,
            $shippingFeeConverted,
            $discountConverted,
            $taxConverted,
            $surchargeFee
        ) {
            $order = Order::createWithGeneratedIdentifiers([
                'merchant_id'            => $merchant->id,
                'application_id'         => $applicationId,
                'payment_group_id'       => $group->id,
                'merchant_order_no'      => $data['merchant_order_no'],
                'source'                 => $source,
                // 电商网站平台类型（API 下单必传；手工建单可选，没传就是 null）
                'platform'               => $data['platform'] ?? null,
                'currency'               => $data['currency'],
                'subtotal'               => $data['subtotal'],
                'shipping_fee'           => $data['shipping_fee'],
                'discount'               => $data['discount'],
                'tax'                    => $data['tax'],
                'amount'                 => $data['amount'],
                'converted_currency'     => 'USD',
                'converted_amount'       => $convertedAmount,
                'subtotal_converted'     => $subtotalConverted,
                'shipping_fee_converted' => $shippingFeeConverted,
                'discount_converted'     => $discountConverted,
                'tax_converted'          => $taxConverted,
                'exchange_rate'          => $rate['actual_rate'],
                'original_exchange_rate' => $rate['original_rate'],
                'surcharge_percent'      => $rate['surcharge_percent'],
                'surcharge_type'         => $rate['surcharge_type'],
                'surcharge_amount'       => $rate['surcharge_amount'],
                'surcharge_fee'          => $surchargeFee,
                'customer_first_name'    => $data['customer_first_name'],
                'customer_last_name'     => $data['customer_last_name'],
                'customer_email'         => $data['customer_email'],
                'customer_phone'         => $data['customer_phone'],
                'shipping_address_line1' => $data['shipping_address_line1'],
                'shipping_address_line2' => $data['shipping_address_line2'] ?? null,
                'shipping_city'          => $data['shipping_city'],
                'shipping_state'         => $data['shipping_state'] ?? null,
                'shipping_country'       => $data['shipping_country'],
                'shipping_zip'           => $data['shipping_zip'],
                'payment_method'         => $paymentMethod->method_code,
                'payment_method_id'      => $paymentMethod->id,
                'customer_ip'            => $data['customer_ip'] ?? null,
                'user_agent'             => $data['user_agent'] ?? null,
                'accept_language'        => $data['accept_language'] ?? null,
                'notify_url'             => $data['notify_url'] ?? null,
                'return_url'             => $data['return_url'] ?? null,
                'cancel_url'             => $data['cancel_url'] ?? null,
                'status'                 => 'pending',
                'remark'                 => $data['remark'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'product_sku'          => $item['product_sku'] ?? null,
                    'product_id'           => $item['product_id'],
                    'product_url'          => $item['product_url'],
                    'product_name'         => $item['product_name'],
                    'product_description'  => $item['product_description'] ?? null,
                    'unit_price'           => $item['unit_price'],
                    'quantity'             => $item['quantity'],
                    'total_price'          => bcmul((string) $item['unit_price'], (string) $item['quantity'], 2),
                    'converted_unit_price' => bcmul((string) $item['unit_price'], (string) $rate['actual_rate'], 2),
                ]);
            }

            return $order;
        });

        // 8. 调支付网关插件 /pay 远程创建支付订单，获取收银台支付链接。
        $this->createRemotePayment($order, $paymentMethod);

        return $order;
    }

    /**
     * API 下单显式指定支付渠道（payment_method_key 取 payment_methods.method_code，商户内唯一）。
     *
     * 与支付组路由（PaymentService::resolvePaymentMethod）的区别：
     *   1. 不做组内加权均匀分配，也不查单笔/当日金额/当日笔数/当月金额这些风控阈值——
     *      商户点名用哪个渠道就用哪个；
     *   2. 作为跳过风控的对价，强制要求 notify_url / return_url / cancel_url 三个地址
     *      都存在，且域名都与该渠道绑定的电商网站域名（payment_methods.domain）一致，
     *      任一缺失或不匹配都直接拒单，避免"指定渠道"被当成任意站点收款的口子。
     *      （第 4 步的商户回调域名白名单校验仍然照跑，两道关卡叠加。）
     *
     * 渠道必须属于当前商户且处于启用状态；不要求它一定挂在 group_key 对应的支付组里
     * （group_key 只用于校验归属并记录到订单上）。
     *
     * @param  array  $data  下单数据，这里只取 notify_url / return_url / cancel_url
     *
     * @throws PaymentMethodNotAvailableException
     * @throws PaymentMethodDomainMismatchException
     */
    private function resolveDesignatedPaymentMethod(Merchant $merchant, string $methodKey, array $data): PaymentMethod
    {
        // 走 forMerchant 而不是全局 Scope：API 上下文没有登录用户（见 BelongsToMerchant 注释）。
        $method = PaymentMethod::query()
            ->forMerchant($merchant->id)
            ->where('method_code', $methodKey)
            ->where('is_active', true)
            ->first();

        if (! $method) {
            throw new PaymentMethodNotAvailableException($methodKey);
        }

        foreach (['notify_url', 'return_url', 'cancel_url'] as $field) {
            $url = (string) ($data[$field] ?? '');

            if (! $this->isSameHost($url, $method->domain)) {
                throw new PaymentMethodDomainMismatchException($field, $url, $methodKey, (string) $method->domain);
            }
        }

        return $method;
    }

    /**
     * 调支付网关插件 POST /pay 在站点侧远程创建支付订单，把返回的收银台地址（pay_url）
     * 与 WordPress 订单 ID 回填到本地订单。用"创建订单账户/密码"做 Basic Auth。
     *
     * 远程创建前按支付方式的商品匹配模式准备一份明细（存 order_matched_items，
     * 与商户下单时传的真实明细分开存放），把明细同步给 WordPress：
     *   - 同站点直连：回跳地址与支付方式站点同域名时，商户商品本来就在该站点上，
     *     任何匹配模式都不走，直接用下单明细发起支付；
     *   - MATCH / VIRTUAL：按订单商品金额从站点商品变体贪心凑单（matchItems）；
     *   - CREATE：逐条按订单明细找同价商品，找不到就复制一份改价并在站点上
     *     同步创建同价商品（createItems，/pay 需要真实的商品 ID / 链接）。
     * 各分支均保证明细小计与订单商品金额完全一致（matched_discount 恒为 0）。
     *
     * 插件对同一 s_order_id（传系统订单号）幂等，失败重试安全。
     *
     * @throws PaymentGatewayException 站点地址/订单账号未配齐，或插件返回业务错误。
     */
    private function createRemotePayment(Order $order, ?PaymentMethod $paymentMethod): void
    {
        if (!$paymentMethod) {
            throw new PaymentGatewayException('订单锁定的支付方式已不存在，无法远程创建支付订单', -1);
        }

        $paymentMethod->loadMissing('configMap');
        $tag = $paymentMethod->configMap?->payment_config_tag;

        if (blank($tag)) {
            throw new PaymentGatewayException("支付方式 {$paymentMethod->method_code} 未关联配置模板（或未填写支付类型标签），无法远程创建支付订单",
                -1);
        }

        if (blank($paymentMethod->domain) || blank($paymentMethod->order_account) || blank($paymentMethod->order_password)) {
            throw new PaymentGatewayException("支付方式 {$paymentMethod->method_code} 未配齐站点域名/创建订单账户/创建订单密码，无法远程创建支付订单",
                -1);
        }

        // 同站点直连：下单回跳地址与支付方式配置的 WordPress 站点同域名时，
        // 商户商品本来就在这个站点上，任何匹配模式都不走，
        // 直接把下单明细复制为匹配明细发起支付。
        $mode = (string) ($paymentMethod->product_match_mode ?: PaymentMethod::MODE_MATCH);

        $reusableCreated = $mode === PaymentMethod::MODE_CREATE
            ? $order->matchedItems()->where('auto_created', true)->get()
            : null;

        $matched = match (true) {
            $this->isSameSite($order, $paymentMethod) => $this->directItems($order),
            $mode === PaymentMethod::MODE_MATCH,
            $mode === PaymentMethod::MODE_VIRTUAL => $this->orderItemService->matchItems($paymentMethod, (string) $order->subtotal,
                (string) $order->exchange_rate),
            $mode === PaymentMethod::MODE_CREATE => $this->orderItemService->createItems($paymentMethod, $order, $reusableCreated),
            default => throw new PaymentGatewayException("支付方式 {$paymentMethod->method_code} 的商品匹配模式 {$mode} 尚未实现，无法远程创建支付订单",
                -1),
        };

        // 匹配算法末件改价补齐后 overflow 恒为 0，这里仍照旧写入（与商户下单传的 discount 分开），
        // 保留字段以免后续匹配规则再引入溢出折扣。
        // 发票号与订单主题随支付方式配置生成，落库后随 payload 一并同步给 WordPress。
        $invoiceNumber = $this->buildInvoiceNumber($order, $paymentMethod);
        $subject       = $this->buildSubject($order, $paymentMethod);

        $order->update([
            'matched_discount' => $matched['overflow'],
            'invoice_number'   => $invoiceNumber,
            'subject'          => $subject,
        ]);

        // 先清掉旧的匹配明细，保证幂等补单重试时不会残留上一次的匹配结果。
        $order->matchedItems()->delete();

        foreach ($matched['items'] as $matchedItem) {
            $order->matchedItems()->create([
                'product_sku'          => $matchedItem['product_sku'],
                'product_id'           => $matchedItem['product_id'],
                'product_url'          => $matchedItem['product_url'],
                'product_name'         => $matchedItem['product_name'],
                'product_description'  => $matchedItem['product_description'],
                'unit_price'           => $matchedItem['unit_price'],
                'quantity'             => $matchedItem['quantity'],
                'total_price'          => $matchedItem['total_price'],
                'converted_unit_price' => $matchedItem['converted_unit_price'],
                // CREATE 模式扩展字段（匹配/直连分支不携带，落默认值）。
                'source_variation_id'  => $matchedItem['source_variation_id'] ?? null,
                'auto_created'         => $matchedItem['auto_created'] ?? false,
            ]);
        }

        $payload = [
            's_order_id'       => $order->order_no,
            // 按客户原始币种收款（订单上的原币种金额）
            'amount'           => (float) $order->amount,
            'subtotal'         => (float) $order->subtotal,
            'currency'         => $order->currency,
            'payment_method'   => $tag,
            // 发票号与订单主题（invoice 前缀 / 虚拟商品前缀拼接系统订单号）
            'invoice_number'   => $order->invoice_number,
            'subject'          => $order->subject,
            // 支付方式的商品匹配模式作为交易类型透传给商城系统
            'trans_type'       => $mode,
            'callback_url'     => url('/api/webhooks/payment-gateway/status'),
            // TODO: 支付状态回调路由（PaymentGatewayWebhookController）待实现
            'return_url'       => $order->return_url ?: url('/payment/'.$order->payment_link_token),
            'cancel_url'       => $order->cancel_url ?: url('/payment/'.$order->payment_link_token),
            'customer'         => [
                'name'  => trim($order->customer_first_name.' '.$order->customer_last_name),
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            // 本系统只采集收货地址，账单地址与收货地址同值。
            'billing_address'  => $address = [
                'country' => $order->shipping_country,
                'state'   => $order->shipping_state,
                'city'    => $order->shipping_city,
                'address' => trim($order->shipping_address_line1.' '.$order->shipping_address_line2),
                'zip'     => $order->shipping_zip,
            ],
            'shipping_address' => $address,
            // 商品明细用匹配/创建/直连结果（而非商户下单时传的明细）同步给 WordPress。
            'items'            => collect($matched['items'])->map(fn(array $matchedItem) => [
                'sku'         => $matchedItem['product_sku'],
                'product_id'  => $matchedItem['product_id'],
                'product_url' => $matchedItem['product_url'],
                'name'        => $matchedItem['product_name'],
                'quantity'    => (int) $matchedItem['quantity'],
                'price'       => (float) $matchedItem['unit_price'],
            ])->all(),
            'shipping_fee'     => (float) $order->shipping_fee,
            'tax_fee'          => (float) $order->tax,
            // 自动匹配商品溢出产生的折扣（订单原币种）
            'discount_fee'     => (float) ($order->matched_discount + $order->discount),
        ];

        // 优先引用已注册的网关配置（见「同步支付配置」按钮）；尚未同步过时退化为内联明文配置。
        if (filled($paymentMethod->payment_config_id)) {
            $payload['gateway_config_id'] = (int) $paymentMethod->payment_config_id;
        } else {
            $payload['gateway_config'] = [$tag => (array) $paymentMethod->config];
        }

        $result = $this->paymentGateway
            ->withConnection(
                rtrim((string) $paymentMethod->domain, '/').'/wp-json/payment-plugin/v1',
                (string) $paymentMethod->order_account,
                (string) $paymentMethod->order_password,
            )
            ->createPayment($payload);

        $order->update([
            'pay_url'     => $result['pay_url'] ?? null,
            'wp_order_id' => $result['wp_order_id'] ?? null,
        ]);
    }

    /**
     * 发票号：支付方式 invoice 前缀 _ 客户名 _ 客户姓 _ 系统订单号，
     * 去掉所有空白字符后统一转大写（WordPress 侧对发票号格式有要求）。
     */
    private function buildInvoiceNumber(Order $order, PaymentMethod $paymentMethod): string
    {
        $raw = implode('_', [
            (string) $paymentMethod->invoice_prefix,
            (string) $order->customer_first_name,
            (string) $order->customer_last_name,
            (string) $order->order_no,
        ]);

        return mb_strtoupper((string) preg_replace('/\s+/u', '', $raw));
    }

    /**
     * 订单主题：支付方式虚拟商品前缀拼接系统订单号。
     */
    private function buildSubject(Order $order, PaymentMethod $paymentMethod): string
    {
        return trim((string) $paymentMethod->virtual_product_prefix)." ".$order->order_no;
    }

    /**
     * 同站点直连的匹配明细：商户商品本来就在支付方式的 WordPress 站点上，
     * 直接把下单明细原样复制为匹配明细，不做任何匹配/创建。
     * 结构与 matchItems() 返回一致。
     */
    private function directItems(Order $order): array
    {
        $items = $order->items()->get()->map(fn (OrderItem $item) => [
            'product_sku' => $item->product_sku,
            'product_id' => $item->product_id,
            'product_url' => $item->product_url,
            'product_name' => $item->product_name,
            'product_description' => $item->product_description,
            'unit_price' => (string) $item->unit_price,
            'quantity' => (int) $item->quantity,
            'total_price' => (string) $item->total_price,
            'converted_unit_price' => number_format((float) $item->converted_unit_price, 2, '.', ''),
            'source_variation_id' => null,
            'auto_created' => false,
        ])->all();

        return ['items' => $items, 'subtotal' => (string) $order->subtotal, 'overflow' => '0'];
    }

    /**
     * 订单回跳地址与支付方式的 WordPress 站点是否同一域名。
     * 比较规则见 normalizeHost()；任一地址缺失或域名解析不出来则视为不同站点。
     */
    private function isSameSite(Order $order, PaymentMethod $paymentMethod): bool
    {
        if (blank($order->return_url) || blank($paymentMethod->domain)) {
            return false;
        }

        return $this->isSameHost($order->return_url, $paymentMethod->domain);
    }

    /**
     * 两个地址（完整 URL 或域名）是否指向同一站点。解析不出主机名一律视为不同站点。
     */
    private function isSameHost(?string $url, ?string $domain): bool
    {
        $urlHost = $this->normalizeHost($url);
        $siteHost = $this->normalizeHost($domain);

        return $urlHost !== '' && $urlHost === $siteHost;
    }

    /**
     * 提取并归一化主机名：转小写、去掉 www. 前缀与端口号。
     * 兼容只填裸域名（example.com）或带路径（https://example.com/shop）的写法——
     * 没有 scheme 时 parse_url 会把整串当成 path 取不到 host，这里手动剥一次。
     */
    private function normalizeHost(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (blank($host)) {
            $host = explode('/', str_replace(['https://', 'http://'], '', strtolower($value)))[0];
        }

        $host = strtolower(trim(explode(':', (string) $host)[0]));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
