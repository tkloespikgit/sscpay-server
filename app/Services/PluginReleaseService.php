<?php

namespace App\Services;

use App\Support\PluginProtocol;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

final readonly class PluginReleaseService
{
    private const PACKAGE_FILE_PATTERN = '/\.(php|js|css|json|twig|png|jpg|jpeg|gif|svg|webp|woff2?|mo|po|pot)$/i';

    private const VENDOR_SUPPORT_PATTERN = '/^(LICENSE(?:\.txt)?|ca-certificates\.crt)$/i';

    public function __construct(private PluginCatalog $catalog, private PluginProtocol $protocol) {}

    /** @return array{zip: string, manifest: string, release: array<string, mixed>} */
    public function build(string $slug, ?string $tag = null): array
    {
        $this->protocol->check($this->catalog);
        $plugin = $this->catalog->find($slug);
        $version = $plugin['version'];
        $isGateway = $slug === PluginCatalog::GATEWAY;
        $isWordPress = $plugin['platform'] === 'wordpress';
        if ($tag !== null && $tag !== 'plugins/'.$slug.'/v'.$version) {
            throw new InvalidArgumentException('Tag does not match the plugin and version.');
        }
        $directory = config('plugins.build_path').'/'.$slug.'/'.$version;
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$plugin['archive_name'];
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create plugin ZIP.');
        }
        try {
            $entries = match (true) {
                $isGateway => [$slug.'.php', 'uninstall.php', 'src', 'assets'],
                $isWordPress => [$slug.'.php', 'index.php', 'includes', 'assets', 'languages'],
                $slug === 'sscpay-opencart3' => ['upload', 'install.json'],
                default => ['admin', 'catalog', 'install.json'],
            };
            foreach ($entries as $entry) {
                $source = $plugin['path'].'/'.$entry;
                $files = is_dir($source)
                    ? array_map('strval', iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS)), false))
                    : [$source];
                sort($files, SORT_STRING);
                foreach ($files as $file) {
                    $file = (string) $file;
                    $relative = substr($file, strlen($plugin['path']) + 1);
                    if ($isGateway
                        && (str_starts_with($relative, 'src/Frontend/Tpl/') || $relative === 'assets/images/test.html')) {
                        continue;
                    }
                    $target = ($isWordPress ? $slug.'/' : '').$relative;
                    $this->addFile($zip, $file, $target);
                }
            }
            if ($isGateway) {
                $this->addGatewayDependencies($zip, $plugin['path'].'/vendor');
            }
            if ($plugin['platform'] === 'opencart') {
                $this->addOpenCartSharedFiles($zip, $plugin);
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Cannot finish plugin ZIP.');
            }
        }
        $release = [
            'slug' => $slug, 'name' => $plugin['name'], 'version' => $version,
            'platform' => $plugin['platform'], 'archive_name' => $plugin['archive_name'],
            'api_version' => $plugin['api_version'] ?? null, 'protocol_hash' => $plugin['protocol_hash'] ?? null,
            'requires' => $plugin['requires'], 'requires_php' => $plugin['requires_php'],
            'tested' => $plugin['tested'] ?? null, 'wc_requires' => $plugin['wc_requires'] ?? null,
            'description' => $plugin['description'], 'changelog' => $plugin['changelog'] ?? '',
            'sha256' => hash_file('sha256', $path), 'size' => filesize($path),
            'published_at' => now()->toIso8601String(),
        ];
        if ($isGateway) {
            $release['public_update'] = true;
        }
        $manifest = $directory.'/release.json';
        File::put($manifest, json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return ['zip' => $path, 'manifest' => $manifest, 'release' => $release];
    }

    /** @param array{zip: string, manifest: string, release: array<string, mixed>} $build */
    public function publish(array $build): void
    {
        $release = $this->publication($build);
        $disk = $this->disk($release['slug']);
        $options = $release['slug'] === PluginCatalog::GATEWAY ? ['visibility' => 'private'] : [];
        $prefix = $this->prefix($release['slug']);
        $versionPrefix = $prefix.'/'.$release['version'];
        $metadataPath = $versionPrefix.'/release.json';
        $packagePath = $this->packagePath($release['slug'], $release['version']);
        if (! $disk->exists($metadataPath) || ! $disk->exists($packagePath)) {
            $stream = fopen($build['zip'], 'rb');
            try {
                if (! $disk->put($packagePath, $stream, $options)
                    || ! $disk->put($metadataPath, json_encode($release, JSON_THROW_ON_ERROR), $options)) {
                    throw new RuntimeException('Uploading the plugin release failed.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        if (! $disk->put($prefix.'/stable.json', json_encode($release, JSON_THROW_ON_ERROR), $options)) {
            throw new RuntimeException('Publishing the stable release failed.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function sync(bool $dryRun = false): array
    {
        $builds = [];
        foreach ([...array_keys($this->catalog->all()), PluginCatalog::GATEWAY] as $slug) {
            $builds[] = $this->build($slug);
        }
        $releases = [];
        foreach ($builds as $build) {
            $releases[] = $this->publication($build);
        }
        if (! $dryRun) {
            foreach ($builds as $build) {
                $this->publish($build);
            }
        }

        return $releases;
    }

    /**
     * @param  array{zip: string, manifest: string, release: array<string, mixed>}  $build
     * @return array<string, mixed>
     */
    private function publication(array $build): array
    {
        $release = $build['release'];
        $existing = $this->version($release['slug'], $release['version']);
        if ($existing !== null && ($existing['sha256'] !== $release['sha256']
            || ($existing['protocol_hash'] ?? null) !== $release['protocol_hash']
            || ($existing['api_version'] ?? null) !== $release['api_version'])) {
            throw new RuntimeException('This version is already published with different contents. Increment the plugin version.');
        }
        $latest = $this->latest($release['slug']);
        if ($latest !== null && version_compare($latest['version'], $release['version'], '>')) {
            throw new RuntimeException('Refusing to replace the latest release with an older version.');
        }

        return $existing ?? $release;
    }

    /** @return array<string, mixed>|null */
    public function latest(string $slug): ?array
    {
        $this->catalog->find($slug);

        return $this->read($this->prefix($slug).'/stable.json', $slug);
    }

    /** @return array<string, mixed>|null */
    public function version(string $slug, string $version): ?array
    {
        $this->catalog->find($slug);
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            return null;
        }

        return $this->read($this->prefix($slug).'/'.$version.'/release.json', $slug, $version);
    }

    public function disk(?string $slug = null): Filesystem
    {
        $disk = config('plugins.disk');
        if ($slug === PluginCatalog::GATEWAY && ($disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public')) {
            throw new RuntimeException('The gateway plugin requires a private release disk.');
        }

        return Storage::disk($disk);
    }

    public function packagePath(string $slug, string $version): string
    {
        $plugin = $this->catalog->find($slug);
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new InvalidArgumentException('Invalid plugin version.');
        }

        return $this->prefix($slug).'/'.$version.'/'.$plugin['archive_name'];
    }

    private function addFile(ZipArchive $zip, string $file, string $target, bool $vendor = false): void
    {
        if (! is_file($file) || is_link($file) || (! preg_match(self::PACKAGE_FILE_PATTERN, $file)
            && ! ($vendor && preg_match(self::VENDOR_SUPPORT_PATTERN, basename($file))))) {
            throw new RuntimeException('Unexpected file in plugin package: '.$target);
        }
        if (! $zip->addFile($file, $target)) {
            throw new RuntimeException('Cannot add plugin file: '.$target);
        }
        $zip->setMtimeName($target, 315532800);
        $zip->setExternalAttributesName($target, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }

    private function addGatewayDependencies(ZipArchive $zip, string $vendor): void
    {
        $required = [
            'autoload.php',
            'composer/autoload_real.php',
            'stripe/stripe-php/lib/Stripe.php',
            'stripe/stripe-php/data/ca-certificates.crt',
            'yahnis-elsts/plugin-update-checker/plugin-update-checker.php',
        ];
        foreach ($required as $file) {
            if (! is_file($vendor.'/'.$file)) {
                throw new RuntimeException('Install the gateway plugin dependencies from its composer.lock before building.');
            }
        }
        $files = (new Finder)->files()->in($vendor)
            ->exclude(['tests', 'test', 'docs', 'examples'])
            ->notName('auth.json')
            ->name(self::PACKAGE_FILE_PATTERN)
            ->name(self::VENDOR_SUPPORT_PATTERN)
            ->sortByName();

        foreach ($files as $file) {
            $this->addFile($zip, $file->getPathname(), PluginCatalog::GATEWAY.'/vendor/'.$file->getRelativePathname(), vendor: true);
        }
    }

    /** @param array<string, mixed> $plugin */
    private function addOpenCartSharedFiles(ZipArchive $zip, array $plugin): void
    {
        $prefix = $plugin['slug'] === 'sscpay-opencart3' ? 'upload/' : '';
        $shared = base_path('plugins/opencart/shared');
        foreach (['bootstrap', 'Decimal', 'Signature', 'Client', 'PaymentState', 'Payload', 'Repository', 'Gateway', 'Admin', 'Text'] as $class) {
            $this->addFile($zip, $shared.'/'.$class.'.php', $prefix.'system/library/sscpay/'.$class.'.php');
        }
        $target = $prefix.'system/library/sscpay/release-version.json';
        $zip->addFromString($target, json_encode(['version' => $plugin['version']], JSON_THROW_ON_ERROR));
        $zip->setMtimeName($target, 315532800);
        $zip->setExternalAttributesName($target, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $this->addFile($zip, $shared.'/sscpay_settings.twig', $prefix.'admin/view/template/'.($prefix !== '' ? 'extension/' : '').'payment/sscpay_settings.twig');
        foreach (['sscpay_checkout', 'sscpay_result'] as $name) {
            $this->addFile($zip, $shared.'/'.$name.'.twig', $prefix.'catalog/view/'.($prefix !== '' ? 'theme/default/' : '').'template/'.($prefix !== '' ? 'extension/' : '').'payment/'.$name.'.twig');
        }
    }

    private function prefix(string $slug): string
    {
        return trim(config('plugins.prefix'), '/').'/'.$slug;
    }

    /** @return array<string, mixed>|null */
    private function read(string $path, string $slug, ?string $version = null): ?array
    {
        $disk = $this->disk($slug);
        if (! $disk->exists($path)) {
            return null;
        }
        $release = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        if (($release['slug'] ?? '') !== $slug || ! preg_match('/^\d+\.\d+\.\d+$/D', $release['version'] ?? '')
            || ($version !== null && $release['version'] !== $version)
            || ! preg_match('/^[a-f0-9]{64}$/D', $release['sha256'] ?? '')
            || ! is_int($release['size'] ?? null) || $release['size'] <= 0) {
            throw new RuntimeException('Invalid plugin release metadata.');
        }
        $plugin = $this->catalog->find($slug);
        if (($release['platform'] ?? $plugin['platform']) !== $plugin['platform']
            || ($release['archive_name'] ?? $plugin['archive_name']) !== $plugin['archive_name']) {
            throw new RuntimeException('Invalid plugin package metadata.');
        }

        return $release;
    }
}
