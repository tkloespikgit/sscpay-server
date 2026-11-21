<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\TestCase;

class OrderCustomerIpTest extends TestCase
{
    use CreatesCombinedPayments;
    use RefreshDatabase;

    private const STORE_SERVER_IP = '192.0.2.15';

    private const CUSTOMER_IPV4 = '198.51.100.20';

    private const CUSTOMER_IPV6 = '2001:db8::20';

    private const SINGLE_ORDER = ['subtotal' => '100.00', 'amount' => '100.00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCombinedPayments();
        $this->group->update(['is_combine_payment_enabled' => false]);
        $this->combinedMethod();
        $this->withServerVariables(['REMOTE_ADDR' => self::STORE_SERVER_IP]);
    }

    public static function customerAddresses(): array
    {
        return [
            'IPv4' => [['customer_ip' => self::CUSTOMER_IPV4], self::CUSTOMER_IPV4],
            'IPv6' => [['customer_ip' => self::CUSTOMER_IPV6], self::CUSTOMER_IPV6],
            'legacy' => [[], null],
            'null' => [['customer_ip' => null], null],
        ];
    }

    #[DataProvider('customerAddresses')]
    public function test_only_the_signed_customer_address_is_stored_and_forwarded(array $fields, ?string $expected): void
    {
        $this->fakeCombinedGateway();

        $this->createCombined(self::SINGLE_ORDER + $fields)
            ->assertOk()->assertJsonMissingPath('data.customer_ip')->assertJsonMissingPath('data.checkout_customer_ip');

        $order = Order::sole();
        $this->assertSame(self::STORE_SERVER_IP, $order->customer_ip);
        $this->assertSame($expected, $order->checkout_customer_ip);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $this->hasExpectedGatewayAddress($request, $expected));
    }

    public function test_unsigned_query_or_forwarding_headers_do_not_supply_customer_ip(): void
    {
        $this->fakeCombinedGateway();
        $this->withHeaders(['X-Forwarded-For' => self::CUSTOMER_IPV4, 'X-Customer-IP' => self::CUSTOMER_IPV4]);

        $this->signedCombinedRequest('/api/order/create?customer_ip='.self::CUSTOMER_IPV4, $this->combinedCreationBody(self::SINGLE_ORDER))
            ->assertOk();

        $this->assertNull(Order::sole()->checkout_customer_ip);
        Http::assertSent(fn (Request $request): bool => $this->hasExpectedGatewayAddress($request, null));
    }

    public static function malformedAddresses(): array
    {
        return ['invalid literal' => ['not-an-ip'], 'proxy chain' => ['192.0.2.1, 192.0.2.2'], 'array' => [[self::CUSTOMER_IPV4]]];
    }

    #[DataProvider('malformedAddresses')]
    public function test_malformed_customer_addresses_do_not_create_an_order(string|array $address): void
    {
        $this->createCombined(self::SINGLE_ORDER + ['customer_ip' => $address])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_ip');

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_changing_the_address_after_signing_rejects_the_order_without_logging_it(): void
    {
        Log::spy();
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body = $this->combinedCreationBody(self::SINGLE_ORDER + ['customer_ip' => self::CUSTOMER_IPV4]);
        $body['sign'] = SignatureCanonicalizer::sign($this->application->app_id, $timestamp, $nonce, $body, $this->application->api_key);
        $body['customer_ip'] = self::CUSTOMER_IPV6;

        $this->withHeaders(['App-ID' => $this->application->app_id, 'Timestamp' => $timestamp, 'X-Nonce' => $nonce])
            ->postJson('/api/order/create', $body)->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Signature mismatch'
            && ! str_contains(json_encode($context), self::CUSTOMER_IPV6))->once();
    }

    #[DataProvider('customerAddresses')]
    public function test_gateway_retry_preserves_the_original_address_or_its_absence(array $fields, ?string $expected): void
    {
        Http::fake(['https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::sequence()
            ->push(['code' => 10008, 'message' => 'Temporary error'], 502)
            ->push(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/session']])]);
        $this->createCombined(self::SINGLE_ORDER + $fields)->assertStatus(502);
        $order = Order::sole();

        $this->createCombined(self::SINGLE_ORDER + ['customer_ip' => '203.0.113.30'])
            ->assertOk()->assertJsonPath('data.order_no', $order->order_no);

        $this->assertSame($expected, Order::sole()->checkout_customer_ip);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($this->hasExpectedGatewayAddress($request, $expected));
        }
    }

    private function hasExpectedGatewayAddress(Request $request, ?string $expected): bool
    {
        return $expected === null
            ? ! array_key_exists('customer_ip', $request->data())
            : $request['customer_ip'] === $expected;
    }
}
