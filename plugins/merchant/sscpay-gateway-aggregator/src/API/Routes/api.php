<?php
/**
 * REST路由注册。挂在 rest_api_init 钩子上调用（见主插件文件）。
 *
 * 两组地址：
 * - S系统调用的接口：固定 /wp-json/payment-plugin/v1/
 * - 面向支付通道的端点：本站随机形态 + 上面那个旧形态，两套并存
 *   （原因见 Core/RouteIdentity）。本站实际地址在后台"设置"页可以看到。
 */

namespace PaymentGatewayAggregator\API\Routes;

use PaymentGatewayAggregator\API\Controllers\GatewayConfigController;
use PaymentGatewayAggregator\API\Controllers\HealthController;
use PaymentGatewayAggregator\API\Controllers\OrderLogController;
use PaymentGatewayAggregator\API\Controllers\OrderQueryController;
use PaymentGatewayAggregator\API\Controllers\PayController;
use PaymentGatewayAggregator\API\Controllers\PayPalJsController;
use PaymentGatewayAggregator\API\Controllers\PayPalRestController;
use PaymentGatewayAggregator\API\Controllers\SyncTrackingController;
use PaymentGatewayAggregator\API\Controllers\WebhookController;
use PaymentGatewayAggregator\API\Middleware\AuthMiddleware;
use PaymentGatewayAggregator\Core\RouteIdentity;

if (!defined('ABSPATH')) {
    exit;
}

function pga_register_routes()
{
    // S系统调用的接口保持固定地址不变：它们只在本站和S系统之间往返，
    // 支付通道看不到，没有去指纹的必要；而S系统那边已经配好了这些地址，
    // 改了要两边同时上线。只有下面"面向支付通道"的那几条才走随机命名空间。
    $namespace = RouteIdentity::LEGACY_NAMESPACE;

    // ---- 订单接口权限（应用密码 或 WC API key read/write 均可）----
    register_rest_route($namespace, '/pay', [
        'methods' => 'POST',
        'callback' => [PayController::class, 'handle'],
        'permission_callback' => AuthMiddleware::requireLevel(AuthMiddleware::LEVEL_ORDERS),
    ]);

    register_rest_route($namespace, '/sync-tracking', [
        'methods' => 'POST',
        'callback' => [SyncTrackingController::class, 'handle'],
        'permission_callback' => AuthMiddleware::requireLevel(AuthMiddleware::LEVEL_ORDERS),
    ]);

    register_rest_route($namespace, '/health', [
        'methods' => 'POST',
        'callback' => [HealthController::class, 'handle'],
        'permission_callback' => AuthMiddleware::requireLevel(AuthMiddleware::LEVEL_ORDERS),
    ]);

    register_rest_route($namespace, '/order-logs', [
        'methods' => 'POST',
        'callback' => [OrderLogController::class, 'handle'],
        'permission_callback' => AuthMiddleware::requireLevel(AuthMiddleware::LEVEL_ORDERS),
    ]);

    register_rest_route($namespace, '/order-query', [
        'methods' => 'POST',
        'callback' => [OrderQueryController::class, 'handle'],
        'permission_callback' => AuthMiddleware::requireLevel(AuthMiddleware::LEVEL_ORDERS),
    ]);

    // ---- 配置接口权限（最敏感：需配置capability，或WC API key的读写权限）----
    register_rest_route($namespace, '/gateway-config', [
        'methods' => 'POST',
        'callback' => [GatewayConfigController::class, 'register'],
        'permission_callback' => AuthMiddleware::requireLevel(AuthMiddleware::LEVEL_CONFIG),
    ]);

    // ---- 面向支付通道的公开端点 ----
    //
    // 这几条【同时】注册在两个命名空间下：
    // - 本站随机命名空间（RouteIdentity）：所有新下发给通道的地址都用它，
    //   目的是不让各站点的回跳/通知地址长成逐字符相同的形态，避免通道的风控
    //   顺着共享 URL 特征把用同一插件的站点互相关联（见 Core/RouteIdentity 的说明）；
    // - 旧的 payment-plugin/v1：永久保留。通道那边存着的是下单/配置当时的地址——
    //   在途订单的 return_url、已经登记在 PayPal/Stripe/Airwallex 后台的 webhook
    //   地址都还是旧的，这条路由一撤它们当场全断。
    //
    // 两套路由指向同一批 handler，行为完全一致。
    $channelNamespaces = array_unique([RouteIdentity::namespaceSlug(), $namespace]);

    foreach ($channelNamespaces as $channelNamespace) {
        $isLegacy = ($channelNamespace === $namespace);

        // Webhook：公开，安全性依赖各网关自己的签名验证。
        $notifyPath = $isLegacy ? 'webhook' : RouteIdentity::notifyPath();
        register_rest_route($channelNamespace, '/' . $notifyPath . '/(?P<gateway>[a-z_]+)', [
            'methods' => 'POST',
            'callback' => [WebhookController::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);

        // PayPal 前端/跳转流程的桥接端点：公开，靠 pay_token 防护
        // （见 PayPalRestController::resolveTransaction）。
        $capturePath = $isLegacy ? 'paypal-js/capture' : RouteIdentity::capturePath();
        register_rest_route($channelNamespace, '/' . $capturePath, [
            'methods' => 'POST',
            'callback' => [PayPalJsController::class, 'capture'],
            'permission_callback' => '__return_true',
        ]);

        $returnPath = $isLegacy ? 'paypal-rest/return' : RouteIdentity::returnPath();
        register_rest_route($channelNamespace, '/' . $returnPath, [
            'methods' => 'GET',
            'callback' => [PayPalRestController::class, 'returnHandler'],
            'permission_callback' => '__return_true',
        ]);

        $cancelPath = $isLegacy ? 'paypal-rest/cancel' : RouteIdentity::cancelPath();
        register_rest_route($channelNamespace, '/' . $cancelPath, [
            'methods' => 'GET',
            'callback' => [PayPalRestController::class, 'cancelHandler'],
            'permission_callback' => '__return_true',
        ]);
    }
}
