<?php

return [

    'tatum' => [
        'checkout_enabled' => env('CRYPTO_CHECKOUT_ENABLED', false),
        'subscriptions_enabled' => env('TATUM_SUBSCRIPTIONS_ENABLED', false),
        'verification_enabled' => env('TATUM_VERIFICATION_ENABLED', false),
        'history_enabled' => env('CRYPTO_HISTORY_ENABLED', false),
        'history_max_head_age_seconds' => env('CRYPTO_HISTORY_MAX_HEAD_AGE_SECONDS'),
        'settlement_enabled' => env('CRYPTO_SETTLEMENT_ENABLED', false),
        'evm_min_confirmations' => [
            'ethereum-mainnet' => env('CRYPTO_ETHEREUM_MIN_CONFIRMATIONS'),
            'ethereum-sepolia' => env('CRYPTO_SEPOLIA_MIN_CONFIRMATIONS'),
        ],
        'utxo_min_confirmations' => [
            'bitcoin-mainnet' => env('CRYPTO_BITCOIN_MIN_CONFIRMATIONS'),
            'bitcoin-testnet' => env('CRYPTO_BITCOIN_TESTNET_MIN_CONFIRMATIONS'),
            'litecoin-mainnet' => env('CRYPTO_LITECOIN_MIN_CONFIRMATIONS'),
            'litecoin-testnet' => env('CRYPTO_LITECOIN_TESTNET_MIN_CONFIRMATIONS'),
        ],
        'tron_min_confirmations' => [
            'tron-mainnet' => env('CRYPTO_TRON_MIN_CONFIRMATIONS'),
            'tron-shasta' => env('CRYPTO_SHASTA_MIN_CONFIRMATIONS'),
        ],
        'genesis_hashes' => [
            'bitcoin-mainnet' => env('CRYPTO_BITCOIN_GENESIS_HASH'),
            'bitcoin-testnet' => env('CRYPTO_BITCOIN_TESTNET_GENESIS_HASH'),
            'litecoin-mainnet' => env('CRYPTO_LITECOIN_GENESIS_HASH'),
            'litecoin-testnet' => env('CRYPTO_LITECOIN_TESTNET_GENESIS_HASH'),
            'tron-mainnet' => env('CRYPTO_TRON_GENESIS_HASH'),
            'tron-shasta' => env('CRYPTO_SHASTA_GENESIS_HASH'),
        ],
        'webhook_base_url' => env('TATUM_WEBHOOK_BASE_URL'),
        'mainnet_hmac_secret' => env('TATUM_MAINNET_HMAC_SECRET'),
        'testnet_hmac_secret' => env('TATUM_TESTNET_HMAC_SECRET'),
        'api_key' => env('TATUM_API_KEY'),
        'testnet_key' => env('TATUM_TESTNET_API_KEY'),
        'timeout' => 10,
        'connect_timeout' => 3,
        'test_tokens' => [
            'ethereum-sepolia' => env('CRYPTO_TEST_ERC20_CONTRACT'),
            'tron-shasta' => env('CRYPTO_TEST_TRC20_CONTRACT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'exchange_rate' => [
        'url' => env('EXCHANGE_RATE_URL'),
        'key' => env('EXCHANGE_RATE_KEY'),
    ],

    'baidu_translate' => [
        'api_key' => env('BAIDU_TRANSLATE_API_KEY'),
        'secret_key' => env('BAIDU_TRANSLATE_SECRET_KEY'),
    ],

    /*
    | Cloudflare for SaaS（自定义主机名）。商户把自己的域名 CNAME 到
    | fallback_origin，平台调 CF API 把该域名注册为自定义主机名，
    | 由 CF 边缘签发并续期证书——源站 Nginx 配置无需为每个商户改动。
    | 详见 doc/checkout-link-cloudflare-saas.md。
    */
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        // 商户需要 CNAME 指向的主机名，如 link.yourpay.com。
        // 这个主机名必须解析到源站，且源站证书要覆盖它。
        'fallback_origin' => env('CLOUDFLARE_FALLBACK_ORIGIN'),
    ],

    /*
    | Cloudflare Turnstile：收款链接落地页及各后台面板登录页的人机验证。
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    /*
    | 物流轨迹跟踪：可选驱动枚举见 system_configs.logistics_tracking.available_drivers；
    | 商户选用驱动 / base_url / API Token / Webhook Secret 见 merchant_logistics_tracking_settings。
    | Webhook：POST /api/webhooks/logistics-tracking/{driver}/{webhookToken}
    */

];
