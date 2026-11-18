<?php

namespace App\Console\Commands;

use App\Models\CryptoPaymentRequest;
use App\Services\CryptoSettlementService;
use App\Support\CryptoRuntimeSettings;
use Illuminate\Console\Command;
use Throwable;

class SettleCryptoPayments extends Command
{
    protected $signature = 'crypto:settle-payments {--limit=100 : Maximum payment requests per invocation}';

    protected $description = '将链上核验确认付足的订单原子入账，重试失败待办，不动法币余额';

    public function handle(CryptoSettlementService $service): int
    {
        if (! CryptoRuntimeSettings::get('settlement_enabled') || ! CryptoRuntimeSettings::get('history_enabled') || ! CryptoRuntimeSettings::get('verification_enabled')) {
            $this->info('Crypto automatic settlement is disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 500.');

            return self::INVALID;
        }
        $requests = CryptoPaymentRequest::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->where('status', '!=', 'review')->orWhereNotNull('credited_at'))
            ->where(fn ($due) => $due->where('settlement_next_attempt_at', '<=', now())
                ->orWhere(fn ($paid) => $paid->whereNotNull('credited_at')->whereHas('order', fn ($orders) => $orders->withoutGlobalScopes()
                    ->whereHas('notificationAttempts', fn ($attempts) => $attempts->withoutGlobalScopes()
                        ->where('notify_type', 'trade_result')->where('status', 'pending')->where('scheduled_at', '<=', now()->subMinutes(5))))))
            ->orderBy('settlement_next_attempt_at')->orderBy('id')->limit($limit)->get(['id', 'merchant_id']);
        $failed = 0;
        $processed = 0;
        $deadline = microtime(true) + 45;
        foreach ($requests as $request) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $processed++;
            try {
                $service->settle((int) $request->merchant_id, (int) $request->id);
            } catch (Throwable) {
                $failed++;
                CryptoPaymentRequest::query()->forMerchant($request->merchant_id)->whereKey($request->id)
                    ->update(['settlement_next_attempt_at' => now()->addMinute()]);
                $this->error('Settlement will retry for payment request '.$request->id.'.');
            }
        }
        $this->info('Processed '.$processed.' payment request(s); '.$failed.' failed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
