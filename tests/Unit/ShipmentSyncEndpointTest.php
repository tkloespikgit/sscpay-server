<?php

namespace Tests\Unit;

use App\Support\ShipmentSyncEndpoint;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShipmentSyncEndpointTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_pins_the_validated_public_address_and_disables_environment_proxies(): void
    {
        $endpoint = Mockery::mock(ShipmentSyncEndpoint::class)->makePartial();
        $endpoint->shouldReceive('resolveHost')->once()->with('merchant.example.com')
            ->andReturn(['8.8.8.8', '1.1.1.1']);

        $options = $endpoint->options('https://merchant.example.com:8443/custom/shipment?store=5');

        $this->assertSame('', $options['proxy']);
        $this->assertSame(['merchant.example.com:8443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertArrayNotHasKey('verify', $options);
    }

    public function test_pins_public_ipv6_addresses_using_curl_brackets(): void
    {
        $endpoint = Mockery::mock(ShipmentSyncEndpoint::class)->makePartial();
        $endpoint->shouldReceive('resolveHost')->once()->with('merchant.example.com')
            ->andReturn(['2001:4860:4860::8888']);

        $options = $endpoint->options('https://merchant.example.com/api/shipment');

        $this->assertSame(['merchant.example.com:443:[2001:4860:4860::8888]'], $options['curl'][CURLOPT_RESOLVE]);
    }

    #[DataProvider('unsafeDnsAddresses')]
    public function test_rejects_non_public_dns_addresses_even_when_another_record_is_public(array $addresses): void
    {
        $endpoint = Mockery::mock(ShipmentSyncEndpoint::class)->makePartial();
        $endpoint->shouldReceive('resolveHost')->once()->with('merchant.example.com')->andReturn($addresses);

        $this->expectException(InvalidArgumentException::class);

        $endpoint->options('https://merchant.example.com/api/shipment');
    }

    /** @return array<string, array{list<string>}> */
    public static function unsafeDnsAddresses(): array
    {
        return [
            'loopback' => [['127.0.0.1']],
            'private' => [['10.0.0.2']],
            'metadata' => [['169.254.169.254']],
            'shared address space' => [['100.64.0.1']],
            'public and private' => [['8.8.8.8', '192.168.0.1']],
            'public and ipv6 loopback' => [['8.8.8.8', '::1']],
            'ipv6 unique local' => [['fd00::1']],
            'ipv4 mapped loopback' => [['::ffff:127.0.0.1']],
        ];
    }

    public function test_dns_failure_is_a_retryable_connection_exception(): void
    {
        $endpoint = Mockery::mock(ShipmentSyncEndpoint::class)->makePartial();
        $endpoint->shouldReceive('resolveHost')->once()->with('merchant.example.com')->andReturn([]);

        $this->expectException(ConnectionException::class);

        $endpoint->options('https://merchant.example.com/api/shipment');
    }

    public function test_invalid_endpoints_are_rejected_before_resolving_dns(): void
    {
        $endpoint = Mockery::mock(ShipmentSyncEndpoint::class)->makePartial();
        $endpoint->shouldNotReceive('resolveHost');

        $this->expectException(InvalidArgumentException::class);

        $endpoint->options('https://127.0.0.1/api/shipment');
    }

    public function test_public_ip_literals_are_pinned_without_dns_resolution(): void
    {
        $options = (new ShipmentSyncEndpoint)->options('https://8.8.8.8/api/shipment');

        $this->assertSame(['8.8.8.8:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
    }
}
