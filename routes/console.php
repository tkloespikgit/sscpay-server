<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::command('exchange:fetch')->hourly();

Schedule::command('db:backup:upload')->everySixHours();

Schedule::command('order-events:sync')->everyMinute()->withoutOverlapping();

Schedule::command('orders:sync-pending-status')
    ->everyMinute()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::error('待支付订单状态定时查询存在失败，请检查单笔查询日志');
    });

Schedule::command('order-notifications:process-due')->everyMinute()->withoutOverlapping();

Schedule::command('order-disputes:close-due')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('order-disputes:send-reminders')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('ad-conversions:process-due')->everyMinute()->withoutOverlapping();

Schedule::command('fund-freezes:release-due')->everyTenSeconds()->runInBackground()->withoutOverlapping(5)
    ->onFailure(fn () => Log::error('到期保证金释放任务执行失败，未完成记录将在后续调度重试。'));

Schedule::command('orders:settle-due')->everyTenSeconds()->runInBackground()->withoutOverlapping(5)
    ->onFailure(fn () => Log::error('订单记账或结算任务执行失败，未完成记录将在后续调度重试。'));
// 订单每日统计。凌晨滚动重算最近 8 天而不是只算昨天：网关可能带着真实 paid_at
// 延迟补推（会写进过去的某一天），这也是任务失败一次之后的自愈手段。
// 每 3 小时那条覆盖今天 + 昨天——只算今天的话，21:00 到次日 02:00 之间
// 昨天最后三小时的数据是缺的。
Schedule::command('order-stats:aggregate --days=8')->dailyAt('02:00')->withoutOverlapping();

Schedule::command('order-stats:aggregate --days=2')->everyThreeHours()->withoutOverlapping();

// 收款链接：核对写死的 Cloudflare 网段是否还和官方一致。CF 增删网段是静默的，
// 不跟进会让经由新网段进来的客户 IP 被记成 CF 的边缘 IP（见 CloudflareIpRanges）。
// 有差异时命令返回非 0，方便被监控告警捕捉。
Schedule::command('checkout:check-cloudflare-ips')->weeklyOn(1, '09:00');

// 收款链接：刷新商户自有域名在 Cloudflare 侧的证书状态。证书签发是异步的，
// 商户加完 DCV 记录后不会有人通知我们，只能主动轮询。
Schedule::command('checkout:sync-domains')->everyFifteenMinutes()->withoutOverlapping();

// 物流上传记录保留 90 天（业务确认）：明细表随行数线性增长，过期任务连明细一起清掉。
Schedule::command('logistics-imports:prune --days=90')->dailyAt('03:10')->withoutOverlapping();
