<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 数据库备份并上传阿里云 OSS（4.8 / 8.5 节）。
 * 建议调度：$schedule->command('db:backup:upload')->everySixHours();
 *
 * 数据库密码从 .env 读取（DB_PASSWORD），不硬编码在命令里。
 */
class BackupDatabaseToOss extends Command
{
    protected $signature = 'db:backup:upload';

    protected $description = '导出数据库、gzip 压缩后上传到阿里云 OSS，并清理本地临时文件';

    public function handle(): int
    {
        $filename = now()->format('Y-m-d_H-i-s').'.sql';
        $localSqlPath = storage_path('app/backups/'.$filename);
        $localGzPath = $localSqlPath.'.gz';

        if (! is_dir(dirname($localSqlPath))) {
            mkdir(dirname($localSqlPath), 0755, true);
        }

        $dumped = $this->dumpDatabase($localSqlPath);

        if (! $dumped) {
            $this->error('mysqldump failed.');

            return self::FAILURE;
        }

        $this->gzipFile($localSqlPath, $localGzPath);
        @unlink($localSqlPath);

        $ossPath = 'backups/'.basename($localGzPath);
        Storage::disk('oss')->put($ossPath, file_get_contents($localGzPath));

        @unlink($localGzPath);

        $this->info("Backup uploaded to oss://{$ossPath}");

        return self::SUCCESS;
    }

    private function dumpDatabase(string $outputPath): bool
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $command = sprintf(
            'mysqldump -h%s -P%s -u%s %s %s > %s 2>/dev/null',
            escapeshellarg($config['host']),
            escapeshellarg((string) $config['port']),
            escapeshellarg($config['username']),
            $config['password'] !== '' ? '-p'.escapeshellarg($config['password']) : '',
            escapeshellarg($config['database']),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $exitCode);

        return $exitCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0;
    }

    private function gzipFile(string $source, string $destination): void
    {
        $sourceHandle = fopen($source, 'rb');
        $destHandle = gzopen($destination, 'wb9');

        while (! feof($sourceHandle)) {
            gzwrite($destHandle, fread($sourceHandle, 1024 * 512));
        }

        fclose($sourceHandle);
        gzclose($destHandle);
    }
}
