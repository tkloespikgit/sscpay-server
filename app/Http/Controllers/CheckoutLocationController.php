<?php

namespace App\Http\Controllers;

use App\Support\Countries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** 地区联动数据只经过本站代理；客户地址和 Google Key 都不会发给地区服务。 */
class CheckoutLocationController extends Controller
{
    private const API_BASE = 'https://countriesnow.space/api/v0.1/countries';

    public function states(Request $request): JsonResponse
    {
        $country = $this->countryCode($request);

        if (! $country) {
            return response()->json(['states' => []], 422);
        }

        try {
            $data = $this->statesFor($country);
        } catch (\Throwable) {
            return response()->json(['states' => []], 503);
        }

        return response()->json(['states' => $data['states']]);
    }

    public function cities(Request $request): JsonResponse
    {
        $country = $this->countryCode($request);
        $state = $request->query('state');

        if (! $country || ! is_string($state) || mb_strlen($state) > 100) {
            return response()->json(['cities' => []], 422);
        }

        try {
            $data = $this->statesFor($country);

            if (! in_array($state, $data['states'], true)) {
                return response()->json(['cities' => []], 422);
            }

            $cities = Cache::remember('checkout:locations:cities:'.sha1($country.'|'.$state), now()->addDay(), function () use ($data, $state) {
                $response = Http::timeout(5)->get(self::API_BASE.'/state/cities/q', [
                    'country' => $data['country'],
                    'state' => $state,
                ]);

                if (! $response->successful() || $response->json('error') !== false) {
                    throw new \RuntimeException('Location service unavailable.');
                }

                return array_values(array_filter($response->json('data', []),
                    fn ($city) => is_string($city) && mb_strlen($city) <= 100));
            });
        } catch (\Throwable) {
            return response()->json(['cities' => []], 503);
        }

        return response()->json(['cities' => $cities]);
    }

    private function countryCode(Request $request): ?string
    {
        $country = $request->query('country');

        return is_string($country) && array_key_exists($country, Countries::all()) ? $country : null;
    }

    /** @return array{country: string, states: list<string>} */
    private function statesFor(string $country): array
    {
        return Cache::remember('checkout:locations:states:'.$country, now()->addDay(), function () use ($country) {
            $response = Http::timeout(5)->get(self::API_BASE.'/states/q', ['country' => Countries::all()[$country]]);

            if (! $response->successful() || $response->json('error') !== false) {
                throw new \RuntimeException('Location service unavailable.');
            }

            $states = array_values(array_filter(array_map(
                fn ($state) => is_array($state) ? ($state['name'] ?? null) : null,
                $response->json('data.states', [])),
                fn ($state) => is_string($state) && mb_strlen($state) <= 100));

            return [
                'country' => (string) $response->json('data.name', Countries::all()[$country]),
                'states' => $states,
            ];
        });
    }
}
