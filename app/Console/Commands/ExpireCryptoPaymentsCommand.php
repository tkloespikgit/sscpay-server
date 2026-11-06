<?php

namespace App\Console\Commands;

use App\Services\Crypto\CryptoPaymentService;
use Illuminate\Console\Command;

/**
 * 调度见 routes/console.php（每分钟）。仅处理已写入 crypto_expires_at 的 pending 单。
 */
class ExpireCryptoPaymentsCommand extends Command
{
    protected $signature = 'crypto:expire-payments {--limit=200 : Max orders per run}';

    protected $description = '将超过 crypto_expires_at 仍未付清的链上收款订单标记为 expired';

    public function handle(CryptoPaymentService $cryptoPayments): int
    {
        $count = $cryptoPayments->expireDueOrders((int) $this->option('limit'));
        $this->info("Expired {$count} crypto payment order(s).");

        return self::SUCCESS;
    }
}
