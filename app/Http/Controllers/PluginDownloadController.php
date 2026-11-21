<?php

namespace App\Http\Controllers;

use App\Services\PluginCatalog;
use App\Services\PluginReleaseService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PluginDownloadController extends Controller
{
    private const FIRST_PUBLIC_GATEWAY_VERSION = '1.15.2';

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

        return $this->downloadRelease($this->releases->version($slug, $version), 'public, max-age=86400, immutable');
    }

    public function gateway(): StreamedResponse
    {
        return $this->downloadRelease($this->releases->latest(PluginCatalog::GATEWAY), 'private, no-store');
    }

    public function gatewayUpdate(Request $request): JsonResponse
    {
        $release = $this->publicGatewayRelease($request);
        $installed = $request->query('installed_version');
        if (is_string($installed) && preg_match('/^\d+\.\d+\.\d+$/D', $installed) && version_compare($installed, self::FIRST_PUBLIC_GATEWAY_VERSION, '<')
            && $request->query('site_prepared') !== '1') {
            return response()->json(['message' => '请先在旧插件仍启用时激活收款站初始化包，再检查更新。'], 409)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($release + [
            'download_url' => route('plugins.gateway.update.download', ['version' => $release['version']]),
            'sections' => ['description' => $release['description']],
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function gatewayUpdateDownload(Request $request, ?string $version = null): StreamedResponse
    {
        return $this->downloadRelease($this->publicGatewayRelease($request, $version), $version === null
            ? 'public, max-age=60' : 'public, max-age=86400, immutable');
    }

    /** @return array<string, mixed> */
    private function publicGatewayRelease(Request $request, ?string $version = null): array
    {
        abort_unless($request->isSecure(), 404);
        $release = $version === null ? $this->releases->latest(PluginCatalog::GATEWAY)
            : $this->releases->version(PluginCatalog::GATEWAY, $version);
        abort_if(($release['public_update'] ?? false) !== true || version_compare($release['version'], self::FIRST_PUBLIC_GATEWAY_VERSION, '<'), 404);

        return $release;
    }

    /** @param array<string, mixed>|null $release */
    private function downloadRelease(?array $release, string $cacheControl): StreamedResponse
    {
        abort_if($release === null, 404);
        $slug = $release['slug'];
        $version = $release['version'];
        $stream = $this->releases->disk($slug)->readStream($this->releases->packagePath($slug, $version));
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
            'Cache-Control' => $cacheControl,
        ]);
    }

    private function plugin(string $slug): void
    {
        abort_unless(isset($this->catalog->all()[$slug]), 404);
    }
}
