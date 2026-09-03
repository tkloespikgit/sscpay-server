<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Models\SystemConfig;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 拉取 exchange.supported_currencies 配置的币种列表对应的实时汇率，
 * 批量更新 exchange_rates 表（4.9 节，建议调度：每小时一次）。
 *
 * 【需要你确认】文档没有指定具体用哪个汇率数据提供商，这里用
 * config('services.exchange_rate.url') + config('services.exchange_rate.key')
 * 作为占位配置，实际接入时把 fetchRatesFromProvider() 换成你们选定的
 * 第三方汇率 API 调用方式即可，本命令的编排逻辑（只请求配置的币种、
 * 批量更新、清缓存）不需要跟着改。
 *
 *   $schedule->command('exchange:fetch')->hourly();
 */
class FetchExchangeRates extends Command
{
    protected $signature = 'exchange:fetch';

    protected $description = '拉取配置中支持的币种的实时汇率并批量更新';

    public function handle(): int
    {
        $currencies = SystemConfig::getArray('exchange.supported_currencies', []);

        if (empty($currencies)) {
            $this->warn('exchange.supported_currencies is empty, nothing to fetch.');

            return self::SUCCESS;
        }

        $rates = $this->fetchRatesFromProvider($currencies);

        if (empty($rates)) {
            $this->error('Failed to fetch rates from provider.');

            return self::FAILURE;
        }

        ExchangeRate::updateBatchRates($rates, 'USD');

        $this->info('Updated rates for: '.implode(', ', array_keys($rates)));

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $currencies
     * @return array<string, float> 币种 => 汇率（1 目标币种 = ? USD）
     */
    private function fetchRatesFromProvider(array $currencies): array
    {
        $url    = config('services.exchange_rate.url');
        $apiKey = config('services.exchange_rate.key');

        if (empty($url)) {
            $this->warn('services.exchange_rate.url not configured, skipping actual HTTP call.');

            return [];
        }


        /*
         *
         * $response = Http::get('https://data.fixer.io/api/latest', [
                'access_key' => $fixerKey,
                'base'       => $currency,
                'symbols'    => implode(',', array_diff($currencyArr, [$currency]))
            ]);
         * */
        try {
            $response = Http::timeout(10)
                ->retry(3, 100)
                ->withToken($apiKey)
                ->get($url, [
                    'symbols' => implode(',', $currencies),
                    'base' => 'USD',
                    'access_key' => $apiKey,
                ]);
        } catch (ConnectionException $e) {
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        // 假定响应结构为 { "rates": { "EUR": 0.92, "JPY": 155.3, ... } }，
        // 且返回的是"1 USD = ? 目标币种"，需要取倒数换算成"1 目标币种 = ? USD"
        // （与 exchange_rates 表 rate 字段的语义保持一致，见该表注释）。
        $rawRates = $response->json('rates', []);

        $rates = [];
        foreach ($currencies as $currency) {
            if (isset($rawRates[$currency]) && (float) $rawRates[$currency] > 0) {
                $rates[$currency] = round(1 / (float) $rawRates[$currency], 6);
            }
        }

        return $rates;
    }
}
