<?php

namespace Tests\Feature;

use App\Services\PluginCatalog;
use App\Services\PluginReleaseService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPluginReleasesTest extends TestCase
{
    private string $buildPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPath = sys_get_temp_dir().'/sscpay-plugin-sync-'.Str::uuid();
        config(['plugins.disk' => 'plugin-sync-test', 'plugins.build_path' => $this->buildPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->buildPath);
        parent::tearDown();
    }

    public function test_sync_publishes_all_plugins_and_the_public_updates_use_the_same_storage(): void
    {
        Storage::fake('plugin-sync-test');
        Http::preventStrayRequests();

        $this->artisan('plugins:sync')->expectsOutputToContain('插件发布存储：plugin-sync-test')->assertSuccessful();

        foreach (app(PluginCatalog::class)->all() as $slug => $plugin) {
            $this->get('/plugins/updates/v1/'.$slug)->assertOk()->assertJsonPath('version', $plugin['version']);
            Storage::disk('plugin-sync-test')->assertExists('plugin-releases/'.$slug.'/'.$plugin['version'].'/'.$plugin['archive_name']);
        }
        $gateway = app(PluginCatalog::class)->find(PluginCatalog::GATEWAY);
        $disk = Storage::disk('plugin-sync-test');
        $prefix = 'plugin-releases/'.$gateway['slug'];
        $path = $prefix.'/'.$gateway['version'].'/'.$gateway['archive_name'];
        $disk->assertExists([$path, $prefix.'/stable.json']);
        $this->assertSame('private', $disk->getVisibility($path));
        Http::assertNothingSent();
    }

    public function test_repeated_sync_preserves_the_published_metadata_and_package_bytes(): void
    {
        Storage::fake('plugin-sync-test');
        $this->freezeTime();
        $this->artisan('plugins:sync')->assertSuccessful();
        $disk = Storage::disk('plugin-sync-test');
        $original = [];
        foreach ($disk->allFiles() as $path) {
            $original[$path] = $disk->get($path);
        }
        $this->travel(2)->days();

        $this->artisan('plugins:sync')->assertSuccessful();

        foreach ($original as $path => $content) {
            $this->assertSame($content, $disk->get($path), $path);
        }
    }

    public function test_sync_refuses_a_public_disk_before_publishing_the_gateway_or_other_plugins(): void
    {
        $disk = Storage::fake('public');
        config(['plugins.disk' => 'public']);

        $this->artisan('plugins:sync')->expectsOutputToContain('requires a private release disk')->assertFailed();

        $this->assertSame([], $disk->allFiles());
    }

    public function test_sync_restores_a_missing_stable_index_and_package_from_the_same_source_version(): void
    {
        Storage::fake('plugin-sync-test');
        $this->artisan('plugins:sync')->assertSuccessful();
        $disk = Storage::disk('plugin-sync-test');
        $prefix = 'plugin-releases/sscpay-payment-paypal';
        $version = app(PluginCatalog::class)->find('sscpay-payment-paypal')['version'];
        $original = $disk->get($prefix.'/stable.json');
        $disk->delete([$prefix.'/stable.json', "{$prefix}/{$version}/sscpay-payment-paypal.zip"]);

        $this->artisan('plugins:sync')->assertSuccessful();

        $this->assertSame($original, $disk->get($prefix.'/stable.json'));
        $download = $this->get("/plugins/sscpay-payment-paypal/{$version}/download")->assertOk();
        $this->assertSame(json_decode($original, true)['sha256'], hash('sha256', $download->streamedContent()));
    }

    public function test_dry_run_builds_all_plugins_without_writing_to_the_release_storage(): void
    {
        Storage::fake('plugin-sync-test');

        $this->artisan('plugins:sync', ['--dry-run' => true])->expectsOutputToContain('发布预检通过')->assertSuccessful();

        $this->assertSame([], Storage::disk('plugin-sync-test')->allFiles());
        $catalog = app(PluginCatalog::class);
        foreach ([...$catalog->all(), $catalog->find(PluginCatalog::GATEWAY)] as $plugin) {
            $this->assertFileExists($this->buildPath.'/'.$plugin['slug'].'/'.$plugin['version'].'/'.$plugin['archive_name']);
        }
    }

    public function test_a_conflict_in_the_later_plugin_prevents_publishing_any_other_plugin(): void
    {
        Storage::fake('plugin-sync-test');
        $releases = app(PluginReleaseService::class);
        $build = $releases->build('sscpay-payment-paypal');
        $releases->publish($build);
        $disk = Storage::disk('plugin-sync-test');
        $path = 'plugin-releases/sscpay-payment-paypal/'.$build['release']['version'].'/release.json';
        $metadata = json_decode($disk->get($path), true);
        $metadata['sha256'] = str_repeat('b', 64);
        $disk->put($path, json_encode($metadata));
        $originalStable = $disk->get('plugin-releases/sscpay-payment-paypal/stable.json');

        $this->artisan('plugins:sync')->expectsOutputToContain('Increment the plugin version')->assertFailed();

        foreach (['sscpay-payment-creditcard', 'sscpay-opencart3', 'sscpay-opencart4', PluginCatalog::GATEWAY] as $slug) {
            $disk->assertMissing('plugin-releases/'.$slug.'/stable.json');
        }
        $this->assertSame([], $disk->allFiles('plugin-releases/sscpay-payment-creditcard'));
        $this->assertSame($originalStable, $disk->get('plugin-releases/sscpay-payment-paypal/stable.json'));
    }

    public function test_an_older_checkout_cannot_downgrade_the_stable_release_or_upload_old_packages(): void
    {
        Storage::fake('plugin-sync-test');
        $disk = Storage::disk('plugin-sync-test');
        $release = app(PluginReleaseService::class)->build('sscpay-payment-creditcard')['release'];
        $release['version'] = '9.0.0';
        $path = 'plugin-releases/sscpay-payment-creditcard/stable.json';
        $disk->put($path, json_encode($release));

        $this->artisan('plugins:sync')->expectsOutputToContain('older version')->assertFailed();

        $this->assertSame([$path], $disk->allFiles());
        $this->assertSame('9.0.0', json_decode($disk->get($path), true)['version']);
    }

    public function test_an_unsynchronized_protocol_blocks_all_publication(): void
    {
        Storage::fake('plugin-sync-test');
        $source = $this->buildPath.'/source';
        foreach (app(PluginCatalog::class)->all() as $plugin) {
            $directory = $source.'/'.$plugin['slug'];
            File::ensureDirectoryExists($directory);
            $plugin['protocol_hash'] = str_repeat('c', 64);
            File::put($directory.'/plugin.json', json_encode($plugin));
        }
        config(['plugins.source_path' => $source]);

        $this->artisan('plugins:sync')->expectsOutputToContain('尚未同步当前 API 协议')->assertFailed();

        $this->assertSame([], Storage::disk('plugin-sync-test')->allFiles());
    }
}
