<?php

/**
 * Tatum / 链上收款全局缺省。
 * 渠道可在 payment_methods.config 覆盖 tatum_api_key、tatum_base_url、tatum_webhook_url；
 * 业务编排见 App\Services\Crypto\CryptoPaymentService。
 */
return [
    // 全局 API Key；渠道未配 tatum_api_key 时回退于此（空则跳过订阅/校对）
    'api_key' => env('TATUM_API_KEY', ''),
    'base_url' => rtrim(env('TATUM_BASE_URL', 'https://api.tatum.io'), '/'),
    // 公网 https 回调；空则用 url('/api/webhooks/tatum/address')（本地 .local 会被拒绝订阅）
    'webhook_url' => env('TATUM_WEBHOOK_URL', ''),
    'timeout' => (int) env('TATUM_TIMEOUT', 15),
    // pending 链上单存活分钟数 → orders.crypto_expires_at（默认 8 小时）
    'payment_ttl_minutes' => (int) env('CRYPTO_PAYMENT_TTL_MINUTES', 480),
    // 非稳定币 Tatum 报价 fresh 缓存小时数；失败另用 forever last 缓存降级
    'quote_cache_ttl_hours' => max(1, (int) env('TATUM_QUOTE_CACHE_TTL_HOURS', 24)),
];
