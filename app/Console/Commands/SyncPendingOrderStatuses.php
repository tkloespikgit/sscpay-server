<?php

namespace App\Console\Commands;

use App\Services\PendingOrderStatusSyncService;
use Illuminate\Console\Command;

class SyncPendingOrderStatuses extends Command
{
    protected $signature = 'orders:sync-pending-status';

    protected $description = '补查最近 48 小时内的待支付订单，每单最多两次，分别于创建满 30 和 90 分钟后查询';

    public function handle(PendingOrderStatusSyncService $service): int
    {
        $result = $service->sync();

        $this->info('待支付订单状态查询结果：'.json_encode($result));

        return $result['orders_failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
