<?php
/**
 * 出站HTTP请求的统一封装，基于WordPress的wp_remote_*系列函数
 * （而不是直接用curl，这样能自动兼容WP的代理设置等环境差异）。
 *
 * 核心目的：显式设置超时（评审里指出的问题——不设超时的话，三方接口
 * 响应慢会一直占着PHP-FPM worker），并统一返回结构，调用方不用每次
 * 都去判断 is_wp_error()。
 *
 * 代理支持：每个网关配置（wp_payment_gateway_configs.config）可以带一个
 * `proxy` 字段（支持 http/socks5/socks5h://user:pass@host:port），要求"这一路对渠道方的
 * 请求走这个代理，没配置就直连"。wp_remote_系列函数背后的 WP_Http 的代理只认
 * wp-config.php里的WP_PROXY_HOST等常量，是站点级、全局唯一的，没有
 * "这次请求单独指定一个代理"的口子（见WP_HTTP_Proxy），所以传了$proxy时
 * 这里绕开wp_remote_系列函数改走原生curl，未传时行为和以前完全一样。
 */

namespace PaymentGatewayAggregator\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class HttpClient
{
    /** 默认超时秒数，三方支付接口一般不需要更长 */
    const DEFAULT_TIMEOUT = 15;

    /**
     * @param string $url
     * @param array $headers
     * @param int $timeout
     * @param ?string $proxy http/socks5/socks5h://user:pass@host:port，为空/null表示不走代理
     * @return array{ok:bool,status:int,body:mixed,raw:string,error:?string}
     */
    public static function getJson($url, array $headers = [], $timeout = self::DEFAULT_TIMEOUT, $proxy = null)
    {
        $headers = array_merge(['Accept' => 'application/json'], $headers);

        if ($proxy !== null && $proxy !== '') {
            return self::requestViaProxy('GET', $url, $headers, null, $timeout, $proxy);
        }

        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'headers' => $headers,
        ]);

        return self::normalize($response);
    }

    /**
     * 发送 JSON body 的 POST 请求（Stripe之外，PayPal/Airwallex/Antom都是JSON API）。
     *
     * @param string $url
     * @param array $payload
     * @param array $headers
     * @param int $timeout
     * @param ?string $proxy
     * @return array{ok:bool,status:int,body:mixed,raw:string,error:?string}
     */
    public static function postJson($url, array $payload, array $headers = [], $timeout = self::DEFAULT_TIMEOUT, $proxy = null)
    {
        // PHP的json_encode(空数组)编出来是"[]"（JSON数组），但JSON
        // API的请求体语义上永远是对象——PayPal的Capture等"允许空body"的
        // 端点会严格校验schema，收到"[]"会直接判MALFORMED_REQUEST_JSON。
        $body = $payload === [] ? '{}' : wp_json_encode($payload);
        $headers = array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers);

        if ($proxy !== null && $proxy !== '') {
            return self::requestViaProxy('POST', $url, $headers, $body, $timeout, $proxy);
        }

        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => $headers,
            'body' => $body,
        ]);

        return self::normalize($response);
    }

    /**
     * 发送 form-urlencoded 的 POST 请求（Stripe REST API用这个格式）。
     *
     * @param string $url
     * @param array $payload 支持嵌套数组，会被展开成 line_items[0][price] 这种形式
     * @param array $headers
     * @param int $timeout
     * @param ?string $proxy
     * @return array{ok:bool,status:int,body:mixed,raw:string,error:?string}
     */
    public static function postForm($url, array $payload, array $headers = [], $timeout = self::DEFAULT_TIMEOUT, $proxy = null)
    {
        $headers = array_merge(['Accept' => 'application/json'], $headers);

        if ($proxy !== null && $proxy !== '') {
            // 走curl时没有wp_remote_post那层自动http_build_query，这里手动做同样的事，
            // 保证嵌套数组的展开格式（line_items[0][price]）和不走代理时一致。
            return self::requestViaProxy('POST', $url, $headers, http_build_query($payload, '', '&'), $timeout, $proxy);
        }

        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => $headers,
            'body' => $payload, // wp_remote_post会自动做http_build_query，支持嵌套数组
        ]);

        return self::normalize($response);
    }

    /**
     * 发送一段【已经序列化好的】JSON字符串作为body，原样发送不再重新编码。
     * 出站签名（如转发给S系统的HMAC签名）是对某个具体JSON字符串计算的，
     * 如果发送时重新json_encode一遍数组，字段顺序/转义方式可能变化，
     * 导致接收方按同样字符串重新计算出的签名对不上——所以这里必须发原始字符串。
     *
     * @param string $url
     * @param string $rawJsonBody
     * @param array $headers
     * @param int $timeout
     * @param ?string $proxy
     * @return array{ok:bool,status:int,body:mixed,raw:string,error:?string}
     */
    public static function postRawJson($url, $rawJsonBody, array $headers = [], $timeout = self::DEFAULT_TIMEOUT, $proxy = null)
    {
        $headers = array_merge(['Content-Type' => 'application/json'], $headers);

        if ($proxy !== null && $proxy !== '') {
            return self::requestViaProxy('POST', $url, $headers, $rawJsonBody, $timeout, $proxy);
        }

        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => $headers,
            'body' => $rawJsonBody,
        ]);

        return self::normalize($response);
    }

    /**
     * @param array|\WP_Error $response
     * @return array{ok:bool,status:int,body:mixed,raw:string,error:?string}
     */
    private static function normalize($response)
    {
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => null,
                'raw' => '',
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $body = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'raw' => $raw,
            'error' => null,
        ];
    }

    /**
     * 用原生curl发一次请求并走指定代理。只在$proxy非空时被调用。
     *
     * 不直接把代理形式的URL交给wp_remote_*，是因为WP_Http的代理实现
     * （WP_HTTP_Proxy）只读wp-config.php里的WP_PROXY_*常量，没有"按次请求
     * 传一个代理"的参数位置——要做到"配了proxy的渠道走代理、没配的直连"，
     * 只能在这里自己控制curl句柄。
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param ?string $body GET请求传null
     * @param int $timeout
     * @param string $proxy
     * @return array{ok:bool,status:int,body:mixed,raw:string,error:?string}
     */
    private static function requestViaProxy($method, $url, array $headers, $body, $timeout, $proxy)
    {
        try {
            $proxyOptions = ProxyConfig::curlOptions($proxy);
        } catch (\InvalidArgumentException $e) {
            // 代理配了但格式不对：明确报错，不能悄悄降级成直连——那样"配了代理"
            // 这件事就变成了假象，敏感支付请求可能在用户以为绕行代理时直接暴露了出口IP。
            return [
                'ok' => false,
                'status' => 0,
                'body' => null,
                'raw' => '',
                'error' => $e->getMessage(),
            ];
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
        ] + $proxyOptions);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);

        if ($raw === false) {
            $error = curl_error($handle) ?: '代理请求失败（curl未返回具体错误）';
            curl_close($handle);
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => $error];
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = json_decode($raw, true);
        $decodedBody = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $decodedBody,
            'raw' => $raw,
            'error' => null,
        ];
    }
}
