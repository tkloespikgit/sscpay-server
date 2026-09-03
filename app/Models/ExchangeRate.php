<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExchangeRate extends Model
{
    protected $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'retrieved_at' => 'datetime',
        ];
    }

    protected const CACHE_TTL_SECONDS = 3600;

    /**
     * 带缓存查询最新汇率（1 目标币种 = ? 基准币种）。
     */
    public static function getLatestRate(string $targetCurrency, string $baseCurrency = 'USD'): ?float
    {
        $cacheKey = "exchange_rate:{$baseCurrency}:{$targetCurrency}";

        return Cache::remember($cacheKey, static::CACHE_TTL_SECONDS, function () use ($targetCurrency, $baseCurrency) {
            $row = static::query()
                ->where('base_currency', $baseCurrency)
                ->where('target_currency', $targetCurrency)
                ->first();

            return $row ? (float) $row->rate : null;
        });
    }

    /**
     * 批量更新（或插入）汇率，来自 exchange:fetch 命令。更新后清掉对应缓存，
     * 避免和 getLatestRate() 的缓存不一致。
     */
    public static function updateBatchRates(array $rates, string $baseCurrency = 'USD'): void
    {
        $now = now();

        DB::transaction(function () use ($rates, $baseCurrency, $now) {
            foreach ($rates as $targetCurrency => $rate) {
                static::query()->updateOrInsert(
                    ['base_currency' => $baseCurrency, 'target_currency' => $targetCurrency],
                    ['rate' => $rate, 'retrieved_at' => $now, 'updated_at' => $now, 'created_at' => $now]
                );

                Cache::forget("exchange_rate:{$baseCurrency}:{$targetCurrency}");
            }
        });
    }

    /**
     * 计算含汇损的实际结算汇率，并返回下单时需要冻结进 orders 表的完整快照。
     *
     * @return array{
     *     original_rate: float,
     *     surcharge_percent: float,
     *     surcharge_type: string,
     *     surcharge_amount: float,
     *     actual_rate: float,
     * }
     */
    public static function getRateWithSurcharge(string $targetCurrency, string $baseCurrency = 'USD'): array
    {
        // 同币种无需换汇，也不存在汇损，exchange_rates 里也不会有自身对自身的行。
        if ($targetCurrency === $baseCurrency) {
            return [
                'original_rate' => 1.0,
                'surcharge_percent' => 0.0,
                'surcharge_type' => (string) SystemConfig::get('exchange.surcharge_type', 'percent'),
                'surcharge_amount' => 0.0,
                'actual_rate' => 1.0,
            ];
        }

        $originalRate = static::getLatestRate($targetCurrency, $baseCurrency);

        if ($originalRate === null) {
            throw new \RuntimeException("No exchange rate found for {$targetCurrency}->{$baseCurrency}");
        }

        $surchargeType = SystemConfig::get('exchange.surcharge_type', 'percent');

        if ($surchargeType === 'fixed') {
            $surchargeAmount = (float) SystemConfig::get('exchange.surcharge_fixed', 0);
            $actualRate = bcadd((string) $originalRate, (string) $surchargeAmount, 6);
            $surchargePercent = $originalRate > 0
                ? round(($surchargeAmount / $originalRate) * 100, 4)
                : 0.0;
        } else {
            $surchargePercent = (float) SystemConfig::get('exchange.surcharge_percent', 0);
            $actualRate = bcmul((string) $originalRate, (string) (1 + $surchargePercent / 100), 6);
            $surchargeAmount = bcsub($actualRate, (string) $originalRate, 6);
        }

        return [
            'original_rate' => (float) $originalRate,
            'surcharge_percent' => (float) $surchargePercent,
            'surcharge_type' => $surchargeType,
            'surcharge_amount' => (float) $surchargeAmount,
            'actual_rate' => (float) $actualRate,
        ];
    }
}
