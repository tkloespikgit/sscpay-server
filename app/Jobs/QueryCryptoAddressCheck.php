<?php

namespace App\Jobs;

use App\Models\CryptoAddressCheck;
use App\Services\CryptoAddressCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueryCryptoAddressCheck implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 100;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public array $backoff = [300, 900, 3600];

    public function __construct(public readonly int $merchantId, public readonly int $checkId) {}

    public function handle(CryptoAddressCheckService $checks): void
    {
        if (! $checks->process($this->merchantId, $this->checkId)) {
            $this->release(15);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $check = CryptoAddressCheck::query()->forMerchant($this->merchantId)->whereIn('status', ['pending', 'running'])->find($this->checkId);
        if ($check === null) {
            return;
        }
        $failures = $check->failure_count + 1;
        CryptoAddressCheck::query()->forMerchant($this->merchantId)->whereKey($check->id)
            ->where('status', $check->status)->where('attempts', $check->attempts)
            ->update(['status' => 'error', 'failure_count' => $failures, 'last_error_code' => 'worker_failed', 'dispatched_at' => null,
                'next_attempt_at' => $failures < CryptoAddressCheckService::MAX_FAILURES ? now()->addHours(3) : null]);
    }
}
