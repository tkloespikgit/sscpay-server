<?php

namespace App\Console\Commands;

use App\Models\LogisticsImportTask;
use App\Models\LogisticsImportTaskRecord;
use Illuminate\Console\Command;

/**
 * 清理过期的物流上传记录（业务确认保留 90 天）。
 *
 * 为什么是物理删除而不是软删除：软删除只把任务表的 deleted_at 填上，明细表
 * (logistics_import_task_records) 一行都不会少——一次导入上万行，真正撑大库的是
 * 明细表。保留期的意义就是控制明细体量，所以这里连明细一起删。
 *
 * 已软删的任务（withTrashed）也在清理范围内，否则它们会永远留在表里占索引空间。
 *
 * 分批删除的原因：一条 DELETE ... WHERE created_at < ? 在大表上会长时间持锁，
 * 这里每批 200 条，批间释放锁，避免正在跑的导入任务被堵住。
 */
class PruneLogisticsImportTasks extends Command
{
    protected $signature = 'logistics-imports:prune
                            {--days=90 : 保留最近多少天的记录（1-3650）}
                            {--dry-run : 只统计将被删除的数量，不实际删除}';

    protected $description = '清理超过保留期的物流上传记录及其逐行明细';

    public function handle(): int
    {
        $days = (string) $this->option('days');

        if (! ctype_digit($days) || (int) $days < 1 || (int) $days > 3650) {
            $this->error('--days 必须在 1 至 3650 之间。');

            return self::INVALID;
        }

        $cutoff = now()->subDays((int) $days);

        $baseQuery = fn () => LogisticsImportTask::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('created_at', '<', $cutoff);

        $total = $baseQuery()->count();

        if ($this->option('dry-run')) {
            $this->info("将删除 {$total} 条 {$cutoff->toDateTimeString()} 之前的导入记录（试运行，未实际删除）。");

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('没有需要清理的导入记录。');

            return self::SUCCESS;
        }

        $deleted = 0;

        while (true) {
            $ids = $baseQuery()->orderBy('id')->limit(200)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // 明细显式删除：任务表与明细表之间虽然有 cascadeOnDelete，但那是物理外键
            // 行为，测试环境（SQLite :memory:）不保证启用 foreign_keys，显式删一次
            // 保证两种环境行为一致，也不依赖数据库配置。
            LogisticsImportTaskRecord::query()
                ->withoutGlobalScopes()
                ->whereIn('task_id', $ids)
                ->delete();

            LogisticsImportTask::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->whereIn('id', $ids)
                ->forceDelete();

            $deleted += $ids->count();
        }

        $this->info("已清理 {$deleted} 条 {$cutoff->toDateTimeString()} 之前的导入记录（含逐行明细）。");

        return self::SUCCESS;
    }
}
