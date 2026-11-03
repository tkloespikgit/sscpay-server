<?php

namespace App\Services;

use App\Support\ShipmentSyncEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final readonly class ShipmentSyncProbeService
{
    public function __construct(private ShipmentSyncEndpoint $endpoint) {}

    /** @return array{outcome: string, status: ?int} */
    public function probe(string $url): array
    {
        try {
            $response = Http::withOptions($this->endpoint->options($url))
                ->connectTimeout(3)->timeout(10)->withoutRedirecting()->head($url);
            $status = $response->status();

            return [
                'outcome' => match (true) {
                    $status >= 200 && $status < 300 => 'reachable',
                    in_array($status, [401, 403, 405], true) => 'requires_post',
                    $status === 404 => 'not_found',
                    $status >= 300 && $status < 400 => 'redirect',
                    $status >= 500 => 'server_error',
                    default => 'rejected',
                },
                'status' => $status,
            ];
        } catch (InvalidArgumentException $exception) {
            return ['outcome' => 'invalid_address', 'status' => null];
        } catch (ConnectionException $exception) {
            return ['outcome' => 'connection_error', 'status' => null];
        } catch (Throwable $exception) {
            return ['outcome' => 'probe_error', 'status' => null];
        }
    }
}
