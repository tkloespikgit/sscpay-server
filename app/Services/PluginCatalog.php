<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

final class PluginCatalog
{
    public const GATEWAY = 'sscpay-gateway-aggregator';

    /**
     * Storefront plugins included in the public catalog and merchant API protocol checks.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $plugins = [];
        $root = config('plugins.source_path');
        $paths = array_merge(File::glob($root.'/*/plugin.json'), File::glob($root.'/*/*/plugin.json'));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $plugin = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            if (($plugin['slug'] ?? '') !== basename(dirname($path))
                || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $plugin['slug'] ?? '')
                || ! preg_match('/^\d+\.\d+\.\d+$/D', $plugin['version'] ?? '')
                || ! in_array($plugin['platform'] ?? '', ['wordpress', 'opencart'], true)
                || $plugin['slug'] === self::GATEWAY
                || isset($plugins[$plugin['slug']])) {
                throw new RuntimeException('Invalid plugin manifest.');
            }
            $plugin['archive_name'] ??= $plugin['slug'].'.zip';
            if (! preg_match('/^[a-z0-9-]+(?:\.ocmod)?\.zip$/D', $plugin['archive_name'])) {
                throw new RuntimeException('Invalid plugin archive name.');
            }
            $plugin['path'] = dirname($path);
            $plugin['manifest_path'] = str_replace(base_path().'/', '', $path);
            $plugins[$plugin['slug']] = $plugin;
        }
        foreach (config('plugins.required') as $slug) {
            if (! isset($plugins[$slug])) {
                throw new RuntimeException('Missing required plugin: '.$slug);
            }
        }

        return $plugins;
    }

    /** @return array<string, mixed> */
    public function find(string $slug): array
    {
        if ($slug === self::GATEWAY) {
            return $this->gateway();
        }

        return $this->all()[$slug] ?? throw new InvalidArgumentException('Unknown plugin.');
    }

    /** @return array<string, mixed> */
    private function gateway(): array
    {
        $path = config('plugins.source_path').'/merchant/'.self::GATEWAY;
        $main = File::get($path.'/'.self::GATEWAY.'.php');
        preg_match_all('/^\h*\*\h*(Plugin Name|Description|Version|Requires at least|Requires PHP|WC requires at least):\h*([^\r\n]+)/m', substr($main, 0, 8192), $header);
        $fields = array_combine($header[1], array_map('trim', $header[2]));
        $version = $fields['Version'] ?? '';
        preg_match("/define\(\s*'PGA_VERSION',\s*'([^']+)'/", $main, $constant);
        if (($fields['Plugin Name'] ?? '') === '' || ! preg_match('/^\d+\.\d+\.\d+$/D', $version)
            || $version !== ($constant[1] ?? null)) {
            throw new RuntimeException('The gateway plugin needs a valid WordPress header and a matching PGA_VERSION.');
        }

        return [
            'slug' => self::GATEWAY,
            'name' => $fields['Plugin Name'],
            'version' => $version,
            'platform' => 'wordpress',
            'archive_name' => self::GATEWAY.'.zip',
            'requires' => $fields['Requires at least'] ?? null,
            'requires_php' => $fields['Requires PHP'] ?? null,
            'wc_requires' => $fields['WC requires at least'] ?? null,
            'description' => $fields['Description'] ?? '',
            'path' => $path,
        ];
    }
}
