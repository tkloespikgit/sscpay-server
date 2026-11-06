<?php

namespace App\Services\Crypto;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tatum HTTP 客户端（listen-only：地址订阅，不派生地址/不持钥）。
 *
 * - using($credentials)：按渠道 config 覆盖 api_key / base_url（缺省 config/tatum.php）
 * - subscribeAddress：须传入适配器给出的 tatumChain（如 ethereum-mainnet）
 * 入账以 webhook payload 为准，不做交易详情二次校对。
 */
class TatumClient
{
    /**
     * @var array{api_key?: string, base_url?: string, timeout?: int}|null
     */
    private ?array $credentials = null;

    /**
     * @param  array{api_key?: string, base_url?: string, timeout?: int}|null  $credentials
     */
    public function using(?array $credentials): self
    {
        $clone = clone $this;
        $clone->credentials = $credentials;

        return $clone;
    }

    public const SUBSCRIPTION_FUNGIBLE = 'INCOMING_FUNGIBLE_TX';

    public const SUBSCRIPTION_NATIVE = 'INCOMING_NATIVE_TX';

    /**
     * 有 token_contract → 代币入账；空合约 → 原生币入账（BTC / 原生 ETH/TRX 等）。
     */
    public static function subscriptionTypeForContract(string $tokenContract): string
    {
        return trim($tokenContract) === ''
            ? self::SUBSCRIPTION_NATIVE
            : self::SUBSCRIPTION_FUNGIBLE;
    }

