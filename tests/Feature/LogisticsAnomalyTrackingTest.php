<?php

namespace Tests\Feature;

use App\Events\LogisticsAnomalyDetected;
use App\Filament\Resources\OrderResource;
use App\Models\MerchantLogisticsTrackingSetting;
use App\Models\OrderShipping;
use App\Models\SystemConfig;
use App\Services\LogisticsTracking\DTOs\TrackSnapshot;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class LogisticsAnomalyTrackingTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0, 0));
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();
        SystemConfig::set('logistics_tracking.available_drivers', [], 'logistics');
    }

    #[DataProvider('providerAnomalies')]
    public function test_provider_anomaly_is_red_and_stale_repeated_snapshots_notify_once(string $status, string $reason): void
    {
        $shipping = $this->createShipping('PROVIDER');
        $firstRead = $shipping->fresh(['order']);
        $secondRead = $shipping->fresh(['order']);
        $snapshot = $this->snapshot('PROVIDER', $status);
        Event::fake([LogisticsAnomalyDetected::class]);
        $sync = app(LogisticsTrackingSyncService::class);

        $this->assertTrue($sync->applySnapshot($snapshot, driverName: 'seventeen_track', shipping: $firstRead));
        $this->assertTrue($sync->applySnapshot($snapshot, driverName: 'seventeen_track', shipping: $secondRead));

        $shipping->refresh();
        $this->assertTrue($shipping->logistics_anomaly);
        $this->assertSame($reason, $shipping->logistics_anomaly_reason);
        $this->assertNotNull($shipping->logistics_anomaly_at);
        $this->assertSame('danger', OrderResource::trackingStatusColumnColor($shipping->order->fresh(['shipping'])));
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);
        Event::assertDispatched(LogisticsAnomalyDetected::class, fn (LogisticsAnomalyDetected $event): bool => $event->merchantId === $this->merchant->id && $event->details === [
            'order_no' => 'SSC-PROVIDER',
            'merchant_order_no' => 'M-PROVIDER',
            'transaction_id' => '',
            'payment_method_name' => 'test_method',
            'paid_at' => '',
            'currency' => 'USD',
            'amount' => '100.00',
            'tracking_number' => 'PROVIDER',
            'status' => $status,
            'reason' => $reason,
            'days' => '',
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function providerAnomalies(): array
    {
        return [
            'provider exception' => ['Exception', 'provider_exception'],
            'delivery failure' => ['DeliveryFailure', 'delivery_failure'],
            'tracking expired' => ['Expired', 'expired'],
        ];
    }

    public function test_recovered_shipment_notifies_again_only_after_entering_anomaly_again(): void
    {
        $shipping = $this->createShipping('RECOVERY');
        Event::fake([LogisticsAnomalyDetected::class]);
        $sync = app(LogisticsTrackingSyncService::class);

        $this->assertTrue($sync->applySnapshot($this->snapshot('RECOVERY', 'DeliveryFailure'), shipping: $shipping));
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);

        $this->assertTrue($sync->applySnapshot($this->snapshot('RECOVERY', 'InTransit'), shipping: $shipping));

        $shipping->refresh();
        $this->assertFalse($shipping->logistics_anomaly);
        $this->assertNull($shipping->logistics_anomaly_reason);
        $this->assertNull($shipping->logistics_anomaly_at);
        $this->assertSame('success', OrderResource::trackingStatusColumnColor($shipping->order->fresh(['shipping'])));
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);

        $this->assertTrue($sync->applySnapshot($this->snapshot('RECOVERY', 'Expired'), shipping: $shipping));

        $this->assertTrue($shipping->fresh()->logistics_anomaly);
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 2);
        Event::assertDispatched(LogisticsAnomalyDetected::class, fn (LogisticsAnomalyDetected $event): bool => $event->details['status'] === 'Expired' && $event->details['reason'] === 'expired');
    }

    public function test_delivery_clears_anomaly_and_preserves_order_shipping_status(): void
    {
        $shipping = $this->createShipping('DELIVERED', [
            'tracking_status' => 'Exception',
            'logistics_anomaly' => true,
            'logistics_anomaly_reason' => 'provider_exception',
            'logistics_anomaly_at' => now()->subHour(),
        ]);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->assertTrue(app(LogisticsTrackingSyncService::class)->applySnapshot(
            $this->snapshot('DELIVERED', 'Delivered'), shipping: $shipping,
        ));

        $shipping->refresh();
        $this->assertFalse($shipping->logistics_anomaly);
        $this->assertNull($shipping->logistics_anomaly_reason);
        $this->assertNull($shipping->logistics_anomaly_at);
        $this->assertNotNull($shipping->tracking_delivered_at);
        $this->assertSame('shipped', $shipping->order->fresh()->status);
        Event::assertNotDispatched(LogisticsAnomalyDetected::class);
    }

    public function test_stale_tracking_uses_configured_days_at_the_exact_second_boundary(): void
    {
        SystemConfig::set('logistics_tracking.stale_days', 3, 'logistics');
        $shipping = $this->createShipping('STALE', [
            'shipped_at' => now()->subDays(4),
            'tracking_last_event_at' => now()->subDays(3)->addSecond(),
        ]);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertFalse($shipping->fresh()->logistics_anomaly);
        Event::assertNotDispatched(LogisticsAnomalyDetected::class);

        $this->travel(1)->seconds();
        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertTrue($shipping->fresh()->logistics_anomaly);
        $this->assertSame('stale_5d', $shipping->fresh()->logistics_anomaly_reason);
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);
        Event::assertDispatched(LogisticsAnomalyDetected::class, fn (LogisticsAnomalyDetected $event): bool => $event->details['reason'] === 'stale_5d' && $event->details['days'] === '3');
    }

    public function test_undelivered_shipment_uses_configured_days_even_with_a_fresh_event(): void
    {
        SystemConfig::set('logistics_tracking.no_delivery_days', 7, 'logistics');
        $shipping = $this->createShipping('UNDELIVERED', ['shipped_at' => now()->subDays(7)]);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertTrue($shipping->fresh()->logistics_anomaly);
        $this->assertSame('no_delivery_15d', $shipping->fresh()->logistics_anomaly_reason);
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);
        Event::assertDispatched(LogisticsAnomalyDetected::class, fn (LogisticsAnomalyDetected $event): bool => $event->details['reason'] === 'no_delivery_15d' && $event->details['days'] === '7');
    }

    public function test_missing_event_uses_shipment_time_and_relaxed_threshold_clears_existing_anomaly(): void
    {
        SystemConfig::set('logistics_tracking.stale_days', 5, 'logistics');
        $shipping = $this->createShipping('NO-EVENT', [
            'shipped_at' => now()->subDays(5),
            'tracking_last_event_at' => null,
        ]);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertTrue($shipping->fresh()->logistics_anomaly);
        $this->assertSame('stale_5d', $shipping->fresh()->logistics_anomaly_reason);
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);

        SystemConfig::set('logistics_tracking.stale_days', 10, 'logistics');
        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $shipping->refresh();
        $this->assertFalse($shipping->logistics_anomaly);
        $this->assertNull($shipping->logistics_anomaly_reason);
        $this->assertNull($shipping->logistics_anomaly_at);
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);
    }

    public function test_scan_flags_fresh_provider_anomaly_and_excludes_disabled_and_pending_orders(): void
    {
        $enabled = $this->createShipping('SCAN-EXPIRED', ['tracking_status' => 'Expired']);
        $disabled = $this->createShipping('SCAN-DISABLED', ['tracking_status' => 'Expired'], ['logistics_tracking_enabled' => false]);
        $pending = $this->createShipping('SCAN-PENDING', ['tracking_status' => 'Expired'], ['status' => 'pending']);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertTrue($enabled->fresh()->logistics_anomaly);
        $this->assertSame('expired', $enabled->fresh()->logistics_anomaly_reason);
        $this->assertFalse($disabled->fresh()->logistics_anomaly);
        $this->assertFalse($pending->fresh()->logistics_anomaly);
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 1);
        Event::assertDispatched(LogisticsAnomalyDetected::class, fn (LogisticsAnomalyDetected $event): bool => $event->details['tracking_number'] === 'SCAN-EXPIRED');
    }

    public function test_first_anomaly_poll_receiving_delivery_does_not_send_false_alert(): void
    {
        SystemConfig::set('logistics_tracking.available_drivers', ['seventeen_track'], 'logistics');
        MerchantLogisticsTrackingSetting::query()->create([
            'merchant_id' => $this->merchant->id,
            'driver' => 'seventeen_track',
            'base_url' => 'https://api.17track.test/track/v2.4',
            'api_token' => 'tracking-api-token',
            'webhook_secret' => 'tracking-webhook-secret',
            'webhook_token' => 'tracking-webhook-token',
        ]);
        $shipping = $this->createShipping('POLL-DELIVERED', [
            'shipped_at' => now()->subDays(6),
            'tracking_last_event_at' => now()->subDays(5),
        ]);
        Http::fake([
            'https://api.17track.test/track/v2.4/gettrackinfo' => Http::response([
                'code' => 0,
                'data' => [
                    'accepted' => [[
                        'number' => 'POLL-DELIVERED',
                        'carrier' => 100001,
                        'track_info' => [
                            'latest_status' => ['status' => 'Delivered', 'sub_status' => 'Delivered_Other'],
                            'latest_event' => ['time_utc' => now()->toIso8601String(), 'description' => 'Delivered'],
                        ],
                    ]],
                    'rejected' => [],
                ],
            ]),
        ]);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $shipping->refresh();
        $this->assertSame('Delivered', $shipping->tracking_status);
        $this->assertNotNull($shipping->tracking_delivered_at);
        $this->assertNotNull($shipping->tracking_last_polled_at);
        $this->assertFalse($shipping->logistics_anomaly);
        Event::assertNotDispatched(LogisticsAnomalyDetected::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.17track.test/track/v2.4/gettrackinfo'
            && $request->hasHeader('17token', 'tracking-api-token'));
        Http::assertSentCount(1);
    }

    public function test_rolled_back_snapshot_does_not_persist_anomaly_or_dispatch_alert(): void
    {
        $shipping = $this->createShipping('ROLLBACK');
        Event::fake([LogisticsAnomalyDetected::class]);

        DB::beginTransaction();

        try {
            $this->assertTrue(app(LogisticsTrackingSyncService::class)->applySnapshot(
                $this->snapshot('ROLLBACK', 'Exception'), shipping: $shipping,
            ));
            $this->assertTrue($shipping->fresh()->logistics_anomaly);
            Event::assertNotDispatched(LogisticsAnomalyDetected::class);
        } finally {
            DB::rollBack();
        }

        $shipping->refresh();
        $this->assertSame('InTransit', $shipping->tracking_status);
        $this->assertFalse($shipping->logistics_anomaly);
        $this->assertNull($shipping->logistics_anomaly_reason);
        $this->assertNull($shipping->logistics_anomaly_at);
        Event::assertNotDispatched(LogisticsAnomalyDetected::class);
    }

    public function test_late_snapshot_for_replaced_number_cannot_pollute_the_current_shipment(): void
    {
        $shipping = $this->createShipping('OLD-NUMBER');
        $oldRead = $shipping->fresh(['order']);
        OrderShipping::query()->whereKey($shipping->id)->update(['tracking_number' => 'NEW-NUMBER']);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->assertFalse(app(LogisticsTrackingSyncService::class)->applySnapshot(
            $this->snapshot('OLD-NUMBER', 'Delivered'), shipping: $oldRead,
        ));

        $shipping->refresh();
        $this->assertSame('NEW-NUMBER', $shipping->tracking_number);
        $this->assertSame('InTransit', $shipping->tracking_status);
        $this->assertNull($shipping->tracking_delivered_at);
        $this->assertNull($shipping->tracking_checked_at);
        Event::assertNotDispatched(LogisticsAnomalyDetected::class);
    }

    public function test_late_registration_for_replaced_number_does_not_skip_registering_the_new_number(): void
    {
        SystemConfig::set('logistics_tracking.available_drivers', ['seventeen_track'], 'logistics');
        MerchantLogisticsTrackingSetting::query()->create([
            'merchant_id' => $this->merchant->id,
            'driver' => 'seventeen_track',
            'base_url' => 'https://api.17track.test/track/v2.4',
            'api_token' => 'tracking-api-token',
            'webhook_secret' => 'tracking-webhook-secret',
            'webhook_token' => 'tracking-webhook-token',
        ]);
        $shipping = $this->createShipping('REGISTER-OLD');
        Http::fake([
            'https://api.17track.test/track/v2.4/register' => function (Request $request) use ($shipping): PromiseInterface {
                $number = $request->data()[0]['number'];

                if ($number === 'REGISTER-OLD') {
                    OrderShipping::query()->whereKey($shipping->id)->update(['tracking_number' => 'REGISTER-NEW']);
                }

                return Http::response([
                    'code' => 0,
                    'data' => [
                        'accepted' => [['number' => $number, 'carrier' => $number === 'REGISTER-OLD' ? 100001 : 100002]],
                        'rejected' => [],
                    ],
                ]);
            },
        ]);
        $sync = app(LogisticsTrackingSyncService::class);

        $sync->registerShipping($shipping);

        $shipping->refresh();
        $this->assertSame('REGISTER-NEW', $shipping->tracking_number);
        $this->assertNull($shipping->tracking_registered_at);
        $this->assertNull($shipping->tracking_provider);
        $this->assertNull($shipping->tracking_carrier_code);

        $sync->registerShipping($shipping->fresh());

        $shipping->refresh();
        $this->assertNotNull($shipping->tracking_registered_at);
        $this->assertSame('seventeen_track', $shipping->tracking_provider);
        $this->assertSame('100002', $shipping->tracking_carrier_code);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->data()[0]['number'] === 'REGISTER-OLD');
        Http::assertSent(fn (Request $request): bool => $request->data()[0]['number'] === 'REGISTER-NEW');
    }

    #[DataProvider('invalidThresholds')]
    public function test_invalid_legacy_thresholds_fall_back_to_five_and_fifteen_days(int|string $invalid): void
    {
        SystemConfig::set('logistics_tracking.stale_days', $invalid, 'logistics');
        SystemConfig::set('logistics_tracking.no_delivery_days', $invalid, 'logistics');
        $stale = $this->createShipping('DEFAULT-STALE', [
            'shipped_at' => now()->subDays(5),
            'tracking_last_event_at' => null,
        ]);
        $undelivered = $this->createShipping('DEFAULT-UNDELIVERED', ['shipped_at' => now()->subDays(15)]);
        Event::fake([LogisticsAnomalyDetected::class]);

        $this->artisan('logistics-tracking:reflag-anomalies')->assertSuccessful();

        $this->assertSame(5, LogisticsTrackingSyncService::staleDays());
        $this->assertSame(15, LogisticsTrackingSyncService::noDeliveryDays());
        $this->assertSame('stale_5d', $stale->fresh()->logistics_anomaly_reason);
        $this->assertSame('no_delivery_15d', $undelivered->fresh()->logistics_anomaly_reason);
        Event::assertDispatchedTimes(LogisticsAnomalyDetected::class, 2);
        Event::assertDispatched(LogisticsAnomalyDetected::class, fn (LogisticsAnomalyDetected $event): bool => $event->details['tracking_number'] === 'DEFAULT-STALE' && $event->details['days'] === '5');
        Event::assertDispatched(LogisticsAnomalyDetected::class, fn (LogisticsAnomalyDetected $event): bool => $event->details['tracking_number'] === 'DEFAULT-UNDELIVERED' && $event->details['days'] === '15');
    }

    /** @return array<string, array{int|string}> */
    public static function invalidThresholds(): array
    {
        return [
            'zero days' => [0],
            'invalid legacy value' => ['invalid'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $orderAttributes
     */
    private function createShipping(string $trackingNumber, array $attributes = [], array $orderAttributes = []): OrderShipping
    {
        $order = $this->makeOrder('SSC-'.$trackingNumber, 'M-'.$trackingNumber, array_merge([
            'status' => 'shipped',
            'logistics_tracking_enabled' => true,
        ], $orderAttributes));

        return $order->shipping()->create(array_merge([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => $trackingNumber,
            'operator_id' => 0,
            'shipped_at' => now(),
            'tracking_status' => 'InTransit',
            'tracking_last_event_at' => now(),
        ], $attributes));
    }

    private function snapshot(string $number, string $status): TrackSnapshot
    {
        return new TrackSnapshot(
            number: $number,
            carrierCode: '100001',
            status: $status,
            subStatus: $status.'_Other',
            lastEventAt: now(),
            lastEventDesc: 'Latest event',
        );
    }
}
