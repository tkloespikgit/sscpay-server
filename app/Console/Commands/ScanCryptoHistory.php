<?php

namespace App\Console\Commands;

use App\Services\CryptoHistoryScanService;
use App\Support\CryptoVerificationPolicy;
use Illuminate\Console\Command;
use Throwable;

class ScanCryptoHistory extends Command
{
    protected $signature = 'crypto:scan-history {network : Explicit network, including mainnet/testnet} {--limit=100 : Maximum block batches per invocation}';

    protected $description = '按网络连续补查全部订单地址（包括冷地址），持久化待核验线索与完整扫描检查点';

    public function handle(CryptoHistoryScanService $service): int
    {
        if (! config('services.tatum.history_enabled')) {
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
        $processed = 0;
        $deadline = microtime(true) + 45;
        try {
            while ($processed < $limit && microtime(true) < $deadline && $service->scan($network)) {
                $processed++;
            }
        } catch (Throwable) {
            $this->error('History scan failed for '.$network.'; completed checkpoints are retained.');

            return self::FAILURE;
        }
        $this->info('Processed '.$processed.' history batch(es) for '.$network.'.');

        return self::SUCCESS;
    }
}
