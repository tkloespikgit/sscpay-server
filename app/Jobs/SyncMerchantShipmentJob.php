<?php

namespace App\Jobs;

use App\Services\MerchantShipmentSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMerchantShipmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $syncId, public readonly int $merchantId)
    {
        $this->onQueue('low');
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(MerchantShipmentSyncService $service): void
    {
        $service->attempt($this->syncId, $this->merchantId);
    }
}
