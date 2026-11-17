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
use ZipArchive;

final readonly class PluginReleaseService
{
    public function __construct(private PluginCatalog $catalog, private PluginProtocol $protocol) {}

    /** @return array{zip: string, manifest: string, release: array<string, mixed>} */
    public function build(string $slug, ?string $tag = null): array
    {
        $this->protocol->check($this->catalog);
        $plugin = $this->catalog->find($slug);
        $version = $plugin['version'];
        if ($tag !== null && $tag !== 'plugins/'.$slug.'/v'.$version) {
            throw new InvalidArgumentException('Tag does not match the plugin and version.');
        }
        $directory = config('plugins.build_path').'/'.$slug.'/'.$version;
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$slug.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create plugin ZIP.');
        }
        try {
            foreach ([$slug.'.php', 'index.php', 'includes', 'assets', 'languages'] as $entry) {
                $source = $plugin['path'].'/'.$entry;
                $files = is_dir($source)
                    ? array_map('strval', iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS)), false))
                    : [$source];
                sort($files, SORT_STRING);
                foreach ($files as $file) {
                    $file = (string) $file;
                    $relative = substr($file, strlen($plugin['path']) + 1);
                    if (is_link($file) || ! preg_match('/\.(php|js|css|png|jpg|jpeg|gif|svg|webp|woff2?|mo|po|pot)$/i', $relative)) {
                        throw new RuntimeException('Unexpected file in plugin package: '.$relative);
                    }
                    if (! $zip->addFile($file, $slug.'/'.$relative)) {
                        throw new RuntimeException('Cannot add plugin file: '.$relative);
                    }
                    $zip->setMtimeName($slug.'/'.$relative, 315532800);
                    $zip->setExternalAttributesName($slug.'/'.$relative, ZipArchive::OPSYS_UNIX, 0100644 << 16);
                }
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Cannot finish plugin ZIP.');
            }
        }
        $release = [
            'slug' => $slug, 'name' => $plugin['name'], 'version' => $version,
            'api_version' => $plugin['api_version'], 'protocol_hash' => $plugin['protocol_hash'],
            'requires' => $plugin['requires'], 'requires_php' => $plugin['requires_php'],
            'tested' => $plugin['tested'], 'wc_requires' => $plugin['wc_requires'],
            'description' => $plugin['description'], 'changelog' => $plugin['changelog'],
            'sha256' => hash_file('sha256', $path), 'size' => filesize($path),
            'published_at' => now()->toIso8601String(),
        ];
        $manifest = $directory.'/release.json';
        File::put($manifest, json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return ['zip' => $path, 'manifest' => $manifest, 'release' => $release];
    }

    /** @param array{zip: string, manifest: string, release: array<string, mixed>} $build */
    public function publish(array $build): void
    {
        $release = $this->publication($build);
        $disk = $this->disk();
        $prefix = $this->prefix($release['slug']);
        $versionPrefix = $prefix.'/'.$release['version'];
        $metadataPath = $versionPrefix.'/release.json';
        if (! $disk->exists($metadataPath) || ! $disk->exists($versionPrefix.'/'.$release['slug'].'.zip')) {
            $stream = fopen($build['zip'], 'rb');
            try {
                if (! $disk->put($versionPrefix.'/'.$release['slug'].'.zip', $stream)
                    || ! $disk->put($metadataPath, json_encode($release, JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('Uploading the plugin release failed.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        if (! $disk->put($prefix.'/stable.json', json_encode($release, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Publishing the stable release failed.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function sync(bool $dryRun = false): array
    {
        $builds = [];
        foreach ($this->catalog->all() as $plugin) {
            $builds[] = $this->build($plugin['slug']);
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
            || ($existing['protocol_hash'] ?? '') !== $release['protocol_hash']
            || ($existing['api_version'] ?? '') !== $release['api_version'])) {
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

    public function disk(): Filesystem
    {
        return Storage::disk(config('plugins.disk'));
    }

    public function packagePath(string $slug, string $version): string
    {
        $this->catalog->find($slug);
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new InvalidArgumentException('Invalid plugin version.');
        }

        return $this->prefix($slug).'/'.$version.'/'.$slug.'.zip';
    }

    private function prefix(string $slug): string
    {
        return trim(config('plugins.prefix'), '/').'/'.$slug;
    }

    /** @return array<string, mixed>|null */
    private function read(string $path, string $slug, ?string $version = null): ?array
    {
        if (! $this->disk()->exists($path)) {
            return null;
        }
        $release = json_decode($this->disk()->get($path), true, flags: JSON_THROW_ON_ERROR);
        if (($release['slug'] ?? '') !== $slug || ! preg_match('/^\d+\.\d+\.\d+$/D', $release['version'] ?? '')
            || ($version !== null && $release['version'] !== $version)
            || ! preg_match('/^[a-f0-9]{64}$/D', $release['sha256'] ?? '')
            || ! is_int($release['size'] ?? null) || $release['size'] <= 0) {
            throw new RuntimeException('Invalid plugin release metadata.');
        }

        return $release;
    }
}
