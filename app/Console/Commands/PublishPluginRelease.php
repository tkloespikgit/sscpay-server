<?php

namespace App\Console\Commands;

use App\Services\PluginReleaseService;
use Illuminate\Console\Command;

class PublishPluginRelease extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugins:publish {slug : 插件标识} {--tag= : 插件发布 tag}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '构建插件并发布到配置的插件存储，成功后更新稳定版入口';

    /**
     * Execute the console command.
     */
    public function handle(PluginReleaseService $releases): int
    {
        try {
            $build = $releases->build($this->argument('slug'), $this->option('tag') ?: null);
            $releases->publish($build);
            $this->info($build['release']['slug'].' '.$build['release']['version'].' 已发布到 '.config('plugins.disk').'。');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
