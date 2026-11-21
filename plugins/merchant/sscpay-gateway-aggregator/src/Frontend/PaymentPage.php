<?php
/**
 * 支付页面：五条相互独立的路由（对应设计文档v2第八节 + loading页拆分需求 +
 * 支付结果页需求 + 取消页需求）。
 *
 * - /payment/{s_order_id}?token=xxx
 *   loading页：只显示当前支付方式的logo + 2秒倒计时，倒计时结束后通过真实
 *   页面导航跳转离开。
 * - /payment/{s_order_id}/checkout?token=xxx
 *   结账页：渲染真正的支付界面（paypal_js 加载 PayPal JS SDK 渲染
 *   Smart Buttons，其余网关是跳转三方收银台的按钮）。
 * - /payment/{s_order_id}/result?token=xxx
 *   支付结果页：支付成功返回统一落地到这里，展示的是【终态】。展示结果文案 +
 *   “Return to Store”按钮；如果该订单已经跳回过S系统（returned_to_s
 *   标记），不再展示按钮，改为根据 wp_order_id 自动跳转到 WordPress 的
 *   order-received 页面。
 * - /payment/{s_order_id}/cancel?token=xxx
 *   取消页：买家自己放弃这一次支付（PayPal授权弹层点取消、Stripe/Airwallex
 *   收银台点取消）后统一落地到这里。与结果页的区别是订单还停在pending、
 *   仍然可支付，所以除了灰色的“Return to Store”，还要给一个高亮的
 *   “Retry Payment”按钮把买家引回支付流程；落地时写一条 user canceled 日志。
 * - /payment/{s_order_id}/return-to-shop?token=xxx&action=return|cancel
 *   结果页/取消页按钮的中转端点：先做访客风控校验（确认当前浏览器就是打开这笔
 *   订单的浏览器，见 ensureVisitorBinding/isOriginalVisitor），通过后记录
 *   returned_to_s 标记再跳转到 S 系统回传的 return_url/cancel_url；
 *   校验不通过则不跳S系统，直接导去WordPress的order-received页面。
 *
 * 各路由绝不能合并成同一路由，也禁止用"同一页面关闭遮罩层"的方式模拟
 * 跳转——loading页必须通过真实的页面导航跳到另一个地址。
 *
 * 跳往三方（PayPal/Stripe等）之前统一声明 Referrer-Policy: origin +
 * <meta name="referrer" content="origin">：浏览器跳转时只发送本站域名
 * （scheme+host），不带路径/查询参数，这样三方网关能确认订单来自本站，
 * 又不会把订单号等路径信息泄漏给对方。
 *
 * 用 WordPress 的 rewrite rule + template_redirect 实现"伪路由"，
 * 不依赖任何页面模板/主题，直接在这里输出完整HTML并exit，
 * 这样不管站点用什么主题都不会受影响，也不会被主题的输出干扰。
 */

namespace PaymentGatewayAggregator\Frontend;

