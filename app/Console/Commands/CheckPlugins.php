<?php

namespace App\Console\Commands;

use App\Services\PluginCatalog;
use App\Support\PluginProtocol;
use Illuminate\Console\Command;

class CheckPlugins extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugins:check {--base= : 比较基线提交，协议变更时要求两个插件递增版本}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查电商插件版本与商户 API 协议同步状态';

    /**
     * Execute the console command.
     */
    public function handle(PluginProtocol $protocol, PluginCatalog $catalog): int
    {
        try {
            $protocol->check($catalog, $this->option('base') ?: null);
            $this->info('插件版本与 API '.$protocol::VERSION.' 协议检查通过。');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
