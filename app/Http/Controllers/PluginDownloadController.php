<?php

namespace App\Http\Controllers;

use App\Services\PluginCatalog;
use App\Services\PluginReleaseService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PluginDownloadController extends Controller
{
    public function __construct(private readonly PluginCatalog $catalog, private readonly PluginReleaseService $releases) {}

    public function index(): View
    {
        $plugins = [];
        foreach ($this->catalog->all() as $plugin) {
            $plugins[] = $plugin + ['release' => $this->releases->latest($plugin['slug'])];
        }

        return view('plugins.index', compact('plugins'));
    }

    public function update(string $slug): JsonResponse
    {
        $this->plugin($slug);
        $release = $this->releases->latest($slug);
        abort_if($release === null, 404);

        return response()->json($release + [
            'download_url' => route('plugins.download', ['slug' => $slug, 'version' => $release['version']]),
            'homepage' => route('plugins.index'),
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function download(string $slug, string $version): StreamedResponse
    {
        $this->plugin($slug);
        $release = $this->releases->version($slug, $version);
        abort_if($release === null, 404);
        $stream = $this->releases->disk()->readStream($this->releases->packagePath($slug, $version));
        abort_unless(is_resource($stream), 404);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, ($release['platform'] ?? 'wordpress') === 'opencart' ? $release['archive_name'] : $slug.'-'.$version.'.zip', [
            'Content-Type' => 'application/zip', 'Content-Length' => (string) $release['size'],
            'X-Checksum-Sha256' => $release['sha256'], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }

    private function plugin(string $slug): void
    {
        try {
            $this->catalog->find($slug);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }
}
