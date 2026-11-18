<?php

namespace App\Services;

use App\Models\CryptoPaymentRequest;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoRuntimeSettings;
use App\Support\CryptoTronAddress;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** Address-index results are discovery hints. Amounts are only accepted by the existing RPC verifiers. */
final class TatumAddressHistoryClient
{
    public function __construct(private readonly CryptoAssetRegistry $assets) {}

    /** @param array{from: int, to: int, from_time: int, to_time: int, stage: int, cursor: int|string} $range
     * @return array{hashes: list<string>, next: int|string|null}
     */
    public function page(CryptoPaymentRequest $request, array $range): array
    {
        $network = $request->network;
        $this->assets->get($network.':'.$request->asset);
        $address = $request->cryptoAddress->address;
        $contract = $request->quote_snapshot['payment_config_snapshot']['contract_address'] ?? null;
        $base = ['chain' => $network, 'blockFrom' => $range['from'], 'blockTo' => $range['to'], 'pageSize' => 50, 'offset' => $range['cursor']];
        $tron = str_starts_with($network, 'tron-');
        $internal = $request->asset === 'ETH' && $range['stage'] === 1;
        if ($tron) {
            $query = ['onlyTo' => 'true', 'orderBy' => 'block_timestamp,asc',
                'minTimestamp' => $range['from_time'] * 1000, 'maxTimestamp' => $range['to_time'] * 1000,
                'contractAddress' => $contract];
            if ($range['cursor'] !== 0) {
                $query['next'] = $range['cursor'];
            }
            $body = $this->get($network, '/v3/tron/transaction/account/'.rawurlencode($address).'/trc20', $query, 'transactions');
            $rows = $body['transactions'];
            $next = $body['next'] ?? null;
            if ($next !== null && (! is_string($next) || $next === '' || strlen($next) > 2048 || $next === $range['cursor'])) {
                throw new DomainException('Invalid address history continuation.');
            }
        } elseif (str_starts_with($network, 'ethereum-')) {
            $path = $internal ? '/v4/data/blockchains/transaction/internal' : '/v4/data/transaction/history';
            $query = $internal ? [...$base, 'address' => $address, 'sort' => 'asc']
                : [...$base, 'addresses' => $address, 'sort' => 'ASC', 'transactionSubtype' => 'incoming',
                    'transactionTypes' => $request->asset === 'ETH' ? 'native' : 'fungible'];
            if ($request->asset === 'USDT') {
                $query['tokenAddress'] = $contract;
            }
            $body = $this->get($network, $path, $query, $internal ? null : 'result');
            $rows = $internal ? $body : $body['result'];
            $next = count($rows) === 50 ? (int) $range['cursor'] + 50 : null;
        } else {
            $rows = $this->get($network, '/v4/data/blockchains/transaction/history/utxos', [...$base, 'address' => $address, 'txType' => 'incoming']);
            $next = count($rows) === 50 ? (int) $range['cursor'] + 50 : null;
        }
        if (count($rows) > ($tron ? 200 : 50)) {
            throw new DomainException('Oversized address history page.');
        }
        $hashes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('Malformed address history row.');
            }
            $hash = $row[$tron ? 'txID' : 'hash'] ?? null;
            if (! is_string($hash) || ! preg_match('/^(?:0x)?[a-fA-F0-9]{64}$/D', $hash)) {
                throw new DomainException('Invalid address history transaction identity.');
            }
            if (! $tron) {
                $height = $row['blockNumber'] ?? null;
                if ((! is_int($height) && ! is_string($height)) || ! preg_match('/^[0-9]{1,12}$/D', (string) $height)
                    || (int) $height < $range['from'] || (int) $height > $range['to']) {
                    throw new DomainException('Address history returned a transaction outside the fixed range.');
                }
            }
            if ($tron) {
                if (($row['type'] ?? null) !== 'Transfer') {
                    continue;
                }
                if (CryptoTronAddress::hex($row['to'] ?? '') !== CryptoTronAddress::hex($address)
                    || CryptoTronAddress::hex($row['tokenInfo']['address'] ?? '') !== CryptoTronAddress::hex($contract ?? '')) {
                    throw new DomainException('Address history recipient or contract mismatch.');
                }
            } elseif ($internal) {
                if (! in_array($row['isError'] ?? null, ['0', '1'], true)) {
                    throw new DomainException('Missing internal transfer execution status.');
                }
                if ($row['isError'] === '1' || strtolower($row['to'] ?? '') !== strtolower($address)) {
                    continue;
                }
            } elseif (str_starts_with($network, 'ethereum-')) {
                if (($row['chain'] ?? null) !== $network || strtolower($row['address'] ?? '') !== strtolower($address)
                    || ($row['transactionSubtype'] ?? null) !== 'incoming'
                    || ($row['transactionType'] ?? null) !== ($request->asset === 'ETH' ? 'native' : 'fungible')
                    || ($request->asset === 'USDT' && strtolower($row['tokenAddress'] ?? '') !== strtolower($contract ?? ''))) {
                    throw new DomainException('Address history asset binding mismatch.');
                }
            } else {
                $outputs = $row['outputs'] ?? null;
                if (! is_array($outputs) || ! array_is_list($outputs) || ! collect($outputs)->contains(
                    fn ($output): bool => is_array($output) && is_string($output['address'] ?? null)
                        && $this->assets->addressKey($network, $output['address']) === $request->cryptoAddress->address_key
                )) {
                    throw new DomainException('UTXO address history omitted the recipient.');
                }
            }
            $hashes[] = strtolower(str_starts_with($hash, '0x') ? substr($hash, 2) : $hash);
        }

        return ['hashes' => array_values(array_unique($hashes)), 'next' => $next];
    }

    /** @param array<string, mixed> $query */
    private function get(string $network, string $path, array $query, ?string $listKey = null): array
    {
        $key = CryptoRuntimeSettings::get(str_ends_with($network, '-mainnet') ? 'api_key' : 'testnet_key');
        if (! is_string($key) || trim($key) === '') {
            throw new DomainException('Tatum address history credentials are missing.');
        }
        try {
            $response = Http::acceptJson()->withHeaders(['x-api-key' => $key])->connectTimeout(2)->timeout(8)->withoutRedirecting()
                ->retry(2, 100, fn (?Throwable $error): bool => $error instanceof ConnectionException
                    || ($error instanceof RequestException && ($error->response->serverError() || $error->response->status() === 429)))
                ->get('https://api.tatum.io'.$path, $query)->throw();
            if (! $response->successful() || strlen($response->body()) > 8 * 1024 * 1024) {
                throw new DomainException('Tatum address history response was rejected.');
            }
            $object = json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($listKey === null ? $object : ($object->{$listKey} ?? null))) {
                throw new DomainException('Tatum address history returned an incomplete page.');
            }

            return json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (ConnectionException|RequestException|JsonException) {
            throw new DomainException('Tatum address history request failed.');
        }
    }
}
