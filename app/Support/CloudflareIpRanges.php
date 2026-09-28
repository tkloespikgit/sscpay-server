<?php

namespace App\Support;

/**
 * Cloudflare 边缘节点的 IP 网段，用作 TrustProxies 的可信代理列表。
 *
 * 收款链接的流量经由 Cloudflare for SaaS 进来，源站看到的 REMOTE_ADDR 是 CF
 * 边缘节点的 IP。不把 CF 列为可信代理的话：
 *   - orders.customer_ip 全部变成 CF 的 IP，风控与对账失去意义；
 *   - CheckoutLinkController 的「同 IP 频率限制」形同虚设（所有客户共用少数 IP）；
 *   - url() 可能退回生成 http:// 地址，导致表单 action 与回跳出错。
 *
 * ⚠️ 这里**只列 Cloudflare 的段，绝不要写成 '*'**。商户 API（/api/order/create）
 * 和后台面板是直连源站的、不走 CF；配成 '*' 等于允许任何人伪造 X-Forwarded-For
 * 冒充任意来源 IP，把下单接口的 IP 风控整个绕过去。只信任 CF 段时，直连源站的
 * 请求因为来源 IP 不在列表里，转发头会被 Symfony 忽略。
 *
 * ⚠️ 为什么是写死的常量而不是读 config()：这份列表要在 bootstrap/app.php 的
 * withMiddleware() 闭包里用，那个闭包执行在配置加载**之前**，调 config() 会直接
 * 抛 "Class config does not exist"。同理也不能用 env()。
 *
 * 列表取自 https://api.cloudflare.com/client/v4/ips（2026-09-24 快照）。
 * CF 偶尔会增删网段，用 `php artisan checkout:check-cloudflare-ips` 定期核对
 * （建议挂进 crontab 每周跑一次），有差异时手工更新下面的数组。
 */
final class CloudflareIpRanges
{
    /** @var list<string> */
    public const IPV4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /** @var list<string> */
    public const IPV6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(self::IPV4, self::IPV6);
    }
}
