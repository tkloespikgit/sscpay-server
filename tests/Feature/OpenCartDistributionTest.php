<?php

namespace Tests\Feature;

use App\Services\PluginReleaseService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class OpenCartDistributionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->directory = sys_get_temp_dir().'/sscpay-oc-'.Str::uuid();
        config(['plugins.build_path' => $this->directory, 'plugins.disk' => 'oc-release-test']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function platforms(): array
    {
        return ['OpenCart 3' => [3, 'sscpay-opencart3.ocmod.zip'], 'OpenCart 4' => [4, 'sscpay.ocmod.zip']];
    }

    #[DataProvider('platforms')]
    public function test_both_payment_methods_install_from_the_platform_specific_archive_and_can_be_downloaded(int $major, string $filename): void
    {
        Storage::fake('oc-release-test');
        $releases = app(PluginReleaseService::class);
        $slug = 'sscpay-opencart'.$major;
        $version = '1.1.0';
        $build = $releases->build($slug, 'plugins/'.$slug.'/v'.$version);
        $this->assertSame($filename, basename($build['zip']));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($build['zip']));
        $installer = json_decode($zip->getFromName('install.json'), true);
        $this->assertSame($version, $installer['version']);
        $this->assertNotEmpty($installer['author']);
        $prefix = $major === 3 ? 'upload/' : '';
        foreach (['paypal', 'creditcard'] as $channel) {
            foreach (['admin/controller', 'catalog/controller', 'catalog/model'] as $path) {
                $this->assertNotFalse($zip->getFromName($prefix.$path.($major === 3 ? '/extension' : '').'/payment/sscpay'.$channel.'.php'));
            }
        }
        $this->assertNotFalse($zip->getFromName($prefix.'system/library/sscpay/bootstrap.php'));
        $this->assertNotFalse($zip->getFromName($prefix.'system/library/sscpay/PaymentState.php'));
        $this->assertSame(['version' => $version], json_decode($zip->getFromName($prefix.'system/library/sscpay/release-version.json'), true));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertDoesNotMatchRegularExpression('/(?:\.env|\.git|artisan|plugin\.json|tests\/|\.\.\/)/', $name);
            if ($major === 4) {
                $this->assertStringNotContainsString('upload/', $name);
            }
        }
        $install = $this->directory.'/installed-'.$major;
        $zip->extractTo($major === 3 ? $install : $install.'/extension/sscpay');
        $zip->close();
        $this->verifyInstalledEntryPoints($install, $major);
        $releases->publish($build);
        $releases->publish($releases->build($slug));
        $this->get('/plugins/updates/v1/'.$slug)->assertOk()->assertJsonPath('version', $version)->assertJsonPath('archive_name', $filename);
        $response = $this->get("/plugins/{$slug}/{$version}/download")->assertOk()->assertDownload($filename);
        $this->assertSame($build['release']['sha256'], hash('sha256', $response->streamedContent()));
        $this->get('/plugins')->assertOk()->assertSee('OpenCart '.$major.'.x')->assertSee("/plugins/{$slug}/{$version}/download", false);
        $this->assertSame(hash_file('sha256', $build['zip']), $build['release']['sha256']);
    }

    private function verifyInstalledEntryPoints(string $directory, int $major): void
    {
        $fixture = base_path('tests/Support/OpenCartAdapterRuntime.php');
        $process = new Process([PHP_BINARY, $fixture, $directory, (string) $major], base_path());
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['sscpaypaypal', 'sscpaycreditcard'], $result['codes']);
        $this->assertSame([true, true], $result['checkout']);
        if ($major === 4) {
            $this->assertSame(['sscpaypaypal.sscpaypaypal', 'sscpaycreditcard.sscpaycreditcard'], $result['options']);
        }
    }
}