use PaymentGatewayAggregator\Core\PaymentSessionManager;
use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\OrderStatusReconciler;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Core\TransactionStateMachine;
use PaymentGatewayAggregator\Frontend\Themes\ThemeManager;
use PaymentGatewayAggregator\Helpers\TokenGenerator;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentPage
{
    const QUERY_VAR = 'pga_payment';
    const CHECKOUT_QUERY_VAR = 'pga_payment_checkout';
    const RESULT_QUERY_VAR = 'pga_payment_result';
    const CANCEL_QUERY_VAR = 'pga_payment_cancel';
    const RETURN_TO_SHOP_QUERY_VAR = 'pga_payment_return';

    /** 取消页 reason 参数取值：区分"买家主动取消"与"capture调用失败被兜底送来"。 */
    const CANCEL_REASON_USER = 'user_cancel';
    const CANCEL_REASON_CAPTURE_FAILED = 'capture_failed';

    /** loading页倒计时时长（毫秒） */
    const LOADING_DELAY_MS = 2000;

    /** 访客风控cookie：见 ensureVisitorBinding() 与 maybeRenderReturnToShop()。 */
    const VISITOR_COOKIE = 'pga_visitor_token';

    /** 风控cookie有效期：30天，覆盖"支付后隔了几天才点返回"的场景。 */
    const VISITOR_COOKIE_TTL = 2592000;

    public static function registerHooks()
    {
        add_action('init', [self::class, 'registerRewriteRule']);
        add_filter('query_vars', [self::class, 'addQueryVar']);
        add_action('template_redirect', [self::class, 'maybeRenderLoadingPage']);
        add_action('template_redirect', [self::class, 'maybeRenderCheckoutPage']);
        add_action('template_redirect', [self::class, 'maybeRenderResultPage']);
        add_action('template_redirect', [self::class, 'maybeRenderCancelPage']);
        add_action('template_redirect', [self::class, 'maybeRenderReturnToShop']);
        // 老站点兜底：见方法注释。
        add_action('init', [self::class, 'maybeFlushRewriteRules'], 20);
    }

    public static function registerRewriteRule()
    {
        add_rewrite_rule('^payment/([^/]+)/checkout/?$', 'index.php?' . self::CHECKOUT_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^payment/([^/]+)/result/?$', 'index.php?' . self::RESULT_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^payment/([^/]+)/cancel/?$', 'index.php?' . self::CANCEL_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^payment/([^/]+)/return-to-shop/?$', 'index.php?' . self::RETURN_TO_SHOP_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^payment/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    public static function addQueryVar($vars)
    {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::CHECKOUT_QUERY_VAR;
        $vars[] = self::RESULT_QUERY_VAR;
        $vars[] = self::CANCEL_QUERY_VAR;
        $vars[] = self::RETURN_TO_SHOP_QUERY_VAR;
        return $vars;
    }

    /**
     * 老站点兜底：通过"在线更新"覆盖插件代码时不会重新激活插件，
     * 结账路由不会被注册进rewrite rules。每次请求检查一次（只是读
     * 一个已自动加载的option，开销可忽略），缺失时补注册并flush。
     */
    public static function maybeFlushRewriteRules()
    {
        $rules = get_option('rewrite_rules');
        if (!is_array($rules) || empty($rules)) {
            // 站点没有启用固定链接时本来就没有伪路由可用，不做无谓的flush。
            return;
        }

        // 取消页路由是1.5.0加的：老站点通过在线更新覆盖代码后不会重新激活插件，
        // 少检这一条会导致买家点取消时落到主题的404页。
        if (isset($rules['^payment/([^/]+)/checkout/?$'])
            && isset($rules['^payment/([^/]+)/result/?$'])
            && isset($rules['^payment/([^/]+)/cancel/?$'])) {
            return;
        }

        self::registerRewriteRule();
        flush_rewrite_rules();
    }

    /**
     * loading页：2秒倒计时后真实跳转离开。
     *
     * 跳转目标：
     * - paypal_js   → 本插件独立的结账路由（PayPal Smart Buttons，见下面结账页）；
     * - 其余网关    → 三方收银台地址（Stripe/PayPal REST/Airwallex/Antom）。
     */
    public static function maybeRenderLoadingPage()
    {
        $sOrderId = get_query_var(self::QUERY_VAR);
        if (empty($sOrderId)) {
            return;
        }

        $transaction = self::resolvePendingTransactionOrExit($sOrderId);
        self::ensureVisitorBinding($transaction);
        $context = self::prepareGatewayContextOrExit($transaction);

        if ($transaction['payment_method'] === 'paypal_js') {
            $targetUrl = self::checkoutPageUrl($transaction);
        } else {
            $targetUrl = $context['sessionData']['redirect_url'] ?? '';
        }

        if (empty($targetUrl)) {
            self::renderShell('Payment Unavailable', '<p>The payment channel failed to initialize. Please contact the merchant.</p>', 500);
            exit;
        }

        self::renderLoadingShell($transaction, $targetUrl);
        exit;
    }

    /**
     * 结账页：独立路由，渲染真正的支付界面。
     * 从loading页真实跳转过来，也允许直接访问（链接本身带token校验）。
     */
    public static function maybeRenderCheckoutPage()
    {
        $sOrderId = get_query_var(self::CHECKOUT_QUERY_VAR);
        if (empty($sOrderId)) {
            return;
        }

        $transaction = self::resolvePendingTransactionOrExit($sOrderId);
        self::ensureVisitorBinding($transaction);
        $context = self::prepareGatewayContextOrExit($transaction);

        try {
            $actionsHtml = $context['gateway']->renderPaymentPage($transaction, $context['sessionData']);
        } catch (\Throwable $e) {
            self::renderShell('Payment Unavailable', '<p>An error occurred while loading the payment channel. Please try again later or contact the merchant.</p>', 500);
            exit;
        }

        $notice = [
            'title' => __('Your order is awaiting payment', 'sscpay-gateway-aggregator'),
            'body' => __('Complete payment to confirm your order.', 'sscpay-gateway-aggregator'),
        ];
        $viewModel = self::buildInvoiceViewModel($transaction, self::statusBadge($transaction['status']), $actionsHtml, $notice);
        $body = ThemeManager::getActive()->renderInvoiceCard($viewModel);

        self::renderShell('Order Payment', $body, 200, true);
        exit;
    }

    /**
     * 支付结果页：支付成功返回的统一落地页，展示的是【终态】。
     *
     * - 未跳回过S系统：展示结果文案 + “Return to Store”按钮，点击先跳到
     *   return-to-shop 中转端点，再由它跳去S系统的return_url/cancel_url；
     * - 已跳回过（returned_to_s标记）：不再展示按钮，直接根据 wp_order_id
     *   自动跳转到WordPress的order-received页面。
     *
     * 买家自己取消支付不走这里，走取消页（见maybeRenderCancelPage）：
     * 那里订单还是pending，需要给重新支付的入口。
     */
    public static function maybeRenderResultPage()
    {
        $sOrderId = get_query_var(self::RESULT_QUERY_VAR);
        if (empty($sOrderId)) {
            return;
        }

        $transaction = self::resolveTransactionOrExit($sOrderId, false);

        // 买家返回可能晚于本站链接有效期，且Webhook尚未更新本地状态。
        // pending/expired都先核实渠道真实收款，再决定是否展示链接过期或跳转。
        $transaction = OrderStatusReconciler::reconcileForResultPage($transaction);

        if (TransactionManager::isReturnedToS($transaction)) {
            $receivedUrl = self::orderReceivedUrl($transaction);
            self::renderShell('Payment Result', self::renderAutoRedirectBody($receivedUrl));
            exit;
        }

        $copy = self::resultCopy($transaction['status']);
        // 取消的订单按钮最终跳S系统的cancel_url，其余跳return_url（见return-to-shop端点）。
        $action = $transaction['status'] === TransactionStateMachine::CANCELLED ? 'cancel' : 'return';

        // 结果页上返回按钮是唯一的主操作，用高亮样式。
        $actionsHtml = self::renderReturnButton($transaction, $action, true);
        $notice = ['title' => $copy['title'], 'body' => $copy['message']];
        $viewModel = self::buildInvoiceViewModel($transaction, self::statusBadge($transaction['status']), $actionsHtml, $notice);
        $body = ThemeManager::getActive()->renderInvoiceCard($viewModel);

        self::renderShell($copy['title'], $body, 200, true);
        exit;
    }

    /**
     * 取消页：买家在三方收银台/PayPal授权弹层点了“取消”之后统一落地到这里。
     *
     * 与结果页的根本区别：买家只是放弃了【这一次】支付，订单本身还停在
     * pending、还可以继续付（因此 PayPalRestController::cancelHandler 不再把它
     * 写成cancelled终态，详见那里的注释）。所以这个页面除了返回商店，
     * 还要把“重新支付”做成高亮主按钮，尽量把这一单救回来；
     * “Return to Store”降为灰色次要按钮，避免两个按钮抢视觉。
     *
     * 各网关的入口：
     * - paypal_js / paypal_rest：先走 /v1/paypal-rest/cancel 中转端点，由它
     *   重定向到这里（见PayPalRestController::cancelHandler）；
     * - stripe / airwallex：三方收银台的 cancel_url / failUrl 直接指向这里。
     * 各入口最终都汇到同一个页面，user canceled 日志也只在这里记一次。
     */
    public static function maybeRenderCancelPage()
    {
        $sOrderId = get_query_var(self::CANCEL_QUERY_VAR);
        if (empty($sOrderId)) {
            return;
        }

        $transaction = self::resolveTransactionOrExit($sOrderId);

        // 已经跳回过S系统的订单（书签重访等）：与结果页一致，直接自动跳
        // order-received，不再展示任何按钮，避免重复跳S系统。
        if (TransactionManager::isReturnedToS($transaction)) {
            self::renderShell('Payment Result', self::renderAutoRedirectBody(self::orderReceivedUrl($transaction)));
            exit;
        }

        // 非pending：订单已经落到终态（比如买家点取消的同时webhook已经把款扣了，
        // 或者链接已过期），重新支付已无意义也无路可走（结账页会拦住非pending），
        // 按结果页的文案展示 + 灰色返回按钮。返回目标的选法与结果页一致：
        // 只有真正取消的订单跳cancel_url，其余（尤其是竞态下已支付的）跳return_url。
        if ($transaction['status'] !== TransactionStateMachine::PENDING) {
            $copy = self::resultCopy($transaction['status']);
            $action = $transaction['status'] === TransactionStateMachine::CANCELLED ? 'cancel' : 'return';
            $actionsHtml = self::renderReturnButton($transaction, $action);
            $notice = ['title' => $copy['title'], 'body' => $copy['message']];
            $viewModel = self::buildInvoiceViewModel($transaction, self::statusBadge($transaction['status']), $actionsHtml, $notice);
            $body = ThemeManager::getActive()->renderInvoiceCard($viewModel);

            self::renderShell($copy['title'], $body, 200, true);
            exit;
        }

        self::logUserCancelled($transaction);

        $actionsHtml =
            // 重新支付：高亮主按钮，回到loading页重走一遍完整支付流程。
            ThemeManager::getActive()->renderActionButton([
                'url' => self::loadingPageUrl($transaction),
                'label' => __('Retry Payment', 'sscpay-gateway-aggregator'),
                'primary' => true,
            ])
            // 返回商店：灰色次要按钮，仍走return-to-shop中转端点（含访客风控）。
            . self::renderReturnButton($transaction, 'cancel');
        $notice = [
            'title' => __('Payment Cancelled', 'sscpay-gateway-aggregator'),
            'body' => __('Your payment was cancelled. Your order is still open — you can try paying again right away.', 'sscpay-gateway-aggregator'),
        ];
        $viewModel = self::buildInvoiceViewModel($transaction, self::statusBadge($transaction['status']), $actionsHtml, $notice);
        $body = ThemeManager::getActive()->renderInvoiceCard($viewModel);

        self::renderShell(__('Payment Cancelled', 'sscpay-gateway-aggregator'), $body, 200, true);
        exit;
    }

    /**
     * 记一条“买家取消了支付”（user canceled）日志。
     *
     * 只在取消页渲染时记一次：PayPal两个网关经中转端点重定向过来、
     * Stripe/Airwallex由三方收银台直接跳过来，最终都落到这里，日志入口统一，
     * 不会像"中转端点记一次 + 页面再记一次"那样重复。
     *
     * reason 用来区分"买家主动点了取消"和"capture调用失败被兜底送到这里"
     * （见PayPalJsGateway的onApprove catch分支）——后者不是买家的行为，
     * 而是服务端异常（记WARNING），混在一起会污染取消率统计，所以分开记。
     */
    private static function logUserCancelled(array $transaction)
    {
        $reason = isset($_GET['reason']) ? sanitize_key(wp_unslash($_GET['reason'])) : '';
        $isCaptureFailure = $reason === self::CANCEL_REASON_CAPTURE_FAILED;

        $context = [
            'wp_order_id' => $transaction['wp_order_id'],
            'payment_method' => $transaction['payment_method'],
            'response_data' => [
                'reason' => $isCaptureFailure ? self::CANCEL_REASON_CAPTURE_FAILED : self::CANCEL_REASON_USER,
                'status' => $transaction['status'],
                'payment_intent' => $transaction['payment_intent'] ?? null,
                'expires_at' => $transaction['expires_at'],
            ],
        ];

        if ($isCaptureFailure) {
            LogManager::warning(
                $transaction['s_order_id'],
                'capture调用失败后进入取消页（非买家主动取消），订单保持pending等待重新支付',
                $context
            );
            return;
        }

        LogManager::info(
            $transaction['s_order_id'],
            '买家取消了支付（user canceled），订单保持pending，取消页已展示重新支付入口',
            $context
        );
    }

    /**
     * “Return to Store”按钮（面向用户的文案统一英文）。
     *
     * 点击后走 return-to-shop 中转端点（含访客风控）再跳S系统。
     *
     * S系统可以通过/v1/pay传入的allow_returned_source=N关掉这个按钮（见
     * TransactionManager::create()）：结果页/取消页此时不展示任何返回入口，
     * 买家停留在当前页面——这是这个方法唯一的调用入口，改这里就覆盖了所有
     * 会展示该按钮的页面。
     *
     * @param array $transaction
     * @param string $action 'return'跳S系统return_url；'cancel'跳cancel_url
     * @param bool $primary 结果页上它是唯一的主操作，用高亮样式；取消页上
     *                      “重新支付”才是主操作，这里降为灰色次要按钮。
     * @return string allow_returned_source=N时返回空字符串（不展示按钮）
     */
    private static function renderReturnButton(array $transaction, $action, $primary = false)
    {
        if (($transaction['allow_returned_source'] ?? 'Y') === 'N') {
            return '';
        }

        $url = add_query_arg('action', $action, self::returnToShopUrl($transaction));

        return ThemeManager::getActive()->renderActionButton([
            'url' => $url,
            'label' => __('Return to Store', 'sscpay-gateway-aggregator'),
            'primary' => $primary,
        ]);
    }

    /**
     * 结果页/取消页“Return to Store”按钮的中转端点：先做访客风控校验，通过后记录
     * returned_to_s 标记，再跳转到S系统回传的 return_url/cancel_url。
     *
     * 风控规则（见 ensureVisitorBinding/isOriginalVisitor）：只有当初打开这笔
     * 订单的浏览器（cookie里的visitor_token与库中记录一致）才允许跳回S系统；
     * 链接被转发给他人、cookie被清等情况一律导去order-received页面。
     *
     * 已经跳回过的订单再次访问这里（比如书签重访）：不再绕回结果页/取消页，
     * 直接根据 wp_order_id 跳转到WordPress的order-received页面，
     * 避免重复跳S系统。
     */
    public static function maybeRenderReturnToShop()
    {
        $sOrderId = get_query_var(self::RETURN_TO_SHOP_QUERY_VAR);
        if (empty($sOrderId)) {
            return;
        }

        $transaction = self::resolveTransactionOrExit($sOrderId);

        if (TransactionManager::isReturnedToS($transaction)) {
            self::renderRedirectHtml(self::orderReceivedUrl($transaction));
        }

        // allow_returned_source=N：结果页/取消页本来就不会渲染这个按钮（见
        // renderReturnButton()），这里同样拦一道，防止有人直接拼URL绕过按钮
        // 隐藏直接跳S系统。
        if (($transaction['allow_returned_source'] ?? 'Y') === 'N') {
            self::renderRedirectHtml(self::orderReceivedUrl($transaction));
        }

        // 风控：确认是打开这笔订单的浏览器才放行跳S系统。不通过时不写
        // returned_to_s（首开者后续仍可通过按钮正常跳回），只记审计日志。
        $cookieToken = isset($_COOKIE[self::VISITOR_COOKIE])
            ? sanitize_text_field(wp_unslash($_COOKIE[self::VISITOR_COOKIE])) : '';
        if (!TransactionManager::isOriginalVisitor($transaction, $cookieToken)) {
            LogManager::warning($transaction['s_order_id'], 'return-to-shop风控拦截：访问者不是打开该订单的浏览器，已导去order-received页面', [
                'wp_order_id' => $transaction['wp_order_id'],
                'payment_method' => $transaction['payment_method'],
                'response_data' => [
                    'cookie_present' => $cookieToken !== '',
                    'opened_at_ip' => $transaction['visitor_ip'] ?? null,
                    'opened_at_user_agent' => $transaction['visitor_user_agent'] ?? null,
                ],
            ]);
            self::renderRedirectHtml(self::orderReceivedUrl($transaction));
        }

        TransactionManager::markReturnedToS($transaction);

        $action = isset($_GET['action']) && $_GET['action'] === 'cancel' ? 'cancel' : 'return';
        $target = $action === 'cancel' ? $transaction['cancel_url'] : $transaction['return_url'];

        // 只允许http/https，防止异常scheme被当作跳转目标输出。
        $scheme = wp_parse_url((string) $target, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            self::renderShell('Unavailable', '<p>The return address is unavailable. Please contact the merchant.</p>', 500);
            exit;
        }

        self::renderRedirectHtml($target);
    }

    /**
     * 访客风控绑定：用户第一次打开支付页（loading/结账页）时调用。
     *
     * 生成随机 visitor_token：
     * - 写库（仅首个打开者能写成功，见 TransactionManager::bindVisitor）；
     * - 种进当前浏览器的同名cookie（HttpOnly + SameSite=Lax，JS读不到，
     *   随后续请求自动携带）。
     * 之后用户在 return-to-shop 端点跳回S系统前，用cookie与库中记录比对，
     * 确认"来的是不是打开这笔订单的浏览器"。
     *
     * 并发/转发场景：两个浏览器同时首开，只有一个写库成功；写库失败的浏览器
     * 不种cookie，后续风控自然拦截。已绑定过的订单重复访问不做任何事，
     * 不覆盖cookie，保证首开者身份始终可验证。
     */
    private static function ensureVisitorBinding(array $transaction)
    {
        if (!empty($transaction['visitor_token'])) {
            return;
        }

        $visitorToken = TokenGenerator::generate(16);

        // IP取法与LogManager一致：只信REMOTE_ADDR，不信可伪造的转发头；
        // 两者仅作审计线索落库，不参与风控硬性判断。
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), 0, 64) : '';
        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';

        if (!TransactionManager::bindVisitor($transaction, $visitorToken, $ip, $userAgent)) {
            // 并发下被另一个浏览器抢先绑定：当前浏览器不是首开者，不种cookie。
            return;
        }

        setcookie(self::VISITOR_COOKIE, $visitorToken, [
            'expires' => time() + self::VISITOR_COOKIE_TTL,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        LogManager::info($transaction['s_order_id'], '支付页首次打开，已完成访客风控绑定', [
            'wp_order_id' => $transaction['wp_order_id'],
            'payment_method' => $transaction['payment_method'],
        ]);
    }

    /**
     * 校验支付链接，结果页/中转端点共用。与结账页不同：这里允许任意状态的
     * 交易（结果页本来就是展示终态的），校验不过同样输出统一提示页并exit。
     */
    private static function resolveTransactionOrExit($sOrderId, $checkExpiry = true)
    {
        $sOrderId = sanitize_text_field(wp_unslash($sOrderId));
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        $transaction = TransactionManager::findBySOrderId($sOrderId);

        if (!$transaction || !TokenGenerator::isEqual($transaction['pay_token'], $token)) {
            self::renderShell('Invalid Link', '<p>This payment link is invalid or has expired. Please contact the merchant for a new one.</p>', 404);
            exit;
        }

        // 结果页会先核实渠道收款，不能在读取记录时就把pending改为expired。
        return $checkExpiry ? TransactionManager::maybeExpire($transaction) : $transaction;
    }

    /**
     * 校验支付链接与订单状态，两条路由共用。
     * 校验不过直接输出对应提示页并exit；通过则返回pending状态的交易。
     */
    private static function resolvePendingTransactionOrExit($sOrderId, $inspectPending = true)
    {
        $sOrderId = sanitize_text_field(wp_unslash($sOrderId));
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        $transaction = TransactionManager::findBySOrderId($sOrderId);

        // 不区分"订单不存在"和"token不对"，统一提示，避免被用来遍历探测订单号
        // （对应评审第4点 / 设计文档v2 8.2流程）。
        if (!$transaction || !TokenGenerator::isEqual($transaction['pay_token'], $token)) {
            self::renderShell('Invalid Link', '<p>This payment link is invalid or has expired. Please contact the merchant for a new one.</p>', 404);
            exit;
        }

        // 平台可能已经收款但Webhook尚未送达；先交给会话管理器核实，再执行本站过期检查。
        if ($inspectPending && in_array($transaction['status'], [TransactionStateMachine::PENDING, TransactionStateMachine::EXPIRED], true)) {
            return $transaction;
        }
        $transaction = TransactionManager::maybeExpire($transaction);

        switch ($transaction['status']) {
            case TransactionStateMachine::PAID:
                self::renderShell('Order Paid', '<p>This order has already been paid. No further action is needed.</p>');
                exit;

            case TransactionStateMachine::CANCELLED:
                self::renderShell('Order Cancelled', '<p>This order has been cancelled.</p>');
                exit;

            case TransactionStateMachine::EXPIRED:
                self::renderShell('Link Expired', '<p>The payment link has expired. Please contact the merchant to place a new order.</p>');
                exit;

            case TransactionStateMachine::FAILED:
                self::renderShell('Payment Failed', '<p>Payment for this order failed. Please contact the merchant or place a new order.</p>');
                exit;

            case TransactionStateMachine::REFUNDED:
                self::renderShell('Order Refunded', '<p>This order has been refunded.</p>');
                exit;
        }

        if ($transaction['status'] !== TransactionStateMachine::PENDING) {
            self::renderShell('Payment Unavailable', '<p>This order is not available for payment. Please contact the merchant.</p>');
            exit;
        }

        return $transaction;
    }

    /**
     * 核实平台收款并复用或轮换平台会话，两条路由共用。
     * 失败直接输出错误页并exit。
     *
     * @return array{gateway:\PaymentGatewayAggregator\Core\PaymentBase,sessionData:array}
     */
    private static function prepareGatewayContextOrExit(array &$transaction)
    {
        try {
            $context = PaymentSessionManager::ensure($transaction);
            $transaction = $context['transaction'];
            if ($transaction['status'] !== TransactionStateMachine::PENDING) {
                self::resolvePendingTransactionOrExit($transaction['s_order_id'], false);
            }
            return $context;
        } catch (\Throwable $e) {
            LogManager::warning($transaction['s_order_id'], '支付页面准备平台会话失败：' . $e->getMessage(), [
                'payment_method' => $transaction['payment_method'],
            ]);
            self::renderShell('Payment Unavailable', '<p>Payment is temporarily unavailable or is still being processed. Please try again later or contact the merchant.</p>', 503);
            exit;
        }
    }

    /**
     * 结账页地址（独立路由，与loading页不同地址）。
     */
    private static function checkoutPageUrl(array $transaction)
    {
        return add_query_arg(
            'token',
            rawurlencode($transaction['pay_token']),
            home_url('/payment/' . rawurlencode($transaction['s_order_id']) . '/checkout')
        );
    }

    /**
     * loading页地址，也就是整个支付流程的入口（与/v1/pay返回给S系统的
     * pay_url是同一个地址）。取消页的“Retry Payment”按钮指向这里：
     * 各网关都从loading页重走一遍完整流程；Stripe/PayPal先核实收款，
     * 有效会话复用，超过本站会话期限且确认未付款才创建下一次尝试。
     */
    private static function loadingPageUrl(array $transaction)
    {
        return add_query_arg(
            'token',
            rawurlencode($transaction['pay_token']),
            home_url('/payment/' . rawurlencode($transaction['s_order_id']))
        );
    }

    /**
     * 支付结果页地址。PayPal中转端点/JS前端统一跳到这里。
     */
    public static function resultPageUrl(array $transaction)
    {
        return add_query_arg(
            'token',
            rawurlencode($transaction['pay_token']),
            home_url('/payment/' . rawurlencode($transaction['s_order_id']) . '/result')
        );
    }

    /**
     * 取消页地址。各网关把它当作三方收银台的 cancel_url / failUrl（见
     * StripeGateway / AirwallexGateway），PayPal两个网关则由
     * /v1/paypal-rest/cancel 中转端点重定向过来——买家点“取消”后统一落回
     * 插件自己的取消页（可重新支付），而不是直接跳回S系统。
     *
     * @param array $transaction
     * @param string $reason 可选，透传给取消页用于区分日志（见logUserCancelled）
     * @return string
     */
    public static function cancelPageUrl(array $transaction, $reason = '')
    {
        $args = ['token' => rawurlencode($transaction['pay_token'])];
        if ($reason !== '') {
            $args['reason'] = rawurlencode($reason);
        }

        return add_query_arg(
            $args,
            home_url('/payment/' . rawurlencode($transaction['s_order_id']) . '/cancel')
        );
    }

    /**
     * “Return to shop”按钮的中转端点地址（action参数由结果页渲染时追加）。
     */
    private static function returnToShopUrl(array $transaction)
    {
        return add_query_arg(
            'token',
            rawurlencode($transaction['pay_token']),
            home_url('/payment/' . rawurlencode($transaction['s_order_id']) . '/return-to-shop')
        );
    }

    /**
     * WordPress/WooCommerce的order-received页面地址（按wp_order_id拼接）。
     * 优先用WC自己的URL构造（自带order key，游客场景兼容性最好），
     * WC不可用时退化为固定路径拼接。
     */
    private static function orderReceivedUrl(array $transaction)
    {
        $wpOrderId = (int) ($transaction['wp_order_id'] ?? 0);

        if (function_exists('wc_get_order')) {
            $order = $wpOrderId > 0 ? wc_get_order($wpOrderId) : false;
            if ($order) {
                return $order->get_checkout_order_received_url();
            }
        }

        $checkoutUrl = function_exists('wc_get_page_permalink')
            ? wc_get_page_permalink('checkout')
            : home_url('/checkout/');

        return trailingslashit($checkoutUrl) . 'order-received/' . $wpOrderId . '/';
    }

    /**
     * 结果页各状态对应的标题与文案（面向用户的文案统一英文，
     * 保留翻译函数以便后续多语言扩展）。
     *
     * @return array{title:string,message:string}
     */
    private static function resultCopy($status)
    {
        switch ($status) {
            case TransactionStateMachine::PAID:
                return [
                    'title' => __('Payment Successful', 'sscpay-gateway-aggregator'),
                    'message' => __('Your payment was completed successfully. Thank you for your order.', 'sscpay-gateway-aggregator'),
                ];

            case TransactionStateMachine::CANCELLED:
                return [
                    'title' => __('Order Cancelled', 'sscpay-gateway-aggregator'),
                    'message' => __('This order has been cancelled.', 'sscpay-gateway-aggregator'),
                ];

            case TransactionStateMachine::FAILED:
                return [
                    'title' => __('Payment Failed', 'sscpay-gateway-aggregator'),
                    'message' => __('Payment for this order failed. Please contact the merchant or place a new order.', 'sscpay-gateway-aggregator'),
                ];

            case TransactionStateMachine::EXPIRED:
                return [
                    'title' => __('Link Expired', 'sscpay-gateway-aggregator'),
                    'message' => __('The payment link has expired. Please contact the merchant to place a new order.', 'sscpay-gateway-aggregator'),
                ];

            case TransactionStateMachine::REFUNDED:
                return [
                    'title' => __('Order Refunded', 'sscpay-gateway-aggregator'),
                    'message' => __('This order has been refunded.', 'sscpay-gateway-aggregator'),
                ];

            default:
                // pending：capture已发起但webhook尚未确认，提示以最终通知为准。
                return [
                    'title' => __('Payment In Progress', 'sscpay-gateway-aggregator'),
                    'message' => __('Your payment is being processed. The final result is subject to the payment provider\'s confirmation.', 'sscpay-gateway-aggregator'),
                ];
        }
    }

    /**
     * 已跳回过S系统时的结果页内容：不展示按钮，脚本直接自动跳转到
     * order-received页面，noscript/JS失效时提供兑底链接。
     */
    private static function renderAutoRedirectBody($receivedUrl)
    {
        ob_start();
        ?>
        <p class="pga-result-message">
            <?php esc_html_e('You have already returned to the shop. Redirecting you to your order confirmation page…', 'sscpay-gateway-aggregator'); ?>
        </p>
        <div class="pga-loading-spinner" aria-hidden="true"></div>
        <noscript>
            <p><a class="pga-pay-button" href="<?php echo esc_url($receivedUrl); ?>">
                <?php esc_html_e('Continue to order confirmation', 'sscpay-gateway-aggregator'); ?>
            </a></p>
        </noscript>
        <script>
        (function () {
            // 不做破框：页面本身禁止被嵌套，被装进框架时根本不会渲染到这里。
            window.location.replace(<?php echo wp_json_encode($receivedUrl); ?>);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * 输出一个只负责跳转的HTML页并终止请求（与PayPalRestController里同样的模式）。
     * 和其它页面一样禁止被嵌套：被装进框架时遮罩脚本会让它什么都不显示，
     * 自然也不会执行下面的跳转。
     */
    private static function renderRedirectHtml($url)
    {
        status_header(200);
        nocache_headers();
        header('Referrer-Policy: origin');
        self::sendFrameGuardHeaders();
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <?php echo self::frameGuardHead(); // phpcs:ignore -- 内部生成的可信HTML ?>
            <meta name="referrer" content="origin">
            <meta name="robots" content="noindex, nofollow">
            <title>Redirecting…</title>
            <noscript><meta http-equiv="refresh" content="0; url=<?php echo esc_url($url); ?>"></noscript>
        </head>
        <body>
        <script>
        (function () {
            // 页面禁止被嵌套（见 frameGuardHead）：真被装进框架时上面的遮罩脚本
            // 已经拦下了，这里不再做"把跳转提升到顶层窗口"的破框处理——那等于
            // 替别人的页面做导航。
            window.location.replace(<?php echo wp_json_encode($url); ?>);
        })();
        </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * 反嵌套（第一道，主防线）：禁止插件的任何页面被装进别人的页面里。
     *
     * 支付页会展示买家姓名、账单地址、金额，并且是发起真实扣款的入口。被第三方
     * 页面嵌套等于把这些内容和操作放进一个我们控制不了的上下文——点击劫持、
     * 用覆盖层伪造按钮、上层脚本按尺寸/时序推断买家行为，都成立。所以策略是
     * "根本不给渲染"，而不是原来那种"检测到框架就把跳转提升到顶层窗口"
     * （那等于默认允许被嵌套，只是把跳转弹出去，并不能阻止页面被看到）。
     *
     * 两个头一起发，语义一致不冲突：
     * - X-Frame-Options: DENY —— 老浏览器和链路上的老设备认这个；
     * - CSP frame-ancestors 'none' —— 现代浏览器的正式做法，优先级高于上一条，
     *   而且能覆盖 <object>/<embed> 这类 X-Frame-Options 管不到的嵌入方式。
     *
     * 注意 header() 默认 replace=true：链路上如果有安全插件先发过一个
     * SAMEORIGIN，会被这里覆盖掉，不会出现两条互相打架的同名头。
     */
    public static function sendFrameGuardHeaders()
    {
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
    }

    /**
     * 反嵌套（第二道，兜底）：放在每个页面 <head> 里的遮罩 + 自检脚本。
     *
     * 响应头是主防线，但它可能被链路上的反向代理/CDN/安全插件改写或剥掉，
     * 那种情况下页面会毫无察觉地被嵌进别人的站里，所以再加一层不依赖响应头的
     * 保险：默认先用内联样式把整个文档藏起来，确认自己是顶层窗口之后才撤掉遮罩；
     * 发现处于框架内就保持隐藏、停止加载，并在DOM可用后清空body。
     *
     * 几个刻意的取舍：
     * - 【不能】用 documentElement.innerHTML='' 来清空：那会把下面这条遮罩样式
     *   本身一起删掉，反而让内容显示出来。真正起作用的是 display:none 这条，
     *   清 body 只是顺带让同源父窗口也读不到订单信息。
     * - 不碰 window.top.location：那是在替别人的页面做导航，容易被反过来利用。
     *   被嵌套时我们只负责自己什么都不显示。
     * - 跨域时读 window.top 会抛异常，catch 到一律按"被嵌套"处理（同源框架能读到、
     *   跨域框架读不到，两种都不允许）。
     * - 关掉JS的浏览器由 <noscript> 里的同名样式把显示恢复回来，否则正常买家会
     *   看到一片空白。这种情况下只剩响应头一道，属于可接受的残余风险。
     */
    public static function frameGuardHead()
    {
        ob_start();
        ?>
        <style id="pga-frame-guard">html{display:none !important}</style>
        <noscript><style>html{display:block !important}</style></noscript>
        <script>
        (function () {
            var framed = true;
            try {
                framed = (window.top !== window.self);
            } catch (e) {
                // 跨域父窗口，连比较都做不了——按"被嵌套"处理。
                framed = true;
            }

            if (framed) {
                try { window.stop(); } catch (e) {}
                // 遮罩样式保持不动，整个文档维持 display:none。
                document.addEventListener('DOMContentLoaded', function () {
                    if (document.body) { document.body.innerHTML = ''; }
                });
                return;
            }

            var guard = document.getElementById('pga-frame-guard');
            if (guard && guard.parentNode) {
                guard.parentNode.removeChild(guard);
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * 每种支付方式内置的默认1:1 logo（占位设计）。
     * 图片放在 assets/images/logos/ 下，直接替换同名文件即可更换品牌图。
     */
    private static function gatewayLogoUrl($paymentMethod)
    {
        if ($paymentMethod === 'paypal_js' || $paymentMethod === 'paypal_rest') {
            $path = 'assets/images/logos/paypal.png';
        } else {
            $path = 'assets/images/logos/creditcard.png';
        }

        if (!is_file(PGA_PLUGIN_DIR . $path)) {
            return '';
        }

        if (!defined('PGA_PLUGIN_URL')) {
            return '';
        }

        return PGA_PLUGIN_URL . $path;
    }

    /**
     * loading页：全屏显示支付方式logo + 倒计时，倒计时结束后真实跳转。
     *
     * 跳转通过程序化点击一个真实的 <a> 完成——是真实页面导航（目标地址是
     * 另一条路由/三方收银台），不是同页关遮罩的伪跳转；页面级
     * Referrer-Policy: origin 保证跳转时只发送本站域名。
     */
    private static function renderLoadingShell(array $transaction, $targetUrl)
    {
        status_header(200);
        nocache_headers();
        // 统一声明origin：后续跳往三方（PayPal/Stripe等）时浏览器只发送本站
        // 域名（不含路径/查询参数），让三方能确认订单来源又不泄漏订单细节。
        header('Referrer-Policy: origin');
        self::sendFrameGuardHeaders();

        $theme = ThemeManager::getActive();
        $cssUrl = $theme->getStylesheetUrl();
        $logoUrl = self::gatewayLogoUrl($transaction['payment_method']);
        $countdownSeconds = (int) ceil(self::LOADING_DELAY_MS / 1000);

        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <?php echo self::frameGuardHead(); // phpcs:ignore -- 内部生成的可信HTML ?>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="referrer" content="origin">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html__('Redirecting…', 'sscpay-gateway-aggregator'); ?></title>
            <?php if ($cssUrl) : ?>
                <link rel="stylesheet" href="<?php echo esc_url($cssUrl); ?>">
            <?php endif; ?>
            <noscript><meta http-equiv="refresh" content="<?php echo esc_attr($countdownSeconds); ?>; url=<?php echo esc_url($targetUrl); ?>"></noscript>
        </head>
        <body class="pga-payment-page">
            <?php
            echo $theme->renderLoadingBody([
                'logo_url' => $logoUrl,
                'countdown_seconds' => $countdownSeconds,
                'target_url' => $targetUrl,
            ]); // phpcs:ignore -- 主题内部已各自转义
            ?>
            <script>
            (function () {
                var DELAY_MS = <?php echo (int) self::LOADING_DELAY_MS; ?>;
                var link = document.getElementById('pga-jump-link');
                if (!link) { return; }

                var countEl = document.getElementById('pga-countdown-num');
                var deadline = Date.now() + DELAY_MS;
                var timer = setInterval(function () {
                    if (countEl) {
                        countEl.textContent = String(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)));
                    }
                }, 250);

                // 倒计时结束后点击真实锚点跳转：目标（结账页/三方收银台）与
                // loading页是不同地址，这里是真实页面导航，不是关遮罩的伪跳转。
                setTimeout(function () {
                    clearInterval(timer);
                    link.click();
                }, DELAY_MS);
            })();
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * 结账页/结果页/取消页共用的“发票风格”订单卡片数据：发票号/订单号/日期、
     * 客户与账单地址、金额小计与总计（不展示商户站点信息与商品明细，只呈现
     * 客户与金额相关信息）。$statusBadge 非空时在右上角展示支付状态徽章
     * （见 statusBadge()），结账页以外的三个终态/取消场景都会传入，对应
     * 本次需求“订单支付结果返回的页面也复用这个界面，额外显示支付状态信息”。
     *
     * 只组装纯数据，具体markup由当前生效主题的renderInvoiceCard()决定
     * （见ThemeManager/ThemeInterface）——这里改的是内容，不是样式。
     *
     * @param array $transaction
     * @param array{label:string,type:string}|null $statusBadge
     * @return array
     */
    private static function buildInvoiceViewModel(array $transaction, $statusBadge = null, $actionsHtml = '', $notice = null)
    {
        $invoiceNumber = trim((string) ($transaction['invoice_number'] ?? ''));
        $billingAddress = json_decode((string) ($transaction['billing_address'] ?? ''), true);
        $billingAddress = is_array($billingAddress) ? $billingAddress : [];

        $currency = (string) $transaction['currency'];
        $subtotal = (float) ($transaction['subtotal'] ?? 0);
        $shippingFee = (float) ($transaction['shipping_fee'] ?? 0);
        $taxFee = (float) ($transaction['tax_fee'] ?? 0);
        $discountFee = (float) ($transaction['discount_fee'] ?? 0);
        $total = (float) $transaction['amount'];

        $createdAt = !empty($transaction['created_at'])
            ? mysql2date(get_option('date_format'), $transaction['created_at'])
            : '';

        $totals = [
            ['label' => __('Subtotal', 'sscpay-gateway-aggregator'), 'value' => self::formatMoney($subtotal, $currency), 'grand' => false],
        ];
        if ($shippingFee > 0) {
            $totals[] = ['label' => __('Shipping', 'sscpay-gateway-aggregator'), 'value' => self::formatMoney($shippingFee, $currency), 'grand' => false];
        }
        if ($taxFee > 0) {
            $totals[] = ['label' => __('Tax', 'sscpay-gateway-aggregator'), 'value' => self::formatMoney($taxFee, $currency), 'grand' => false];
        }
        if ($discountFee > 0) {
            $totals[] = ['label' => __('Discount', 'sscpay-gateway-aggregator'), 'value' => "\u{2212}" . self::formatMoney($discountFee, $currency), 'grand' => false];
        }
        $totals[] = ['label' => __('Total', 'sscpay-gateway-aggregator'), 'value' => self::formatMoney($total, $currency), 'grand' => true];

        return [
            'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : (string) $transaction['s_order_id'],
            'order_no' => (string) $transaction['s_order_id'],
            'date' => $createdAt,
            'customer_name' => (string) $transaction['customer_name'],
            'address_lines' => self::formatAddressLines($billingAddress),
            'customer_email' => (string) $transaction['customer_email'],
            'customer_phone' => (string) ($transaction['customer_phone'] ?? ''),
            'status_badge' => $statusBadge,
            'totals' => $totals,
            'notice' => $notice,
            'actions_html' => $actionsHtml,
        ];
    }

    /**
     * 地址字段名与 OrderManager::applyAddress() 保持一致：
     * country / state / city / address / zip（S系统传参口径）。
     * 拼成"街道地址"、"城市, 省份 邮编"、"国家"最多三行，跳过空字段。
     *
     * @param array $address
     * @return string[]
     */
    private static function formatAddressLines(array $address)
    {
        $lines = [];

        $street = trim((string) ($address['address'] ?? ''));
        if ($street !== '') {
            $lines[] = $street;
        }

        $cityLine = trim(implode(', ', array_filter([
            trim((string) ($address['city'] ?? '')),
            trim((string) ($address['state'] ?? '')),
        ])));
        $zip = trim((string) ($address['zip'] ?? ''));
        if ($zip !== '') {
            $cityLine = $cityLine !== '' ? $cityLine . ' ' . $zip : $zip;
        }
        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }

        $country = trim((string) ($address['country'] ?? ''));
        if ($country !== '') {
            $lines[] = $country;
        }

        return $lines;
    }

    /**
     * @param float $amount
     * @param string $currency
     * @return string
     */
    private static function formatMoney($amount, $currency)
    {
        return $currency . ' ' . number_format((float) $amount, 2);
    }

    /**
     * 结果页/取消页/结账页共用的状态徽章文案与颜色分类（面向用户的文案统一英文）。
     *
     * @param string $status
     * @return array{label:string,type:string}
     */
    private static function statusBadge($status)
    {
        switch ($status) {
            case TransactionStateMachine::PAID:
                return ['label' => __('Paid', 'sscpay-gateway-aggregator'), 'type' => 'paid'];

            case TransactionStateMachine::CANCELLED:
                return ['label' => __('Cancelled', 'sscpay-gateway-aggregator'), 'type' => 'neutral'];

            case TransactionStateMachine::FAILED:
                return ['label' => __('Failed', 'sscpay-gateway-aggregator'), 'type' => 'danger'];

            case TransactionStateMachine::EXPIRED:
                return ['label' => __('Expired', 'sscpay-gateway-aggregator'), 'type' => 'neutral'];

            case TransactionStateMachine::REFUNDED:
                return ['label' => __('Refunded', 'sscpay-gateway-aggregator'), 'type' => 'neutral'];

            case TransactionStateMachine::DISPUTING:
            case TransactionStateMachine::CONFUSED:
                return ['label' => __('Under Review', 'sscpay-gateway-aggregator'), 'type' => 'warning'];

            default:
                return ['label' => __('Awaiting Payment', 'sscpay-gateway-aggregator'), 'type' => 'warning'];
        }
    }

    /**
     * @param string $title
     * @param string $bodyHtml
     * @param int $httpStatus
     * @param bool $wide 发票卡片类页面（结账/结果/取消）用更宽的容器装下整张发票；
     *                   纯文案提示页（无效链接/网关不可用等）保持原有窄宽度。
     */
    private static function renderShell($title, $bodyHtml, $httpStatus = 200, $wide = false)
    {
        status_header($httpStatus);
        nocache_headers();
        // 统一声明origin：结账页跳往三方、加载三方SDK脚本或唤起授权弹层时，
        // 只携带本站域名，不带路径/查询参数。
        header('Referrer-Policy: origin');
        self::sendFrameGuardHeaders();

        $cssUrl = ThemeManager::getActive()->getStylesheetUrl();
        $containerClass = 'pga-payment-container' . ($wide ? ' pga-payment-container--wide' : '');

        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <?php echo self::frameGuardHead(); // phpcs:ignore -- 内部生成的可信HTML ?>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="referrer" content="origin">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html($title); ?></title>
            <?php if ($cssUrl) : ?>
                <link rel="stylesheet" href="<?php echo esc_url($cssUrl); ?>">
            <?php endif; ?>
        </head>
        <body class="pga-payment-page">
            <main class="<?php echo esc_attr($containerClass); ?>">
                <?php
                // 发票卡片类页面自带标题（商户名 + “Invoice”），顶部再放一个<h1>是
                // 重复视觉信息，这里只保留给屏幕阅读器，不在页面上占位置。
                ?>
                <h1 class="<?php echo $wide ? 'screen-reader-text' : ''; ?>"><?php echo esc_html($title); ?></h1>
                <?php echo $bodyHtml; // phpcs:ignore -- 内部拼装的可信HTML，字段级已各自转义 ?>
            </main>
        </body>
        </html>
        <?php
    }
}
