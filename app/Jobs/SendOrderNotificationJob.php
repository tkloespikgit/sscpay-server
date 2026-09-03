<?php

namespace App\Jobs;

use App\Models\OrderNotificationAttempt;
use App\Services\OrderNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 队列任务本身不做失败重试（tries=1）——重试次数、间隔完全由
 * OrderNotificationAttempt 的 attempt_number / next_retry_at 机制管理，
 * 不能让 Laravel 队列自带的重试和我们自己的重试计数重叠，
 * 否则实际重试次数会变成两套机制相乘，行为不可预测。
 */
class SendOrderNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $attemptId) {}

    public function handle(OrderNotificationService $service): void
    {
        $attempt = OrderNotificationAttempt::query()->find($this->attemptId);

        if (! $attempt || $attempt->status !== 'pending') {
            return;
        }

        $service->attempt($attempt);
    }
}
