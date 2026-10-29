<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\MerchantLogisticsTrackingSetting;
use App\Models\PaymentGroup;
use App\Models\SystemConfig;
use App\Models\TelegramBot;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use App\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class SeventeenTrackAnomalyAndUnshippedTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
        Carrier::create([
            'carrier_name' => 'UPS',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);
    }

    private function enablePlatformAndMerchantTracking(): void
    {
        SystemConfig::set('logistics_tracking.available_drivers', ['seventeen_track'], 'logistics');

        MerchantLogisticsTrackingSetting::query()->create([
            'merchant_id' => $this->merchant->id,
            'driver' => 'seventeen_track',
            'base_url' => 'https://api.17track.test/track/v2.4',
            'api_token' => 'merchant-api-token',
            'webhook_secret' => 'merchant-webhook-secret',
            'webhook_token' => 'wh-token-anomaly-test-aaaaaaaa',
        ]);
    }

    public function test_reflag_marks_fifteen_day_undelivered_and_five_day_stale_shipments(): void
    {
        $noDelivery = $this->makeOrder('SSC-15', 'M-15', [
            'status' => 'shipped',
            'logistics_tracking_enabled' => false,
        ]);
        $noDelivery->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-15',
            'shipped_at' => now()->subHours(LogisticsTrackingSyncService::NO_DELIVERY_HOURS + 1),
            'operator_id' => 0,
            'tracking_status' => 'InTransit',
            'tracking_last_event_at' => now()->subDay(),
        ]);

        $stale = $this->makeOrder('SSC-5', 'M-5', [
            'status' => 'shipped',
            'logistics_tracking_enabled' => false,
        ]);
        $stale->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-5',
            'shipped_at' => now()->subDays(7),
            'operator_id' => 0,
            'tracking_status' => 'InTransit',
            'tracking_last_event_at' => now()->subHours(LogisticsTrackingSyncService::STALE_EVENT_HOURS + 1),
        ]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertTrue($noDelivery->fresh()->shipping->logistics_anomaly);
        $this->assertSame(LogisticsTrackingSyncService::ANOMALY_NO_DELIVERY, $noDelivery->fresh()->shipping->logistics_anomaly_reason);
        $this->assertTrue($stale->fresh()->shipping->logistics_anomaly);
        $this->assertSame(LogisticsTrackingSyncService::ANOMALY_STALE, $stale->fresh()->shipping->logistics_anomaly_reason);
        $this->assertNull($noDelivery->fresh()->shipping->tracking_last_polled_at);
        $this->assertNull($stale->fresh()->shipping->tracking_last_polled_at);
    }

    public function test_reflag_polls_once_on_anomaly_false_to_true_edge_when_gates_pass(): void
    {
        $this->enablePlatformAndMerchantTracking();

        Http::fake([
            'api.17track.test/*' => Http::response([
                'code' => 0,
                'data' => [
                    'accepted' => [[
                        'number' => '1Z-EDGE',
                        'carrier' => 100001,
                        'track_info' => [
                            'latest_status' => ['status' => 'InTransit', 'sub_status' => 'InTransit_Other'],
                            'latest_event' => [
                                'time_utc' => now()->subDays(6)->toIso8601String(),
                                'description' => 'Still in transit',
                            ],
                        ],
                    ]],
                    'rejected' => [],
                ],
            ]),
        ]);

        $order = $this->makeOrder('SSC-EDGE', 'M-EDGE', [
            'status' => 'shipped',
            'logistics_tracking_enabled' => true,
        ]);
        $order->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-EDGE',
            'shipped_at' => now()->subHours(LogisticsTrackingSyncService::NO_DELIVERY_HOURS + 1),
            'operator_id' => 0,
            'tracking_provider' => 'seventeen_track',
            'tracking_registered_at' => now()->subDays(16),
            'tracking_status' => 'InTransit',
            'tracking_last_event_at' => now()->subDay(),
            'logistics_anomaly' => false,
        ]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $shipping = $order->fresh()->shipping;
        $this->assertTrue($shipping->logistics_anomaly);
        $this->assertNotNull($shipping->tracking_last_polled_at);
        $this->assertSame('Still in transit', $shipping->tracking_last_event_desc);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/gettrackinfo'));
    }

    public function test_poll_track_info_applies_snapshot_when_gates_pass(): void
    {
        $this->enablePlatformAndMerchantTracking();

        Http::fake([
            'api.17track.test/*' => Http::response([
                'code' => 0,
                'data' => [
                    'accepted' => [[
                        'number' => '1Z-POLL',
                        'carrier' => 100001,
                        'track_info' => [
                            'latest_status' => ['status' => 'InTransit', 'sub_status' => 'InTransit_Other'],
                            'latest_event' => [
                                'time_utc' => '2026-10-20T08:00:00Z',
                                'description' => 'Polled event',
                            ],
                        ],
                    ]],
                    'rejected' => [],
                ],
            ]),
        ]);

        $order = $this->makeOrder('SSC-POLL', 'M-POLL', [
            'status' => 'shipped',
            'logistics_tracking_enabled' => true,
        ]);
        $shipping = $order->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-POLL',
            'shipped_at' => now()->subDay(),
            'operator_id' => 0,
            'tracking_provider' => 'seventeen_track',
            'tracking_registered_at' => now()->subDay(),
        ]);

        $ok = app(LogisticsTrackingSyncService::class)->pollTrackInfo($shipping);

        $this->assertTrue($ok);
        $shipping->refresh();
        $this->assertSame('InTransit', $shipping->tracking_status);
        $this->assertSame('Polled event', $shipping->tracking_last_event_desc);
        $this->assertNotNull($shipping->tracking_last_polled_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/gettrackinfo'));
    }

    public function test_poll_track_info_skips_when_order_snapshot_disabled(): void
    {
        $this->enablePlatformAndMerchantTracking();
        Http::fake();

        $order = $this->makeOrder('SSC-POLL-OFF', 'M-POLL-OFF', [
            'status' => 'shipped',
            'logistics_tracking_enabled' => false,
        ]);
        $shipping = $order->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-POLL-OFF',
            'shipped_at' => now(),
            'operator_id' => 0,
        ]);

        $this->assertFalse(app(LogisticsTrackingSyncService::class)->pollTrackInfo($shipping));
        Http::assertNothingSent();
    }

    public function test_reflag_does_not_poll_when_already_anomaly(): void
    {
        $this->enablePlatformAndMerchantTracking();
        Http::fake();

        $order = $this->makeOrder('SSC-ALREADY', 'M-ALREADY', [
            'status' => 'shipped',
            'logistics_tracking_enabled' => true,
        ]);
        $order->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-ALREADY',
            'shipped_at' => now()->subHours(LogisticsTrackingSyncService::NO_DELIVERY_HOURS + 1),
            'operator_id' => 0,
            'tracking_status' => 'InTransit',
            'tracking_last_event_at' => now()->subDay(),
            'logistics_anomaly' => true,
            'logistics_anomaly_reason' => LogisticsTrackingSyncService::ANOMALY_NO_DELIVERY,
            'logistics_anomaly_at' => now()->subDay(),
        ]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertTrue($order->fresh()->shipping->logistics_anomaly);
        $this->assertNull($order->fresh()->shipping->tracking_last_polled_at);
        Http::assertNothingSent();
    }

    public function test_unshipped_overdue_command_sends_merchant_telegram_digest(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        TelegramBot::create([
            'merchant_id' => $this->merchant->id,
            'bot_token' => '123:ABC',
            'chat_id' => '-1001',
            'is_enabled' => true,
        ]);

        $this->makeOrder('SSC-OVER', 'M-OVER', [
            'status' => 'paid',
            'paid_at' => now()->subHours(LogisticsTrackingSyncService::UNSHIPPED_HOURS + 2),
        ]);
        $this->makeOrder('SSC-FRESH', 'M-FRESH', [
            'status' => 'paid',
            'paid_at' => now()->subHours(10),
        ]);

        $this->artisan('orders:notify-unshipped-overdue')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'SSC-OVER')
            && ! str_contains((string) $request['text'], 'SSC-FRESH'));
    }

    public function test_order_creation_snapshots_application_logistics_tracking_flag(): void
    {
        $this->makePaymentMethod('snap')->update(['fee_percent' => '0.00']);
        PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'default',
            'group_name' => 'Default',
            'is_active' => true,
        ]);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example/test', 'wp_order_id' => 1]])]);

        $payload = [
            'merchant_order_no' => 'SNAP-ON',
            'currency' => 'USD',
            'group_key' => 'default',
            'payment_method_key' => 'snap',
            'subtotal' => '100.00',
            'discount' => '0.00',
            'shipping_fee' => '0.00',
            'tax' => '0.00',
            'amount' => '100.00',
            'customer_first_name' => 'John',
            'customer_last_name' => 'Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => null,
            'shipping_address_line1' => '123 Main St',
            'shipping_city' => 'City',
            'shipping_country' => 'US',
            'shipping_zip' => null,
            'notify_url' => 'https://shop.example.com/notify',
            'return_url' => 'https://shop.example.com/return',
            'cancel_url' => 'https://shop.example.com/cancel',
            'items' => [[
                'product_id' => '1',
                'product_url' => 'https://shop.example.com/product/1',
                'product_name' => 'Product',
                'unit_price' => '100.00',
                'quantity' => 1,
            ]],
        ];

        $this->application->update(['is_logistics_tracking_enabled' => true]);
        $enabled = app(OrderCreationService::class)->createOrder($payload, $this->merchant, $this->application, 'api');
        $this->assertTrue($enabled->logistics_tracking_enabled);

        $this->application->update(['is_logistics_tracking_enabled' => false]);
        $payload['merchant_order_no'] = 'SNAP-OFF';
        $disabled = app(OrderCreationService::class)->createOrder($payload, $this->merchant, $this->application, 'api');
        $this->assertFalse($disabled->logistics_tracking_enabled);
        $this->assertTrue($enabled->fresh()->logistics_tracking_enabled);
    }
}
