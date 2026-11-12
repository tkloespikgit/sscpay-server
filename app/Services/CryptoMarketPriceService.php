<?php

namespace App\Services;

use App\Models\SystemConfig;
use App\Support\CryptoAmount;
use App\Support\CryptoAssetRegistry;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class CryptoMarketPriceService
{
    public function __construct(private readonly TatumReadClient $tatum, private readonly CryptoAssetRegistry $assets) {}

    /** @return array{value: string, at: CarbonImmutable, source: string} */
    public function usdPrice(string $assetKey): array
    {
        $asset = $this->assets->get($assetKey);
        $maxAge = SystemConfig::get('crypto.max_market_price_age_seconds');
        if (! is_string($maxAge) || ! preg_match('/^[1-9][0-9]{0,6}$/D', $maxAge)) {
            throw new DomainException('Crypto market freshness has not been configured.');
        }
        $maxAge = (int) $maxAge;
        $key = 'crypto:tatum:usd-price:'.$asset['asset'];
        $cached = Cache::get($key);
        if ($this->fresh($cached, $maxAge)) {
            return $cached;
        }

        $lease = max(30, (int) config('services.tatum.timeout', 10) * 2 + 5);

        return Cache::lock($key.':lock', $lease)->block(5, function () use ($assetKey, $key, $maxAge): array {
            $cached = Cache::get($key);
            if ($this->fresh($cached, $maxAge)) {
                return $cached;
            }
            Cache::forget($key);
            $price = $this->tatum->usdPrice($assetKey);
            Cache::put($key, $price, min(10, $maxAge));

            return $price;
        });
    }

    private function fresh(mixed $price, int $maxAge): bool
    {
        if (! is_array($price) || ! is_string($price['value'] ?? null) || ! (($price['at'] ?? null) instanceof CarbonImmutable)
            || ($price['source'] ?? null) !== 'Tatum') {
            return false;
        }
        try {
            [$numerator] = CryptoAmount::fraction($price['value']);
        } catch (InvalidArgumentException) {
            return false;
        }
        $timestamp = $price['at']->getTimestampMs();

        return $numerator !== '0' && $timestamp <= now()->getTimestampMs() && $timestamp >= now()->getTimestampMs() - $maxAge * 1000;
    }
}
