<?php

namespace App\Console\Commands;

use App\Models\CryptoWebhookReceipt;
use App\Services\CryptoReceiptVerificationService;
use Illuminate\Console\Command;
use Throwable;

class VerifyCryptoReceipts extends Command
{
    protected $signature = 'crypto:verify-receipts {--limit=25 : Maximum receipts processed per invocation}';

    protected $description = '核验链上回调线索与确认状态；不直接记账或更新订单支付事实';

    public function handle(CryptoReceiptVerificationService $service): int
    {
        if (! config('services.tatum.verification_enabled')) {
            $this->info('Crypto receipt verification is disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }
        $receipts = CryptoWebhookReceipt::query()->withoutGlobalScopes()->whereIn('status', ['pending', 'confirming'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get(['id', 'merchant_id']);
        $failed = 0;
        $processed = 0;
        $deadline = microtime(true) + 45;
        foreach ($receipts as $receipt) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $processed++;
            try {
                if (! $service->process((int) $receipt->merchant_id, (int) $receipt->id)) {
                    $failed++;
                }
            } catch (Throwable) {
                $failed++;
                $this->error('Verification failed for receipt '.$receipt->id.'.');
            }
        }
        $this->info('Processed '.$processed.' receipt(s); '.$failed.' failed or locked.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
