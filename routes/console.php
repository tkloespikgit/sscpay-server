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

Schedule::command('combined-orders:expire')->everyMinute()->withoutOverlapping()
    ->onFailure(fn () => Log::error('组合订单到期处理失败，将在后续调度重试。'));

Schedule::command('order-notifications:process-due')->everyMinute()->withoutOverlapping();

Schedule::command('merchant-shipments:process-due')->everyMinute()->runInBackground()->withoutOverlapping(10)
    ->onFailure(fn () => Log::error('商户物流回传任务或失败提醒处理失败，未完成记录将在后续调度重试。'));

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

// 物流异常：按系统阈值重算；首次异常且配置可用时主动查一次，确认后派发 Telegram 提醒。
Schedule::command('logistics-tracking:reflag-anomalies')->dailyAt('06:00')->withoutOverlapping();

// 支付超 10 天仍未发货：按商户汇总 Telegram 提醒。
Schedule::command('orders:notify-unshipped-overdue')->dailyAt('10:00')->withoutOverlapping();

Schedule::command('crypto:maintain-subscriptions --limit=5')
    ->everyMinute()->runInBackground()->withoutOverlapping(30)->onOneServer()
    ->onFailure(fn () => Log::error('区块链订阅维护失败或等待历史接管，请检查监控记录；未自动放弃监听。'));

Schedule::command('crypto:verify-receipts --limit=25')
    ->everyMinute()->runInBackground()->withoutOverlapping(30)->onOneServer()
    ->onFailure(fn () => Log::error('区块链回调核验失败，请检查收件记录；未凭回调直接入账。'));

foreach (['bitcoin-mainnet', 'bitcoin-testnet', 'litecoin-mainnet', 'litecoin-testnet',
    'ethereum-mainnet', 'ethereum-sepolia', 'tron-mainnet', 'tron-shasta'] as $cryptoNetwork) {
    Schedule::command('crypto:scan-history '.$cryptoNetwork.' --limit=100')
        ->everyMinute()->runInBackground()->withoutOverlapping(30)->onOneServer()
        ->onFailure(fn () => Log::error('区块链历史扫描失败，请检查网络进度；不推进失败区块或自动放弃冷地址。', ['network' => $cryptoNetwork]));
}

Schedule::command('crypto:prepare-checkouts --limit=5')
    ->everyTenSeconds()->runInBackground()->withoutOverlapping(30)->onOneServer()
    ->onFailure(fn () => Log::error('Crypto checkout preparation failed.'));

Schedule::command('crypto:settle-payments --limit=100')
    ->everyMinute()->runInBackground()->withoutOverlapping(30)->onOneServer()
    ->onFailure(fn () => Log::error('区块链自动记账出现重试，请检查付款请求；不改写原始证据或法币余额。'));
