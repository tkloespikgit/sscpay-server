<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Carrier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderStatusApiCompatibilityTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_query_preserves_the_paid_contract_and_exposes_independent_progress(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->settlingOrder();

        $this->signedRequest('/api/order/query', ['order_no' => $order->order_no])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.order_status', 'pending_settlement')
            ->assertJsonPath('data.settlement_status', 'pending')
            ->assertJsonPath('data.shipping_status', 'unshipped');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_settlement']);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    public function test_shipping_keeps_payment_based_settlement_progress_in_the_response(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();
        $order = $this->settlingOrder();
        Carrier::create(['carrier_name' => 'UPS', 'carrier_code' => 'ups', 'status' => Carrier::STATUS_ENABLED]);

        $this->signedRequest('/api/order/ship', [
            'merchant_order_no' => $order->merchant_order_no,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-API-STATUS',
            'shipped_at' => now()->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.order_status', 'pending_settlement')
            ->assertJsonPath('data.settlement_status', 'pending')
            ->assertJsonPath('data.shipping_status', 'shipped');

        $this->assertDatabaseHas('order_shippings', ['order_id' => $order->id, 'tracking_number' => '1Z-API-STATUS']);
        $this->assertSame('pending_settlement', $order->refresh()->status);
    }

    public function test_legacy_orders_expose_no_fabricated_settlement(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('SSC-LEGACY', 'M-LEGACY', ['status' => 'completed']);

        $this->signedRequest('/api/order/query', ['order_no' => $order->order_no])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.order_status', 'completed')
            ->assertJsonPath('data.settlement_status', null)
            ->assertJsonPath('data.shipping_status', 'unshipped');

        $this->assertDatabaseCount('order_settlements', 0);
    }

    public function test_query_cannot_expose_another_merchants_progress(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->settlingOrder();
        $otherMerchant = Merchant::create(['name' => 'Other Merchant']);
        $otherApplication = Application::createWithCredentials([
            'merchant_id' => $otherMerchant->id, 'name' => 'Other App', 'website' => 'https://other.example.com',
        ]);

        $this->signedRequest('/api/order/query', ['order_no' => $order->order_no], $otherApplication)
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND')
            ->assertJsonMissingPath('data');
    }

    public function test_query_requires_a_valid_signature(): void
    {
        $this->postJson('/api/order/query', ['order_no' => 'SSC-STATUS'])->assertUnauthorized();
    }

    private function settlingOrder(): Order
    {
        $order = $this->makeOrder('SSC-STATUS', 'M-STATUS', [
            'status' => 'pending_settlement', 'paid_at' => now(), 'settlement_mode' => 'immediate', 'settlement_days' => 2,
        ]);
        OrderSettlement::create([
            'merchant_id' => $order->merchant_id, 'order_id' => $order->id,
            'amount' => '100.00', 'remaining_amount' => '100.00', 'mode' => 'immediate', 'cycle_days' => 2,
            'status' => OrderSettlement::STATUS_PENDING, 'started_at' => now(), 'due_at' => now()->addDays(2),
        ]);

        return $order;
    }

    /** @param array<string, mixed> $body */
    private function signedRequest(string $path, array $body, ?Application $application = null): TestResponse
    {
        $application ??= $this->application;
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $body['sign'] = SignatureCanonicalizer::sign($application->app_id, $timestamp, $nonce, $body, $application->api_key);

        return $this->withHeaders([
            'App-ID' => $application->app_id, 'Timestamp' => $timestamp, 'X-Nonce' => $nonce,
        ])->postJson($path, $body);
    }
}
