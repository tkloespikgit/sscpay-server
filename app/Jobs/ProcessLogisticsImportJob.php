<?php

namespace App\Jobs;

use App\Services\LogisticsImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLogisticsImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 600]; // 1分钟、5分钟、10分钟（8.4 节）

    public function __construct(public readonly int $taskId) {}

    public function handle(LogisticsImportService $service): void
    {
        $service->processImport($this->taskId);
    }
}
