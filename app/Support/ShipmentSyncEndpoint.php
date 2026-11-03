<?php

namespace App\Support;

use App\Rules\ShipmentSyncUrl;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use RuntimeException;

class ShipmentSyncEndpoint
{
    /** @return array{proxy: string, curl: array<int, list<string>>} */
    public function options(string $url): array
    {
        if (! ShipmentSyncUrl::isValid($url)) {
            throw new InvalidArgumentException('Invalid HTTPS shipment endpoint.');
        }

        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('The cURL extension is required for shipment synchronization.');
        }

        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'], '[]'));
        $addresses = $this->resolveHost($host);

        if ($addresses === []) {
            throw new ConnectionException('Shipment endpoint could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                throw new InvalidArgumentException('Shipment endpoint must resolve to public IP addresses.');
            }
        }

        $address = $addresses[0];
        if (str_contains($address, ':')) {
            $address = '['.$address.']';
        }
        $port = $parts['port'] ?? 443;

        return [
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$address]],
        ];
    }

    /** @return list<string> */
    public function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw new ConnectionException('Shipment endpoint could not be resolved.');
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            throw new ConnectionException('Shipment endpoint could not be resolved.');
        }

        return array_values(array_unique($addresses));
    }
}
