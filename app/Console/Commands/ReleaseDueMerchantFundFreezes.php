<?php

namespace App\Console\Commands;

use App\Models\MerchantFundFreeze;
use App\Models\Order;
use App\Services\BalanceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * 扫描"冻结中且已到计划解冻时间"的资金冻结记录，逐个自动释放。
 * release_type 落 auto，released_by 落 NULL，与人工手动解冻在审计记录里可区分。
 * release_at 为 NULL（只能人工解冻）的记录不会被 dueForAutoRelease scope 选中。
 *
 * 建议调度频率：每 10 秒一次，限制批量大小并避免重叠。
 *
 *   Schedule::command('fund-freezes:release-due')->everyTenSeconds()->runInBackground()->withoutOverlapping(5);
 */
class ReleaseDueMerchantFundFreezes extends Command
{
    protected $signature = 'fund-freezes:release-due {--limit=500 : 单次最多处理的记录数（1-10000）}';

    protected $description = '扫描到期的资金冻结记录并自动释放';

    public function handle(BalanceService $service): int
    {
        $limit = (string) $this->option('limit');

        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 10000) {
            $this->error('--limit 必须在 1 至 10000 之间。');

            return self::INVALID;
        }

        $released = 0;
        $visited = 0;
        $failed = 0;

        MerchantFundFreeze::query()
            ->withoutGlobalScopes()
            ->dueForAutoRelease()
            ->where(fn (Builder $query) => $query->whereNull('order_id')
                ->orWhereHas('order', fn (Builder $orders) => $orders->withoutGlobalScopes()
                    ->whereNotIn('status', ['disputing', Order::STATUS_DISPUTE_REVIEW])
                    ->whereDoesntHave('accountingTasks', fn (Builder $tasks) => $tasks->withoutGlobalScopes()->unsettledReversals())))
            ->chunkById(min(100, (int) $limit), function ($freezes) use ($service, $limit, &$released, &$visited, &$failed): bool {
                foreach ($freezes as $freeze) {
                    try {
                        if ($service->releaseFundFreeze($freeze, null, MerchantFundFreeze::RELEASE_TYPE_AUTO)) {
                            $released++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }

                    $visited++;

                    if ($visited >= (int) $limit) {
                        return false;
                    }
                }

                return true;
            });

        $this->info("Auto-released {$released} of {$visited} due fund freeze(s); failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
