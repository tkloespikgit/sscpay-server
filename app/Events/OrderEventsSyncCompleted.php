<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderEventsSyncCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $fetchedCount,
        public readonly int $writtenCount,
        public readonly int $skippedCount,
    ) {}
}
