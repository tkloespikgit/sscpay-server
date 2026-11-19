<?php

namespace App\Http\Controllers;

use App\Services\WooCommerceAuthorizationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WooCommerceAuthorizationController extends Controller
{
    public function store(Request $request, string $token, WooCommerceAuthorizationService $service): JsonResponse
    {
        $validator = Validator::make($request->json()->all(), [
            'key_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'string', 'size:32'],
            'consumer_key' => ['required', 'string', 'regex:/\Ack_[a-f0-9]{40}\z/'],
            'consumer_secret' => ['required', 'string', 'regex:/\Acs_[a-f0-9]{40}\z/'],
            'key_permissions' => ['required', 'in:read_write'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false], 422)->header('Cache-Control', 'no-store');
        }

        $service->receive($token, $validator->validated());

        return response()->json(['success' => true])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): View
    {
        if (in_array($request->query('locale'), ['zh_CN', 'en'], true)) {
            app()->setLocale($request->query('locale'));
        }

        return view('filament.woocommerce-authorization-return');
    }
}
