<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\OrderSurchargeRecalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class RecalculateOrderSurcharge extends Command
{
    protected $signature = 'orders:recalculate-surcharge
                            {merchant : 商户 ID 或完整名称（重名时使用 ID）}
                            {--from= : 订单创建开始日期（Y-m-d，系统时区，含当天）}
                            {--to= : 订单创建结束日期（Y-m-d，系统时区，含当天）}
                            {--include-existing : 同时重算已有汇损的订单，默认只补未配置汇损的订单}
                            {--apply : 写入订单；不传时仅预览}
                            {--report= : 新建 JSON 审计报告路径，默认位于 storage/app/private/order-surcharge-reports}
                            {--details : 在终端显示逐单处理结果}';

    protected $description = '按最新汇损配置修复指定商户已入账订单的金额快照，汇总差额，不修改余额';

    public function handle(OrderSurchargeRecalculationService $service): int
    {
        try {
            $identifier = trim((string) $this->argument('merchant'));
            if ($identifier === '') {
                throw new \RuntimeException('请传入商户 ID 或完整名称。');
            }
            $merchants = Merchant::query()
                ->when(ctype_digit($identifier), fn ($query) => $query->whereKey($identifier), fn ($query) => $query->where('name', $identifier))
                ->limit(2)->get();
            if ($merchants->count() !== 1) {
                throw new \RuntimeException('商户不存在或名称不唯一，请使用明确的商户 ID。');
            }
            $merchant = $merchants->first();
            $from = $this->dateOption('from');
            $to = $this->dateOption('to');
            if ($from && $to && $from->gt($to)) {
                throw new \RuntimeException('--from 不能晚于 --to。');
            }
            $path = $this->option('report') ?: storage_path('app/private/order-surcharge-reports/'.Str::uuid().'.json');
            if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $path = base_path($path);
            }

            $this->info("商户：{$merchant->name}（ID: {$merchant->id}）");
            $this->line('保留订单历史市场汇率及原支付手续费金额；仅处理已入账且无退款/争议的订单。');
            $this->line('审计报告路径：'.$path);
            $report = $service->run($merchant->id, (bool) $this->option('apply'), $from, $to,
                (bool) $this->option('include-existing'), $path);

            $summary = $report['summary'];
            $this->info($report['mode'] === 'applied' ? '订单更新完成；商户余额和资金台账未修改。' : '预览完成，未修改数据库；加 --apply 才写入订单。');
            $this->line("最新汇损：{$report['settings']['type']} = {$report['settings']['value']}");
            $this->line("变更 {$summary['changed']} 笔；未变化 {$summary['unchanged']} 笔；跳过 {$summary['skipped']} 笔。");
            $this->table(['本次变更订单汇总（USD）', '金额'], [
                ['无汇损折算总额', $summary['no_surcharge_amount']],
                ['按最新汇损折算总额', $summary['new_converted_amount']],
                ['含汇损 - 无汇损', $summary['surcharge_difference']],
                ['修改前净入账总额', $summary['old_settlement_amount']],
                ['修改后净入账总额', $summary['new_settlement_amount']],
                ['本次净入账变化（手工调账参考）', $summary['settlement_difference']],
            ]);
            $this->line('差额为正表示增加余额，为负表示扣减余额；仅按本次成功写入的报告调账，勿重复操作。');

            $reasons = collect($report['orders'])->where('result', 'skipped')->countBy('reason');
            foreach ($reasons as $reason => $count) {
                $this->line("跳过 {$count} 笔：{$reason}");
            }
            if ($this->option('details')) {
                $this->table(['订单号', '结果', '净入账变化 USD', '说明'], collect($report['orders'])->map(fn ($row) => [
                    $row['order_no'], $row['result'], $row['settlement_difference'], $row['reason'] ?? '',
                ])->all());
            }
            $this->line('审计报告：'.$path);
            if ($report['mode'] === 'applied') {
                foreach ($report['stats_dates'] as $date) {
                    $this->line('请更新该日统计：php artisan order-stats:aggregate --date='.$date);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function dateOption(string $name): ?Carbon
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
            if ($date && $date->format('Y-m-d') === $value) {
                return $date;
            }
        } catch (Throwable) {
            // 统一输出明确的选项错误。
        }

        throw new \RuntimeException("--{$name} 必须是有效日期（Y-m-d）。");
    }
}
