<?php

namespace App\Console\Commands;

use App\Services\CombinedOrderService;
use Illuminate\Console\Command;

class ExpireCombinedOrders extends Command
{
    protected $signature = 'combined-orders:expire';

    protected $description = '停止已到期组合订单的新付款，保留未决远端支付与已付金额';

    public function handle(CombinedOrderService $payments): int
    {
        $this->info(__('combined.expired_count', ['count' => $payments->expireDue()]));

        return self::SUCCESS;
    }
}
