<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** 物流首次进入异常时的标量快照，不携带商户凭证或客户资料。 */
class LogisticsAnomalyDetected
{
    use Dispatchable;

    /**
     * @param  array{order_no: string, merchant_order_no: string, tracking_number: string, status: string, reason: string, days: string}  $details
     */
    public function __construct(
        public readonly int $merchantId,
        public readonly array $details,
    ) {}
}
