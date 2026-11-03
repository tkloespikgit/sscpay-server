<?php

namespace App\Console\Commands;

use App\Services\MerchantShipmentSyncService;
use Illuminate\Console\Command;

class ProcessMerchantShipmentSyncs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'merchant-shipments:process-due {--limit=500}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '投递商户物流回传、重试及按商户汇总的失败 Telegram 提醒';

    /**
     * Execute the console command.
     */
    public function handle(MerchantShipmentSyncService $service): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $dispatched = $service->dispatchDue($limit);
        $notified = $service->notifyFailures($limit);
        $this->info("Dispatched {$dispatched} shipment sync(s); notified {$notified} failure(s).");

        return self::SUCCESS;
    }
}
