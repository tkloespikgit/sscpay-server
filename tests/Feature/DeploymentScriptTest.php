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
        $this->assertStringContainsString('npm ci --include=dev --engine-strict', $trace);
        $this->assertBefore('node --version', 'php artisan down', $trace);
        $this->assertStringContainsString('plugins:check --base='.str_repeat('a', 40), $trace);
        $this->assertBefore('filament:upgrade', 'config:cache', $trace);
        $this->assertBefore('--working-dir=plugins/merchant/sscpay-gateway-aggregator', 'plugins:sync --dry-run', $trace);
        $this->assertBefore('plugins:sync --dry-run', 'migrate --force', $trace);
        $this->assertBefore('migrate --force', 'db:seed --class=PermissionSeeder --force --no-interaction', $trace);
        $this->assertBefore('db:seed --class=PermissionSeeder --force --no-interaction', 'queue:restart', $trace);
        $this->assertBefore('migrate --force', 'plugins:sync --no-interaction', $trace);
        $this->assertBefore('migrate --force', 'crypto:check-addresses --initialize --no-interaction', $trace);
        $this->assertBefore('crypto:check-addresses --initialize --no-interaction', 'queue:restart', $trace);
        $this->assertBefore('plugins:sync --no-interaction', 'queue:restart', $trace);
        $this->assertBefore('route:cache', 'telegram:register-webhook --if-configured --no-interaction', $trace);
        $this->assertBefore('telegram:register-webhook --if-configured --no-interaction', 'artisan up', $trace);
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
            'gateway dependencies' => ['composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --working-dir=plugins/merchant/sscpay-gateway-aggregator', false],
            'plugin preflight' => ['php artisan plugins:sync --dry-run --no-interaction', false],
            'migration' => ['php artisan migrate --force --no-interaction', true],
            'permission catalog synchronization' => ['php artisan db:seed --class=PermissionSeeder --force --no-interaction', true],
            'crypto address check initialization' => ['php artisan crypto:check-addresses --initialize --no-interaction', true],
            'Telegram webhook registration' => ['php artisan telegram:register-webhook --if-configured --no-interaction', true],
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

    public static function userSwitchCommands(): array
    {
        return ['runuser' => ['runuser'], 'sudo' => ['sudo']];
    }

    #[DataProvider('userSwitchCommands')]
    public function test_root_startup_runs_composer_and_deployment_as_the_default_site_account(string $switch): void
    {
        $this->prepareDeployment();
        if ($switch === 'sudo') {
            File::delete($this->directory.'/bin/runuser');
        }

        $process = $this->deploy([], ['DEPLOY_TEST_UID' => '0', 'PATH' => $this->directory.'/bin']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $trace = File::get($this->directory.'/trace');
        $this->assertBefore($switch === 'sudo' ? 'sudo -n -H -u www-data --' : 'runuser -u www-data --', 'php artisan down', $trace);
        $this->assertStringContainsString('composer install --no-dev', $trace);
        $this->assertStringContainsString('切换到站点账号 www-data', $process->getOutput());
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/down');
    }

    public function test_root_startup_preserves_the_selected_tools_and_arguments_for_a_custom_site_account(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--no-pull'], ['DEPLOY_TEST_UID' => '0', 'DEPLOY_USER' => 'site-user']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $trace = File::get($this->directory.'/trace');
        $this->assertStringContainsString('runuser -u site-user --', $trace);
        $this->assertStringContainsString('composer install --no-dev', $trace);
        $this->assertStringContainsString('plugins:sync --no-interaction', $trace);
        $this->assertStringNotContainsString('git pull', $trace);
    }

    public function test_root_startup_without_a_user_switch_command_stops_before_changing_the_site(): void
    {
        $this->prepareDeployment();
        File::delete([$this->directory.'/bin/runuser', $this->directory.'/bin/sudo']);

        $process = $this->deploy([], ['DEPLOY_TEST_UID' => '0', 'PATH' => $this->directory.'/bin']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('未找到 runuser 或 sudo', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
        $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
    }

    public static function invalidDeploymentUsers(): array
    {
        return ['root' => ['root'], 'missing account' => ['missing-user']];
    }

    #[DataProvider('invalidDeploymentUsers')]
    public function test_invalid_site_accounts_stop_root_startup_before_any_site_changes(string $user): void
    {
        $this->prepareDeployment();

        $process = $this->deploy([], ['DEPLOY_TEST_UID' => '0', 'DEPLOY_USER' => $user]);

        $this->assertSame(1, $process->getExitCode());
        $trace = File::get($this->directory.'/trace');
        $this->assertStringNotContainsString('php artisan', $trace);
        $this->assertStringNotContainsString('composer install', $trace);
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
        $this->assertStringContainsString('指定已存在的非 root 站点账号', $process->getErrorOutput());
    }

    public function test_root_detects_the_site_from_runtime_owners_without_a_manual_user(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], ['DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_RUNTIME_UID' => '1002']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('自动识别站点账号：site-user', $process->getOutput());
        $this->assertStringContainsString('本站运行目录属主', $process->getOutput());
        $this->assertStringContainsString('runuser -u site-user --', File::get($this->directory.'/trace'));
    }

    public function test_conflicting_runtime_owners_stop_detection_before_switching_accounts(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy([], ['DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_CACHE_UID' => '1002']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('站点账号识别存在冲突', $process->getErrorOutput());
        $this->assertStringContainsString('bootstrap/cache -> UID 1002', $process->getErrorOutput());
        $this->assertStringNotContainsString('runuser -u', File::get($this->directory.'/trace'));
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
    }

    public function test_root_can_detect_a_single_php_fpm_worker_account_when_runtime_directories_are_root_owned(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], [
            'DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_RUNTIME_UID' => '0', 'DEPLOY_TEST_PROJECT_UID' => '0',
            'DEPLOY_TEST_FPM' => "0 php-fpm8.3\n1002 php-fpm8.3\n1002 php-fpm8.3",
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('自动识别站点账号：site-user（依据：PHP-FPM 进程）', $process->getOutput());
    }

    public function test_multiple_php_fpm_accounts_do_not_silently_select_another_sites_account(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], [
            'DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_RUNTIME_UID' => '0', 'DEPLOY_TEST_PROJECT_UID' => '0',
            'DEPLOY_TEST_FPM' => "1001 php-fpm8.2\n1002 php-fpm8.3",
        ]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('站点账号识别存在冲突（PHP-FPM 进程）', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
    }

    public function test_root_uses_the_project_owner_if_no_runtime_or_worker_owner_is_available(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], [
            'DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_RUNTIME_UID' => '0', 'DEPLOY_TEST_PROJECT_UID' => '1002',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('自动识别站点账号：site-user（依据：项目目录与 .env 属主）', $process->getOutput());
    }

    public function test_missing_account_evidence_does_not_fall_back_to_a_guessed_default_user(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], [
            'DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_RUNTIME_UID' => '0', 'DEPLOY_TEST_PROJECT_UID' => '0',
        ]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('未能识别非 root 站点账号', $process->getErrorOutput());
        $this->assertStringNotContainsString('runuser -u', File::get($this->directory.'/trace'));
    }

    public function test_project_owner_evidence_takes_precedence_over_another_sites_global_fpm_process(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], [
            'DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_RUNTIME_UID' => '0', 'DEPLOY_TEST_PROJECT_UID' => '1002',
            'DEPLOY_TEST_FPM' => '1001 php-fpm8.3',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('自动识别站点账号：site-user（依据：项目目录与 .env 属主）', $process->getOutput());
    }

    public function test_an_orphaned_owner_uid_is_reported_instead_of_selecting_another_account(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], ['DEPLOY_TEST_UID' => '0', 'DEPLOY_TEST_RUNTIME_UID' => '9999']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('UID 9999 没有对应的系统账号', $process->getErrorOutput());
    }

    public function test_check_only_finishes_without_a_lock_maintenance_or_deployment_commands(): void
    {
        $this->prepareDeployment();
        chmod($this->directory.'/.env', 0400);
        chmod($this->directory.'/bootstrap/cache/.gitignore', 0444);

        $process = $this->deploy(['--check']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $trace = File::get($this->directory.'/trace');
        $this->assertStringContainsString('账号与目录权限预检通过', $process->getOutput());
        $this->assertStringNotContainsString('php artisan', $trace);
        $this->assertStringNotContainsString('composer install', $trace);
        $this->assertStringNotContainsString('npm ci', $trace);
        $this->assertStringNotContainsString('git pull', $trace);
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/down');
        $this->assertDirectoryDoesNotExist($this->directory.'/storage/app/deploy-cache');
    }

    public static function inaccessiblePaths(): array
    {
        return [
            'Composer package directory' => ['vendor/package/nested', true],
            'npm package directory' => ['node_modules/package/nested', true],
            'Git objects directory' => ['.git/objects', true],
            'tracked source directory' => ['app', true],
            'existing deployment lock' => ['storage/framework/deploy.lock', false],
            'compiled configuration' => ['bootstrap/cache/config.php', false],
            'plugin build directory' => ['storage/app/plugin-builds/plugin/1.0.0', true],
            'local plugin index' => ['storage/app/private/plugin-releases/plugin/stable.json', false],
            'Filament generated CSS' => ['public/css/filament/existing.css', false],
            'npm cache directory' => ['storage/app/deploy-cache/npm', true],
            'npm cache append index' => ['storage/app/deploy-cache/npm/_cacache/index-v5/aa/index', false],
            'npm debug log' => ['storage/app/deploy-cache/npm/_logs/existing.log', false],
        ];
    }

    #[DataProvider('inaccessiblePaths')]
    public function test_inaccessible_paths_are_reported_before_maintenance_and_remain_untouched(string $relativePath, bool $isDirectory): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('此权限回归需要非 root 的实际测试进程。');
        }
        $this->prepareDeployment();
        $path = $this->directory.'/'.$relativePath;
        File::ensureDirectoryExists($isDirectory ? $path : dirname($path));
        if (! $isDirectory) {
            File::put($path, 'original artifact');
        }
        chmod($path, $isDirectory ? 0555 : 0444);

        try {
            $process = $this->deploy();

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString($relativePath, $process->getErrorOutput());
            $this->assertStringContainsString('权限', $process->getErrorOutput());
            $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
            $this->assertFileDoesNotExist($this->directory.'/storage/framework/down');
            if (! $isDirectory) {
                $this->assertSame('original artifact', File::get($path));
            }
        } finally {
            chmod($path, $isDirectory ? 0755 : 0644);
        }
    }

    public function test_an_unreadable_environment_file_is_reported_without_booting_artisan(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('此权限回归需要非 root 的实际测试进程。');
        }
        $this->prepareDeployment();
        chmod($this->directory.'/.env', 0000);

        try {
            $process = $this->deploy();

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('.env', $process->getErrorOutput());
            $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
        } finally {
            chmod($this->directory.'/.env', 0644);
        }
    }

    public function test_shared_runtime_symlinks_are_checked_for_existing_file_permissions(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('此权限回归需要非 root 的实际测试进程。');
        }
        $this->prepareDeployment();
        $shared = $this->directory.'/shared-cache';
        File::moveDirectory($this->directory.'/bootstrap/cache', $shared);
        symlink($shared, $this->directory.'/bootstrap/cache');
        File::put($shared.'/config.php', 'original cache');
        chmod($shared.'/config.php', 0444);

        try {
            $process = $this->deploy();

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('bootstrap/cache/config.php', $process->getErrorOutput());
            $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
            $this->assertSame('original cache', File::get($shared.'/config.php'));
            $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
        } finally {
            chmod($shared.'/config.php', 0644);
        }
    }

    public function test_a_regular_file_blocking_a_build_directory_is_reported_before_deployment(): void
    {
        $this->prepareDeployment();
        File::put($this->directory.'/node_modules', 'original artifact');

        $process = $this->deploy();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('node_modules', $process->getErrorOutput());
        $this->assertSame('original artifact', File::get($this->directory.'/node_modules'));
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
    }

    public function test_git_status_errors_stop_deployment_before_maintenance(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy([], ['DEPLOY_TEST_FAILURE' => 'git status --porcelain']);

        $this->assertSame(23, $process->getExitCode());
        $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
    }

    public function test_an_explicit_user_mismatch_cannot_be_ignored_when_started_without_root(): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], ['DEPLOY_USER' => 'site-user']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('与指定的站点账号 site-user 不一致', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
    }

    public static function nodeVersions(): array
    {
        return [
            'reported Node 18' => ['v18.19.1', false],
            'Node 20 below minimum' => ['v20.18.3', false],
            'Node 20 minimum' => ['v20.19.0', true],
            'Node 20 later patch' => ['v20.19.5', true],
            'unsupported Node 21' => ['v21.7.3', false],
            'Node 22 below minimum' => ['v22.11.0', false],
            'Node 22 minimum' => ['v22.12.0', true],
            'reported root Node 22' => ['v22.23.4', true],
            'Node 24' => ['v24.11.0', true],
            'Node 26' => ['v26.0.0', true],
            'prerelease' => ['v22.12.0-rc.1', false],
            'unknown version' => ['unknown', false],
        ];
    }

    #[DataProvider('nodeVersions')]
    public function test_node_compatibility_is_checked_before_maintenance(string $version, bool $supported): void
    {
        $this->prepareDeployment();

        $process = $this->deploy(['--check'], ['DEPLOY_TEST_NODE_VERSION' => $version]);

        $this->assertSame($supported ? 0 : 1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString($version, $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
        $this->assertDirectoryDoesNotExist($this->directory.'/storage/app/deploy-cache');
    }

    public function test_missing_node_stops_deployment_while_npm_is_present(): void
    {
        $this->prepareDeployment();
        File::delete($this->directory.'/bin/node');

        $process = $this->deploy([], ['PATH' => $this->directory.'/bin']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('无法使用 Node/npm 工具目录', $process->getErrorOutput());
        $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
    }

    public function test_node_preflight_failure_preserves_maintenance_left_by_an_earlier_failed_deployment(): void
    {
        $this->prepareDeployment();
        File::put($this->directory.'/storage/framework/down', 'existing maintenance');

        $process = $this->deploy([], ['DEPLOY_TEST_NODE_VERSION' => 'v18.19.1']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('existing maintenance', File::get($this->directory.'/storage/framework/down'));
        $this->assertStringNotContainsString('php artisan up', File::get($this->directory.'/trace'));
    }

    public function test_deployment_overrides_legacy_home_cache_settings_without_changing_the_old_cache(): void
    {
        $this->prepareDeployment();
        $legacyCache = $this->directory.'/storage/app/legacy-npm';
        File::ensureDirectoryExists($legacyCache);
        File::put($legacyCache.'/original', 'legacy cache');
        chmod($legacyCache, 0555);

        try {
            $process = $this->deploy([], ['npm_config_cache' => $legacyCache, 'NPM_CONFIG_CACHE' => $legacyCache]);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('npm 缓存：'.$this->directory.'/storage/app/deploy-cache/npm', $process->getOutput());
            $this->assertSame('legacy cache', File::get($legacyCache.'/original'));
            $this->assertDirectoryExists($this->directory.'/storage/app/deploy-cache/npm');
        } finally {
            chmod($legacyCache, 0755);
        }
    }

    public function test_read_only_npm_content_blobs_are_accepted_while_mutable_indexes_remain_writable(): void
    {
        $this->prepareDeployment();
        $cache = $this->directory.'/storage/app/deploy-cache/npm';
        File::ensureDirectoryExists($cache.'/_cacache/content-v2/sha512/aa');
        File::ensureDirectoryExists($cache.'/_cacache/index-v5/aa');
        File::put($cache.'/_cacache/content-v2/sha512/aa/blob', 'cached package');
        File::put($cache.'/_cacache/index-v5/aa/index', 'cache index');
        chmod($cache.'/_cacache/content-v2/sha512/aa/blob', 0444);

        $process = $this->deploy(['--check']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('cached package', File::get($cache.'/_cacache/content-v2/sha512/aa/blob'));
        $this->assertSame('cache index', File::get($cache.'/_cacache/index-v5/aa/index'));
    }

    public function test_site_node_tools_take_precedence_over_an_old_system_node_and_keep_npm_on_the_same_path(): void
    {
        $this->prepareDeployment();
        $tools = $this->directory.'/storage/app/deploy-tools/node/bin';
        $this->prepareNodeTools($tools);

        $process = $this->deploy([], ['DEPLOY_NODE_BIN' => '', 'DEPLOY_TEST_NODE_VERSION' => 'v18.19.1']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Node.js v22.23.4（'.realpath($tools).'/node）', $process->getOutput());
        $this->assertStringContainsString('npm 命令：'.realpath($tools).'/npm', $process->getOutput());
        $this->assertStringContainsString('npm ci --include=dev --engine-strict', File::get($this->directory.'/trace'));
    }

    public function test_root_handoff_keeps_an_explicitly_selected_accessible_node_directory(): void
    {
        $this->prepareDeployment();
        $tools = $this->directory.'/storage/app/custom-node/bin';
        $this->prepareNodeTools($tools);

        $process = $this->deploy(['--check'], [
            'DEPLOY_TEST_UID' => '0', 'DEPLOY_NODE_BIN' => $tools, 'DEPLOY_TEST_NODE_VERSION' => 'v18.19.1',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Node.js v22.23.4', $process->getOutput());
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/deploy.lock');
    }

    public function test_an_incomplete_node_directory_does_not_silently_fall_back_to_the_system_tools(): void
    {
        $this->prepareDeployment();
        $tools = $this->directory.'/storage/app/deploy-tools/node/bin';
        $this->prepareNodeTools($tools);
        File::delete($tools.'/npm');

        $process = $this->deploy([], ['DEPLOY_NODE_BIN' => '']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('无法使用 Node/npm 工具目录：'.$tools, $process->getErrorOutput());
        $this->assertStringNotContainsString('php artisan', File::get($this->directory.'/trace'));
    }

    private function prepareNodeTools(string $directory): void
    {
        File::ensureDirectoryExists($directory);
        File::put($directory.'/node', <<<'BASH'
#!/bin/bash
export DEPLOY_TEST_NODE_VERSION=v22.23.4
exec "$DEPLOY_TEST_DIRECTORY/bin/node" "$@"
BASH);
        File::put($directory.'/npm', <<<'BASH'
#!/bin/bash
if [[ "$(node --version)" != v22.23.4 ]]; then
    printf 'npm selected a different Node runtime\n' >&2
    exit 96
fi
exec "$DEPLOY_TEST_DIRECTORY/bin/npm" "$@"
BASH);
        chmod($directory.'/node', 0755);
        chmod($directory.'/npm', 0755);
    }

    private function prepareDeployment(): void
    {
        foreach (['app', 'bin', 'vendor', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $directory) {
            File::ensureDirectoryExists($this->directory.'/'.$directory);
        }
        File::copy(base_path('deploy.sh'), $this->directory.'/deploy.sh');
        File::copy(base_path('storage/framework/.gitignore'), $this->directory.'/storage/framework/.gitignore');
        File::copy(base_path('bootstrap/cache/.gitignore'), $this->directory.'/bootstrap/cache/.gitignore');
        File::ensureDirectoryExists($this->directory.'/storage/app');
        File::copy(base_path('storage/app/.gitignore'), $this->directory.'/storage/app/.gitignore');
        File::put($this->directory.'/.gitignore', File::get(base_path('.gitignore'))."\n/bin/\n/trace\n");
        File::put($this->directory.'/.env', 'APP_ENV=production');
        File::put($this->directory.'/app/Example.php', '<?php');
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
    node)
        if [[ "$1" == --version ]]; then
            printf '%s\n' "$DEPLOY_TEST_NODE_VERSION"
        else
            exit 93
        fi
        ;;
    id)
        case "$1" in
            -un|-nu)
                case "${2:-$DEPLOY_TEST_UID}" in
                    0) printf 'root\n' ;;
                    1001) printf 'www-data\n' ;;
                    1002) printf 'site-user\n' ;;
                    *) exit 1 ;;
                esac
                ;;
            -u)
                if [[ "$#" -eq 1 ]]; then
                    printf '%s\n' "$DEPLOY_TEST_UID"
                else
                    case "$2" in
                        root) printf '0\n' ;;
                        www-data) printf '1001\n' ;;
                        site-user) printf '1002\n' ;;
                        *) exit 1 ;;
                    esac
                fi
                ;;
        esac
        ;;
    stat)
        case "${!#}" in
            bootstrap/cache) printf '%s\n' "${DEPLOY_TEST_CACHE_UID:-$DEPLOY_TEST_RUNTIME_UID}" ;;
            "$DEPLOY_TEST_DIRECTORY"|.env) printf '%s\n' "$DEPLOY_TEST_PROJECT_UID" ;;
            *) printf '%s\n' "$DEPLOY_TEST_RUNTIME_UID" ;;
        esac
        ;;
    ps)
        printf '%s\n' "$DEPLOY_TEST_FPM"
        ;;
    runuser)
        if [[ "$2" == site-user ]]; then export DEPLOY_TEST_UID=1002; else export DEPLOY_TEST_UID=1001; fi
        shift 3
        exec "$@"
        ;;
    sudo)
        if [[ "$4" == site-user ]]; then export DEPLOY_TEST_UID=1002; else export DEPLOY_TEST_UID=1001; fi
        shift 5
        exec "$@"
        ;;
    git)
        case "$1" in
            status) exec "$DEPLOY_TEST_GIT" status --porcelain ;;
            rev-parse)
                case "$2" in
                    --absolute-git-dir|--git-common-dir) exec "$DEPLOY_TEST_GIT" "$@" ;;
                    *) printf '%s\n' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
                esac
                ;;
            ls-files) exec "$DEPLOY_TEST_GIT" "$@" ;;
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
    composer)
        if [[ "$DEPLOY_TEST_UID" -eq 0 ]]; then
            printf 'Composer was executed as root\n' >&2
            exit 94
        fi
        ;;
    npm)
        if [[ "${npm_config_cache:-}" != "$DEPLOY_TEST_DIRECTORY/storage/app/deploy-cache/npm" || "${NPM_CONFIG_CACHE:-}" != "$npm_config_cache" || ! -d "$npm_config_cache" ]]; then
            printf 'npm cache is not prepared for the site account\n' >&2
            exit 95
        fi
        ;;
    flock) ;;
    *) exit 92 ;;
esac
BASH;
        foreach (['site-php', 'composer', 'node', 'npm', 'git', 'flock', 'id', 'runuser', 'sudo', 'stat', 'ps'] as $tool) {
            $path = $this->directory.'/bin/'.$tool;
            File::put($path, $runner);
            chmod($path, 0755);
        }
        foreach (['bash', 'dirname', 'env', 'mkdir', 'rm', 'touch', 'find'] as $tool) {
            symlink((new ExecutableFinder)->find($tool), $this->directory.'/bin/'.$tool);
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
            'DEPLOY_TEST_UID' => '1001',
            'DEPLOY_TEST_RUNTIME_UID' => '1001',
            'DEPLOY_TEST_CACHE_UID' => '',
            'DEPLOY_TEST_PROJECT_UID' => '1001',
            'DEPLOY_TEST_FPM' => '',
            'DEPLOY_TEST_NODE_VERSION' => 'v24.11.0',
            'DEPLOY_NODE_BIN' => $this->directory.'/bin',
            'DEPLOY_USER' => '',
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
