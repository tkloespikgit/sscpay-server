<?php
/**
 * Stripe网关：Checkout Session 跳转支付。
 *
 * 依赖官方SDK `stripe/stripe-php`（composer.json里已声明），
 * webhook验签用SDK自带的 \Stripe\Webhook::constructEvent()，
 * 不手写HMAC比对——Stripe官方SDK的实现已经处理了时间戳容差、
 * 多重签名（v0/v1）兼容等细节，没必要重新实现一遍。
 *
 * 参考：
 * - Checkout Sessions API: https://docs.stripe.com/api/checkout/sessions
 * - Webhook签名: https://docs.stripe.com/webhooks
 */

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\PaymentBase;
use PaymentGatewayAggregator\Core\CurrencyManager;
use PaymentGatewayAggregator\Core\Exceptions\GatewayAccountForbiddenException;
use PaymentGatewayAggregator\Core\LineItemBuilder;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\TransactionStateMachine;
use PaymentGatewayAggregator\Frontend\PaymentPage;
use PaymentGatewayAggregator\Helpers\ProxyConfig;
use PaymentGatewayAggregator\Helpers\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class StripeGateway extends PaymentBase
{
    public function code()
    {
        return 'stripe';
    }

    public function validateConfig(array $config)
    {
        return Validator::requireFields($config, ['publishable_key', 'secret_key', 'webhook_secret']);
    }

    /**
     * Stripe官方SDK自带cURL客户端直连三方，完全绕开本插件其它网关共用的
     * Helpers/HttpClient（进而绕开wp_remote_*）——所以"按渠道配置走代理"这件事
     * 在Stripe这里复用 ProxyConfig 的代理选项，再通过SDK提供的
     * \Stripe\ApiRequestor::setHttpClient()单独接管。这是SDK级别的全局状态，
     * 每次发起调用前都按当前config显式设置（有proxy设代理客户端，没有则
     * 使用直连客户端，并限制连接和请求超时），
     * 不依赖上一次调用遗留的状态，所以不同配置之间不会互相污染。
     *
     * @param ?string $proxy http/socks5/socks5h://user:pass@host:port，为空表示直连
     */
    private static function configureStripeProxy($proxy)
    {
        try {
            $curlOpts = ProxyConfig::curlOptions($proxy);
        } catch (\InvalidArgumentException $e) {
            // 保持 Stripe 查询/通知上层对代理错误的 RuntimeException 处理契约。
            throw new \RuntimeException('Stripe' . $e->getMessage(), 0, $e);
        }
        $client = new \Stripe\HttpClient\CurlClient($curlOpts);
        $client->setConnectTimeout(3);
        $client->setTimeout(8);
        \Stripe\ApiRequestor::setHttpClient($client);
    }

    public function preparePayment(array $transaction)
    {
        if (!class_exists('\Stripe\StripeClient')) {
            throw new \RuntimeException('stripe/stripe-php 未安装，请先执行 composer require stripe/stripe-php');
        }

        self::configureStripeProxy($this->config['proxy'] ?? null);
        $stripe = new \Stripe\StripeClient($this->config['secret_key']);
        $attemptKey = $transaction['payment_attempt_key'] ?? null;
        $requestOptions = is_string($attemptKey) && $attemptKey !== ''
            ? ['idempotency_key' => 'pga-session-' . $attemptKey] : [];
        $currency = strtolower($transaction['currency']);
        // 行项目统一走 LineItemBuilder，和PayPal两个网关共用同一套 trans_type 规则：
        // CREATE模式商品名位置是SKU，VIRTUAL模式只有 subject 那一条虚拟商品。
        $items = LineItemBuilder::build($transaction);
        $invoiceNumber = LineItemBuilder::invoiceNumber($transaction);

        $itemsSubtotal = 0.0;
        $lineItems = [];
        foreach ($items as $item) {
            $itemsSubtotal += $item['price'] * $item['quantity'];

            $productData = ['name' => $item['name'] !== '' ? $item['name'] : 'Item'];

            // SKU和商品链接：Stripe的price_data.product_data没有对应的专属字段
            // （不像PayPal有items[].sku / items[].url），只能塞进metadata——
            // Dashboard的付款详情里能看到，对账时可以对回站内商品。
            // metadata的值上限500字符，超了整个请求会被Stripe拒掉，所以先截断。
            $productMetadata = array_filter([
                'sku'         => mb_substr((string) $item['sku'], 0, 500),
                'product_url' => mb_substr((string) $item['url'], 0, 500),
            ]);
            if ($productMetadata) {
                $productData['metadata'] = $productMetadata;
            }

            $lineItems[] = [
                'quantity' => $item['quantity'],
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => CurrencyManager::toMinorUnit($item['price'], $transaction['currency']),
                    'product_data' => $productData,
                ],
            ];
        }

        $shippingFee = (float) $transaction['shipping_fee'];
        $taxFee = (float) $transaction['tax_fee'];
        $discountFee = (float) ($transaction['discount_fee'] ?? 0);

        if ($shippingFee > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => CurrencyManager::toMinorUnit($shippingFee, $transaction['currency']),
                    'product_data' => ['name' => 'Shipping'],
                ],
            ];
        }

        if ($taxFee > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => CurrencyManager::toMinorUnit($taxFee, $transaction['currency']),
                    'product_data' => ['name' => 'Tax'],
                ],
            ];
        }

        // 明细（商品+运费+税费-折扣）合计必须与订单总金额一致才能按明细传给Stripe——
        // Checkout Session的应付金额就是line_items求和，不认识我们自己的amount字段，
        // 之前一直没减掉discount_fee就是这里漏的：折扣既不在页面上显示，Stripe那边
        // 收的也是没打折的全额，跟S系统记录的实付金额对不上。对不上（含明细本身就不自洽
        // 的历史数据）时退化为单条"Order Total"按实际总金额收款，保证收款金额正确，
        // 牺牲明细/折扣展示——与PayPal网关同样的降级策略，见
        // PayPalRestClient::createOrder()。
        $amount = (float) $transaction['amount'];
        $detailSum = round($itemsSubtotal + $shippingFee + $taxFee - $discountFee, 2, PHP_ROUND_HALF_DOWN);
        $detailMatches = abs($detailSum - round($amount, 2, PHP_ROUND_HALF_DOWN)) < 0.01;

        if (!$detailMatches) {
            LogManager::warning($transaction['s_order_id'], sprintf(
                'Stripe明细合计%.2f与订单总金额%.2f不一致（商品%.2f/运费%.2f/税费%.2f/折扣%.2f），放弃商品明细仅传总额',
                $detailSum,
                $amount,
                $itemsSubtotal,
                $shippingFee,
                $taxFee,
                $discountFee
            ), ['payment_method' => 'stripe']);

            $lineItems = [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => CurrencyManager::toMinorUnit($amount, $transaction['currency']),
                    'product_data' => ['name' => 'Order Total'],
                ],
            ]];
        }

        $sessionParams = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            // 买家在Stripe收银台完成支付时不直接跳回S系统，而是落回插件自己的支付
            // 结果页：真正的支付结果以Webhook为准，结果页展示终态，"Return to
            // Store"按钮才会跳S系统的return_url（与PayPal两个网关的return流程一致，
            // 见PayPalRestController::returnHandler / PaymentPage::resultPageUrl）。
            'success_url' => PaymentPage::resultPageUrl($transaction),
            // 买家在Stripe收银台点"取消"时不直接跳回S系统，而是落回插件的取消页：
            // 交易仍是pending，取消页会写 user canceled 日志并展示高亮的
            // "Retry Payment"按钮（与PayPal两个网关的行为一致，见PaymentPage）。
            // Stripe Checkout Session本身可重复打开，重试会复用已缓存的会话。
            'cancel_url' => PaymentPage::cancelPageUrl($transaction),
            'customer_email' => $transaction['customer_email'],
        ];
        // metadata里带上本站域名，让Stripe后台/Dashboard能看出这笔订单来自哪个站点。
        $sessionParams['metadata'] = ['site_domain' => (string) wp_parse_url(home_url(), PHP_URL_HOST)];
        // PI在确认Checkout Session时才创建。把已持久化的随机尝试键同时写入
        // Session及其PI，便于旧版Webhook没有Session引用时仍可定位本次尝试。
        if ((int) ($transaction['stripe_metadata_version'] ?? 0) >= 1
            && is_string($attemptKey) && $attemptKey !== '') {
            $sessionParams['metadata']['pga_attempt_key'] = $attemptKey;
            $sessionParams['payment_intent_data']['metadata']['pga_attempt_key'] = $attemptKey;
        }

        // 不把S系统内部订单号(s_order_id)透传给Stripe：client_reference_id/metadata
        // 这类"商户可见"字段统一用账单号(invoice_no)标记，历史交易没有账单号
        // （1.4.0之前）时这两个字段都不写，不能传空字符串给Stripe。
        if ($invoiceNumber !== '') {
            $sessionParams['client_reference_id'] = $invoiceNumber;
            $sessionParams['metadata']['invoice_no'] = $invoiceNumber;
        }

        try {
            // Stripe的line_items不允许负数unit_amount，没法直接加一条"折扣"行，只能
            // 靠一次性Coupon抵扣一个具体金额，才能让折扣单独显示在Checkout页面上
            // （而不是悄悄摊薄进商品单价里，买家完全看不出打了折）。走明细合计
            // 一致才有意义——上面已经退化为单条总额的情况下不再需要额外抵扣。
            if ($detailMatches && $discountFee > 0) {
                $coupon = $stripe->coupons->create([
                    'duration' => 'once',
                    'amount_off' => CurrencyManager::toMinorUnit($discountFee, $transaction['currency']),
                    'currency' => $currency,
                ], is_string($attemptKey) && $attemptKey !== ''
                    ? ['idempotency_key' => 'pga-coupon-' . $attemptKey] : []);
                $sessionParams['discounts'] = [['coupon' => $coupon->id]];
            }

            $session = $stripe->checkout->sessions->create($sessionParams, $requestOptions);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // secret_key本身无效/已吊销：明确的账户凭证问题，不是这一单参数有误。
            throw new GatewayAccountForbiddenException('Stripe密钥无效：' . $e->getMessage(), 0, $e);
        } catch (\Stripe\Exception\PermissionException $e) {
            // Restricted Key权限不足（比如没有创建Checkout Session的权限）：
            // 同样是账户/凭证配置问题，重试同样的订单也不会成功。
            throw new GatewayAccountForbiddenException('Stripe密钥权限不足：' . $e->getMessage(), 0, $e);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Stripe对"账户未激活收款能力/被限制"（charges_enabled=false）这类错误
            // 没有提供稳定的error code，只能匹配官方措辞里的关键词兜底识别；
            // 除此之外的InvalidRequestException多数是行项目/参数问题，按普通失败处理，
            // 不计入账户异常。这个关键词匹配比较脆弱，如果发现漏判/误判，
            // 需要结合Stripe返回的原始错误信息调整。
            $message = $e->getMessage();
            if (stripos($message, 'cannot currently make') !== false
                || stripos($message, 'account cannot') !== false
            ) {
                throw new GatewayAccountForbiddenException('Stripe账户无法收款：' . $message, 0, $e);
            }
            throw $e;
        }

        return [
            'redirect_url' => $session->url,
            'session_id' => $session->id,
            'external_ref' => $session->id,
            'provider_created_at' => isset($session->created) ? (int) $session->created : null,
            'provider_expires_at' => isset($session->expires_at) ? (int) $session->expires_at : null,
            // 较新API延迟到确认Session才创建PI，初始值通常为null；已有ID时提前
            // 保存，其余由已验签的Session通知/尝试键关联补齐，不能依赖事件顺序。
            'transaction_id' => is_string($session->payment_intent) ? $session->payment_intent : null,
        ];
    }

    public function renderPaymentPage(array $transaction, array $sessionData)
    {
        $url = esc_url($sessionData['redirect_url']);
        ob_start();
        ?>
        <div class="pga-gateway pga-gateway-stripe">
            <a class="pga-pay-button" href="<?php echo $url; ?>" rel="noopener">
                <?php esc_html_e('前往支付 (Stripe)', 'sscpay-gateway-aggregator'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Only these snapshot events participate in order state handling. */
    public function supportsWebhookEvent($type)
    {
        return is_string($type) && in_array($type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'charge.refunded',
            'charge.dispute.created',
            'charge.dispute.updated',
            'charge.dispute.closed',
        ], true);
    }

    public function extractExternalRef($rawBody, array $headers)
    {
        $refs = $this->extractExternalRefs($rawBody);
        return $refs[0] ?? null;
    }

    /** Unverified candidates for local lookup only; callers must verify before writing. */
    public function extractExternalRefs($rawBody)
    {
        $data = json_decode($rawBody, true);
        $type = $data['type'] ?? '';
        $object = $data['data']['object'] ?? [];
        if (!$this->supportsWebhookEvent($type) || !is_array($object)) {
            return [];
        }

        $refs = [];
        if (strpos($type, 'charge.dispute.') === 0) {
            $refs = [$object['payment_intent'] ?? null, $object['charge'] ?? null];
        } elseif ($type === 'charge.refunded') {
            $refs = [$object['payment_intent'] ?? null, $object['id'] ?? null];
        } elseif (strpos($type, 'payment_intent.') === 0) {
            // order_reference是商户交易参考号，不能假定总是cs_。先尝试PI本身，
            // 只有确为Session编号时才作为候选，避免账单号遮蔽已保存的PI。
            $refs[] = $object['id'] ?? null;
            $orderRef = $object['payment_details']['order_reference'] ?? null;
            if (is_string($orderRef) && preg_match('/\Acs_[A-Za-z0-9_]+\z/', $orderRef)) {
                $refs[] = $orderRef;
            }
        } else {
            $refs = [$object['id'] ?? null, $object['payment_intent'] ?? null];
        }

        return array_values(array_unique(array_filter(array_map([self::class, 'stripeObjectId'], $refs))));
    }

    /** Expandable references can be strings or expanded JSON objects. */
    private static function stripeObjectId($value)
    {
        $value = is_array($value) ? ($value['id'] ?? null) : $value;
        return is_string($value) && $value !== '' && strlen($value) <= 255 ? $value : null;
    }

    public function verifyCallbackSignature($rawBody, array $headers, array $config, $diagnose = true)
    {
        if (!class_exists('\Stripe\Webhook')) {
            return false;
        }

        $sigHeader = $headers['stripe-signature'] ?? '';
        if (!$sigHeader || empty($config['webhook_secret'])) {
            return false;
        }

        try {
            \Stripe\Webhook::constructEvent($rawBody, $sigHeader, $config['webhook_secret']);
            return true;
        } catch (\Exception $e) {
            // SDK异常对象可能携带原始body和签名；只记录固定原因与异常类。
            $reason = $e instanceof \Stripe\Exception\SignatureVerificationException
                ? (strpos($e->getMessage(), 'Timestamp outside') === 0 ? 'timestamp_outside_tolerance' : 'signature_invalid')
                : 'payload_invalid';
            if ($diagnose) {
                LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'Stripe Webhook验签失败', [
                    'payment_method' => 'stripe',
                    'response_data' => ['reason' => $reason, 'exception_class' => get_class($e)],
                ]);
            }
            return false;
        }
    }

    public function parseCallbackEvent($rawBody, array $headers)
    {
        $data = json_decode($rawBody, true) ?: [];
        $type = $data['type'] ?? '';
        $object = $data['data']['object'] ?? [];

        $result = [
            'event_id' => $data['id'] ?? '',
            'external_ref' => $object['id'] ?? '',
            'event_status' => 'ignored',
            'amount_minor' => null,
            'currency' => null,
            'transaction_id' => null,
        ];

        switch ($type) {
            case 'checkout.session.completed':
                $result['transaction_id'] = self::stripeObjectId($object['payment_intent'] ?? null);
                if (($object['payment_status'] ?? '') === 'paid') {
                    $result['event_status'] = 'paid';
                    $result['amount_minor'] = isset($object['amount_total']) ? (int) $object['amount_total'] : null;
                    $result['currency'] = isset($object['currency']) ? strtoupper($object['currency']) : null;
                } elseif (($object['payment_status'] ?? '') === 'unpaid') {
                    // Checkout已完成但仍在清算中，保存PI关联而不提前标记已付款。
                    $result['event_status'] = 'processing';
                }
                break;

            case 'checkout.session.async_payment_succeeded':
                $result['event_status'] = 'paid';
                $result['amount_minor'] = isset($object['amount_total']) ? (int) $object['amount_total'] : null;
                $result['currency'] = isset($object['currency']) ? strtoupper($object['currency']) : null;
                $result['transaction_id'] = self::stripeObjectId($object['payment_intent'] ?? null);
                break;

            case 'checkout.session.async_payment_failed':
                $result['event_status'] = 'failed';
                $result['amount_minor'] = isset($object['amount_total']) ? (int) $object['amount_total'] : null;
                $result['currency'] = isset($object['currency']) ? strtoupper($object['currency']) : null;
                $result['transaction_id'] = self::stripeObjectId($object['payment_intent'] ?? null);
                break;

            // 部分支付方式不是即时到账（银行扣款等delayed notification方式）：
            // checkout.session.completed触发时payment_status可能还是unpaid，真正的
            // "钱到账"信号要等这个事件才来。同一笔订单可能先后收到两个都判定为paid
            // 的事件（先completed后succeeded，或反过来），TransactionStateMachine
            // 对paid->paid是noop，不会重复触发副作用，两个都保留互为兜底。
            case 'payment_intent.succeeded':
                $result['event_status'] = 'paid';
                $result['amount_minor'] = isset($object['amount_received']) ? (int) $object['amount_received'] : null;
                $result['currency'] = isset($object['currency']) ? strtoupper($object['currency']) : null;
                $result['transaction_id'] = $object['id'] ?? null;
                break;

            case 'payment_intent.payment_failed':
                $result['event_status'] = 'failed';
                $result['amount_minor'] = isset($object['amount']) ? (int) $object['amount'] : null;
                $result['currency'] = isset($object['currency']) ? strtoupper($object['currency']) : null;
                $result['transaction_id'] = $object['id'] ?? null;
                break;

            case 'charge.refunded':
                $result['amount_minor'] = isset($object['amount']) ? (int) $object['amount'] : null;
                $result['refund_amount_minor'] = isset($object['amount_refunded']) ? (int) $object['amount_refunded'] : null;
                $result['currency'] = isset($object['currency']) ? strtoupper($object['currency']) : null;
                $result['event_status'] = ($object['refunded'] ?? false) === true
                    || ($result['amount_minor'] !== null && $result['amount_minor'] > 0
                        && $result['refund_amount_minor'] === $result['amount_minor']) ? 'refunded' : 'partial_refund';
                $result['transaction_id'] = self::stripeObjectId($object['payment_intent'] ?? null);
                break;

            // 买家发起争议（客诉），结果未定；status在created时通常是needs_response。
            case 'charge.dispute.created':
                $result['event_status'] = 'dispute_created';
                $result = self::fillStripeDisputeInfo($result, $object);
                break;

            // 争议中间状态变化（比如进入under_review），结果仍未定。
            case 'charge.dispute.updated':
                $result['event_status'] = 'dispute_updated';
                $result = self::fillStripeDisputeInfo($result, $object);
                break;

            // 争议关闭：status为won表示我方胜诉，lost表示我方败诉（拒付成立，资金被扣回）；
            // 其余中间态（如charge_refunded）按更新处理，不当作最终结论。
            case 'charge.dispute.closed':
                $status = $object['status'] ?? '';
                if ($status === 'won') {
                    $result['event_status'] = 'dispute_won';
                } elseif ($status === 'lost') {
                    $result['event_status'] = 'dispute_lost';
                } else {
                    $result['event_status'] = 'dispute_updated';
                }
                $result = self::fillStripeDisputeInfo($result, $object);
                break;
        }

        return $result;
    }

    /**
     * 把Stripe Dispute对象里的争议单号/原因/涉及交易ID/金额统一填进事件结果。
     *
     * @param array $result
     * @param array $object Dispute对象（data.object）
     * @return array
     */
    private static function fillStripeDisputeInfo(array $result, array $object)
    {
        $result['dispute_id'] = $object['id'] ?? null;
        $result['dispute_reason'] = $object['reason'] ?? null;
        $result['dispute_status'] = $object['status'] ?? null;
        $result['transaction_id'] = self::stripeObjectId($object['payment_intent'] ?? null)
            ?: self::stripeObjectId($object['charge'] ?? null);

        if (isset($object['amount'])) {
            $result['amount_minor'] = (int) $object['amount'];
        }
        if (isset($object['currency'])) {
            $result['currency'] = strtoupper($object['currency']);
        }

        return $result;
    }

    /** Call only after signature verification; stale snapshots must not reopen closed disputes. */
    public function refreshDisputeEvent(array $event): array
    {
        if (strpos($event['event_status'] ?? '', 'dispute_') !== 0) {
            return $event;
        }
        $disputeId = self::stripeObjectId($event['dispute_id'] ?? null);
        if ($disputeId === null || !class_exists('\\Stripe\\StripeClient')) {
            throw new \RuntimeException('Stripe争议查询缺少争议编号或SDK');
        }
        self::configureStripeProxy($this->config['proxy'] ?? null);
        $stripe = new \Stripe\StripeClient($this->config['secret_key']);
        try {
            $dispute = $stripe->disputes->retrieve($disputeId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Stripe争议当前状态查询失败', 0, $e);
        }
        $object = $dispute instanceof \Stripe\StripeObject ? $dispute->toArray() : (array) $dispute;
        if (($object['id'] ?? null) !== $disputeId) {
            throw new \RuntimeException('Stripe争议查询返回的编号不匹配');
        }
        $current = self::fillStripeDisputeInfo($event, $object);
        if (!empty($event['transaction_id']) && !empty($current['transaction_id'])
            && $event['transaction_id'] !== $current['transaction_id']) {
            throw new \RuntimeException('Stripe争议查询返回的付款编号不匹配');
        }
        $status = $object['status'] ?? '';
        if (in_array($status, ['won', 'warning_closed', 'prevented'], true)) {
            $current['event_status'] = 'dispute_won';
        } elseif ($status === 'lost') {
            $current['event_status'] = 'dispute_lost';
        } elseif (in_array($status, ['needs_response', 'under_review', 'warning_needs_response', 'warning_under_review'], true)) {
            $current['event_status'] = $event['event_status'] === 'dispute_created' ? 'dispute_created' : 'dispute_updated';
        } else {
            throw new \RuntimeException('Stripe争议查询返回未知状态');
        }
        return $current;
    }

    /** Recover old PI-only notifications using Stripe's server-side Session association. */
    public function findCheckoutSessionForPaymentIntent($piId, ?string $account = null): ?string
    {
        if (!is_string($piId) || !preg_match('/\Api_[A-Za-z0-9_]+\z/', $piId)) {
            throw new \RuntimeException('Stripe支付意图编号无效');
        }
        if (!class_exists('\\Stripe\\StripeClient')) {
            throw new \RuntimeException('stripe/stripe-php 未安装');
        }
        self::configureStripeProxy($this->config['proxy'] ?? null);
        $stripe = new \Stripe\StripeClient($this->config['secret_key']);
        try {
            $sessions = $stripe->checkout->sessions->all(['payment_intent' => $piId, 'limit' => 1], $account ? ['stripe_account' => $account] : []);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Stripe支付意图所属Checkout Session查询失败', 0, $e);
        }
        $session = $sessions->data[0] ?? null;
        if ($session === null) {
            return null;
        }
        $object = $session instanceof \Stripe\StripeObject ? $session->toArray() : (array) $session;
        $sessionId = $object['id'] ?? null;
        if (!is_string($sessionId) || !preg_match('/\Acs_[A-Za-z0-9_]+\z/', $sessionId)
            || self::stripeObjectId($object['payment_intent'] ?? null) !== $piId) {
            throw new \RuntimeException('Stripe支付意图所属Checkout Session不匹配');
        }
        return $sessionId;
    }

    public function refundReferences(array $event): array
    {
        $object = $event['data']['object'] ?? [];
        $charge = ($event['type'] ?? '') === 'charge.refunded' ? ($object['id'] ?? null) : ($object['charge'] ?? null);
        return RefundEvidence::refs($object['payment_intent'] ?? null, $charge);
    }

    public function readRefund(array $event, ?array $transaction): array
    {
        $object = $event['data']['object'] ?? [];
        $refs = $this->refundReferences($event);
        $extra = ['event_id' => $event['id'] ?? null];
        if (isset($event['account'])) {
            $extra['provider_account'] = $event['account'];
        }
        if (is_bool($event['livemode'] ?? null)) {
            $extra['livemode'] = $event['livemode'];
        }
        if (($event['type'] ?? '') === 'charge.refunded') {
            $charge = $object['id'] ?? '';
            if (!is_string($charge) || !preg_match('/\A(?:ch|py)_[A-Za-z0-9_]+\z/', $charge)) {
                return RefundEvidence::result('lookup', $extra + ['payment_refs' => $refs]);
            }
            return $this->listRefunds(['charge' => $charge], $refs, $extra, $event['created'] ?? null, $transaction === null);
        }
        if ($transaction === null && ($object['status'] ?? '') === 'succeeded') {
            $refs = $this->withRefundSession($refs, $extra['provider_account'] ?? null);
        }
        return $this->stripeRefund($object, $refs, $event['created'] ?? null, $extra, $event['type'] ?? '');
    }

    private function withRefundSession(array $refs, ?string $account): array
    {
        foreach ($refs as $ref) {
            if (strpos($ref, 'pi_') === 0) {
                $session = $this->findCheckoutSessionForPaymentIntent($ref, $account);
                return RefundEvidence::refs(...array_merge($refs, [$session]));
            }
        }
        foreach ($refs as $ref) {
            if (!preg_match('/\A(?:ch|py)_[A-Za-z0-9_]+\z/', $ref)) {
                continue;
            }
            $options = $account ? ['stripe_account' => $account] : [];
            $charge = $this->refundClient()->charges->retrieve($ref, [], $options)->toArray();
            $intent = self::stripeObjectId($charge['payment_intent'] ?? null);
            if (($charge['id'] ?? null) !== $ref) {
                throw new \RuntimeException('Stripe查询返回了其他Charge');
            }
            if ($intent && preg_match('/\Api_[A-Za-z0-9_]+\z/', $intent)) {
                $session = $this->findCheckoutSessionForPaymentIntent($intent, $account);
                return RefundEvidence::refs(...array_merge($refs, [$intent, $session]));
            }
        }
        return $refs;
    }

    protected function refundClient()
    {
        self::configureStripeProxy($this->config['proxy'] ?? null);
        return new \Stripe\StripeClient($this->config['secret_key']);
    }

    public function queryRefunds(array $transaction): array
    {
        $refs = RefundEvidence::refs($transaction['transaction_id'] ?? null, $transaction['payment_intent'] ?? null);
        foreach ($refs as $ref) {
            if (preg_match('/\A(?:pi|ch|py)_[A-Za-z0-9_]+\z/', $ref)) {
                return $this->listRefunds([strpos($ref, 'pi_') === 0 ? 'payment_intent' : 'charge' => $ref], [$ref]);
            }
        }
        foreach ($refs as $ref) {
            if (strpos($ref, 'cs_') === 0) {
                $session = $this->refundClient()->checkout->sessions->retrieve($ref)->toArray();
                $intent = self::stripeObjectId($session['payment_intent'] ?? null);
                if (($session['id'] ?? '') === $ref && is_string($intent) && strpos($intent, 'pi_') === 0) {
                    return $this->listRefunds(['payment_intent' => $intent], [$intent, $ref]);
                }
            }
        }
        return RefundEvidence::result('lookup', ['payment_refs' => $refs]);
    }

    private function listRefunds(array $filter, array $refs, array $extra = [], $observedAt = null, bool $recoverSession = false): array
    {
        $client = $this->refundClient();
        $options = $filter + ['limit' => 100, 'expand' => ['data.balance_transaction']];
        $requestOptions = isset($extra['provider_account']) ? ['stripe_account' => $extra['provider_account']] : [];
        $pages = [];
        $observations = [];
        $field = key($filter);
        do {
            $response = $client->refunds->all($options, $requestOptions)->toArray();
            if (!is_array($response['data'] ?? null) || !is_bool($response['has_more'] ?? null)) {
                throw new \RuntimeException('Stripe退款列表不完整');
            }
            $cursor = null;
            foreach ($response['data'] as $refund) {
                if (self::stripeObjectId($refund[$field] ?? null) !== $filter[$field]) {
                    throw new \RuntimeException('Stripe退款不属于所查的原付款');
                }
                $refundIntent = self::stripeObjectId($refund['payment_intent'] ?? null);
                foreach ($refs as $verifiedRef) {
                    if (strpos($verifiedRef, 'pi_') === 0 && $refundIntent && $refundIntent !== $verifiedRef) {
                        throw new \RuntimeException('Stripe退款详情与原Payment Intent冲突');
                    }
                }
                $cursor = $refund['id'] ?? null;
                $rowRefs = RefundEvidence::refs($refund['payment_intent'] ?? null, $refund['charge'] ?? null, ...$refs);
                if ($recoverSession && $rowRefs) {
                    $rowRefs = $this->withRefundSession($rowRefs, $extra['provider_account'] ?? null);
                    $refs = $rowRefs;
                    $recoverSession = false;
                }
                $time = $refund['balance_transaction']['created'] ?? null;
                $rowExtra = $extra;
                if (is_bool($refund['livemode'] ?? null)) {
                    $rowExtra['livemode'] = $refund['livemode'];
                }
                $observations[] = $this->stripeRefund($refund, $rowRefs, $time, $rowExtra);
            }
            if ($response['has_more']) {
                if (!is_string($cursor) || $cursor === '' || isset($pages[$cursor])) {
                    throw new \RuntimeException('Stripe退款分页未前进');
                }
                $pages[$cursor] = true;
                $options['starting_after'] = $cursor;
            }
        } while ($response['has_more']);
        $result = RefundEvidence::collection($observations, $refs);
        $result['evidence'] += $extra;
        if (!$observations && $observedAt !== null) {
            $result['kind'] = 'lookup';
        }
        return $result;
    }

    private function stripeRefund(array $refund, array $refs, $time, array $extra, string $eventType = ''): array
    {
        $status = $refund['status'] ?? '';
        $states = ['succeeded' => 'succeeded', 'failed' => 'failed', 'canceled' => 'failed', 'pending' => 'pending', 'requires_action' => 'pending'];
        $kind = $states[$status] ?? 'lookup';
        if (($refund['object'] ?? 'refund') !== 'refund' || !preg_match('/\A(?:re|pyr)_[A-Za-z0-9_]+\z/', (string) ($refund['id'] ?? ''))
            || ($eventType === 'refund.failed' && $kind !== 'failed')) {
            $kind = 'lookup';
        }
        return RefundEvidence::observation($kind, $refund['id'] ?? null, $refund['amount'] ?? null, $refund['currency'] ?? null,
            $refs, $time, $status, $extra, 'stripe');
    }

    /**
     * 主动核实：Retrieve Checkout Session（payment_intent字段落库的是Session id，
     * 见preparePayment()里的external_ref）。Session的payment_status/status
     * 已经是Stripe侧算好的聚合结果，不需要我们自己再去查PaymentIntent/Charge。
     */
    public function queryOrderStatus(array $transaction)
    {
        $sessionId = $transaction['payment_intent'] ?? '';
        if ($sessionId === '') {
            return ['status' => null, 'transaction_id' => null, 'amount_minor' => null, 'currency' => null, 'session_state' => 'unknown'];
        }

        if (!class_exists('\Stripe\StripeClient')) {
            throw new \RuntimeException('stripe/stripe-php 未安装');
        }

        self::configureStripeProxy($this->config['proxy'] ?? null);
        $stripe = new \Stripe\StripeClient($this->config['secret_key']);

        try {
            $session = $stripe->checkout->sessions->retrieve($sessionId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Stripe查询Checkout Session失败：' . $e->getMessage());
        }

        $status = null;
        $sessionState = 'unknown';
        if ($session->payment_status === 'paid' || $session->payment_status === 'no_payment_required') {
            $status = TransactionStateMachine::PAID;
        } elseif ($session->status === 'expired' && $session->payment_status === 'unpaid') {
            // 关闭的是平台会话，不是本地订单；本站可按会话期限创建新的支付尝试。
            $sessionState = 'closed';
        } elseif ($session->status === 'open' && $session->payment_status === 'unpaid') {
            $sessionState = 'reusable';
        } elseif ($session->status === 'complete' && $session->payment_status === 'unpaid') {
            $sessionState = 'processing';
        }

        return [
            'status' => $status,
            'session_state' => $sessionState,
            // payment_intent字段在未expand时本身就是ID字符串。
            'transaction_id' => is_string($session->payment_intent) ? $session->payment_intent : null,
            'amount_minor' => isset($session->amount_total) ? (int) $session->amount_total : null,
            'currency' => isset($session->currency) ? strtoupper($session->currency) : null,
        ];
    }

    /** 轮换前关闭Stripe旧收银台；查询失败或已开始付款时不得放行新会话。 */
    public function retirePaymentSession(array $transaction): bool
    {
        $sessionId = $transaction['payment_intent'] ?? '';
        if (!is_string($sessionId) || $sessionId === '') {
            return false;
        }
        if (!class_exists('\\Stripe\\StripeClient')) {
            throw new \RuntimeException('stripe/stripe-php 未安装');
        }

        self::configureStripeProxy($this->config['proxy'] ?? null);
        $stripe = new \Stripe\StripeClient($this->config['secret_key']);
        try {
            $session = $stripe->checkout->sessions->retrieve($sessionId);
            if ($session->payment_status !== 'unpaid') {
                return false;
            }
            if ($session->status === 'expired') {
                return true;
            }
            if ($session->status !== 'open') {
                return false;
            }

            $expiredSession = $stripe->checkout->sessions->expire($sessionId, []);
            return $expiredSession->status === 'expired' && $expiredSession->payment_status === 'unpaid';
        } catch (\Throwable $e) {
            throw new \RuntimeException('Stripe旧支付会话关闭失败：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Stripe标准Checkout/PaymentIntent流程没有一个通用的"商家把物流信息回传给
     * Stripe"的API（这不是Stripe覆盖的业务范畴），如实告知不支持，不假装同步成功。
     */
    public function syncTrackingToRemote(array $transaction, array $trackingData)
    {
        return ['supported' => false, 'success' => false, 'message' => 'Stripe不提供物流信息同步接口'];
    }

    public function healthCheck(array $config)
    {
        if (!class_exists('\Stripe\StripeClient')) {
            return ['status' => 'unhealthy', 'message' => 'stripe/stripe-php 未安装'];
        }

        try {
            self::configureStripeProxy($config['proxy'] ?? null);
            $stripe = new \Stripe\StripeClient($config['secret_key']);
            $account = $stripe->accounts->retrieve();

            return [
                'status' => 'healthy',
                'message' => 'Stripe account is active',
                'account_id' => $account->id ?? null,
                'account_name' => $account->settings->dashboard->display_name
                    ?? ($account->business_profile->name ?? null),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }
}
