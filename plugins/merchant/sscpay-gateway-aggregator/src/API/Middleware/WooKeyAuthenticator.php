<?php
/**
 * WooCommerce REST API 密钥（consumer_key / consumer_secret）认证。
 *
 * 背景：S系统那边已经保存了站点的 WooCommerce REST API 密钥，不想为了这个插件
 * 再单独建两个WP账号+应用密码。所以插件自己的REST路由也接受 WC API key 认证。
 *
 * 注意：我们的路由挂在 WP REST API（/wp-json/payment-plugin/v1/...），不走
 * WooCommerce 自己的 /wc-api/ 分发，WC不会替我们做密钥认证，这里需要按WC
 * 同样的规则自行验证一遍：
 *   1. consumer_key 在库里以 wc_api_hash()（hash_hmac('sha256', $data, 'wc-api')，
 *      不是普通sha256）形式存储，必须用 wc_api_hash(传入的key) 去查 woocommerce_api_keys 表；
 *   2. consumer_secret 在库里是明文，用 hash_equals 常量时间比较；
 *   3. 只认 HTTPS 请求（与WC一致；本地调试可设 WC_API_DEV_MODE）。
 *
 * 权限映射（对齐原设计文档7.9的"下单/配置"两级分离）：
 *   - /pay、/sync-tracking、/health、/order-logs → 需要 write 或 read_write 的key；
 *   - /gateway-config（网关凭证注册/轮换，最敏感）→ 只接受 read_write 的key。
 * 如果只有一把key，建议权限选"读写"；想保持最小权限就建两把：
 * 一把"读写"给订单接口，一把"读写"单独给配置接口（描述里区分开）。
 */

namespace PaymentGatewayAggregator\API\Middleware;

use PaymentGatewayAggregator\Core\LogManager;

if (!defined('ABSPATH')) {
    exit;
}

class WooKeyAuthenticator
{
    /** 权限分级要求 */
    const LEVEL_ORDERS = 'write';        // 订单类接口：write 或 read_write
    const LEVEL_CONFIG = 'read_write';   // 网关配置接口：必须 read_write

