<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Services\CryptoMarketPriceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CryptoMarketPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_asset_across_networks_shares_fresh_usd_price(): void
    {
        $this->freezeTime();
        config(['services.tatum.api_key' => 'key']);
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/data/rate/symbol*' => Http::response([
            'value' => '0.997', 'basePair' => 'USD', 'symbol' => 'USDT', 'timestamp' => now()->getTimestampMs(),
        ])]);
        $service = app(CryptoMarketPriceService::class);

        $erc20 = $service->usdPrice('ethereum-mainnet:USDT');
        $trc20 = $service->usdPrice('tron-mainnet:USDT');

        $this->assertSame('0.997', $erc20['value']);
        $this->assertSame($erc20['value'], $trc20['value']);
        Http::assertSentCount(1);
    }

    public function test_aged_provider_timestamp_is_rechecked_even_while_cache_entry_exists(): void
    {
        $this->freezeTime();
        config(['services.tatum.api_key' => 'key']);
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        Cache::put('crypto:tatum:usd-price:ETH', ['value' => '2000', 'at' => CarbonImmutable::now()->subSeconds(61), 'source' => 'Tatum'], 10);
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/data/rate/symbol*' => Http::response([
            'value' => '2500', 'basePair' => 'USD', 'symbol' => 'ETH', 'timestamp' => now()->getTimestampMs(),
        ])]);

        $price = app(CryptoMarketPriceService::class)->usdPrice('ethereum-mainnet:ETH');

        $this->assertSame('2500', $price['value']);
        Http::assertSentCount(1);
    }

    public function test_provider_failure_never_falls_back_to_stale_price(): void
    {
        $this->freezeTime();
        config(['services.tatum.api_key' => 'key']);
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        Cache::put('crypto:tatum:usd-price:ETH', ['value' => '2000', 'at' => CarbonImmutable::now()->subSeconds(61), 'source' => 'Tatum'], 10);
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v4/data/rate/symbol*' => Http::response([], 401)]);
        $this->expectException(DomainException::class);

        app(CryptoMarketPriceService::class)->usdPrice('ethereum-mainnet:ETH');
    }
}
