<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

final class PluginCatalog
{
    /** @return array<string, array<string, mixed>> */
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
        return $this->all()[$slug] ?? throw new InvalidArgumentException('Unknown plugin.');
    }
}
