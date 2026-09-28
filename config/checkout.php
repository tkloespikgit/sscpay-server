<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 收款链接媒体文件存储盘
    |--------------------------------------------------------------------------
    | 商户 Logo 与商品图片存放的磁盘。
    |
    | ⚠️ 这个盘必须是**公开可读**的：落地页跑在商户自有域名上、访问者是未登录的
    | 终端客户，用不了争议附件那套 Storage::temporaryUrl() 签名地址（签名地址有
    | 有效期，而收款链接是长期挂在广告位上的）。
    |
    | 生产环境建议配成 oss 并在 OSS 控制台把对应目录设为公共读；本地开发用
    | public 盘即可（记得 php artisan storage:link）。
    */
    'media_disk' => env('CHECKOUT_MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | 媒体文件存放目录
    |--------------------------------------------------------------------------
    */
    'logo_directory' => 'checkout-links/logos',
    'item_image_directory' => 'checkout-links/items',

    /*
    |--------------------------------------------------------------------------
    | 防刷
    |--------------------------------------------------------------------------
    | 落地页没有任何登录态，是整个系统里唯一对公网完全敞开的写入口，
    | 必须多层设防。图形验证码（Turnstile）只挡住脚本，挡不住"人肉刷单"，
    | 所以还要叠加频率限制。
    |
    | ip_max_per_hour        同一 IP 每小时最多提交多少次
    | email_max_per_day      同一邮箱每天最多下多少单
    | link_max_per_day       单条收款链接每天最多产生多少订单（兜底闸门）
    | min_form_seconds       从打开页面到提交的最短耗时，低于此值判定为脚本
    */
    /*
    |--------------------------------------------------------------------------
    | 可信代理
    |--------------------------------------------------------------------------
    | 不在这里配置——Cloudflare 的网段列表定义在 App\Support\CloudflareIpRanges，
    | 由 bootstrap/app.php 的 trustProxies() 直接引用。原因见那个类的注释：
    | withMiddleware() 闭包执行在配置加载之前，在那里调 config() 会直接 fatal。
    */

    'throttle' => [
        'ip_max_per_hour' => env('CHECKOUT_THROTTLE_IP_PER_HOUR', 20),
        'email_max_per_day' => env('CHECKOUT_THROTTLE_EMAIL_PER_DAY', 10),
        'link_max_per_day' => env('CHECKOUT_THROTTLE_LINK_PER_DAY', 500),
        'min_form_seconds' => env('CHECKOUT_MIN_FORM_SECONDS', 3),
    ],

];
