<?php

namespace App\Console\Commands;

use App\Services\PluginReleaseService;
use Illuminate\Console\Command;

class SyncPluginReleases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugins:sync {--dry-run : 构建并检查全部插件，不写入发布存储}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '部署时构建并发布全部电商插件，检查协议、版本冲突与稳定版入口';

    /**
     * Execute the console command.
     */
    public function handle(PluginReleaseService $releases): int
    {
        try {
            $dryRun = (bool) $this->option('dry-run');
            $this->info('插件发布存储：'.config('plugins.disk'));
            foreach ($releases->sync($dryRun) as $release) {
                $this->info($release['slug'].' '.$release['version'].($dryRun ? ' 发布预检通过。' : ' 发布同步完成。'));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
