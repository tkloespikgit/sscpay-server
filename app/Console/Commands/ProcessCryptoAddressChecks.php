<?php

namespace App\Console\Commands;

use App\Services\CryptoAddressCheckService;
use Illuminate\Console\Command;

class ProcessCryptoAddressChecks extends Command
{
    protected $signature = 'crypto:check-addresses {--limit=1000 : Maximum due checks dispatched} {--initialize : Seed the two slots for existing cancelled subscriptions without calling Tatum}';

    protected $description = '派发退订后第 3、7 天地址补查；初始化仅补齐本地计划';

    public function handle(CryptoAddressCheckService $checks): int
    {
        if ($this->option('initialize')) {
            $this->info('Initialized address check schedules for '.$checks->initialize().' order(s).');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 10000.');

            return self::INVALID;
        }
        $this->info('Dispatched '.$checks->dispatchDue($limit).' address check(s).');

        return self::SUCCESS;
    }
}
