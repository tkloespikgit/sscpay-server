<?php

namespace App\Console\Commands;

use App\Models\CryptoPaymentRequest;
use App\Services\CryptoHistoryScanService;
use App\Support\CryptoRuntimeSettings;
use App\Support\CryptoVerificationPolicy;
use Illuminate\Console\Command;
use Throwable;

class ScanCryptoHistory extends Command
{
    protected $signature = 'crypto:scan-history {network : Explicit network, including mainnet/testnet} {--merchant= : Merchant owning the address} {--request= : Payment request to query} {--limit=100 : Maximum address history pages}';

    protected $description = '按明确商户与付款请求补查地址历史；不扫描整条链';

    public function handle(CryptoHistoryScanService $service): int
    {
        if (! CryptoRuntimeSettings::get('history_enabled')) {
            $this->info('Crypto history scanning is disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        $network = (string) $this->argument('network');
        try {
            CryptoVerificationPolicy::version($network);
        } catch (Throwable) {
            $this->error('Unsupported history network.');

            return self::INVALID;
        }
        if ($limit === false) {
            $this->error('Limit must be between 1 and 200.');

            return self::INVALID;
        }
        $merchant = filter_var($this->option('merchant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $request = filter_var($this->option('request'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($merchant === false || $request === false) {
            $this->error('Continuous block scanning is retired. Supply --merchant and --request, or use crypto:check-addresses.');

            return self::INVALID;
        }
        $processed = 0;
        $deadline = microtime(true) + 45;
        try {
            while ($processed < $limit && microtime(true) < $deadline && $service->scan($network, $merchant, $request)) {
                $processed++;
                $fresh = CryptoPaymentRequest::query()->forMerchant($merchant)->findOrFail($request);
                if ($fresh->history_query_state === null) {
                    break;
                }
            }
        } catch (Throwable) {
            $this->error('History scan failed for '.$network.'; completed checkpoints are retained.');

            return self::FAILURE;
        }
        $this->info('Processed '.$processed.' history batch(es) for '.$network.'.');

        return self::SUCCESS;
    }
}
