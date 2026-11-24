<?php

namespace App\Services\ChargebackAlerts\Drivers\WeTech;

use App\Models\ChargebackProviderAccount;
use App\Services\ChargebackAlerts\ProviderRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class WeTechClient
{
    public function __construct(private WeTechSignature $signature) {}

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function request(ChargebackProviderAccount $account, string $method, string $path, array $parameters): array
    {
        $host = match ($account->environment) {
            'test' => 'https://xp.test.wetech.vip',
            'production' => 'https://platapi.wetech-rc.com',
            default => throw new ProviderRequestFailed('invalid_environment'),
        };
        $secret = $account->signing_config['secret_key'] ?? null;
        if (! is_string($secret) || $secret === '') {
            throw new ProviderRequestFailed('missing_credentials');
        }

        $parameters['secret_id'] = $account->external_id;
        $parameters['timestamp'] = (string) now()->getTimestampMs();
        $parameters['sign'] = $this->signature->sign($parameters, $secret);

        try {
            $http = Http::baseUrl($host)->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(2)->timeout(6);
            $response = $method === 'GET' ? $http->get($path, $parameters) : $http->post($path, $parameters);
        } catch (ConnectionException) {
            throw new ProviderRequestFailed('connection_failed', uncertain: true);
        }

        $data = $response->json();
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        $code = is_string($code) || is_int($code) ? (string) $code : '';
        if ($response->status() === 200 && $code === '0') {
            return $data;
        }

        if ($code === '5002') {
            throw new ProviderRequestFailed('5002', retryAllowed: true);
        }

        if (in_array($code, ['4001', '4301'], true) || in_array($response->status(), [400, 401, 403, 404, 422], true)) {
            throw new ProviderRequestFailed(in_array($code, ['4001', '4301'], true) ? $code : 'http_'.$response->status());
        }

        throw new ProviderRequestFailed($code === '5001' ? '5001' : 'invalid_response', uncertain: true);
    }
}
