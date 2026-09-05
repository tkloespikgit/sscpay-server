<?php

/*
|--------------------------------------------------------------------------
| WordPress 支付网关聚合插件（PGA）客户端配置
|--------------------------------------------------------------------------
|
| 全局默认值，供 PaymentGatewayService 使用。本系统里每个支付方式对接
| 各自的 WordPress 站点、凭证存在 PaymentMethod 记录里，实际调用时通过
| $service->withConnection($baseUrl, $consumerKey, $consumerSecret) 按记录覆盖，
| 这里的 env 默认值仅作兜底（如站点地址未覆盖时）。
|
| WordPress 侧已支持 WooCommerce 创建的 REST API 认证方式，统一用站点的
| Consumer Key / Secret 做 Basic Auth；原先区分「订单账号」「配置账号」两套
| WordPress 应用密码的方式已弃用。
|
*/

return [
    // 插件 REST 根地址，形如 https://example.com/wp-json/payment-plugin/v1
    'base_url' => env('PGA_BASE_URL', ''),

    // HTTP 超时秒数
    'timeout' => (int) env('PGA_TIMEOUT', 15),

    // 仅连接层失败（超时/连不上）时自动重试的次数与间隔
    'retry_times' => (int) env('PGA_RETRY_TIMES', 2),
    'retry_sleep_ms' => (int) env('PGA_RETRY_SLEEP_MS', 300),

    // 验证插件回调签名（X-PGA-Signature）用的密钥
    'webhook_secret' => env('PGA_WEBHOOK_SECRET', ''),

    // 全局兜底凭证：WooCommerce REST API Consumer Key / Secret
    // （所有接口统一用它做 Basic Auth，不再区分订单账号 / 配置账号）
    'woocommerce' => [
        'username' => env('PGA_WOO_CONSUMER_KEY', ''),
        'password' => env('PGA_WOO_CONSUMER_SECRET', ''),
    ],
];
