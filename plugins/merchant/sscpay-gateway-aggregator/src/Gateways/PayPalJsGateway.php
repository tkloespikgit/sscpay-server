<?php
/**
 * PayPal（paypal_js）：服务端创建订单，然后在本插件独立的结账路由上加载
 * PayPal JS SDK 渲染 Smart Buttons 完成结账（按钮/授权弹层本身是PayPal
 * 官方维护的iframe，属于官方标准嵌入方式）。
 *
 * 路由结构（见 Frontend/PaymentPage.php）：
 *   /payment/{order_id}?token=xxx           loading页（2秒倒计时）
 *   /payment/{order_id}/checkout?token=xxx  Smart Buttons结账页（本路由）
 * 两条路由必须是不同地址：loading页倒计时结束后真实导航到结账路由，
 * 禁止用同一页面关闭遮罩层的方式模拟跳转。
 *
 * 支付流程：
 *   1. createOrder回调直接复用服务端已创建的订单（不让前端再发起创建，
 *      减少可被篡改金额的暴露面）；
 *   2. onApprove后前端调用插件的公开capture端点（/v1/paypal-js/capture，
 *      安全性依赖s_order_id+pay_token；这是本站页面发往本站端点的请求，
 *      不经过PayPal），由服务端完成捕获，然后统一跳转到
 *      插件的支付结果页（/payment/{order_id}/result）；
 *   3. 买家在授权弹层取消时（onCancel）跳转中转cancel端点，由它把买家送到
 *      插件的取消页（/payment/{order_id}/cancel）——订单保持pending，
 *      取消页会展示高亮的"Retry Payment"按钮引导买家重新支付。
 *      网络或非业务成功响应走同一个中转端点，带上reason=capture_failed，
 *      让日志能区分"买家主动取消"和"服务端异常"（见PaymentPage::logUserCancelled）。
 *
 * 页面级Referrer-Policy: origin同样作用于SDK脚本与授权弹层的加载，
 * PayPal只能看到本站域名。
 *
 * 重要安全规则：订单状态变更只由WebhookRouter处理真正的PayPal Webhook
 * 事件（PAYMENT.CAPTURE.COMPLETED）来驱动，避免"前端说成功就写库"这种
 * 可以被绕过的信任链——capture端点的返回只用于前端UX。
 *
 * webhook验签/解析/健康检测等和PayPalRestGateway完全一样，
 * 共享逻辑见 AbstractPayPalGateway。
 */

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\RouteIdentity;
use PaymentGatewayAggregator\Core\TransactionManager;
use PaymentGatewayAggregator\Frontend\PaymentPage;

if (!defined('ABSPATH')) {
    exit;
}

class PayPalJsGateway extends AbstractPayPalGateway
{
    public function code()
    {
        return 'paypal_js';
    }

    public function preparePayment(array $transaction)
    {
        // return_url/cancel_url指向插件的中转端点：Smart Buttons流程里
        // 这两个字段实际不会被用到（授权走弹层回调），保留是为了与
        // paypal_rest的订单结构一致，也作为兜底的跳转出口。
        // 只带token：pay_token有UNIQUE索引、单独就能定位交易（见
        // PayPalRestController::resolveTransaction），内部订单号s_order_id
        // 放进来既没有鉴权价值、又把一个可枚举的业务单号暴露给了PayPal。
        //
        // 路径和参数名取自本站随机形态（见Core/RouteIdentity）。那份词表里
        // 刻意不含'token'：PayPal跳回return_url/cancel_url时会自己追加同名的
        // token（PayPal Order ID）参数，见PayPalRestGateway.php里的详细说明。
        $bridgeReturnUrl = add_query_arg(
            RouteIdentity::tokenParam(),
            rawurlencode($transaction['pay_token']),
            RouteIdentity::restUrl(RouteIdentity::returnPath())
        );

        $bridgeCancelUrl = add_query_arg(
            RouteIdentity::tokenParam(),
            rawurlencode($transaction['pay_token']),
            RouteIdentity::restUrl(RouteIdentity::cancelPath())
        );

        $order = PayPalRestClient::createOrder(
            $this->config,
            $transaction['s_order_id'],
            $transaction['amount'],
            $transaction['currency'],
            ['return_url' => $bridgeReturnUrl, 'cancel_url' => $bridgeCancelUrl],
            $this->buildOrderDetails($transaction),
            $transaction['payment_attempt_key'] ?? null
        );

        return [
            'order_id' => $order['id'],
            // 渲染SDK脚本需要：见renderPaymentPage()。
            'client_id' => $this->config['client_id'],
            'redirect_url' => $order['approve_url'],
            'external_ref' => $order['id'],
        ];
    }

