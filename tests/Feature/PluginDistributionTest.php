<?php

namespace Tests\Feature;

use App\Services\PluginCatalog;
use App\Services\PluginReleaseService;
use App\Support\PluginProtocol;
use DOMDocument;
use DOMXPath;
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
    private const WORDPRESS_VERSION = '1.2.0';

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
        $version = self::WORDPRESS_VERSION;
        $build = $releases->build($slug, 'plugins/'.$slug.'/v'.$version);
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
        Storage::disk('plugin-test')->assertExists("plugin-releases/{$slug}/{$version}/{$slug}.zip");
        $this->get(route('plugins.update', ['slug' => $slug]))->assertOk()
            ->assertJsonPath('slug', $slug)->assertJsonPath('version', $version)
            ->assertJsonPath('api_version', '2.0')
            ->assertJsonPath('download_url', route('plugins.download', ['slug' => $slug, 'version' => $version]));
        $download = $this->get(route('plugins.download', ['slug' => $slug, 'version' => $version]))->assertOk();
        $this->assertSame($build['release']['sha256'], hash('sha256', $download->streamedContent()));
        $download->assertHeader('X-Checksum-Sha256', $build['release']['sha256']);
        Http::assertNothingSent();
    }

    public function test_unpublished_packages_unknown_plugins_and_path_traversal_are_not_downloadable(): void
    {
        Storage::fake('plugin-test');
        $this->get('/plugins/updates/v1/sscpay-payment-paypal')->assertNotFound();
        $this->get('/plugins/updates/v1/unknown-plugin')->assertNotFound();
        $this->get('/plugins/sscpay-payment-paypal/'.self::WORDPRESS_VERSION.'/download')->assertNotFound();
        $this->get('/plugins/sscpay-payment-paypal/latest/download')->assertNotFound();
        $this->get('/plugins/../.env/1.1.0/download')->assertNotFound();
        $this->get('/plugins')->assertOk()->assertSee('SSCPay Payment')->assertDontSee('/'.self::WORDPRESS_VERSION.'/download');
        Storage::disk('plugin-test')->assertMissing('plugin-releases/sscpay-payment-paypal/stable.json');
    }

    public function test_the_gateway_zip_can_load_its_wordpress_classes_and_stays_out_of_the_storefront_catalog(): void
    {
        Storage::fake('plugin-test');
        $releases = app(PluginReleaseService::class);
        $slug = PluginCatalog::GATEWAY;
        $built = $releases->build($slug);
        $releases->publish($built);
        $receivingPlugins = [$slug => $built['release']['version'], 'sscpay-products-api' => '2.0.0'];

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($built['zip']));
        foreach ([$slug.'.php', 'uninstall.php', 'assets/css/admin.css', 'vendor/autoload.php', 'vendor/stripe/stripe-php/LICENSE'] as $file) {
            $this->assertNotFalse($zip->locateName($slug.'/'.$file), $file);
        }
        foreach (['wp-config.php', '.env', 'composer.lock', 'site-setup.php', 'site-setup.php.stub', 'tests/product-catalog.php', 'src/Frontend/Tpl/checkout-01-paper.html'] as $file) {
            $this->assertFalse($zip->locateName($slug.'/'.$file), $file);
        }
        $installPath = $this->buildPath.'/gateway-install';
        $this->assertTrue($zip->extractTo($installPath));
        $zip->close();
        $bootstrap = <<<'PHP'
define('ABSPATH', __DIR__ . '/');
require $argv[1];
exit(class_exists(\PaymentGatewayAggregator\API\Controllers\ProductCatalogController::class)
    && class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)
    && is_readable(\Stripe\Stripe::getCABundlePath()) ? 0 : 1);
