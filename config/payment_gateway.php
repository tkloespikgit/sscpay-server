<?php

/*
|--------------------------------------------------------------------------
| WordPress 支付网关聚合插件（PGA）客户端配置
|--------------------------------------------------------------------------
|
| 全局默认值，供 PaymentGatewayService 使用。本系统里每个支付方式对接
| 各自的 WordPress 站点，认证凭证（domain_client_id / domain_client_sk，即该站点的
| WooCommerce REST API Consumer Key / Secret）只存在 PaymentMethod 记录里，
| 调用时必须通过 $service->withConnection($baseUrl, $consumerKey, $consumerSecret) 显式传入——
| 不提供全局兜底凭证：不同支付方式对接不同站点，一把全局共享的 key 兜底
| 只会在配置遗漏时悄悄拿错站点的密钥去认证，把问题从"报错拒绝"变成"用错凭证"。
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
];