    /**
     * 订阅收款地址入账通知（v4：INCOMING_FUNGIBLE_TX 或 INCOMING_NATIVE_TX）。
     *
     * @param  string  $tatumChain  已由适配器给出的标识，如 tron-mainnet / bitcoin-mainnet
     * @param  string  $subscriptionType  self::SUBSCRIPTION_*
     */
    public function subscribeAddress(
        string $tatumChain,
        string $address,
        string $webhookUrl,
        string $subscriptionType = self::SUBSCRIPTION_FUNGIBLE,
    ): ?string {
        if (blank($this->apiKey())) {
            Log::warning('tatum: 未配置 API Key，跳过地址订阅', ['chain' => $tatumChain, 'address' => $address]);

            return null;
        }

        if (! $this->isPublicWebhookUrl($webhookUrl)) {
            Log::error('tatum: 回调 URL 无效或非公网，跳过订阅', [
                'url' => $webhookUrl,
                'hint' => '请在渠道网关配置填写 https 公网 tatum_webhook_url（不可用 .local / localhost）',
            ]);

            return null;
        }

        if (! in_array($subscriptionType, [self::SUBSCRIPTION_FUNGIBLE, self::SUBSCRIPTION_NATIVE], true)) {
            throw new RuntimeException('不支持的 Tatum 订阅类型：'.$subscriptionType);
        }

        $path = '/v4/subscription';
        $payload = [
            'type' => $subscriptionType,
            'attr' => [
                'address' => $address,
                'chain' => $tatumChain,
                'url' => $webhookUrl,
            ],
        ];

        Log::info('tatum: 订阅请求', [
            'method' => 'POST',
            'base_url' => $this->baseUrl(),
            'path' => $path,
            'payload' => $payload,
        ]);

        $response = $this->http()->post($path, $payload);

        if (! $response->successful()) {
            $body = $response->json();
            $body = is_array($body) ? $body : [];
            $existingId = $this->existingSubscriptionId($response->status(), $body, $webhookUrl);

            if ($existingId !== null) {
                Log::info('tatum: 地址订阅已存在，复用订阅 ID', [
                    'subscription_id' => $existingId,
                    'address' => $address,
                    'url' => $webhookUrl,
                    'payload' => $payload,
                ]);

                return $existingId;
            }

            Log::error('tatum: 订阅地址失败', [
                'status' => $response->status(),
                'body' => $body !== [] ? $body : $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException('Tatum 地址订阅失败：HTTP '.$response->status());
        }

        $subscriptionId = (string) ($response->json('id') ?? $response->json('subscriptionId') ?? '');

        Log::info('tatum: 订阅成功', [
            'status' => $response->status(),
            'subscription_id' => $subscriptionId,
            'payload' => $payload,
            'response' => $response->json(),
        ]);

        return $subscriptionId;
    }

    /**
     * Tatum 对同一地址+币种只允许一条同类订阅；已存在时从错误信息回收 ID。
     * 若已存在订阅指向其他回调 URL，则不能静默复用（需先在 Tatum 删除旧订阅）。
     *
     * @param  array<string, mixed>  $body
     */
    private function existingSubscriptionId(int $status, array $body, string $webhookUrl): ?string
    {
        if ($status !== 403) {
            return null;
        }

        $message = (string) ($body['message'] ?? '');
        $errorCode = (string) ($body['errorCode'] ?? '');

        if ($errorCode !== '' && ! str_contains($errorCode, 'subscription.exists')) {
            return null;
        }

        $existingId = null;
        $existingUrl = null;

        if (preg_match('/already exists\s*\(([^)]+)\)/i', $message, $matches) === 1) {
            $existingId = trim($matches[1]);
        }

        if (preg_match('/url\s+(\S+)\s+already exists/i', $message, $matches) === 1) {
            $existingUrl = rtrim($matches[1], '.');
        }

        if (! filled($existingId)) {
            return null;
        }

        if (is_string($existingUrl) && $existingUrl !== '' && rtrim($existingUrl, '/') !== rtrim($webhookUrl, '/')) {
            Log::error('tatum: 地址已有订阅但回调 URL 不同，请先在 Tatum 删除旧订阅', [
                'subscription_id' => $existingId,
                'existing_url' => $existingUrl,
                'requested_url' => $webhookUrl,
            ]);

            return null;
        }

        return (string) $existingId;
    }

    /**
     * 当前汇率：1 {symbol} 值多少 {basePair}（默认语义与 Tatum 文档一致）。
     *
     * @return array{value: string, basePair?: string, timestamp?: int|float, source?: string, symbol?: string}
     */
    public function getExchangeRateBySymbol(string $symbol, string $basePair = 'USD'): array
    {
        $path = '/v4/data/rate/symbol';
        $query = [
            'symbol' => $symbol,
            'basePair' => $basePair,
        ];

        Log::info('tatum: 符号报价请求', [
            'method' => 'GET',
            'base_url' => $this->baseUrl(),
            'path' => $path,
            'query' => $query,
        ]);

        $response = $this->http()->get($path, $query);

        if (! $response->successful()) {
            Log::error('tatum: 符号报价失败', [
                'status' => $response->status(),
                'query' => $query,
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'Tatum 按符号报价失败：HTTP '.$response->status().' '.$response->body()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * 按链+合约报价：1 代币值多少 {basePair}。
     *
     * @return array{value: string, basePair?: string, timestamp?: int|float, source?: string, chain?: string, address?: string}
     */
    public function getExchangeRateByContract(string $tatumChain, string $contractAddress, string $basePair = 'USD'): array
    {
        $path = '/v4/data/rate/contract';
        $query = [
            'chain' => $tatumChain,
            'contractAddress' => $contractAddress,
            'basePair' => $basePair,
        ];

        Log::info('tatum: 合约报价请求', [
            'method' => 'GET',
            'base_url' => $this->baseUrl(),
            'path' => $path,
            'query' => $query,
        ]);

        $response = $this->http()->get($path, $query);

        if (! $response->successful()) {
            Log::error('tatum: 合约报价失败', [
                'status' => $response->status(),
                'query' => $query,
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'Tatum 按合约报价失败：HTTP '.$response->status().' '.$response->body()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function isPublicWebhookUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')) {
            return false;
        }

        return true;
    }

    private function apiKey(): string
    {
        $key = trim((string) ($this->credentials['api_key'] ?? ''));

        return $key !== '' ? $key : (string) config('tatum.api_key', '');
    }

    private function baseUrl(): string
    {
        $url = trim((string) ($this->credentials['base_url'] ?? ''));

        return rtrim($url !== '' ? $url : (string) config('tatum.base_url', 'https://api.tatum.io'), '/');
    }

    private function timeout(): int
    {
        $timeout = $this->credentials['timeout'] ?? null;

        return max(1, (int) ($timeout ?? config('tatum.timeout', 15)));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout($this->timeout())
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $this->apiKey(),
            ]);
    }
}
