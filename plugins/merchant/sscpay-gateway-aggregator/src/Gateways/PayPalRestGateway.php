<?php
/**
 * PayPal REST API：服务端创建订单，用户跳转到PayPal完成授权，
 * 再跳转回插件自己的一个"中转"端点触发capture，最后统一落地到插件的
 * 支付结果页（跳回S系统由用户在结果页点按钮触发）。
 *
 * 之所以不直接把PayPal的return_url设成S系统的return_url：capture这个动作
 * 必须由服务端发起，如果PayPal直接跳回S系统，插件就没有机会调用capture了。
 * 中转端点见 API/Controllers/PayPalRestController.php。
 *
 * webhook验签/解析/健康检测与PayPalJsGateway共享，见 AbstractPayPalGateway。
 */

namespace PaymentGatewayAggregator\Gateways;

use PaymentGatewayAggregator\Core\RouteIdentity;

if (!defined('ABSPATH')) {
    exit;
}

class PayPalRestGateway extends AbstractPayPalGateway
{
    public function code()
    {
        return 'paypal_rest';
    }

    public function preparePayment(array $transaction)
    {
        // 校验参数不能叫token——PayPal买家授权完成后跳回return_url时会自己在
        // URL后面追加它自己的token（PayPal Order ID）和PayerID，如果我们的
        // return_url里已经带了同名的token参数，会产生两个token，PHP解析重复
        // query key只保留最后一个，最终拿到的是PayPal的token而不是我们的
        // pay_token，导致下面PayPalRestController::resolveTransaction()校验
        // 永远失败、买家被静默跳回首页、captureOrder()根本不会被调用。
        // 只带token：pay_token有UNIQUE索引、单独就能定位交易（见
        // PayPalRestController::resolveTransaction），内部订单号s_order_id
        // 放进来既没有鉴权价值、又把一个可枚举的业务单号暴露给了PayPal。
        //
        // 路径和参数名都取自本站随机生成的形态（见Core/RouteIdentity），
        // 不同站点的回跳地址不会长成一模一样、可被通道拿去做跨站关联的样子。
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
            'redirect_url' => $order['approve_url'],
            'external_ref' => $order['id'],
        ];
    }

    public function renderPaymentPage(array $transaction, array $sessionData)
    {
        $url = esc_url($sessionData['redirect_url']);
        ob_start();
        ?>
        <div class="pga-gateway pga-gateway-paypal-rest">
            <a class="pga-pay-button" href="<?php echo $url; ?>" rel="noopener">
                <?php esc_html_e('前往支付 (PayPal)', 'sscpay-gateway-aggregator'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}
