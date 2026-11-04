<?php

namespace Tests\Feature;

use App\Jobs\SyncMerchantShipmentJob;
use App\Models\Application;
use App\Models\Carrier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderShipmentSync;
use App\Models\OrderShipping;
use App\Models\TelegramBot;
use App\Services\MerchantShipmentSyncService;
use App\Services\OrderShippingService;
use App\Support\ShipmentSyncEndpoint;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class MerchantShipmentSyncTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const ENDPOINT = 'https://merchant.example.com/api/fulfillment/shipment?source=sscpay&store=main';

    public function test_recording_logistics_queues_a_signed_request_to_the_complete_application_url(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 0, 'msg' => 'success'])]);

        $shipping = $this->recordShipping($order, '0001234567890123456789');
        $sync = $this->latestSync($order);
        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);
        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        Queue::assertPushed(SyncMerchantShipmentJob::class, fn (SyncMerchantShipmentJob $job): bool => $job->syncId === $sync->id
            && $job->merchantId === $this->merchant->id && $job->queue === 'low' && $job->afterCommit === true);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame($this->application->app_id, $request->header('App-ID')[0]);
            $this->assertSame((string) now()->timestamp, $request->header('Timestamp')[0]);
            $this->assertNotEmpty($request->header('X-Nonce')[0]);
            $this->assertSame('MERCHANT-LOGISTICS', $request['merchant_order_no']);
            $this->assertSame('United Parcel Service', $request['carrier']);
            $this->assertSame('ups', $request['carrier_code']);
            $this->assertSame('0001234567890123456789', $request['tracking_number']);
            $this->assertSame('https://tracking.example.com/?number=0001234567890123456789', $request['tracking_url']);
            $this->assertSame(1790910000, strtotime($request['shipped_at']));
            $this->assertArrayNotHasKey('items', $request->data());
            $this->assertArrayNotHasKey('s_order_id', $request->data());
            $this->assertSame(SignatureCanonicalizer::sign(
                $this->application->app_id,
                $request->header('Timestamp')[0],
                $request->header('X-Nonce')[0],
                $request->data(),
                $this->application->api_key,
            ), $request['sign']);

            return true;
        });
        $this->assertSame('success', $sync->refresh()->status);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertNotNull($sync->synced_at);
        $this->assertNull($sync->next_attempt_at);
        $attempt = $sync->attempts()->sole();
        $this->assertSame(200, $attempt->response_status);
        $this->assertSame(self::ENDPOINT, $attempt->request_url);
        $this->assertSame($shipping->id, $sync->order_shipping_id);
        $this->assertSame('pending', $shipping->refresh()->sync_status);
        Http::assertSentCount(1);
    }

    /** @param array<string, mixed>|string $body */
    #[DataProvider('unsuccessfulResponses')]
    public function test_http_and_business_success_are_both_required(int $status, array|string $body): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        $this->assertNotSame('success', $sync->refresh()->status);
        $this->assertNull($sync->synced_at);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertSame($status, $sync->attempts()->sole()->response_status);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int, array<string, mixed>|string}> */
    public static function unsuccessfulResponses(): array
    {
        return [
            'http success with business error' => [200, ['code' => 2, 'msg' => 'order not found']],
            'string zero is not the documented numeric code' => [200, ['code' => '0', 'msg' => 'success']],
            'created is not the documented success status' => [201, ['code' => 0]],
            'html response' => [200, '<html>Proxy login</html>'],
            'missing code' => [200, ['msg' => 'success']],
            'redirect is not followed' => [302, ['code' => 0]],
        ];
    }

    #[DataProvider('temporaryResponseStatuses')]
    public function test_temporary_errors_schedule_a_retry_without_reverting_local_shipping(int $status): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 9, 'msg' => 'temporarily unavailable'], $status)]);
        $shipping = $this->recordShipping($order);
        $sync = $this->latestSync($order);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        $this->assertSame('retrying', $sync->refresh()->status);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertTrue(now()->addSeconds(30)->equalTo($sync->next_attempt_at));
        $this->assertSame('shipped', $order->refresh()->status);
        $this->assertSame('TRACK-A', $shipping->refresh()->tracking_number);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int}> */
    public static function temporaryResponseStatuses(): array
    {
        return ['rate limited' => [429], 'server failure' => [500], 'gateway failure' => [502]];
    }

    public function test_connection_failure_is_recorded_and_scheduled_for_retry(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::failedConnection('Connection timed out')]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        $this->assertSame('retrying', $sync->refresh()->status);
        $this->assertTrue(now()->addSeconds(30)->equalTo($sync->next_attempt_at));
        $this->assertNotEmpty($sync->last_error);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertNull($sync->attempts()->sole()->response_status);
    }

    #[DataProvider('permanentResponseStatuses')]
    public function test_permanent_errors_stop_retrying_and_preserve_a_failure_for_telegram(int $status): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 2, 'msg' => 'rejected shipment'], $status)]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        $this->assertSame('failed', $sync->refresh()->status);
        $this->assertNull($sync->next_attempt_at);
        $this->assertNotEmpty($sync->last_error);
        $this->assertSame('pending', $sync->alert_status);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int}> */
    public static function permanentResponseStatuses(): array
    {
        return ['invalid signature' => [401], 'order missing' => [404], 'invalid payload' => [422]];
    }

    public function test_retries_keep_the_payload_but_refresh_timestamp_nonce_and_signature(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::sequence()->push(['code' => 9], 500)->push(['code' => 0], 200)]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $service = app(MerchantShipmentSyncService::class);
        $service->attempt($sync->id, $this->merchant->id);
        $this->travel(30)->seconds();

        $service->dispatchDue();
        $service->attempt($sync->id, $this->merchant->id);

        $requests = Http::recorded()->map(fn (array $exchange): Request => $exchange[0]);
        $this->assertCount(2, $requests);
        $first = $requests[0];
        $second = $requests[1];
        $firstPayload = $first->data();
        $secondPayload = $second->data();
        unset($firstPayload['sign'], $secondPayload['sign']);
        $this->assertSame($firstPayload, $secondPayload);
        $this->assertNotSame($first->header('Timestamp'), $second->header('Timestamp'));
        $this->assertNotSame($first->header('X-Nonce'), $second->header('X-Nonce'));
        $this->assertNotSame($first['sign'], $second['sign']);
        $this->assertSame('success', $sync->refresh()->status);
        $this->assertSame(2, $sync->attempts()->count());
    }

    public function test_five_unsuccessful_attempts_exhaust_the_retry_budget_at_the_agreed_intervals(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 9, 'msg' => 'server unavailable'], 500)]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $service = app(MerchantShipmentSyncService::class);

        foreach ([30, 300, 1800, 3600] as $interval) {
            $service->attempt($sync->id, $this->merchant->id);
            $this->assertSame('retrying', $sync->refresh()->status);
            $this->assertTrue(now()->addSeconds($interval)->equalTo($sync->next_attempt_at));
            $this->travel($interval)->seconds();
            $service->dispatchDue();
        }
        $service->attempt($sync->id, $this->merchant->id);
        $service->attempt($sync->id, $this->merchant->id);

        $this->assertSame('failed', $sync->refresh()->status);
        $this->assertSame(5, $sync->attempt_count);
        $this->assertNull($sync->next_attempt_at);
        $this->assertSame('pending', $sync->alert_status);
        Http::assertSentCount(5);
    }

    public function test_a_corrected_shipment_stops_the_old_retry_and_keeps_one_local_shipping_record(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::sequence()->push(['code' => 9], 500)->push(['code' => 0])]);
        $this->recordShipping($order);
        $old = $this->latestSync($order);
        $service = app(MerchantShipmentSyncService::class);
        $service->attempt($old->id, $this->merchant->id);
        $this->recordShipping($order->refresh(), 'TRACK-B');
        $latest = $this->latestSync($order);

        $this->travel(30)->seconds();
        $service->dispatchDue();
        $service->attempt($old->id, $this->merchant->id);
        $service->attempt($latest->id, $this->merchant->id);

        $this->assertSame('superseded', $old->refresh()->status);
        $this->assertNull($old->next_attempt_at);
        $this->assertSame(1, $old->attempt_count);
        $this->assertSame('success', $latest->refresh()->status);
        $this->assertSame('TRACK-A', $old->payload['tracking_number']);
        $this->assertSame('TRACK-B', $latest->payload['tracking_number']);
        $this->assertSame('TRACK-B', $order->refresh()->shipping->tracking_number);
        $this->assertSame(1, OrderShipping::query()->forMerchant($this->merchant->id)->count());
        Http::assertSentCount(2);
    }

    public function test_disabling_the_application_switch_pauses_queued_delivery_without_an_http_request(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $this->application->update(['is_shipment_sync_enabled' => false]);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        $this->assertSame('paused', $sync->refresh()->status);
        $this->assertSame(0, $sync->attempt_count);
        Http::assertNothingSent();
    }

    public function test_paused_deliveries_do_not_starve_enabled_applications_when_the_scan_is_limited(): void
    {
        $oldOrder = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 0])]);
        $this->recordShipping($oldOrder);
        $paused = $this->latestSync($oldOrder);
        $this->application->update(['is_shipment_sync_enabled' => false]);
        $service = app(MerchantShipmentSyncService::class);
        $service->attempt($paused->id, $this->merchant->id);
        $this->assertSame('paused', $paused->refresh()->status);
        $this->travel(1)->minute();
        $enabledApplication = Application::createWithCredentials([
            'merchant_id' => $this->merchant->id,
            'name' => 'Enabled Application',
            'website' => 'merchant.example.com',
            'is_shipment_sync_enabled' => true,
            'shipment_sync_url' => self::ENDPOINT,
        ]);
        $newOrder = $this->makeOrder('SSC-ENABLED', 'MERCHANT-ENABLED', [
            'application_id' => $enabledApplication->id,
            'status' => 'paid',
        ]);
        $this->recordShipping($newOrder, 'TRACK-ENABLED');
        $latest = $this->latestSync($newOrder);
        Queue::fake();

        $this->assertSame(1, $service->dispatchDue(1));

        Queue::assertPushed(SyncMerchantShipmentJob::class, fn (SyncMerchantShipmentJob $job): bool => $job->syncId === $latest->id);
        Queue::assertNotPushed(SyncMerchantShipmentJob::class, fn (SyncMerchantShipmentJob $job): bool => $job->syncId === $paused->id);
        Http::assertNothingSent();

        $this->application->update(['is_shipment_sync_enabled' => true]);
        Queue::fake();
        $this->assertSame(1, $service->dispatchDue(1));
        Queue::assertPushed(SyncMerchantShipmentJob::class, fn (SyncMerchantShipmentJob $job): bool => $job->syncId === $paused->id);
        $service->attempt($paused->id, $this->merchant->id);

        $this->assertSame('success', $paused->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_an_endpoint_resolving_to_a_private_address_fails_without_sending_or_retrying(): void
    {
        $order = $this->prepareOrder();
        $this->mock(ShipmentSyncEndpoint::class)
            ->shouldReceive('options')
            ->once()
            ->with(self::ENDPOINT)
            ->andThrow(new InvalidArgumentException('Shipment endpoint resolves to a private address.'));
        Queue::fake();
        Http::preventStrayRequests();
        $this->recordShipping($order);
        $sync = $this->latestSync($order);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        $this->assertSame('failed', $sync->refresh()->status);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertNull($sync->next_attempt_at);
        $this->assertSame('pending', $sync->alert_status);
        $this->assertStringContainsString('private address', $sync->last_error);
        $this->assertNull($sync->attempts()->sole()->response_status);
        Http::assertNothingSent();
    }

    public function test_enabling_sync_does_not_backfill_historical_logistics_but_manual_push_uses_the_latest_record(): void
    {
        $order = $this->prepareOrder(false);
        Queue::fake();
        Http::preventStrayRequests();
        $shipping = $this->recordShipping($order, 'HISTORICAL-TRACK');
        $this->assertSame(0, OrderShipmentSync::query()->count());
        Queue::assertNotPushed(SyncMerchantShipmentJob::class);

        $this->application->update(['is_shipment_sync_enabled' => true]);
        $service = app(MerchantShipmentSyncService::class);
        $service->dispatchDue();
        $this->assertSame(0, OrderShipmentSync::query()->count());
        $sync = $service->pushManually($order->refresh());

        $this->assertSame($shipping->id, $sync->order_shipping_id);
        $this->assertSame('HISTORICAL-TRACK', $sync->payload['tracking_number']);
        $this->assertSame('pending', $sync->status);
        $this->assertSame(1, OrderShipping::query()->count());
        Queue::assertPushed(SyncMerchantShipmentJob::class);
        Http::assertNothingSent();
    }

    public function test_a_job_with_a_different_merchant_id_cannot_send_or_mutate_the_delivery(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        $other = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other Operator',
            'contact_phone' => '987654',
            'contact_email' => 'other@example.com',
        ]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $other->id);

        $this->assertSame('pending', $sync->refresh()->status);
        $this->assertSame(0, $sync->attempt_count);
        Http::assertNothingSent();
    }

    public function test_an_order_cannot_use_another_merchants_application_credentials(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        $other = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other Operator',
            'contact_phone' => '987654',
            'contact_email' => 'other@example.com',
        ]);
        $foreignApplication = Application::createWithCredentials([
            'merchant_id' => $other->id,
            'name' => 'Other Store',
            'website' => 'other.example.com',
            'is_shipment_sync_enabled' => true,
            'shipment_sync_url' => 'https://other.example.com/api/fulfillment/shipment',
        ]);
        $order->update(['application_id' => $foreignApplication->id]);

        $this->recordShipping($order);

        $this->assertSame(0, OrderShipmentSync::query()->count());
        Queue::assertNotPushed(SyncMerchantShipmentJob::class);
        Http::assertNothingSent();
    }

    public function test_rolling_back_logistics_does_not_leave_a_delivery_or_send_to_the_merchant(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();

        DB::beginTransaction();
        $this->recordShipping($order);
        $this->assertSame(1, OrderShipmentSync::query()->count());
        DB::rollBack();

        $this->assertSame(0, OrderShipmentSync::query()->count());
        $this->assertSame(0, OrderShipping::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_sync_queue_sends_a_delivery_only_after_logistics_commits(): void
    {
        $order = $this->prepareOrder();
        config(['queue.default' => 'sync']);
        Queue::fake()->except([SyncMerchantShipmentJob::class]);
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 0])]);

        DB::beginTransaction();
        $this->recordShipping($order);
        Http::assertNothingSent();
        DB::rollBack();
        Http::assertNothingSent();
        $this->assertDatabaseCount('order_shipment_syncs', 0);

        DB::beginTransaction();
        $this->recordShipping($order->refresh());
        Http::assertNothingSent();
        DB::commit();

        $this->assertSame('success', $this->latestSync($order)->status);
        Http::assertSentCount(1);
    }

    public function test_an_interrupted_worker_is_recovered_after_its_lease_expires(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 0])]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $sync->update([
            'status' => 'processing',
            'attempt_count' => 1,
            'next_attempt_at' => now()->addMinutes(2),
        ]);
        $interruptedAttempt = $sync->attempts()->create([
            'merchant_id' => $this->merchant->id,
            'attempt_number' => 1,
            'request_url' => self::ENDPOINT,
            'attempted_at' => now(),
        ]);
        $service = app(MerchantShipmentSyncService::class);

        $service->attempt($sync->id, $this->merchant->id);
        Http::assertNothingSent();
        $this->travel(2)->minutes();
        $this->assertSame(1, $service->dispatchDue());
        $service->attempt($sync->id, $this->merchant->id);

        $this->assertSame('success', $sync->refresh()->status);
        $this->assertSame(2, $sync->attempt_count);
        $this->assertNotEmpty($interruptedAttempt->refresh()->error_message);
        $this->assertSame(2, $sync->attempts()->count());
        Http::assertSentCount(1);
    }

    public function test_an_interrupted_final_attempt_becomes_a_failure_without_exceeding_the_retry_budget(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $sync->update(['status' => 'processing', 'attempt_count' => 5, 'next_attempt_at' => now()]);

        app(MerchantShipmentSyncService::class)->attempt($sync->id, $this->merchant->id);

        $this->assertSame('failed', $sync->refresh()->status);
        $this->assertSame(5, $sync->attempt_count);
        $this->assertSame('pending', $sync->alert_status);
        $this->assertNotEmpty($sync->last_error);
        $this->assertNull($sync->next_attempt_at);
        Http::assertNothingSent();
    }

    public function test_editing_logistics_while_the_old_http_request_is_in_progress_prevents_its_retry(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => function (Request $request) use ($order) {
            if ($request['tracking_number'] === 'TRACK-A') {
                $this->recordShipping($order->refresh(), 'TRACK-B');

                return Http::response(['code' => 9, 'msg' => 'server failure'], 500);
            }

            return Http::response(['code' => 0]);
        }]);
        $this->recordShipping($order);
        $old = $this->latestSync($order);
        $service = app(MerchantShipmentSyncService::class);

        $service->attempt($old->id, $this->merchant->id);
        $latest = $this->latestSync($order);
        $service->attempt($latest->id, $this->merchant->id);
        $service->attempt($old->id, $this->merchant->id);

        $this->assertSame('superseded', $old->refresh()->status);
        $this->assertNull($old->next_attempt_at);
        $this->assertNull($old->alert_status);
        $this->assertSame('success', $latest->refresh()->status);
        $this->assertSame('TRACK-B', $order->refresh()->shipping->tracking_number);
        Http::assertSentCount(2);
    }

    public function test_disabling_sync_and_editing_logistics_during_an_http_request_stops_the_old_retry(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => function () use ($order) {
            $this->application->update(['is_shipment_sync_enabled' => false]);
            $this->recordShipping($order->refresh(), 'TRACK-B');

            return Http::response(['code' => 9], 500);
        }]);
        $this->recordShipping($order);
        $old = $this->latestSync($order);
        $service = app(MerchantShipmentSyncService::class);

        $service->attempt($old->id, $this->merchant->id);
        $this->application->update(['is_shipment_sync_enabled' => true]);
        $service->attempt($old->id, $this->merchant->id);

        $this->assertSame('superseded', $old->refresh()->status);
        $this->assertNull($old->next_attempt_at);
        $this->assertSame('TRACK-B', $order->refresh()->shipping->tracking_number);
        $this->assertSame(1, OrderShipmentSync::query()->count());
        Http::assertSentCount(1);
    }

    public function test_a_missing_telegram_bot_keeps_the_failed_order_and_records_the_unavailable_alert(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['code' => 2, 'msg' => 'order not found'], 404)]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $service = app(MerchantShipmentSyncService::class);
        $service->attempt($sync->id, $this->merchant->id);

        $service->notifyFailures();

        $this->assertSame('failed', $sync->refresh()->status);
        $this->assertSame('unavailable', $sync->alert_status);
        $this->assertNotEmpty($sync->last_error);
        Http::assertSentCount(1);
    }

    public function test_failed_orders_are_combined_into_one_merchant_telegram_message_and_not_notified_twice(): void
    {
        $firstOrder = $this->prepareOrder();
        $this->app->setLocale('zh_CN');
        config()->set('app.timezone', 'Asia/Shanghai');
        $firstOrder->update([
            'transaction_id' => 'TX-SHIPMENT',
            'payment_method_id' => $this->makePaymentMethod('shipment-stripe')->id,
            'paid_at' => '2026-10-18 12:30:00',
            'currency' => 'EUR',
            'amount' => '83.25',
        ]);
        $secondOrder = $this->makeOrder('SSC-SECOND', 'MERCHANT-SECOND', ['status' => 'paid']);
        $bot = TelegramBot::create([
            'merchant_id' => $this->merchant->id,
            'bot_token' => 'shipment-bot-token',
            'chat_id' => 'shipment-chat',
            'is_enabled' => true,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::ENDPOINT => Http::response(['code' => 2, 'msg' => 'order not found'], 404),
            'https://api.telegram.org/botshipment-bot-token/sendMessage' => Http::response(['ok' => true]),
        ]);
        $this->recordShipping($firstOrder, 'TRACK-FIRST');
        $this->recordShipping($secondOrder, 'TRACK-SECOND');
        $first = $this->latestSync($firstOrder);
        $second = $this->latestSync($secondOrder);
        $service = app(MerchantShipmentSyncService::class);
        $service->attempt($first->id, $this->merchant->id);
        $service->attempt($second->id, $this->merchant->id);

        $service->notifyFailures();
        $service->notifyFailures();

        $requests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'));
        $this->assertCount(1, $requests);
        $message = $requests->sole()[0];
        $this->assertSame($bot->chat_id, $message['chat_id']);
        $this->assertStringContainsString('MERCHANT-LOGISTICS', $message['text']);
        $this->assertStringContainsString('MERCHANT-SECOND', $message['text']);
        $this->assertStringContainsString('TRACK-FIRST', $message['text']);
        $this->assertStringContainsString('TRACK-SECOND', $message['text']);
        $this->assertStringContainsString('TX-SHIPMENT', $message['text']);
        $this->assertStringContainsString('SHIPMENT-STRIPE', $message['text']);
        $this->assertStringContainsString('2026-10-18 12:30:00 CST', $message['text']);
        $this->assertStringContainsString('EUR 83.25', $message['text']);
        $this->assertSame('sent', $first->refresh()->alert_status);
        $this->assertSame('sent', $second->refresh()->alert_status);
        $this->assertNotNull($first->alerted_at);
    }

    public function test_failure_digest_splits_escaped_order_details_without_losing_pending_alerts(): void
    {
        $firstOrder = $this->prepareOrder();
        $method = $this->makePaymentMethod('long-notice');
        $method->update(['method_name' => str_repeat('"', 80)]);
        $this->application->update(['name' => str_repeat('"', 50)]);
        TelegramBot::create([
            'merchant_id' => $this->merchant->id,
            'bot_token' => 'shipment-bot-token',
            'chat_id' => 'shipment-chat',
            'is_enabled' => true,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/botshipment-bot-token/sendMessage' => Http::response(['ok' => true])]);
        $syncs = [];
        for ($index = 1; $index <= 5; $index++) {
            $order = $index === 1 ? $firstOrder : $this->makeOrder('SSC-LONG-'.$index, 'M-LONG-'.$index, ['status' => 'paid']);
            $order->update([
                'merchant_order_no' => str_repeat('"', 60).'M-LONG-'.$index,
                'transaction_id' => str_repeat('"', 60).'TX-LONG-'.$index,
                'payment_method_id' => $method->id,
                'paid_at' => '2026-10-18 12:30:00',
            ]);
            $this->recordShipping($order, 'TRACK-LONG-'.$index);
            $sync = $this->latestSync($order);
            $sync->update(['status' => 'failed', 'alert_status' => 'pending', 'last_error' => str_repeat('"', 100)]);
            $syncs[] = $sync;
        }

        $this->assertSame(5, app(MerchantShipmentSyncService::class)->notifyFailures());

        $requests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'));
        $this->assertGreaterThan(1, $requests->count());
        $messages = $requests->map(fn ($record): string => $record[0]['text']);
        foreach ($messages as $message) {
            $this->assertLessThanOrEqual(4000, mb_strlen($message));
        }
        $text = $messages->implode("\n");
        foreach ($syncs as $index => $sync) {
            $this->assertSame('sent', $sync->refresh()->alert_status);
            $this->assertStringContainsString('TX-LONG-'.($index + 1), $text);
        }
        $this->assertStringContainsString('&quot;', $text);
    }

    public function test_failure_digest_does_not_load_details_from_another_merchants_order(): void
    {
        $order = $this->prepareOrder();
        $other = Merchant::create([
            'name' => 'Other Merchant', 'contact_person' => 'Other',
            'contact_phone' => '123456', 'contact_email' => 'other@example.com',
        ]);
        $otherApplication = Application::createWithCredentials([
            'merchant_id' => $other->id, 'name' => 'Other App', 'website' => 'https://other.example.com',
        ]);
        $otherOrder = $this->makeOrder('SSC-OTHER-SECRET', 'M-OTHER-SECRET', [
            'merchant_id' => $other->id,
            'application_id' => $otherApplication->id,
            'transaction_id' => 'TX-OTHER-SECRET',
        ]);
        TelegramBot::create([
            'merchant_id' => $this->merchant->id,
            'bot_token' => 'shipment-bot-token', 'chat_id' => 'shipment-chat', 'is_enabled' => true,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/botshipment-bot-token/sendMessage' => Http::response(['ok' => true])]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $sync->update(['order_id' => $otherOrder->id, 'status' => 'failed', 'alert_status' => 'pending']);

        app(MerchantShipmentSyncService::class)->notifyFailures();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'shipment-chat'
            && str_contains($request['text'], 'MERCHANT-LOGISTICS')
            && ! str_contains($request['text'], 'OTHER-SECRET'));
        $this->assertSame('sent', $sync->refresh()->alert_status);
    }

    public function test_a_failed_telegram_delivery_remains_pending_until_the_notification_can_be_sent(): void
    {
        $order = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        TelegramBot::create([
            'merchant_id' => $this->merchant->id,
            'bot_token' => 'shipment-bot-token',
            'chat_id' => 'shipment-chat',
            'is_enabled' => true,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::ENDPOINT => Http::response(['code' => 2, 'msg' => 'order not found'], 404),
            'https://api.telegram.org/botshipment-bot-token/sendMessage' => Http::sequence()
                ->push(['ok' => false], 500)
                ->push(['ok' => true]),
        ]);
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        $service = app(MerchantShipmentSyncService::class);
        $service->attempt($sync->id, $this->merchant->id);

        $service->notifyFailures();
        $this->assertSame('pending', $sync->refresh()->alert_status);
        $this->assertNull($sync->alerted_at);
        $this->assertTrue(now()->addMinutes(5)->equalTo($sync->next_alert_at));
        $service->notifyFailures();
        $this->assertSame('pending', $sync->refresh()->alert_status);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/')));
        $this->travel(5)->minutes();
        $service->notifyFailures();

        $this->assertSame('sent', $sync->refresh()->alert_status);
        $this->assertSame('failed', $sync->status);
        $this->assertNotEmpty($sync->last_error);
        $this->assertNull($sync->next_alert_at);
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/')));
    }

    public function test_a_merchants_failed_telegram_does_not_starve_another_merchants_alert_when_the_scan_is_limited(): void
    {
        $oldOrder = $this->prepareOrder();
        $this->travelTo(now()->startOfSecond());
        $otherMerchant = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other Operator',
            'contact_phone' => '987654',
            'contact_email' => 'other@example.com',
        ]);
        $otherApplication = Application::createWithCredentials([
            'merchant_id' => $otherMerchant->id,
            'name' => 'Other Application',
            'website' => 'merchant.example.com',
            'is_shipment_sync_enabled' => true,
            'shipment_sync_url' => self::ENDPOINT,
        ]);
        $otherOrder = $this->makeOrder('SSC-OTHER-ALERT', 'MERCHANT-OTHER-ALERT', [
            'merchant_id' => $otherMerchant->id,
            'application_id' => $otherApplication->id,
            'status' => 'paid',
        ]);
        TelegramBot::create([
            'merchant_id' => $this->merchant->id,
            'bot_token' => 'old-failing-bot',
            'chat_id' => 'old-chat',
            'is_enabled' => true,
        ]);
        TelegramBot::create([
            'merchant_id' => $otherMerchant->id,
            'bot_token' => 'other-working-bot',
            'chat_id' => 'other-chat',
            'is_enabled' => true,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::ENDPOINT => Http::response(['code' => 2, 'msg' => 'order not found'], 404),
            'https://api.telegram.org/botold-failing-bot/sendMessage' => Http::response(['ok' => false], 500),
            'https://api.telegram.org/botother-working-bot/sendMessage' => Http::response(['ok' => true]),
        ]);
        $this->recordShipping($oldOrder);
        $this->recordShipping($otherOrder, 'OTHER-TRACK');
        $old = $this->latestSync($oldOrder);
        $other = $this->latestSync($otherOrder);
        $service = app(MerchantShipmentSyncService::class);
        $service->attempt($old->id, $this->merchant->id);
        $service->attempt($other->id, $otherMerchant->id);

        $this->assertSame(0, $service->notifyFailures(1));
        $this->assertSame('pending', $old->refresh()->alert_status);
        $this->assertTrue(now()->addMinutes(5)->equalTo($old->next_alert_at));
        $this->assertSame(1, $service->notifyFailures(1));

        $this->assertSame('pending', $old->refresh()->alert_status);
        $this->assertSame('failed', $old->status);
        $this->assertSame('sent', $other->refresh()->alert_status);
        $this->assertNotNull($other->alerted_at);
        $this->assertNull($other->next_alert_at);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'botold-failing-bot/')));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'botother-working-bot/')));
    }

    public function test_the_scheduled_command_dispatches_due_shipment_syncs(): void
    {
        $order = $this->prepareOrder();
        Queue::fake();
        Http::preventStrayRequests();
        $this->recordShipping($order);
        $sync = $this->latestSync($order);
        Queue::fake();

        $this->artisan('merchant-shipments:process-due', ['--limit' => 1])
            ->expectsOutputToContain('Dispatched 1 shipment sync(s); notified 0 failure(s).')
            ->assertSuccessful();

        Queue::assertPushed(SyncMerchantShipmentJob::class, fn (SyncMerchantShipmentJob $job): bool => $job->syncId === $sync->id);
        Http::assertNothingSent();
    }

    private function prepareOrder(bool $enabled = true): Order
    {
        $this->mock(ShipmentSyncEndpoint::class)->shouldReceive('options')->andReturn([]);
        $this->createMerchantAndApplication();
        $this->application->update([
            'is_shipment_sync_enabled' => $enabled,
            'shipment_sync_url' => self::ENDPOINT,
        ]);
        Carrier::create([
            'carrier_name' => 'United Parcel Service',
            'carrier_code' => 'ups',
            'status' => Carrier::STATUS_ENABLED,
        ]);

        return $this->makeOrder('SSC-LOGISTICS', 'MERCHANT-LOGISTICS', ['status' => 'paid']);
    }

    private function recordShipping(Order $order, string $number = 'TRACK-A'): OrderShipping
    {
        return app(OrderShippingService::class)->record($order, 0, [
            'logistics_company' => 'ups',
            'tracking_number' => $number,
            'tracking_url' => 'https://tracking.example.com/?number='.$number,
            'shipped_at' => '2026-10-02T11:00:00+08:00',
        ]);
    }

    private function latestSync(Order $order): OrderShipmentSync
    {
        return OrderShipmentSync::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)->latest('id')->firstOrFail();
    }
}
