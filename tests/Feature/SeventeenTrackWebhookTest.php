<?php

namespace Tests\Feature;

use App\Jobs\RegisterLogisticsTrackingJob;
use App\Models\Carrier;
use App\Models\MerchantLogisticsTrackingSetting;
use App\Models\OrderShipping;
use App\Models\SystemConfig;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use App\Services\OrderShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class SeventeenTrackWebhookTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private string $secret = 'test-webhook-secret';

    private string $webhookToken = 'wh-token-test-aaaaaaaaaaaaaaaa';

    private string $apiToken = 'merchant-api-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        Carrier::create([
            'carrier_name' => 'UPS',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);

        SystemConfig::set('logistics_tracking.available_drivers', ['seventeen_track'], 'logistics');

        MerchantLogisticsTrackingSetting::query()->create([
            'merchant_id' => $this->merchant->id,
            'driver' => 'seventeen_track',
            'base_url' => 'https://api.17track.test/track/v2.4',
            'api_token' => $this->apiToken,
            'webhook_secret' => $this->secret,
            'webhook_token' => $this->webhookToken,
        ]);
    }

    private function webhookPath(): string
    {
        return '/api/webhooks/logistics-tracking/seventeen_track/'.$this->webhookToken;
    }

    public function test_invalid_signature_is_rejected_without_mutating_shipping(): void
    {
        $shipping = $this->createShipping('1Z-SIGN', orderAttrs: ['logistics_tracking_enabled' => true]);

        $this->call('POST', $this->webhookPath(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGN' => 'deadbeef',
        ], $this->payloadJson('1Z-SIGN', 'InTransit'))->assertUnauthorized();

        $this->assertNull($shipping->fresh()->tracking_status);
    }

    public function test_valid_tracking_updated_webhook_writes_status_and_clears_anomaly_on_delivered(): void
    {
        $shipping = $this->createShipping('1Z-OK', [
            'shipped_at' => now()->subDays(20),
            'logistics_anomaly' => true,
            'logistics_anomaly_reason' => LogisticsTrackingSyncService::ANOMALY_NO_DELIVERY,
            'logistics_anomaly_at' => now()->subDay(),
        ], orderAttrs: ['logistics_tracking_enabled' => true]);

        $body = $this->payloadJson('1Z-OK', 'Delivered', '2026-10-20T10:00:00Z');
        $this->call('POST', $this->webhookPath(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGN' => hash('sha256', $body.'/'.$this->secret),
        ], $body)->assertOk();

        $shipping->refresh();
        $this->assertSame('Delivered', $shipping->tracking_status);
        $this->assertNotNull($shipping->tracking_delivered_at);
        $this->assertNotNull($shipping->tracking_checked_at);
        $this->assertFalse($shipping->logistics_anomaly);
        $this->assertNull($shipping->logistics_anomaly_reason);
    }

    public function test_webhook_ignores_writes_when_order_snapshot_disabled(): void
    {
        $shipping = $this->createShipping('1Z-OFF', [
            'tracking_registered_at' => now()->subDay(),
            'tracking_provider' => 'seventeen_track',
        ], orderAttrs: ['logistics_tracking_enabled' => false]);

        $body = $this->payloadJson('1Z-OFF', 'InTransit');
        $this->call('POST', $this->webhookPath(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGN' => hash('sha256', $body.'/'.$this->secret),
        ], $body)->assertOk()
            ->assertJson(['code' => 0, 'updated' => 0]);

        $this->assertNull($shipping->fresh()->tracking_status);
    }

    public function test_unknown_webhook_token_returns_not_found(): void
    {
        $body = $this->payloadJson('1Z-X', 'InTransit');
        $this->call('POST', '/api/webhooks/logistics-tracking/seventeen_track/unknown-token', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGN' => hash('sha256', $body.'/'.$this->secret),
        ], $body)->assertNotFound();
    }

    public function test_recording_shipment_dispatches_logistics_tracking_registration_job(): void
    {
        Queue::fake();
        $order = $this->makeOrder('SSC-REG', 'M-REG', [
            'status' => 'paid',
            'logistics_tracking_enabled' => true,
        ]);

        app(OrderShippingService::class)->record($order, 0, [
            'logistics_company' => 'ups',
            'tracking_number' => '1Z-REG',
            'shipped_at' => now(),
        ]);

        Queue::assertPushed(RegisterLogisticsTrackingJob::class);
    }

    public function test_register_job_posts_when_triple_gates_pass(): void
    {
        Http::fake([
            'api.17track.test/*' => Http::response([
                'code' => 0,
                'data' => [
                    'accepted' => [['number' => '1Z-JOB', 'carrier' => 100001]],
                    'rejected' => [],
                ],
            ]),
        ]);

        $shipping = $this->createShipping('1Z-JOB', orderAttrs: ['logistics_tracking_enabled' => true]);
        (new RegisterLogisticsTrackingJob($shipping->id))->handle(app(LogisticsTrackingSyncService::class));

        $shipping->refresh();
        $this->assertNotNull($shipping->tracking_registered_at);
        $this->assertSame('100001', $shipping->tracking_carrier_code);
        $this->assertSame('seventeen_track', $shipping->tracking_provider);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/register')
            && $request->hasHeader('17token', $this->apiToken));
    }

    public function test_register_job_skips_when_order_snapshot_disabled(): void
    {
        Http::fake();

        $shipping = $this->createShipping('1Z-SKIP', orderAttrs: ['logistics_tracking_enabled' => false]);
        (new RegisterLogisticsTrackingJob($shipping->id))->handle(app(LogisticsTrackingSyncService::class));

        $this->assertNull($shipping->fresh()->tracking_registered_at);
        Http::assertNothingSent();
    }

    public function test_register_job_skips_when_merchant_credentials_missing(): void
    {
        Http::fake();
        MerchantLogisticsTrackingSetting::query()->where('merchant_id', $this->merchant->id)->delete();

        $shipping = $this->createShipping('1Z-MERCH', orderAttrs: ['logistics_tracking_enabled' => true]);
        (new RegisterLogisticsTrackingJob($shipping->id))->handle(app(LogisticsTrackingSyncService::class));

        $this->assertNull($shipping->fresh()->tracking_registered_at);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $orderAttrs
     */
    private function createShipping(string $tracking, array $extra = [], array $orderAttrs = []): OrderShipping
    {
        $order = $this->makeOrder('SSC-'.$tracking, 'M-'.$tracking, array_merge([
            'status' => 'shipped',
        ], $orderAttrs));

        return $order->shipping()->create(array_merge([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => $tracking,
            'shipped_at' => now(),
            'operator_id' => 0,
            'sync_status' => OrderShipping::SYNC_STATUS_PENDING,
        ], $extra));
    }

    private function payloadJson(string $number, string $status, ?string $eventUtc = null): string
    {
        $eventUtc ??= '2026-10-28T02:00:00Z';

        return json_encode([
            'event' => 'TRACKING_UPDATED',
            'data' => [
                'number' => $number,
                'carrier' => 100001,
                'track_info' => [
                    'latest_status' => [
                        'status' => $status,
                        'sub_status' => $status.'_Other',
                    ],
                    'latest_event' => [
                        'time_utc' => $eventUtc,
                        'time_iso' => $eventUtc,
                        'description' => 'Test event '.$status,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
