<?php

namespace App\Console\Commands;

use App\Services\PluginReleaseService;
use Illuminate\Console\Command;

class BuildPluginRelease extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugins:build {slug : 插件标识} {--tag= : 插件发布 tag}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '构建可直接安装的电商插件 ZIP 与发布清单';

    /**
     * Execute the console command.
     */
    public function handle(PluginReleaseService $releases): int
    {
        try {
            $build = $releases->build($this->argument('slug'), $this->option('tag') ?: null);
            $this->info($build['zip']);
            $this->line($build['manifest']);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
