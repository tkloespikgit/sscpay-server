<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** 只携带已脱敏的标量快照，队列中不保存网关凭证、完整请求或异常对象。 */
class PaymentLinkCreationFailed
{
    use Dispatchable;

    /** @param  array<string, string>  $details */
    public function __construct(
        public readonly int $merchantId,
        public readonly array $details,
    ) {}
}
