<?php

namespace App\Services;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** Fixed Bitcoin/Litecoin/TRON gateways and read-method allowlists only. */
final class TatumChainReadClient
{
    /** @param list<mixed> $params */
    public function utxo(string $network, string $method, array $params = []): mixed
    {
        if (! in_array($network, ['bitcoin-mainnet', 'bitcoin-testnet', 'litecoin-mainnet', 'litecoin-testnet'], true)
            || ! in_array($method, ['getblockchaininfo', 'getblockhash', 'getrawtransaction', 'getblock', 'validateaddress'], true)) {
            throw new DomainException('Unsupported UTXO read request.');
        }
        $body = $this->post($network, '/', ['jsonrpc' => '1.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        if (($body['id'] ?? null) !== 1 || (isset($body['jsonrpc']) && ! in_array($body['jsonrpc'], ['1.0', '2.0'], true))) {
            throw new DomainException('Invalid UTXO RPC envelope.');
        }
        if (($body['error'] ?? null) !== null) {
            if ($method === 'getrawtransaction' && is_array($body['error']) && ($body['error']['code'] ?? null) === -5) {
                return null;
            }
            throw new DomainException('UTXO RPC read was rejected.');
        }
        if (! array_key_exists('result', $body)) {
            throw new DomainException('Missing UTXO RPC result.');
        }

        return $body['result'];
    }

    /** @param array<string, int|string|bool> $params
     * @return array<string, mixed>
     */
    public function tron(string $network, string $view, string $method, array $params = []): array
    {
        if (! in_array($network, ['tron-mainnet', 'tron-shasta'], true) || ! in_array($view, ['wallet', 'walletsolidity'], true)
            || ! in_array($method, ['gettransactioninfobyid', 'gettransactionbyid', 'getblockbynum', 'getnowblock', 'gettransactioninfobyblocknum'], true)) {
            throw new DomainException('Unsupported TRON read request.');
        }
        $body = $this->post($network, '/'.$view.'/'.$method, $params, $method === 'gettransactioninfobyblocknum');
        if (array_key_exists('Error', $body) || array_key_exists('error', $body)) {
            throw new DomainException('TRON read was rejected.');
        }

        return $body;
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $network, string $path, array $body, bool $allowList = false): array
    {
        $key = config(str_ends_with($network, '-mainnet') ? 'services.tatum.api_key' : 'services.tatum.testnet_key');
        if (! is_string($key) || trim($key) === '') {
            throw new DomainException('Tatum read credentials are missing.');
        }
        try {
            $response = Http::acceptJson()->withHeaders(['x-api-key' => $key])->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->retry(2, 100, fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429)))
                ->send('POST', 'https://'.$network.'.gateway.tatum.io'.$path, ['json' => (object) $body])->throw();
            if (! $response->successful() || strlen($response->body()) > 8 * 1024 * 1024) {
                throw new DomainException('Tatum chain read response was rejected.');
            }
            $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($data) || ! str_starts_with(ltrim($response->body()), $allowList ? '[' : '{')) {
                throw new DomainException('Tatum chain read returned an invalid object.');
            }

            return $data;
        } catch (ConnectionException|RequestException|JsonException) {
            throw new DomainException('Tatum chain read failed.');
        }
    }
}
