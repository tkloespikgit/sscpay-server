<?php

namespace App\Console\Commands;

use App\Models\MerchantFundFreeze;
use App\Services\BalanceService;
use Illuminate\Console\Command;

/**
 * 扫描"冻结中且已到计划解冻时间"的资金冻结记录，逐个自动释放。
 * release_type 落 auto，released_by 落 NULL，与人工手动解冻在审计记录里可区分。
 * release_at 为 NULL（只能人工解冻）的记录不会被 dueForAutoRelease scope 选中。
 *
 * 建议调度频率：每 5 分钟一次。
 *
 *   Schedule::command('fund-freezes:release-due')->everyFiveMinutes()->withoutOverlapping();
 */
class ReleaseDueMerchantFundFreezes extends Command
{
    protected $signature = 'fund-freezes:release-due';

    protected $description = '扫描到期的资金冻结记录并自动释放';

    public function handle(BalanceService $service): int
    {
        $dueFreezes = MerchantFundFreeze::query()
            ->withoutGlobalScopes()
            ->dueForAutoRelease()
            ->get();

        if ($dueFreezes->isEmpty()) {
            $this->info('No due fund freezes.');

            return self::SUCCESS;
        }

        $released = 0;

        foreach ($dueFreezes as $freeze) {
            if ($service->releaseFundFreeze($freeze, null, MerchantFundFreeze::RELEASE_TYPE_AUTO)) {
                $released++;
            }
        }

        $this->info("Auto-released {$released} of {$dueFreezes->count()} due fund freeze(s).");

        return self::SUCCESS;
    }
}
