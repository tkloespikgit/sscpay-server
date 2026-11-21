<?php
/**
 * REST路由的权限校验。支持两种认证方式（任一通过即可）：
 * 1. WordPress应用密码的Basic Auth（WP 5.6+原生，请求带有效应用密码时，
 *    WP会在permission_callback执行前自动把当前用户设置好），检查插件自定义capability；
 * 2. WooCommerce REST API密钥（consumer_key/consumer_secret，见WooKeyAuthenticator）——
 *    S系统已保存过WC密钥的话，不需要再为插件单独建账号/应用密码。
 */

namespace PaymentGatewayAggregator\API\Middleware;

use PaymentGatewayAggregator\Core\LogManager;

if (!defined('ABSPATH')) {
    exit;
}

class AuthMiddleware
{
    /** 认证分级，与 WooKeyAuthenticator::LEVEL_* 对应 */
    const LEVEL_ORDERS = 'orders';
    const LEVEL_CONFIG = 'config';

    /**
     * @param string $level self::LEVEL_ORDERS 或 self::LEVEL_CONFIG
     * @return callable 供 register_rest_route 的 permission_callback 使用
     */
    public static function requireLevel($level)
    {
        return function ($request) use ($level) {
            // 方式1：WP应用密码/其它登录态 + 插件自定义capability。
            if (is_user_logged_in()) {
                $capability = $level === self::LEVEL_CONFIG
                    ? \PaymentGatewayAggregator\Core\Roles::CAP_MANAGE_GATEWAY_CONFIG
                    : \PaymentGatewayAggregator\Core\Roles::CAP_MANAGE_ORDERS;

                if (current_user_can($capability)) {
                    return true;
                }
            }

            // 方式2：WooCommerce REST API密钥。
            $wooLevel = $level === self::LEVEL_CONFIG
                ? WooKeyAuthenticator::LEVEL_CONFIG
                : WooKeyAuthenticator::LEVEL_ORDERS;
            if (WooKeyAuthenticator::authenticate($request, $wooLevel)) {
                return true;
            }

            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'API认证失败：未提供有效的应用密码或WC API密钥', [
                'request_data' => WooKeyAuthenticator::debugContext($request, [
                    'is_user_logged_in' => is_user_logged_in(),
                ]),
            ]);

            // 已登录但权限不足 → 403；完全没认证 → 401。
            if (is_user_logged_in()) {
                return new \WP_Error('pga_auth_failed', 'API认证失败：权限不足', ['status' => 403, 'code' => 10004]);
            }
            return new \WP_Error('pga_auth_failed', 'API认证失败', ['status' => 401, 'code' => 10004]);
        };
    }

    /**
     * Webhook端点专用：不做应用密码认证（三方支付平台不会带WP应用密码），
     * 安全性完全依赖各Gateway自己的签名验证（在WebhookRouter里做）。
     */
    public static function publicWebhook()
    {
        return '__return_true';
    }
}
