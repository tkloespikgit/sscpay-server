<?php

namespace App\Services\Crypto;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Services\Crypto\Chains\CryptoChainRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * USD→代币报价：稳定币（系统配置白名单）用 peg=1；其余拉 Tatum，fresh TTL 可配，失败用 last 永久缓存。
 *
 * P0 建单仅开放 USDT；白名单默认也仅 USDT。非白名单路径保留供以后扩展，当前 prepareCheckout 会先拒单。
 */
class CryptoQuoteService
{
    public const STABLECOIN_CONFIG_KEY = 'crypto.stablecoin_symbols';

    /** @var list<string> */
    public const STABLECOIN_DEFAULTS = ['USDT'];

    private const FRESH_CACHE_PREFIX = 'crypto:quote:fresh:';

    private const LAST_CACHE_PREFIX = 'crypto:quote:last:';

    public function __construct(
        private readonly TatumClient $tatum,
        private readonly CryptoChainRegistry $chains,
    ) {}

    /**
     * 按渠道 symbol 取 USD→代币报价；稳定币命中白名单则 peg=1。
     */
    public function quoteForMethod(PaymentMethod $method): CryptoQuote
    {
        $symbol = strtoupper(trim($method->cryptoSymbol()));
        if ($symbol === '') {
            throw new RuntimeException('加密渠道未配置 symbol，无法报价');
        }

        if ($this->isStablecoin($symbol)) {
            return new CryptoQuote('1', CryptoQuote::SOURCE_PEG, now());
        }

        $cacheKey = $this->cacheKey($method, $symbol);
        $fresh = Cache::get(self::FRESH_CACHE_PREFIX.$cacheKey);
        if (is_array($fresh) && $this->isValidCachedQuote($fresh)) {
            return new CryptoQuote(
                (string) $fresh['rate'],
                CryptoQuote::SOURCE_CACHE,
                Carbon::parse((string) $fresh['quoted_at']),
            );
        }

        try {
            $fetched = $this->fetchFromTatum($method, $symbol);
            $this->storeCaches($cacheKey, $fetched);

            return $fetched;
        } catch (\Throwable $e) {
            Log::warning('crypto quote: Tatum 报价失败，尝试 last 缓存', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            $last = Cache::get(self::LAST_CACHE_PREFIX.$cacheKey);
            if (is_array($last) && $this->isValidCachedQuote($last)) {
                return new CryptoQuote(
                    (string) $last['rate'],
                    CryptoQuote::SOURCE_CACHE,
                    Carbon::parse((string) $last['quoted_at']),
                );
            }

            throw new RuntimeException(
                "无法获取 {$symbol}/USD 报价（Tatum 失败且无可用缓存）：".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * 辅助：折后原币 amount → USD（非 USD 用订单 exchange_rate）。
     * P0 建单盐写在 converted_amount，主路径不再调用本方法；保留供报价扩展/测试。
     */
    public function usdBasisFromOrderAmount(Order $order): string
    {
        $amount = (string) $order->amount;
        $currency = strtoupper(trim((string) $order->currency));

        if ($currency === '' || $currency === 'USD') {
            return $amount;
        }

        $rate = (string) ($order->exchange_rate ?? '0');
        if (bccomp($rate, '0', 8) <= 0) {
            throw new RuntimeException('订单缺少有效汇率，无法将原币金额折算为 USD 作加密应付基数');
        }

        return bcmul($amount, $rate, 8);
    }

    /**
     * USD 基数 / rate(USD per token) → 代币数量基数（加盐前）。
     */
    public function tokenBaseFromUsd(string $usdAmount, string $rate, int $scale): string
    {
        if (bccomp($rate, '0', 12) <= 0) {
            throw new RuntimeException('代币报价无效：rate 必须大于 0');
        }

        return bcadd(bcdiv($usdAmount, $rate, $scale + 4), '0', $scale);
    }

    public function isStablecoin(string $symbol): bool
    {
        $symbol = strtoupper(trim($symbol));
        $list = SystemConfig::getArray(self::STABLECOIN_CONFIG_KEY, self::STABLECOIN_DEFAULTS);

        $normalized = array_map(
            static fn (mixed $item): string => strtoupper(trim((string) $item)),
            $list
        );

        return in_array($symbol, $normalized, true);
    }

    private function fetchFromTatum(PaymentMethod $method, string $symbol): CryptoQuote
    {
        $client = $this->tatum->using($method->cryptoTatumHttpCredentials());
        $chain = $this->chains->normalize($method->cryptoChain());
        $contract = $method->cryptoTokenContract();
        $payload = null;

        if ($contract !== '' && $chain !== '') {
            try {
                $adapter = $this->chains->resolve($chain);
                $tatumChain = $adapter?->tatumChain() ?? $chain;
                $payload = $client->getExchangeRateByContract($tatumChain, $contract, 'USD');
            } catch (\Throwable $e) {
                Log::info('crypto quote: 合约报价失败，回退符号报价', [
                    'symbol' => $symbol,
                    'chain' => $chain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! is_array($payload)) {
            $payload = $client->getExchangeRateBySymbol($symbol, 'USD');
        }

        $rate = trim((string) ($payload['value'] ?? ''));
        if ($rate === '' || ! is_numeric($rate) || bccomp($rate, '0', 12) <= 0) {
            throw new RuntimeException('Tatum 返回的报价无效');
        }

        $ts = $payload['timestamp'] ?? null;
        $quotedAt = is_numeric($ts)
            ? Carbon::createFromTimestampMs((int) $ts)
            : now();

        return new CryptoQuote($rate, CryptoQuote::SOURCE_TATUM, $quotedAt);
    }

    private function storeCaches(string $cacheKey, CryptoQuote $quote): void
    {
        $payload = [
            'rate' => $quote->rate,
            'quoted_at' => $quote->quotedAt->toIso8601String(),
        ];

        $ttlHours = max(1, (int) config('tatum.quote_cache_ttl_hours', 24));
        Cache::put(self::FRESH_CACHE_PREFIX.$cacheKey, $payload, now()->addHours($ttlHours));
        Cache::forever(self::LAST_CACHE_PREFIX.$cacheKey, $payload);
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function isValidCachedQuote(array $cached): bool
    {
        $rate = trim((string) ($cached['rate'] ?? ''));

        return $rate !== '' && is_numeric($rate) && bccomp($rate, '0', 12) > 0
            && filled($cached['quoted_at'] ?? null);
    }

    private function cacheKey(PaymentMethod $method, string $symbol): string
    {
        $chain = strtolower($this->chains->normalize($method->cryptoChain()));
        $contract = strtolower($method->cryptoTokenContract());

        return sha1($symbol.'|'.$chain.'|'.$contract);
    }
}
