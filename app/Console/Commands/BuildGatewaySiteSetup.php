<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class BuildGatewaySiteSetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugins:gateway-setup {--from-stdin : 从标准输入读取一行回调密钥，默认使用平台的回调验签配置}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '为受信任收款站生成一次性私有初始化包，不发布到下载或更新接口';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $secret = $this->option('from-stdin') ? rtrim((string) fgets(STDIN), "\r\n") : config('payment_gateway.webhook_secret');
        if (! is_string($secret) || trim($secret) !== $secret || ! preg_match('/\A[\x20-\x7e]+\z/', $secret)) {
            $this->error('未配置有效的回调密钥，未生成初始化包。');

            return self::FAILURE;
        }

        $source = config('plugins.source_path').'/merchant/sscpay-gateway-aggregator';
        $directory = storage_path('app/gateway-provisioning');
        File::ensureDirectoryExists($directory, 0700);
        if (! chmod($directory, 0700) || ($temporary = tempnam($directory, 'setup-')) === false) {
            throw new RuntimeException('无法创建私有初始化目录。');
        }
        $path = $directory.'/sscpay-gateway-site-setup.zip';
        try {
            $zip = new ZipArchive;
            if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('无法创建初始化包。');
            }
            try {
                $template = File::get($source.'/site-setup.php.stub');
                $entry = str_replace('__CALLBACK_DEFAULT__', var_export($secret, true), $template, $replacements);
                if ($replacements !== 1 || ! $zip->addFromString('sscpay-gateway-site-setup/site-setup.php', $entry)
                    || ! $zip->addFile($source.'/src/Helpers/SiteCipher.php', 'sscpay-gateway-site-setup/SiteCipher.php')) {
                    throw new RuntimeException('初始化包内容不完整。');
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('初始化包写入失败。');
                }
            }
            if (! chmod($temporary, 0600) || ! rename($temporary, $path)) {
                throw new RuntimeException('无法保存私有初始化包。');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->info($path);

        return self::SUCCESS;
    }
}
