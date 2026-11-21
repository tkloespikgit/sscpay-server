<?php
/**
 * GET /v1/paypal-rest/return 和 /v1/paypal-rest/cancel —— PayPal
 * 跳转流程的中转端点（见PayPalRestGateway.php的说明）。
 *
 * 浏览器从 PayPal 跳回这里，插件借机触发 capture（return）或把买家送到
 * 取消页（cancel），然后统一跳转到插件自己的页面（见PaymentPage）：
 * - return → 支付结果页 /payment/{order_id}/result（展示终态）；
 * - cancel → 取消页 /payment/{order_id}/cancel（订单仍pending，可重新支付）。
 * 不再由这里直接跳S系统——跳回S系统的动作改为用户在页面上点击
 * "Return to Store"按钮后由return-to-shop端点完成。付款来源被拒绝时回PayPal选择其它来源；
 * 其它capture结果都跳转结果页核实，
 * 避免用户卡在一个没有UI的中间页——真正的支付结果仍以Webhook为准。
 *
 * 最终跳转不用302而是输出一个HTML页：需要在跳转前声明 Referrer-Policy: origin 和反嵌套
 * 响应头，302没地方挂这些。本端点与插件其它页面一样禁止被任何形式嵌套
 * （见 PaymentPage::sendFrameGuardHeaders / frameGuardHead），被装进框架时
 * 什么都不显示、也不跳转。
 */

namespace PaymentGatewayAggregator\API\Controllers;

use PaymentGatewayAggregator\Core\LogManager;
use PaymentGatewayAggregator\Core\Exceptions\PayPalCaptureException;
use PaymentGatewayAggregator\Core\PayPalPaymentRecovery;
use PaymentGatewayAggregator\Core\PaymentSessionReferences;
use PaymentGatewayAggregator\Core\RouteIdentity;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Core\TransactionStateMachine;
use PaymentGatewayAggregator\Frontend\PaymentPage;
use PaymentGatewayAggregator\Gateways\PayPalRestClient;
use PaymentGatewayAggregator\Helpers\TokenGenerator;

if (!defined('ABSPATH')) {
    exit;
}

class PayPalRestController
{
    public static function returnHandler(\WP_REST_Request $request)
    {
        $transaction = self::resolveTransaction($request);
        if (!$transaction) {
            // 诊断用：这条早退路径之前完全没有日志，一旦订单查不到/token校验
            // 失败（比如链接被篡改、订单已过期被清理），买家会被静默扔回首页，
            // 事后在LogViewer里完全查不到任何痕迹。
            // 只记token在不在、长度对不对，不落原文——pay_token是凭证。
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'PayPal return中转端点：订单不存在或token校验失败，买家被跳转到首页', [
                'request_data' => self::describeFailedLookup($request),
            ]);
            self::redirectTo(home_url('/'));
        }

        try {
            $transaction = self::captureApprovedOrder($request, $transaction);
        } catch (\Throwable $e) {
            LogManager::error($transaction['s_order_id'], 'PayPal return处理失败：' . $e->getMessage(), [
                'payment_method' => $transaction['payment_method'],
            ]);
        }

