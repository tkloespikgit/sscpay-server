<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressMonitor;
use App\Support\CryptoAssetRegistry;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/** Subscription management only. No signing, transaction broadcast, or balance accounting. */
final class TatumSubscriptionClient
{
    public function __construct(private readonly CryptoAssetRegistry $assets) {}

    public function credentialFingerprint(string $environment): string
    {
        return hash('sha256', $this->key($environment));
    }

    public function enableHmac(string $environment): void
    {
        $secret = config('services.tatum.'.$environment.'_hmac_secret');
        if (! is_string($secret) || strlen($secret) < 16 || strlen($secret) > 100) {
            throw new DomainException('Tatum HMAC secret is not configured.');
        }
        $response = $this->request('PUT', '/v4/subscription', $environment, ['hmacSecret' => $secret]);
        if ($response->status() !== 204) {
            throw new DomainException('Tatum HMAC setup failed.');
        }
    }

    public function create(CryptoAddressMonitor $monitor): string
    {
        $response = $this->request('POST', '/v4/subscription', $monitor->environment, [
            'type' => 'ADDRESS_EVENT',
            'attr' => ['chain' => $monitor->network, 'address' => $this->address($monitor), 'url' => $monitor->callback_url],
        ], ['type' => $monitor->environment]);
        if (! in_array($response->status(), [200, 201], true)) {
            throw new DomainException('Tatum subscription creation was not confirmed.');
        }
        $data = $response->json();
        $id = is_string($data) ? $data : (is_array($data) ? ($data['id'] ?? null) : null);
        $this->validateId($id);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function get(string $environment, string $id): ?array
    {
        $this->validateId($id);
        $response = $this->request('GET', '/v4/subscription/'.$id, $environment);
        if ($response->status() === 404) {
            return null;
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || array_is_list($data)) {
            throw new DomainException('Tatum subscription lookup failed.');
        }

        return $data;
    }

    public function cancel(string $environment, string $id): void
    {
        $this->validateId($id);
        $response = $this->request('DELETE', '/v4/subscription/'.$id, $environment);
        if (! in_array($response->status(), [204, 404], true)) {
            throw new DomainException('Tatum subscription cancellation was not confirmed.');
        }
    }

    /** Fully scans bounded filtered pages before declaring that no matching alert exists.
     * @return list<string>
     */
    public function matchingIds(CryptoAddressMonitor $monitor): array
    {
        $matches = [];
        for ($page = 0; $page < 3; $page++) {
            $response = $this->request('GET', '/v4/subscription', $monitor->environment, [], [
                'pageSize' => 50, 'offset' => $page * 50, 'address' => $this->address($monitor),
                'type' => 'ADDRESS_EVENT', 'chain' => $monitor->network,
            ]);
            $data = $response->json();
            $items = is_array($data) && array_is_list($data) ? $data : (is_array($data) ? ($data['data'] ?? null) : null);
            if (! $response->successful() || ! is_array($items) || ! array_is_list($items)) {
                throw new DomainException('Tatum subscription reconciliation failed.');
            }
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['type'] ?? null)
                    || ! is_array($item['attr'] ?? null) || ! is_string($item['attr']['chain'] ?? null)
                    || ! is_string($item['attr']['address'] ?? null) || ! is_string($item['attr']['url'] ?? null)) {
                    throw new DomainException('Tatum subscription list is malformed.');
                }
                if ($this->identifies($monitor, $item)) {
                    $this->validateId($item['id'] ?? null);
                    $matches[] = $item['id'];
                }
            }
            if (count($items) < 50) {
                return array_values(array_unique($matches));
            }
        }

        throw new DomainException('Tatum reconciliation page limit reached.');
    }

    /** @param array<string, mixed> $alert */
    public function matches(CryptoAddressMonitor $monitor, array $alert): bool
    {
        return $this->identifies($monitor, $alert) && ($alert['paused'] ?? false) === false
            && (! isset($alert['status']) || $alert['status'] === 'active')
            && (! isset($alert['templateId']) || in_array($alert['templateId'], ['legacy', 'enriched', 'enriched_with_raw_data'], true));
    }

    /** @param array<string, mixed> $alert */
    public function identifies(CryptoAddressMonitor $monitor, array $alert): bool
    {
        $attr = $alert['attr'] ?? null;
        if (($alert['type'] ?? null) !== 'ADDRESS_EVENT' || ! is_array($attr)
            || ($attr['chain'] ?? null) !== $monitor->network || ($attr['url'] ?? null) !== $monitor->callback_url
            || ! is_string($attr['address'] ?? null)) {
            return false;
        }

        try {
            return $this->assets->addressKey($monitor->network, $attr['address']) === $this->assets->addressKey($monitor->network, $this->address($monitor));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function key(string $environment): string
    {
        if (! in_array($environment, ['mainnet', 'testnet'], true)) {
            throw new DomainException('Unsupported Tatum credential environment.');
        }
        $key = config($environment === 'mainnet' ? 'services.tatum.api_key' : 'services.tatum.testnet_key');
        if (! is_string($key) || trim($key) === '') {
            throw new DomainException('Tatum subscription credentials are missing.');
        }

        return $key;
    }

    private function address(CryptoAddressMonitor $monitor): string
    {
        $address = $monitor->relationLoaded('address') ? $monitor->getRelation('address') : null;
        if (! $address instanceof CryptoAddress || (int) $address->id !== (int) $monitor->crypto_address_id
            || (int) $address->merchant_id !== (int) $monitor->merchant_id || $address->network !== $monitor->network) {
            $address = CryptoAddress::query()->forMerchant($monitor->merchant_id)->where('network', $monitor->network)->findOrFail($monitor->crypto_address_id);
            $monitor->setRelation('address', $address);
        }

        return $address->address;
    }

    private function validateId(mixed $id): void
    {
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id)) {
            throw new DomainException('Invalid Tatum subscription identity.');
        }
    }

    /** @param array<string, mixed> $body
     * @param  array<string, int|string>  $query
     */
    private function request(string $method, string $path, string $environment, array $body = [], array $query = []): Response
    {
        $request = Http::acceptJson()->withHeaders(['x-api-key' => $this->key($environment)])
            ->connectTimeout(2)->timeout(3)->withoutRedirecting();
        if ($method === 'GET') {
            $request->retry(2, 100, fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429)), throw: false);
        }
        try {
            return $request->send($method, 'https://api.tatum.io'.$path, $body === [] ? ['query' => $query] : ['query' => $query, 'json' => $body]);
        } catch (ConnectionException|RequestException) {
            throw new DomainException('Tatum subscription request failed.');
        }
    }
}
