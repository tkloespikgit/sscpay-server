<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OrderDisputeOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, string> $details 订单通知字段快照，不包含客户资料或凭证。 */
    public function __construct(
        public readonly int $merchantId,
        public readonly array $details,
        public readonly string $eventNo,
        public readonly string $dueAt,
    ) {}
}
