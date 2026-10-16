<?php

namespace App\Console\Commands;

use App\Services\PendingOrderStatusSyncService;
use Illuminate\Console\Command;

class SyncPendingOrderStatuses extends Command
{
    protected $signature = 'orders:sync-pending-status';

    protected $description = '查询最近 48 小时内未支付的待支付订单并同步最新状态';

    public function handle(PendingOrderStatusSyncService $service): int
    {
        $result = $service->sync();

        $this->info('待支付订单状态查询结果：'.json_encode($result));

        return $result['orders_failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
