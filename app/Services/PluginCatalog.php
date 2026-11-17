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
        foreach (File::glob(config('plugins.source_path').'/*/plugin.json') as $path) {
            $plugin = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            if (($plugin['slug'] ?? '') !== basename(dirname($path))
                || ! preg_match('/^\d+\.\d+\.\d+$/D', $plugin['version'] ?? '')) {
                throw new RuntimeException('Invalid plugin manifest.');
            }
            $plugin['path'] = dirname($path);
            $plugins[$plugin['slug']] = $plugin;
        }
        foreach (['sscpay-payment-paypal', 'sscpay-payment-creditcard'] as $slug) {
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
