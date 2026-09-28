<?php

return [

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

];
