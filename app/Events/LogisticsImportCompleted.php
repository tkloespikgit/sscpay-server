<?php

namespace App\Events;

use App\Models\LogisticsImportTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LogisticsImportCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly LogisticsImportTask $task) {}
}
