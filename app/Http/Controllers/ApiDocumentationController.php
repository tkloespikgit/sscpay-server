<?php

namespace App\Http\Controllers;

use App\Services\ApiDocumentationReferenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class ApiDocumentationController extends Controller
{
    private const VERSIONS = ['2.0' => 'api_docs_v2', '1.0' => 'api_docs_v1'];

    public function __construct(private readonly ApiDocumentationReferenceService $references) {}

    public function show(Request $request, ?string $locale = null): View
    {
        $latestVersion = array_key_first(self::VERSIONS);
        $version = $request->query->has('version') ? $request->query('version') : $latestVersion;
        abort_unless(is_string($version) && isset(self::VERSIONS[$version]), 404);

        $preferredLocale = $request->session()->get('locale');
        if (! in_array($preferredLocale, ['zh_CN', 'en'], true)) {
            $preferredLocale = config('app.locale', 'zh_CN');
        }
        $locale ??= in_array($preferredLocale, ['zh_CN', 'en'], true) ? $preferredLocale : 'zh_CN';

        return view('api-documentation', [
            'locale' => $locale,
            'version' => $version,
            'versions' => array_keys(self::VERSIONS),
            'latestVersion' => $latestVersion,
            'content' => $this->references->withConfiguredReferences(Lang::get(self::VERSIONS[$version], [], $locale)),
        ]);
    }
}