        if (!empty($transaction['_paypal_capture_retry_url'])) {
            self::redirectTo($transaction['_paypal_capture_retry_url']);
        }
        // 未知失败先核实最终收款结果，不能盲目重试capture。
        self::redirectTo(PaymentPage::resultPageUrl($transaction));
    }

    /** Capture is deliberately separate from the final redirect, which exits PHP. */
    private static function captureApprovedOrder(\WP_REST_Request $request, array $transaction)
    {
        return PaymentSessionReferences::withOrderLock($transaction['s_order_id'], function () use ($request, $transaction) {
            // Creation/renewal uses the same order lock. Re-read after locking
            // and capture only the actual Order approved by this browser tab.
            $fresh = self::resolveTransaction($request);
            if (!$fresh || $fresh['s_order_id'] !== $transaction['s_order_id']) {
                return $transaction;
            }
            $approvedOrderId = $request->get_param('token');
            if (!in_array($fresh['payment_method'], ['paypal_rest', 'paypal_js'], true)
                || $fresh['status'] !== TransactionStateMachine::PENDING
                || !empty(TransactionManager::getGatewaySession($fresh)['_creating'])) {
                return $fresh;
            }
            if (!is_string($approvedOrderId) || $approvedOrderId === ''
                || $approvedOrderId !== ($fresh['payment_intent'] ?? null)) {
                LogManager::warning($fresh['s_order_id'], 'PayPal return的Order ID并非当前支付会话，未执行capture', [
                    'payment_method' => $fresh['payment_method'],
                ]);
                return $fresh;
            }
            try {
                $config = TransactionManager::decryptGatewayConfig($fresh);
                PayPalRestClient::captureOrder($config, $approvedOrderId);
            } catch (\Throwable $e) {
                LogManager::error($fresh['s_order_id'], 'PayPal capture调用失败：' . $e->getMessage(), [
                    'payment_method' => $fresh['payment_method'],
                ]);
                if ($e instanceof PayPalCaptureException && $e->isInstrumentDeclined()) {
                    $fresh = PayPalPaymentRecovery::remember($fresh, $e);
                    if ($fresh['status'] === TransactionStateMachine::PENDING
                        && $fresh['payment_intent'] === $approvedOrderId) {
                        $fresh['_paypal_capture_retry_url'] = PayPalPaymentRecovery::redirectUrl($fresh)
                            ?? PaymentPage::cancelPageUrl($fresh, PaymentPage::CANCEL_REASON_CAPTURE_FAILED);
                    }
                }
            }
            return $fresh;
        });
    }

    /**
     * 买家在PayPal授权弹层/跳转页点了"取消"，或capture调用失败后的兜底出口。
     *
     * 【重要】这里故意【不】把交易标记为cancelled，只把买家送到取消页。
     *
     * 原因：买家点"取消"只是放弃了这一次支付，订单本身应该继续可支付——
     * 取消页要给"Retry Payment"按钮把这一单救回来（见
     * PaymentPage::maybeRenderCancelPage）。而状态机里cancelled是终态
     * （TransactionStateMachine：CANCELLED => []，没有任何出边），一旦写了
     * cancelled，买家重新支付成功后webhook的 cancelled -> paid 迁移会被
     * assertTransition直接拒绝，结果是"钱扣了但交易永远停在cancelled"，
     * S系统也永远收不到已支付回调。所以这里保持pending，真正的取消/过期
     * 由webhook事件和TransactionManager::maybeExpire驱动。
     *
     * "买家取消了"这个事实由取消页统一写 user canceled 日志（不在这里写，
     * 否则Stripe/Airwallex直接跳取消页的路径就会漏记）。
     */
    public static function cancelHandler(\WP_REST_Request $request)
    {
        $transaction = self::resolveTransaction($request);
        if (!$transaction) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'PayPal cancel中转端点：订单不存在或token校验失败，买家被跳转到首页', [
                'request_data' => self::describeFailedLookup($request),
            ]);
            self::redirectTo(home_url('/'));
        }

        // reason 只用于区分日志（见PaymentPage::logUserCancelled）：
        // paypal_js的onApprove里capture调用失败也会走到这里，那不是买家主动取消。
        $reason = $request->get_param('reason') === PaymentPage::CANCEL_REASON_CAPTURE_FAILED
            ? PaymentPage::CANCEL_REASON_CAPTURE_FAILED
            : '';

        self::redirectTo(PaymentPage::cancelPageUrl($transaction, $reason));
    }

    /**
     * 查不到交易时记点能排查的线索，但不能把 pga_token 原文落进日志——它是凭证。
     * 区分得出来的几种情况：整个参数没送达（被中间环节吃掉/链接被截断）、
     * 长度不对（被改过/被补了字符）、长度对但查不到（订单已过期清理或链接是伪造的）。
     *
     * @return array
     */
    private static function describeFailedLookup(\WP_REST_Request $request)
    {
        $token = RouteIdentity::readToken($request);

        return [
            'token_present' => $token !== '',
            'token_length'  => strlen($token),
            // 走的是本站形态还是旧形态：能区分"老订单跳回来"和"新订单出问题"。
            'token_param'   => $request->get_param(RouteIdentity::tokenParam()) !== null
                ? RouteIdentity::tokenParam()
                : RouteIdentity::LEGACY_TOKEN_PARAM,
        ];
    }

    /**
     * 只认 token：pay_token 有 UNIQUE 索引，单独就能定位交易，所以给PayPal的
     * 回跳地址里不再带内部订单号 s_order_id（它可枚举，又提供不了任何鉴权价值）。
     *
     * 仍然在SQL之外用 TokenGenerator::isEqual() 再比一次，不是多余：
     * MySQL 的 `WHERE pay_token = %s` 走列的排序规则，utf8mb4_*_ci 下大小写不敏感、
     * 尾部空格也会被忽略，光靠SQL等于放宽了token的匹配口径；这里做一次
     * 大小写敏感的常数时间比较把口径收回来。
     */
    private static function resolveTransaction(\WP_REST_Request $request)
    {
        // 参数名取自本站随机形态，并永久兼容旧的 pga_token——在途订单的
        // return_url 是下单当时生成的，可能还是旧形态（见Core/RouteIdentity）。
        // 两套名字都刻意避开'token'：PayPal跳回这两个端点时会自己追加同名的
        // token（PayPal Order ID）参数，见PayPalRestGateway.php里的详细说明。
        $token = RouteIdentity::readToken($request);
        if ($token === '') {
            return null;
        }

        $transaction = TransactionManager::findByPayToken($token);
        if (!$transaction || !TokenGenerator::isEqual($transaction['pay_token'], $token)) {
            return null;
        }

        return $transaction;
    }

    /**
     * 输出一个只负责跳转的 HTML 页并终止请求。
     *
     * 不用 WP_REST_Response：WP 会把响应体统一 JSON 编码，无法原样返回
     * HTML；这里直接输出并 exit（前端页面也是这个模式）。
     * 页面同样声明 Referrer-Policy: origin（最终跳回 S 系统时只带本站域名，
     * 不带路径/参数）和反嵌套响应头。
     */
    private static function redirectTo($url)
    {
        nocache_headers();
        header('Referrer-Policy: origin');
        PaymentPage::sendFrameGuardHeaders();
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <?php echo PaymentPage::frameGuardHead(); // phpcs:ignore -- 内部生成的可信HTML ?>
            <meta name="referrer" content="origin">
            <title>Redirecting…</title>
            <noscript><meta http-equiv="refresh" content="0; url=<?php echo esc_url($url); ?>"></noscript>
        </head>
        <body>
        <script>
        (function () {
            // 不做破框：本端点禁止被嵌套，被装进框架时遮罩脚本已经拦下，
            // 根本走不到这里。
            window.location.replace(<?php echo wp_json_encode($url); ?>);
        })();
        </script>
        </body>
        </html>
        <?php
        exit;
    }
}
