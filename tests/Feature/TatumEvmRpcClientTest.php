<?php

namespace Tests\Feature;

use App\Services\TatumEvmRpcClient;
use App\Support\CryptoEvm;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TatumEvmRpcClientTest extends TestCase
{
    #[DataProvider('networks')]
    public function test_network_selects_fixed_host_and_separate_credential(string $network, string $key): void
    {
        config(['services.tatum.api_key' => 'mainnet-key', 'services.tatum.testnet_key' => 'testnet-key']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1'])]);

        $this->assertSame('0x1', app(TatumEvmRpcClient::class)->read($network, 'eth_chainId'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://'.$network.'.gateway.tatum.io/' && $request->hasHeader('x-api-key', $key)
            && $request['method'] === 'eth_chainId' && $request['params'] === []);
    }

    public static function networks(): array
    {
        return ['mainnet' => ['ethereum-mainnet', 'mainnet-key'], 'testnet' => ['ethereum-sepolia', 'testnet-key']];
    }

    #[DataProvider('badResponses')]
    public function test_http_or_rpc_errors_cannot_be_returned_as_chain_facts(mixed $body, int $status): void
    {
        config(['services.tatum.testnet_key' => 'testnet-key']);
        Http::fake(['*' => Http::response($body, $status, ['Location' => 'https://untrusted.example/'])]);
        try {
            app(TatumEvmRpcClient::class)->read('ethereum-sepolia', 'eth_chainId');
            $this->fail('Malformed RPC response was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('provider-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public static function badResponses(): array
    {
        return [
            'redirect' => ['', 302], 'unauthorized' => [['error' => 'provider-secret'], 401],
            'rpc error' => [['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'provider-secret']], 200],
            'wrong id' => [['jsonrpc' => '2.0', 'id' => 2, 'result' => '0x1'], 200],
            'wrong version' => [['jsonrpc' => '1.0', 'id' => 1, 'result' => '0x1'], 200],
            'missing result' => [['jsonrpc' => '2.0', 'id' => 1], 200], 'invalid JSON' => ['{provider-secret', 200],
        ];
    }

    public function test_only_transient_read_failures_are_retried(): void
    {
        config(['services.tatum.testnet_key' => 'testnet-key']);
        Http::fake(['*' => Http::sequence()->push([], 429)->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => null])]);

        $this->assertNull(app(TatumEvmRpcClient::class)->read('ethereum-sepolia', 'eth_getTransactionReceipt', ['0x'.str_repeat('c', 64)]));
        Http::assertSentCount(2);
    }

    public function test_missing_credentials_stop_before_http(): void
    {
        config(['services.tatum.testnet_key' => null]);
        Http::preventStrayRequests();
        try {
            app(TatumEvmRpcClient::class)->read('ethereum-sepolia', 'eth_chainId');
            $this->fail('Missing credentials were accepted.');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }

    public function test_arbitrary_network_cannot_change_the_outbound_host(): void
    {
        Http::preventStrayRequests();
        $this->expectException(DomainException::class);

        app(TatumEvmRpcClient::class)->read('attacker.example', 'eth_chainId');
    }

    #[DataProvider('badQuantities')]
    public function test_invalid_hex_quantities_cannot_be_cast_to_money(mixed $value): void
    {
        $this->expectException(DomainException::class);

        CryptoEvm::quantity($value);
    }

    public static function badQuantities(): array
    {
        return ['float' => [1.0], 'integer' => [1], 'empty' => ['0x'], 'padded' => ['0x01'],
            'negative' => ['-0x1'], 'overflow' => ['0x1'.str_repeat('0', 64)], 'whitespace' => ['0x1 '], 'invalid digits' => ['0xz']];
    }
}
