<?php

namespace App\Console\Commands;

use App\Services\OrderStatsAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 重算订单每日统计（order_daily_stats），供后台「订单统计」看板读取。
 *
 * 按天整日删掉重插，可反复执行、可指定任意历史日期回填。
 *
 * 建议调度：凌晨滚动重算最近若干天（不是只算昨天一天——网关可能带着真实
 * paid_at 延迟补推，会写进过去的某一天；顺带也是任务失败一次后的自愈手段），
 * 再每 3 小时刷一次今天。每 3 小时那条要带上昨天：只算今天的话，
 * 21:00 到次日凌晨那次之间，昨天最后三小时的数据是缺的。
 *
 *   Schedule::command('order-stats:aggregate --days=8')->dailyAt('02:00')->withoutOverlapping();
 *   Schedule::command('order-stats:aggregate --days=2')->everyThreeHours()->withoutOverlapping();
 */
class AggregateOrderStats extends Command
{
    protected $signature = 'order-stats:aggregate
                            {--days=1 : 重算最近几天（含今天）}
                            {--date= : 只重算指定日期（Y-m-d），优先于 --days}';

    protected $description = '重算订单每日统计（商户/应用/支付方式粒度）';

    public function handle(OrderStatsAggregator $aggregator): int
    {
        $date = $this->option('date');

        if (filled($date)) {
            try {
                $day = Carbon::parse($date);
            } catch (\Throwable) {
                $this->error("Invalid --date: {$date}");

                return self::FAILURE;
            }

            $rows = $aggregator->aggregateDate($day);
            $this->info("Aggregated {$day->toDateString()}: {$rows} row(s).");

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $rows = $aggregator->aggregateRecentDays($days);

        $this->info("Aggregated last {$days} day(s): {$rows} row(s).");

        return self::SUCCESS;
    }
}
