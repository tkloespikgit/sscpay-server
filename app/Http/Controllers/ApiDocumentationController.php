<?php

namespace App\Http\Controllers;

use App\Services\ApiDocumentationReferenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class ApiDocumentationController extends Controller
{
    public function __construct(private readonly ApiDocumentationReferenceService $references) {}

    public function show(Request $request, ?string $locale = null): View
    {
        $preferredLocale = $request->session()->get('locale');
        if (! in_array($preferredLocale, ['zh_CN', 'en'], true)) {
            $preferredLocale = config('app.locale', 'zh_CN');
        }
        $locale ??= in_array($preferredLocale, ['zh_CN', 'en'], true) ? $preferredLocale : 'zh_CN';

        return view('api-documentation', [
            'locale' => $locale,
            'content' => $this->references->withConfiguredReferences(Lang::get('api_docs', [], $locale)),
        ]);
    }
}
