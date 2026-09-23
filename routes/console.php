<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Schedule::command('exchange:fetch')->hourly();

Schedule::command('db:backup:upload')->everySixHours();

Schedule::command('order-events:sync')->everyMinute()->withoutOverlapping();

Schedule::command('order-notifications:process-due')->everyMinute()->withoutOverlapping();

Schedule::command('order-disputes:close-due')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('order-disputes:send-reminders')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('ad-conversions:process-due')->everyMinute()->withoutOverlapping();

Schedule::command('fund-freezes:release-due')->everyFiveMinutes()->withoutOverlapping();
// 订单每日统计。凌晨滚动重算最近 8 天而不是只算昨天：网关可能带着真实 paid_at
// 延迟补推（会写进过去的某一天），这也是任务失败一次之后的自愈手段。
// 每 3 小时那条覆盖今天 + 昨天——只算今天的话，21:00 到次日 02:00 之间
// 昨天最后三小时的数据是缺的。
Schedule::command('order-stats:aggregate --days=8')->dailyAt('02:00')->withoutOverlapping();

Schedule::command('order-stats:aggregate --days=2')->everyThreeHours()->withoutOverlapping();