    /**
     * 在独立结账路由上渲染PayPal Smart Buttons（见PaymentPage）。
     *
     * 旧版本会话里没有存client_id，兜底从交易的网关配置里解密取一次，
     * 保证插件升级瞬间存量待支付订单仍可完成支付。
     */
    public function renderPaymentPage(array $transaction, array $sessionData)
    {
        if (empty($sessionData['order_id'])) {
            return '<p>' . esc_html__('The payment channel is unavailable. Please contact the merchant.', 'sscpay-gateway-aggregator') . '</p>';
        }

        $clientId = $sessionData['client_id'] ?? '';
        if (!$clientId) {
            try {
                $config = TransactionManager::decryptGatewayConfig($transaction);
                $clientId = $config['client_id'] ?? '';
            } catch (\Throwable $e) {
                // 解密失败时保持为空，下面统一按渠道不可用处理。
            }
        }

        if (!$clientId) {
            return '<p>' . esc_html__('The payment channel is unavailable. Please contact the merchant.', 'sscpay-gateway-aggregator') . '</p>';
        }

        $captureUrl = RouteIdentity::restUrl(RouteIdentity::capturePath());
        // capture成功后统一落地到支付结果页；买家取消时走中转cancel端点，
        // 由它送到取消页（订单保持pending，可重新支付）。
        $resultPageUrl = PaymentPage::resultPageUrl($transaction);
        $bridgeCancelUrl = add_query_arg(
            RouteIdentity::tokenParam(),
            rawurlencode($transaction['pay_token']),
            RouteIdentity::restUrl(RouteIdentity::cancelPath())
        );
        // 网络或端点请求失败落到取消页；明确的付款来源拒绝由actions.restart恢复。
        // 但多带一个reason：那不是买家的行为，日志里不能算进取消率。
        $bridgeFailureUrl = add_query_arg('reason', PaymentPage::CANCEL_REASON_CAPTURE_FAILED, $bridgeCancelUrl);

        $sdkUrl = add_query_arg([
            'client-id' => rawurlencode($clientId),
            'currency' => rawurlencode(strtoupper($transaction['currency'])),
            'intent' => 'capture',
        ], 'https://www.paypal.com/sdk/js');

        ob_start();
        ?>
        <div class="pga-gateway pga-gateway-paypal-js">
            <div id="pga-paypal-button-container"></div>
            <p class="pga-paypal-status" id="pga-paypal-status" style="display:none;">
                <?php esc_html_e('Processing your payment, please wait…', 'sscpay-gateway-aggregator'); ?>
            </p>
            <p class="pga-paypal-error" id="pga-paypal-error" style="display:none;"></p>
        </div>
        <script src="<?php echo esc_url($sdkUrl); ?>"></script>
        <script>
        (function () {
            var statusEl = document.getElementById('pga-paypal-status');
            var errorEl = document.getElementById('pga-paypal-error');
            var resultUrl = <?php echo wp_json_encode($resultPageUrl); ?>;
            var cancelUrl = <?php echo wp_json_encode($bridgeCancelUrl); ?>;
            var failureUrl = <?php echo wp_json_encode($bridgeFailureUrl); ?>;

            // 结账页禁止被任何形式嵌套（见 PaymentPage::sendFrameGuardHeaders /
            // frameGuardHead），被装进框架时页面根本不会渲染，所以这里不再做
            // "把跳转提升到顶层窗口"的破框处理——那等于替别人的页面做导航。
            function gotoUrl(url) {
                window.location.href = url;
            }

            function showError(message) {
                if (errorEl) {
                    errorEl.textContent = message;
                    errorEl.style.display = 'block';
                }
            }

            // SDK加载失败（比如网络被墙/被拦截）时给出提示，不静默白屏。
            if (typeof paypal === 'undefined' || !paypal.Buttons) {
                showError(<?php echo wp_json_encode(__('PayPal failed to load. Please refresh the page or try again later.', 'sscpay-gateway-aggregator')); ?>);
                return;
            }

            paypal.Buttons({
                createOrder: function () {
                    // 订单已在preparePayment()阶段由服务端创建好，这里直接复用，
                    // 不再让前端发起create——减少可被篡改金额的暴露面。
                    return Promise.resolve(<?php echo wp_json_encode($sessionData['order_id']); ?>);
                },
                onApprove: function (data, actions) {
                    if (statusEl) { statusEl.style.display = 'block'; }
                    if (errorEl) { errorEl.style.display = 'none'; }
                    return fetch(<?php echo wp_json_encode($captureUrl); ?>, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            s_order_id: <?php echo wp_json_encode($transaction['s_order_id']); ?>,
                            token: <?php echo wp_json_encode($transaction['pay_token']); ?>,
                            order_id: data.orderID
                        })
                    }).then(function (response) {
                        return response.json();
                    }).then(function (response) {
                        if (response.code !== 0 || !response.data) {
                            throw new Error('Capture request could not be completed.');
                        }
                        if (response.data.issue === 'INSTRUMENT_DECLINED' && response.data.restart === true) {
                            if (statusEl) { statusEl.style.display = 'none'; }
                            showError(<?php echo wp_json_encode(__('Your payment method was declined. Please choose another payment method in PayPal.', 'sscpay-gateway-aggregator')); ?>);
                            if (actions && typeof actions.restart === 'function') {
                                return actions.restart();
                            }
                            if (response.data.redirect_url) {
                                gotoUrl(response.data.redirect_url);
                                return;
                            }
                            gotoUrl(failureUrl);
                            return;
                        }
                        // 这里只是前端提示，订单真正标记为已支付以服务端Webhook为准；
                        // 统一跳转到支付结果页，跳回S系统由用户在结果页点按钮触发。
                        gotoUrl(resultUrl);
                    }).catch(function () {
                        // capture失败：订单仍是pending，去取消页重试（带reason区分日志）。
                        gotoUrl(failureUrl);
                    });
                },
                onCancel: function () {
                    // 买家在PayPal授权弹层里点了取消：去取消页，那里会写
                    // user canceled 日志并展示重新支付按钮。
                    gotoUrl(cancelUrl);
                }
            }).render('#pga-paypal-button-container').catch(function () {
                showError(<?php echo wp_json_encode(__('PayPal could not be displayed here. Please refresh the page or try again later.', 'sscpay-gateway-aggregator')); ?>);
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
