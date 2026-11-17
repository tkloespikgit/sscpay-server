<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentScriptTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/sscpay-deploy-test-'.Str::uuid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_deployment_publishes_plugins_before_reopening_and_caches_after_filament_upgrade(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $trace = File::get($this->directory.'/trace');
        $this->assertStringContainsString('git pull --ff-only', $trace);
        $this->assertStringContainsString('composer install --no-dev', $trace);
        $this->assertStringContainsString('npm run build', $trace);
        $this->assertStringContainsString('plugins:check --base='.str_repeat('a', 40), $trace);
        $this->assertBefore('filament:upgrade', 'config:cache', $trace);
        $this->assertBefore('plugins:sync --dry-run', 'migrate --force', $trace);
        $this->assertBefore('migrate --force', 'plugins:sync --no-interaction', $trace);
        $this->assertBefore('plugins:sync --no-interaction', 'queue:restart', $trace);
        $this->assertBefore('queue:restart', 'artisan up', $trace);
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/down');
        $this->assertStringContainsString('部署完成', $process->getOutput());
    }

    public function test_no_pull_deploys_the_checked_out_code_and_still_synchronizes_plugins(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--no-pull']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $trace = File::get($this->directory.'/trace');
        $this->assertStringNotContainsString('git pull', $trace);
        $this->assertStringContainsString('plugins:sync --no-interaction', $trace);
    }

    public static function failures(): array
    {
        return [
            'Git update' => ['git pull --ff-only', false],
            'frontend build' => ['npm run build', false],
            'plugin preflight' => ['php artisan plugins:sync --dry-run --no-interaction', false],
            'migration' => ['php artisan migrate --force --no-interaction', true],
            'plugin upload' => ['php artisan plugins:sync --no-interaction', true],
            'queue restart' => ['php artisan queue:restart --no-interaction', true],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failed_step_stops_deployment_and_keeps_maintenance_mode(string $failure, bool $migrationStarted): void
    {
        $this->prepareDeployment();

        $process = $this->deploy([], ['DEPLOY_TEST_FAILURE' => $failure]);

        $this->assertSame(23, $process->getExitCode(), $process->getErrorOutput());
        $trace = File::get($this->directory.'/trace');
        $this->assertStringNotContainsString('php artisan up', $trace);
        $this->assertSame($migrationStarted, str_contains($trace, 'migrate --force'));
        $this->assertFileExists($this->directory.'/storage/framework/down');
        $this->assertStringContainsString('站点保持维护模式', $process->getErrorOutput());
        $this->assertStringNotContainsString('部署完成', $process->getOutput());
    }

    public function test_local_changes_block_deployment_before_any_artisan_command(): void
    {
        $this->prepareDeployment();
        File::put($this->directory.'/local-change.php', '<?php // Uncommitted work');

        $process = $this->deploy();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
        $this->assertStringContainsString('工作区存在未提交文件', $process->getErrorOutput());
        $this->assertSame('<?php // Uncommitted work', File::get($this->directory.'/local-change.php'));
    }

    public function test_an_existing_deployment_lock_blocks_all_site_changes(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy([], ['DEPLOY_TEST_FAILURE' => 'flock -n 9']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
        $this->assertStringContainsString('另一个部署正在执行', $process->getErrorOutput());
    }

    private function prepareDeployment(): void
    {
        foreach (['bin', 'vendor', 'storage/framework'] as $directory) {
            File::ensureDirectoryExists($this->directory.'/'.$directory);
        }
        File::copy(base_path('deploy.sh'), $this->directory.'/deploy.sh');
        File::copy(base_path('storage/framework/.gitignore'), $this->directory.'/storage/framework/.gitignore');
        File::put($this->directory.'/.gitignore', File::get(base_path('.gitignore'))."\n/bin/\n/trace\n");
        File::put($this->directory.'/.env', 'APP_ENV=production');
        File::put($this->directory.'/vendor/autoload.php', '<?php');
        $runner = <<<'BASH'
#!/bin/bash
set -eu
tool="${0##*/}"
if [[ "$tool" == site-php ]]; then
    tool=php
    if [[ "$1" != artisan ]]; then
        tool=composer
        shift
    fi
fi
action="$tool $*"
printf '%s\n' "$action" >> "$DEPLOY_TEST_DIRECTORY/trace"
if [[ "$action" == "${DEPLOY_TEST_FAILURE:-}" ]]; then
    exit 23
fi
case "$tool" in
    git)
        case "$1" in
            status) exec "$DEPLOY_TEST_GIT" status --porcelain ;;
            rev-parse) printf '%s\n' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
            pull) ;;
            *) exit 91 ;;
        esac
        ;;
    php)
        case "$2" in
            down) touch "$DEPLOY_TEST_DIRECTORY/storage/framework/down" ;;
            up) rm "$DEPLOY_TEST_DIRECTORY/storage/framework/down" ;;
        esac
        ;;
    composer|npm|flock) ;;
    *) exit 92 ;;
esac
BASH;
        foreach (['site-php', 'composer', 'npm', 'git', 'flock'] as $tool) {
            $path = $this->directory.'/bin/'.$tool;
            File::put($path, $runner);
            chmod($path, 0755);
        }
        $git = (new ExecutableFinder)->find('git');
        foreach ([['init', '-q'], ['add', '.'], ['-c', 'user.name=Deployment Tests', '-c', 'user.email=deploy@example.test', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Deployment fixture']] as $arguments) {
            (new Process([$git, ...$arguments], $this->directory))->mustRun();
        }
    }

    private function deploy(array $arguments = [], array $environment = []): Process
    {
        $process = new Process(['bash', $this->directory.'/deploy.sh', ...$arguments], $this->directory, [
            'PATH' => $this->directory.'/bin:'.getenv('PATH'),
            'DEPLOY_PHP' => $this->directory.'/bin/site-php',
            'DEPLOY_COMPOSER' => $this->directory.'/bin/composer',
            'DEPLOY_TEST_DIRECTORY' => $this->directory,
            'DEPLOY_TEST_GIT' => (new ExecutableFinder)->find('git'),
            'DEPLOY_TEST_FAILURE' => '',
            ...$environment,
        ]);
        $process->run();

        return $process;
    }

    private function assertBefore(string $earlier, string $later, string $trace): void
    {
        $this->assertStringContainsString($earlier, $trace);
        $this->assertStringContainsString($later, $trace);
        $this->assertLessThan(strpos($trace, $later), strpos($trace, $earlier));
    }
}
