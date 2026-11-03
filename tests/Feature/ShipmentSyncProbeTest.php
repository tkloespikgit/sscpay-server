<?php

namespace Tests\Feature;

use App\Services\ShipmentSyncProbeService;
use App\Support\ShipmentSyncEndpoint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ShipmentSyncProbeTest extends TestCase
{
    private const URL = 'https://probe.example.com/custom/shipment?source=sscpay&store=main';

    public function test_the_probe_uses_head_with_the_complete_query_without_credentials_a_body_or_redirects(): void
    {
        $this->resolveEndpointTo(['93.184.216.34']);
        Http::preventStrayRequests();
        $transportOptions = [];
        Http::fake([self::URL => function (Request $request, array $options) use (&$transportOptions) {
            $transportOptions = $options;

            return Http::response('', 200);
        }]);

        $result = app(ShipmentSyncProbeService::class)->probe(self::URL);

        $this->assertSame(['outcome' => 'reachable', 'status' => 200], $result);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('HEAD', $request->method());
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('', $request->body());
            $this->assertSame([], $request->header('App-ID'));
            $this->assertSame([], $request->header('Timestamp'));
            $this->assertSame([], $request->header('X-Nonce'));
            $this->assertSame([], $request->header('Authorization'));

            return true;
        });
        $this->assertFalse($transportOptions['allow_redirects']);
        $this->assertSame(3, $transportOptions['connect_timeout']);
        $this->assertSame(10, $transportOptions['timeout']);
        $this->assertSame('', $transportOptions['proxy']);
        $this->assertSame(['probe.example.com:443:93.184.216.34'], $transportOptions['curl'][CURLOPT_RESOLVE]);
        Http::assertSentCount(1);
    }

    #[DataProvider('responseOutcomes')]
    public function test_http_responses_are_classified_without_posting_or_retrying(int $status, string $outcome): void
    {
        $this->resolveEndpointTo(['93.184.216.34']);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('', $status, ['Location' => 'https://untrusted.example.com/redirected'])]);

        $result = app(ShipmentSyncProbeService::class)->probe(self::URL);

        $this->assertSame(['outcome' => $outcome, 'status' => $status], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD' && $request->url() === self::URL);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int, string}> */
    public static function responseOutcomes(): array
    {
        return [
            'successful response' => [200, 'reachable'],
            'empty successful response' => [204, 'reachable'],
            'authentication required' => [401, 'requires_post'],
            'forbidden head request' => [403, 'requires_post'],
            'post required' => [405, 'requires_post'],
            'missing endpoint' => [404, 'not_found'],
            'redirect' => [302, 'redirect'],
            'service failure' => [500, 'server_error'],
            'gateway failure' => [502, 'server_error'],
            'bad request' => [400, 'rejected'],
            'rate limit' => [429, 'rejected'],
        ];
    }

    public function test_connection_failures_are_reported_without_exposing_the_transport_error_or_retrying(): void
    {
        $this->resolveEndpointTo(['93.184.216.34']);
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake([self::URL => function (Request $request) use (&$attempts) {
            $attempts++;

            return Http::failedConnection('cURL failed https://internal.example.com/?secret=private-token')($request);
        }]);

        $result = app(ShipmentSyncProbeService::class)->probe(self::URL);

        $this->assertSame(['outcome' => 'connection_error', 'status' => null], $result);
        $this->assertSame(1, $attempts);
    }

    public function test_unexpected_errors_are_reported_without_exposing_raw_exception_details(): void
    {
        $this->resolveEndpointTo(['93.184.216.34']);
        Http::preventStrayRequests();
        Http::fake([self::URL => function () {
            throw new RuntimeException('Internal transport exception containing private-token');
        }]);

        $result = app(ShipmentSyncProbeService::class)->probe(self::URL);

        $this->assertSame(['outcome' => 'probe_error', 'status' => null], $result);
    }

    public function test_a_non_https_address_is_rejected_before_any_http_request(): void
    {
        Http::preventStrayRequests();

        $result = app(ShipmentSyncProbeService::class)->probe('http://probe.example.com/custom/shipment');

        $this->assertSame(['outcome' => 'invalid_address', 'status' => null], $result);
        Http::assertNothingSent();
    }

    public function test_a_hostname_resolving_to_a_private_address_is_rejected_before_any_http_request(): void
    {
        $this->resolveEndpointTo(['10.0.0.7']);
        Http::preventStrayRequests();

        $result = app(ShipmentSyncProbeService::class)->probe(self::URL);

        $this->assertSame(['outcome' => 'invalid_address', 'status' => null], $result);
        Http::assertNothingSent();
    }

    public function test_dns_failure_is_reported_as_a_connection_error_without_sending_a_request(): void
    {
        $this->resolveEndpointTo([]);
        Http::preventStrayRequests();

        $result = app(ShipmentSyncProbeService::class)->probe(self::URL);

        $this->assertSame(['outcome' => 'connection_error', 'status' => null], $result);
        Http::assertNothingSent();
    }

    /** @param list<string> $addresses */
    private function resolveEndpointTo(array $addresses): void
    {
        $this->partialMock(ShipmentSyncEndpoint::class)
            ->shouldReceive('resolveHost')
            ->once()
            ->with('probe.example.com')
            ->andReturn($addresses);
    }
}