PHP;
        $process = new Process([PHP_BINARY, '-r', $bootstrap, $installPath.'/'.$slug.'/vendor/autoload.php']);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());

        $this->get('/plugins')->assertOk()->assertViewHas('plugins', fn (array $plugins): bool => array_intersect(
            array_keys($receivingPlugins),
            array_column($plugins, 'slug'),
        ) === []);

        foreach ($receivingPlugins as $slug => $version) {
            $this->get("/plugins/updates/v1/{$slug}")->assertNotFound();
            $this->get("/plugins/{$slug}/{$version}/download")->assertNotFound();
        }
    }

    public function test_anonymous_gateway_install_and_update_only_serve_public_versions_over_https(): void
    {
        Storage::fake('plugin-test');
        $feed = 'https://updates.example.test/plugins/gateway/updates/v1';
        $installer = 'https://updates.example.test/plugins/gateway/download';
        $releases = app(PluginReleaseService::class);
        $this->get($feed)->assertNotFound();
        $this->get($installer)->assertNotFound();
        $build = $releases->build(PluginCatalog::GATEWAY);
        $legacy = $build;
        $legacy['release']['version'] = '1.15.1';
        $releases->publish($legacy);
        $this->get($feed)->assertNotFound();
        $this->get($installer)->assertNotFound();

        $releases->publish($build);
        $version = $build['release']['version'];
        $package = $feed.'/'.$version.'/download';
        $this->get($feed.'?installed_version=1.15.0')->assertStatus(409)->assertJsonMissingPath('download_url');
        $this->get($feed.'?installed_version=1.15.0&site_prepared=1')->assertOk()->assertJsonPath('download_url', $package);
        $this->get($feed.'?installed_version='.$version)->assertOk()->assertJsonPath('download_url', $package);
        $manifest = $this->get($feed)->assertOk()
            ->assertJsonPath('slug', PluginCatalog::GATEWAY)
            ->assertJsonPath('version', $version)
            ->assertJsonPath('download_url', $package)
            ->assertHeader('Cache-Control', 'max-age=60, public');
        $this->assertSame([], $manifest->headers->getCookies());
        foreach ([$installer, $package] as $url) {
            $download = $this->get($url)->assertOk()->assertHeader('X-Checksum-Sha256', $build['release']['sha256']);
            $this->assertSame([], $download->headers->getCookies());
            $this->assertSame($build['release']['sha256'], hash('sha256', $download->streamedContent()));
        }
        foreach ([$installer, $feed, $package] as $url) {
            $this->get(str_replace('https://', 'http://', $url))->assertNotFound();
        }
        $this->get($feed.'/1.15.1/download')->assertNotFound();
        $this->get($feed.'/999.999.999/download')->assertNotFound();
        $unmarked = $build;
        $unmarked['release']['version'] = '99.0.0';
        unset($unmarked['release']['public_update']);
        $releases->publish($unmarked);
        $this->get($feed)->assertNotFound();
        $this->get($feed.'/99.0.0/download')->assertNotFound();
    }

    public function test_private_setup_provisions_the_site_and_legacy_cipher_but_never_enters_the_public_update(): void
    {
        Storage::fake('plugin-test');
        $this->app->useStoragePath($this->buildPath);
        $privatePath = storage_path('app/gateway-provisioning/sscpay-gateway-site-setup.zip');
        config(['payment_gateway.webhook_secret' => '']);
        $this->artisan('plugins:gateway-setup')->assertFailed();
        $this->assertFileDoesNotExist($privatePath);

        $secret = 'synthetic-private-default-for-setup-test';
        config(['payment_gateway.webhook_secret' => $secret]);
        $this->artisan('plugins:gateway-setup')->doesntExpectOutputToContain($secret)->assertSuccessful();
        $this->assertSame(0600, fileperms($privatePath) & 0777);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($privatePath));
        $this->assertSame(2, $zip->numFiles);
        $this->assertTrue($zip->extractTo($this->buildPath));
        $zip->close();

        $public = app(PluginReleaseService::class)->build(PluginCatalog::GATEWAY);
        $this->assertTrue($zip->open($public['zip']));
        $unsafeEntries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);
            $contents = $zip->getFromIndex($index);
            if (str_contains($contents, $secret) || (! str_contains($entry, '/vendor/') && preg_match(
                '~\bdefine\s*\(\s*[\'\"](?:PGA_ENCRYPTION_KEY|PGA_DEFAULT_OUTBOUND_SHARED_SECRET|PGA_GITHUB_TOKEN)[\'\"]\s*,\s*[\'\"][^\'\"]+~i',
                $contents,
            ))) {
                $unsafeEntries[] = $entry;
            }
        }
        $this->assertSame([], $unsafeEntries, 'The public archive must not contain the private initializer or baked-in credentials.');
        $this->assertTrue($zip->extractTo($this->buildPath));
        $zip->close();
        foreach (['legacy', 'upgraded'] as $stage) {
            $process = new Process([PHP_BINARY, base_path('plugins/merchant/sscpay-gateway-aggregator/tests/fixtures/site-setup.php'), $stage, $this->buildPath]);
            $this->assertSame(0, $process->run(), $process->getErrorOutput());
        }
        Storage::disk('plugin-test')->assertDirectoryEmpty('');
    }

    public function test_homepage_and_download_center_link_to_published_plugins_and_v2_documentation(): void
    {
        Storage::fake('plugin-test');
        $releases = app(PluginReleaseService::class);
        $releases->publish($releases->build('sscpay-payment-paypal'));
        $this->get('/')->assertOk()->assertSee(route('plugins.index'), false);
        $this->get('/plugins')->assertOk()->assertSee('PayPal')->assertSee('Credit Card')
            ->assertSee(route('plugins.download', ['slug' => 'sscpay-payment-paypal', 'version' => self::WORDPRESS_VERSION]), false)
            ->assertSee(route('plugins.guide'), false)
            ->assertSee(route('api.documentation', ['version' => '2.0']), false);
    }

    public function test_guest_installation_guide_has_working_section_links_and_only_public_downloads(): void
    {
        $response = $this->get('https://plugins.example.test/plugins/guide')->assertOk()
            ->assertSeeText('付款侧：安装网关站插件')->assertSeeText('收款侧：安装商城站插件')
            ->assertSeeText('Consumer Secret')->assertSeeText('App-ID')->assertSeeText('group_key')
            ->assertSeeText('WooCommerce 商城')->assertSeeText('OpenCart 商城')->assertSeeText('沙箱订单')
            ->assertSeeText('回调密钥未配置或无法读取')->assertSee('href="https://plugins.example.test/plugins/gateway/download"', false)
            ->assertSee('/plugins#platform-wordpress', false)->assertSee('/plugins#platform-opencart', false);

        $html = $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*(?:site-setup|\.md["#?]|\/storage\/)/i', $html);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8" ?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//ol[@class="guide-contents"]//a');
        $this->assertGreaterThan(0, $links->length);
        $this->assertSame($xpath->query('//article/h2')->length, $links->length);
        foreach ($links as $link) {
            $target = $document->getElementById(substr($link->getAttribute('href'), 1));
            $this->assertSame('h2', $target?->tagName);
        }

        $this->get('http://plugins.example.test/plugins/guide')->assertOk()->assertDontSee('/plugins/gateway/download"', false);
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
        $disk->shouldReceive('exists')->andReturn(false);
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
        $this->artisan('plugins:build', ['slug' => 'sscpay-payment-paypal', '--tag' => 'plugins/sscpay-payment-creditcard/v'.self::WORDPRESS_VERSION])
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
            $directory = $fixture.'/plugins/'.$plugin['platform'].'/'.$plugin['slug'];
            File::ensureDirectoryExists($directory);
            $entry = $plugin['platform'] === 'wordpress' ? $plugin['slug'].'.php' : 'install.json';
            File::copy($plugin['path'].'/'.$entry, $directory.'/'.$entry);
            $plugin['protocol_hash'] = 'previous-protocol';
            File::put($directory.'/plugin.json', json_encode($plugin));
        }
        foreach ([['git', 'init', '-q'], ['git', 'add', '.'], ['git', '-c', 'user.name=Plugin Tests', '-c', 'user.email=plugins@example.test', 'commit', '-qm', 'Protocol baseline']] as $command) {
            (new Process($command, $fixture))->mustRun();
        }
        $this->app->setBasePath($fixture);
        config(['plugins.source_path' => $fixture.'/plugins']);
        try {
            foreach ($plugins as $slug => $plugin) {
                File::put($fixture.'/plugins/'.$plugin['platform'].'/'.$slug.'/plugin.json', json_encode($plugin));
            }
            $this->artisan('plugins:check', ['--base' => 'HEAD'])->expectsOutputToContain('必须递增版本')->assertFailed();
            foreach ($plugins as $slug => $plugin) {
                $directory = $fixture.'/plugins/'.$plugin['platform'].'/'.$slug;
                $previousVersion = $plugin['version'];
                [$major, $minor, $patch] = explode('.', $previousVersion);
                $plugin['version'] = $major.'.'.$minor.'.'.((int) $patch + 1);
                File::put($directory.'/plugin.json', json_encode($plugin));
                $entry = $plugin['platform'] === 'wordpress' ? $slug.'.php' : 'install.json';
                File::put($directory.'/'.$entry, str_replace($previousVersion, $plugin['version'], File::get($directory.'/'.$entry)));
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