    /**
     * 尝试用 WC API key 认证当前请求。
     *
     * @param \WP_REST_Request $request
     * @param string $level self::LEVEL_ORDERS 或 self::LEVEL_CONFIG
     * @return bool 认证+权限都通过时返回 true（同时会把当前用户设为该key归属的WP用户）
     */
    public static function authenticate($request, $level)
    {
        if (!class_exists('WooCommerce')) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'API认证失败：WooCommerce插件未启用，无法校验WC API Key', [
                'request_data' => self::debugContext($request),
            ]);
            return false;
        }

        // 与WC一致：明文密钥不允许走HTTP。本地调试可以定义 WC_API_DEV_MODE。
        if (!is_ssl() && !defined('WC_API_DEV_MODE')) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'API认证失败：WC API key只允许通过HTTPS使用', [
                'request_data' => self::debugContext($request),
            ]);
            return false;
        }

        list($consumerKey, $consumerSecret) = self::extractCredentials($request);
        if (!$consumerKey || !$consumerSecret) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'API认证失败：未能从请求中提取到consumer_key/consumer_secret（检查传参方式：query参数或"key:secret"明文Authorization头，不支持标准Basic Auth）', [
                'request_data' => self::debugContext($request, [
                    'extracted_consumer_key_present' => $consumerKey !== '',
                    'extracted_consumer_secret_present' => $consumerSecret !== '',
                ]),
            ]);
            return false;
        }

        global $wpdb;

        // consumer_key 在库里存的是 wc_api_hash() 的结果（hash_hmac('sha256', $data, 'wc-api')，
        // 不是普通 hash('sha256', ...)），必须用WC自己的函数算，否则永远查不到。
        $keyId = $wpdb->get_var($wpdb->prepare(
            "SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
            wc_api_hash($consumerKey)
        ));
        if (!$keyId) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'API认证失败：consumer_key不存在或已撤销', [
                'request_data' => self::debugContext($request, ['consumer_key_tail' => substr($consumerKey, -7)]),
            ]);
            return false;
        }

        $keyData = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, permissions, truncated_key FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
            $keyId
        ), ARRAY_A);
        if (!$keyData) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'API认证失败：key_id存在但查不到对应记录（数据不一致）', [
                'request_data' => self::debugContext($request, ['key_id' => $keyId]),
            ]);
            return false;
        }

        // consumer_secret 库里是明文，常量时间比较，防时序侧信道。
        $storedSecret = $wpdb->get_var($wpdb->prepare(
            "SELECT consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
            $keyId
        ));
        if (!hash_equals((string) $storedSecret, $consumerSecret)) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, 'API认证失败：consumer_secret不匹配', [
                'request_data' => self::debugContext($request, ['consumer_key_tail' => $keyData['truncated_key']]),
            ]);
            return false;
        }

        if (!self::permissionSatisfies((string) $keyData['permissions'], $level)) {
            LogManager::warning(LogManager::SYSTEM_ORDER_ID, sprintf(
                'API权限不足：WC API key权限为 %s，当前接口要求 %s',
                $keyData['permissions'],
                $level === self::LEVEL_CONFIG ? 'read_write' : 'write或read_write'
            ), [
                'request_data' => self::debugContext($request, [
                    'consumer_key_tail' => $keyData['truncated_key'],
                    'key_permissions' => $keyData['permissions'],
                    'required_level' => $level,
                ]),
            ]);
            return false;
        }

        // 把当前用户设为key归属的WP用户，后续控制器里如果用到
        // get_current_user_id()/current_user_can()之类的逻辑才有一致的上下文。
        wp_set_current_user((int) $keyData['user_id']);

        // 顺带更新last_access，方便在WC后台看到这把key有没有在用。
        $wpdb->update(
            $wpdb->prefix . 'woocommerce_api_keys',
            ['last_access' => current_time('mysql', true)],
            ['key_id' => $keyId]
        );

        return true;
    }

    /**
     * 从query参数或Authorization头提取 consumer_key / consumer_secret。
     * 两种写法都支持（S系统用哪种都行）：
     *   ?consumer_key=ck_xxx&consumer_secret=cs_xxx
     *   Authorization: WC-TR1 ... （不做，只支持WC-STD明文对，要求HTTPS）
     *
     * @param \WP_REST_Request $request
     * @return array [consumer_key, consumer_secret]
     */
    private static function extractCredentials($request)
    {
        $consumerKey = $request->get_param('consumer_key');
        $consumerSecret = $request->get_param('consumer_secret');

        if ($consumerKey && $consumerSecret) {
            return [sanitize_text_field($consumerKey), sanitize_text_field($consumerSecret)];
        }

        // 兼容 Authorization 头明文方式：Authorization: <consumer_key>:<consumer_secret>
        // （这是WC文档里HTTPS下允许的写法之一）。
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION'])) : '';
        if ($authHeader && strpos($authHeader, ':') !== false && stripos($authHeader, 'Basic') === false) {
            list($key, $secret) = explode(':', $authHeader, 2);
            return [trim($key), trim($secret)];
        }

        return ['', ''];
    }

    /**
     * 认证失败时用的诊断上下文：完整请求头/body/query参数，供排查用。
     * 敏感字段（consumer_secret、Authorization等）会被 LogManager 落库前统一经
     * SensitiveDataFilter 脱敏，这里额外补一份不含明文的 Authorization 头"形状"
     * 元数据（是否存在/长度/是否Basic前缀/是否含冒号），方便判断是不是传参格式问题
     * （比如常见的：客户端用了标准 Basic Auth，而本插件只认明文 "key:secret" 头）。
     *
     * @param \WP_REST_Request $request
     * @param array $extra 额外要合并进去的诊断字段
     * @return array
     */
    public static function debugContext($request, array $extra = [])
    {
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? wp_unslash($_SERVER['HTTP_AUTHORIZATION']) : '';

        $context = [
            'route' => $request->get_route(),
            'method' => $request->get_method(),
            'headers' => $request->get_headers(),
            'query_params' => $request->get_query_params(),
            'body_params' => $request->get_body_params(),
            'json_params' => $request->get_json_params(),
            'auth_header_present' => $authHeader !== '',
            'auth_header_length' => strlen($authHeader),
            'auth_header_starts_with_basic' => stripos($authHeader, 'Basic') === 0,
            'auth_header_has_colon' => strpos($authHeader, ':') !== false,
        ];

        return array_merge($context, $extra);
    }

    /**
     * @param string $keyPermissions 库里存的 read / write / read_write
     * @param string $level 接口要求
     * @return bool
     */
    private static function permissionSatisfies($keyPermissions, $level)
    {
        if ($level === self::LEVEL_CONFIG) {
            return $keyPermissions === 'read_write';
        }
        // LEVEL_ORDERS：写类操作，write 和 read_write 都可以。
        return $keyPermissions === 'write' || $keyPermissions === 'read_write';
    }
}
