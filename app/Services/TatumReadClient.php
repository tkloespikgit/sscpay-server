<?php

namespace App\Services;

use App\Models\SystemConfig;
use App\Support\CryptoAmount;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoPublicKey;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/** Explicit read-only allow-list. No wallet creation, private key, signing, or broadcast endpoints. */
final class TatumReadClient
{
    public function __construct(private readonly CryptoAssetRegistry $assets) {}

    public function deriveAddress(string $network, string $extendedPublicKey, int $index): string
    {
        CryptoPublicKey::payload($extendedPublicKey);
        $family = explode('-', $network, 2)[0];
        $this->assets->get($network.':'.match ($family) {
            'bitcoin' => 'BTC', 'litecoin' => 'LTC', 'ethereum' => 'ETH', 'tron' => 'USDT',
            default => throw new DomainException('Unsupported address network.'),
        });
        if ($index < 0 || $index > 2147483647) {
            throw new DomainException('Public derivation index is out of range.');
        }
        $query = $network === 'ethereum-sepolia' ? ['testnetType' => 'ethereum-sepolia'] : [];
        $result = $this->get('/v3/'.$family.'/address/'.$extendedPublicKey.'/'.$index, $query, ! str_ends_with($network, '-mainnet'));
        $address = $result['address'] ?? null;
        if (! is_string($address)) {
            throw new DomainException('Tatum returned an invalid public address.');
        }
        try {
            $this->assets->addressKey($network, $address);
        } catch (InvalidArgumentException) {
            throw new DomainException('Tatum returned an invalid public address.');
        }

        return $address;
    }

    /** @return array{value: string, at: CarbonImmutable, source: string} */
    public function usdPrice(string $assetKey): array
    {
        $asset = $this->assets->get($assetKey);
        $result = $this->get('/v4/data/rate/symbol', ['symbol' => $asset['asset'], 'basePair' => 'USD']);
        $value = $result['value'] ?? null;
        $timestamp = $result['timestamp'] ?? null;
        if (! is_string($value) || ! is_int($timestamp) || $timestamp <= 0
            || ($result['basePair'] ?? null) !== 'USD' || ($result['symbol'] ?? null) !== $asset['asset']) {
            throw new DomainException('Tatum returned an invalid USD market quote.');
        }
        try {
            [$numerator] = CryptoAmount::fraction($value);
        } catch (InvalidArgumentException) {
            throw new DomainException('Tatum returned an invalid USD market quote.');
        }
        if ($numerator === '0') {
            throw new DomainException('Tatum market price must be positive.');
        }
        $maxAge = SystemConfig::get('crypto.max_market_price_age_seconds');
        if (! is_string($maxAge) || ! preg_match('/^[1-9][0-9]{0,6}$/D', $maxAge)
            || $timestamp > now()->getTimestampMs() || $timestamp < now()->getTimestampMs() - (int) $maxAge * 1000) {
            throw new DomainException('Tatum market quote is stale or freshness is unconfigured.');
        }
        $at = CarbonImmutable::createFromTimestampMs($timestamp);

        return ['value' => $value, 'at' => $at, 'source' => 'Tatum'];
    }

    /** @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = [], bool $testnet = false): array
    {
        $key = config($testnet ? 'services.tatum.testnet_key' : 'services.tatum.api_key');
        if (! is_string($key) || trim($key) === '') {
            throw new DomainException('Tatum credentials are not configured for this environment.');
        }
        try {
            $response = Http::acceptJson()->withHeaders(['x-api-key' => $key])
                ->connectTimeout((int) config('services.tatum.connect_timeout', 3))
                ->timeout((int) config('services.tatum.timeout', 10))
                ->withoutRedirecting()->retry(2, 200, fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429)))
                ->get('https://api.tatum.io'.$path, $query)->throw();
            if (! $response->successful()) {
                throw new DomainException('Tatum read request failed.');
            }
            $data = $response->json();
            if (! is_array($data) || array_is_list($data)) {
                throw new DomainException('Tatum returned an invalid response.');
            }

            return $data;
        } catch (ConnectionException|RequestException) {
            throw new DomainException('Tatum read request failed.');
        }
    }
}
