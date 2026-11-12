<?php

namespace App\Services;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** Only read RPC methods on fixed Tatum hosts, never signing or transaction submission. */
final class TatumEvmRpcClient
{
    /** @param list<mixed> $params */
    public function read(string $network, string $method, array $params = []): mixed
    {
        if (! in_array($network, ['ethereum-mainnet', 'ethereum-sepolia'], true)
            || ! in_array($method, ['eth_chainId', 'eth_getTransactionByHash', 'eth_getTransactionReceipt', 'eth_getBlockByNumber',
                'eth_getLogs', 'debug_traceTransaction', 'debug_traceBlockByNumber'], true)) {
            throw new DomainException('Unsupported read-only EVM RPC request.');
        }
        $key = config($network === 'ethereum-mainnet' ? 'services.tatum.api_key' : 'services.tatum.testnet_key');
        if (! is_string($key) || trim($key) === '') {
            throw new DomainException('Tatum RPC credentials are missing.');
        }
        try {
            $response = Http::acceptJson()->withHeaders(['x-api-key' => $key])
                ->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->retry(2, 100, fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429)))
                ->post('https://'.$network.'.gateway.tatum.io/', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params])->throw();
            if (! $response->successful() || strlen($response->body()) > 4 * 1024 * 1024) {
                throw new DomainException('Tatum EVM RPC response was rejected.');
            }
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0' || ($body['id'] ?? null) !== 1
                || array_key_exists('error', $body) || ! array_key_exists('result', $body)) {
                throw new DomainException('Tatum EVM RPC response was rejected.');
            }
            if (in_array($method, ['eth_getLogs', 'debug_traceBlockByNumber'], true)
                && ! is_array(json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)->result)) {
                throw new DomainException('Tatum EVM RPC expected a complete list.');
            }

            return $body['result'];
        } catch (ConnectionException|RequestException|JsonException) {
            throw new DomainException('Tatum EVM RPC read failed.');
        }
    }
}
