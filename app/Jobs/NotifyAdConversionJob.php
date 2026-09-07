<?php

namespace App\Jobs;

use App\Models\AdConversionAttempt;
use App\Services\AdConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 与 SendOrderNotificationJob 同样的设计：Job 本身不重试（tries=1），
 * 重试次数/间隔完全由 AdConversionAttempt 的 attempt_number/next_retry_at 管理。
 * 放在 low 队列（与物流同步、站点商品同步等非实时任务一致），不占用
 * 支付回调/邮件发送等高优先级队列的处理能力。
 */
class NotifyAdConversionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $attemptId)
    {
        $this->onQueue('low');
    }

    public function handle(AdConversionService $service): void
    {
        $attempt = AdConversionAttempt::query()->find($this->attemptId);

        if (! $attempt || $attempt->status !== 'pending') {
            return;
        }

        $service->attempt($attempt);
    }
}
