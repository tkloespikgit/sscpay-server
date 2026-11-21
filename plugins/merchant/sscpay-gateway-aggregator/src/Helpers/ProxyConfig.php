<?php

namespace PaymentGatewayAggregator\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/** 普通网关与 Stripe SDK 共用的出站代理配置。 */
class ProxyConfig
{
    /**
     * @param ?string $proxy http/socks5/socks5h://user:pass@host:port
     * @return array<int,mixed> 可直接传给 curl_setopt_array 或 Stripe CurlClient
     * @throws \InvalidArgumentException 不支持的协议或非法地址；不在错误中暴露凭据
     */
    public static function curlOptions($proxy)
    {
        if ($proxy === null || $proxy === '') {
            return [];
        }
        if (!is_string($proxy)) {
            throw new \InvalidArgumentException('代理地址必须为字符串');
        }

        $parts = wp_parse_url(trim($proxy));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])
            || $parts['port'] < 1 || $parts['port'] > 65535
            || preg_match('/[\s\x00-\x1f\x7f]/', $parts['host'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('代理地址格式不合法（期望 http/socks5/socks5h://user:pass@host:port）');
        }

        // 兼容原先可解析的无协议地址（例如 //host:port），默认使用 HTTP。
        $scheme = strtolower($parts['scheme'] ?? 'http');
        switch ($scheme) {
            case 'http':
                $type = CURLPROXY_HTTP;
                break;
            case 'socks5':
                $type = CURLPROXY_SOCKS5;
                break;
            case 'socks5h':
                $type = CURLPROXY_SOCKS5_HOSTNAME;
                break;
            default:
                throw new \InvalidArgumentException('不支持的代理协议（仅支持 http、socks5、socks5h）');
        }

        $options = [
            CURLOPT_PROXY => $parts['host'],
            CURLOPT_PROXYPORT => (int) $parts['port'],
            CURLOPT_PROXYTYPE => $type,
            // HTTP 代理使用 CONNECT；SOCKS5 使用自己的握手协议。
            CURLOPT_HTTPPROXYTUNNEL => $scheme === 'http',
            // 显式配置代理时，不允许服务器的 NO_PROXY 环境变量绕过它。
            CURLOPT_NOPROXY => '',
        ];
        if (isset($parts['user']) && $parts['user'] !== '') {
            $options[CURLOPT_PROXYUSERPWD] = rawurldecode($parts['user']) . ':'
                . (isset($parts['pass']) ? rawurldecode($parts['pass']) : '');
        }

        return $options;
    }
}
