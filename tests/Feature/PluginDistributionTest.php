<?php

namespace Tests\Feature;

use App\Services\PluginCatalog;
use App\Services\PluginReleaseService;
use App\Support\PluginProtocol;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class PluginDistributionTest extends TestCase
{
    private string $buildPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildPath = sys_get_temp_dir().'/sscpay-plugin-test-'.Str::uuid();
        config(['plugins.disk' => 'plugin-test', 'plugins.build_path' => $this->buildPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->buildPath);
        parent::tearDown();
    }

    public static function slugs(): array
    {
        return ['PayPal' => ['sscpay-payment-paypal'], 'Credit card' => ['sscpay-payment-creditcard']];
    }

    #[DataProvider('slugs')]
    public function test_build_and_publish_produce_an_installable_immutable_package_and_public_update(string $slug): void
    {
        Storage::fake('plugin-test');
        Http::preventStrayRequests();
        $releases = app(PluginReleaseService::class);
        $build = $releases->build($slug, 'plugins/'.$slug.'/v1.1.0');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($build['zip']));
        $this->assertGreaterThan(15, $zip->numFiles);
        $this->assertNotFalse($zip->getFromName($slug.'/'.$slug.'.php'));
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $this->assertStringStartsWith($slug.'/', $name);
            $this->assertDoesNotMatchRegularExpression('/(?:\.git|\.env|plugin\.json|README|artisan|tests\/)/', $name);
        }
        $zip->close();
        $releases->publish($build);
        $releases->publish($releases->build($slug));
        Storage::disk('plugin-test')->assertExists('plugin-releases/'.$slug.'/1.1.0/'.$slug.'.zip');
        $this->get(route('plugins.update', ['slug' => $slug]))->assertOk()
            ->assertJsonPath('slug', $slug)->assertJsonPath('version', '1.1.0')
            ->assertJsonPath('api_version', '2.0')
            ->assertJsonPath('download_url', route('plugins.download', ['slug' => $slug, 'version' => '1.1.0']));
        $download = $this->get(route('plugins.download', ['slug' => $slug, 'version' => '1.1.0']))->assertOk();
        $this->assertSame($build['release']['sha256'], hash('sha256', $download->streamedContent()));
        $download->assertHeader('X-Checksum-Sha256', $build['release']['sha256']);
        Http::assertNothingSent();
    }

    public function test_unpublished_packages_unknown_plugins_and_path_traversal_are_not_downloadable(): void
    {
        Storage::fake('plugin-test');
        $this->get('/plugins/updates/v1/sscpay-payment-paypal')->assertNotFound();
        $this->get('/plugins/updates/v1/unknown-plugin')->assertNotFound();
        $this->get('/plugins/sscpay-payment-paypal/1.1.0/download')->assertNotFound();
        $this->get('/plugins/sscpay-payment-paypal/latest/download')->assertNotFound();
        $this->get('/plugins/../.env/1.1.0/download')->assertNotFound();
        $this->get('/plugins')->assertOk()->assertSee('SSCPay Payment')->assertDontSee('/1.1.0/download');
        Storage::disk('plugin-test')->assertMissing('plugin-releases/sscpay-payment-paypal/stable.json');
    }

    public function test_homepage_and_download_center_link_to_published_plugins_and_v2_documentation(): void
    {
        Storage::fake('plugin-test');
        $releases = app(PluginReleaseService::class);
        $releases->publish($releases->build('sscpay-payment-paypal'));
        $this->get('/')->assertOk()->assertSee(route('plugins.index'), false);
        $this->get('/plugins')->assertOk()->assertSee('PayPal')->assertSee('Credit Card')
            ->assertSee(route('plugins.download', ['slug' => 'sscpay-payment-paypal', 'version' => '1.1.0']), false)
            ->assertSee(route('api.documentation', ['version' => '2.0']), false);
    }

    public function test_published_version_cannot_be_overwritten_with_different_bytes(): void
    {
        Storage::fake('plugin-test');
        $releases = app(PluginReleaseService::class);
        $build = $releases->build('sscpay-payment-paypal');
        $releases->publish($build);
        $build['release']['sha256'] = str_repeat('a', 64);
        try {
            $releases->publish($build);
            $this->fail('A published version was overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already published', $exception->getMessage());
        }
        $this->assertNotSame(str_repeat('a', 64), $releases->latest('sscpay-payment-paypal')['sha256']);
    }

    public function test_failed_upload_does_not_advertise_an_incomplete_release(): void
    {
        $releases = app(PluginReleaseService::class);
        $build = $releases->build('sscpay-payment-paypal');
        $disk = \Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->with('plugin-test')->andReturn($disk);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $disk->shouldReceive('put')->once()->withArgs(fn (string $path, mixed $stream): bool => str_ends_with($path, '.zip') && is_resource($stream))->andReturn(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Uploading the plugin release failed');
        $releases->publish($build);
    }

    public function test_the_same_package_bytes_cannot_reuse_a_version_for_a_new_protocol(): void
    {
        Storage::fake('plugin-test');
        $releases = app(PluginReleaseService::class);
        $build = $releases->build('sscpay-payment-creditcard');
        $releases->publish($build);
        $build['release']['protocol_hash'] = str_repeat('b', 64);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Increment the plugin version');
        $releases->publish($build);
    }

    public function test_build_command_rejects_a_tag_for_another_plugin(): void
    {
        $this->artisan('plugins:build', ['slug' => 'sscpay-payment-paypal', '--tag' => 'plugins/sscpay-payment-creditcard/v1.1.0'])
            ->expectsOutputToContain('Tag does not match')->assertFailed();
        $this->artisan('plugins:check')->assertSuccessful();
    }

    public function test_protocol_changes_block_distribution_until_both_plugins_acknowledge_them(): void
    {
        $source = $this->buildPath.'/source';
        File::ensureDirectoryExists($source);
        foreach (app(PluginCatalog::class)->all() as $plugin) {
            File::ensureDirectoryExists($source.'/'.$plugin['slug']);
            $plugin['protocol_hash'] = 'old-protocol';
            File::put($source.'/'.$plugin['slug'].'/plugin.json', json_encode($plugin));
        }
        config(['plugins.source_path' => $source]);
        $this->artisan('plugins:check')->expectsOutputToContain('尚未同步当前 API 协议')->assertFailed();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', app(PluginProtocol::class)->fingerprint());
    }

    public function test_changed_protocol_requires_both_plugin_versions_to_advance_against_the_git_base(): void
    {
        $originalBase = base_path();
        $fixture = $this->buildPath.'/repository';
        $plugins = app(PluginCatalog::class)->all();
        foreach (app(PluginProtocol::class)->files() as $file) {
            File::ensureDirectoryExists(dirname($fixture.'/'.$file));
            File::copy(base_path($file), $fixture.'/'.$file);
        }
        foreach ($plugins as $plugin) {
            $directory = $fixture.'/plugins/wordpress/'.$plugin['slug'];
            File::ensureDirectoryExists($directory);
            File::copy($plugin['path'].'/'.$plugin['slug'].'.php', $directory.'/'.$plugin['slug'].'.php');
            $plugin['protocol_hash'] = 'previous-protocol';
            File::put($directory.'/plugin.json', json_encode($plugin));
        }
        foreach ([['git', 'init', '-q'], ['git', 'add', '.'], ['git', '-c', 'user.name=Plugin Tests', '-c', 'user.email=plugins@example.test', 'commit', '-qm', 'Protocol baseline']] as $command) {
            (new Process($command, $fixture))->mustRun();
        }
        $this->app->setBasePath($fixture);
        config(['plugins.source_path' => $fixture.'/plugins/wordpress']);
        try {
            foreach ($plugins as $slug => $plugin) {
                File::put($fixture.'/plugins/wordpress/'.$slug.'/plugin.json', json_encode($plugin));
            }
            $this->artisan('plugins:check', ['--base' => 'HEAD'])->expectsOutputToContain('必须递增版本')->assertFailed();
            foreach (['sscpay-payment-creditcard', 'sscpay-payment-paypal'] as $slug) {
                $directory = $fixture.'/plugins/wordpress/'.$slug;
                $plugin = $plugins[$slug];
                $plugin['version'] = '1.1.1';
                File::put($directory.'/plugin.json', json_encode($plugin));
                File::put($directory.'/'.$slug.'.php', str_replace('1.1.0', '1.1.1', File::get($directory.'/'.$slug.'.php')));
                if ($slug === 'sscpay-payment-creditcard') {
                    $this->artisan('plugins:check', ['--base' => 'HEAD'])->expectsOutputToContain('sscpay-payment-paypal 必须递增版本')->assertFailed();
                }
            }
            $this->artisan('plugins:check', ['--base' => 'HEAD'])->assertSuccessful();
        } finally {
            $this->app->setBasePath($originalBase);
        }
    }
}
