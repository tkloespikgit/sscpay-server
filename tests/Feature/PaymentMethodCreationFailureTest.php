<?php

namespace Tests\Feature;

use App\Events\PaymentLinkCreationFailed;
use App\Exceptions\OrderItemPreparationException;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\OrderItemService;
use App\Services\PaymentMethodFailureService;
use App\Services\TelegramNotificationService;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentMethodCreationFailureTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const PAY_URL = 'https://shop.example.com/wp-json/payment-plugin/v1/pay*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment_gateway.retry_times' => 1]);
        $this->app->setLocale('zh_CN');
    }

    public function test_five_consecutive_creation_failures_disable_the_channel_and_notify_only_once(): void
    {
        $method = $this->routedMethod();
        Event::fake([PaymentLinkCreationFailed::class]);
        $telegram = $this->mock(TelegramNotificationService::class);
        $telegram->shouldReceive('send')->once()
            ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $this->merchant->id
                && str_contains($message, $method->method_code) && str_contains($message, '5'))
            ->andReturn(true);
        $telegram->shouldReceive('send')
            ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $this->merchant->id
                && ! str_contains($message, $method->method_code))
            ->andReturn(true);
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');
            $this->assertSame($attempt, $method->refresh()->consecutive_failed_times);
            $this->assertTrue($method->is_active);
        }

        $this->sendOrder()->assertStatus(502)->assertJsonPath('error_code', 'GATEWAY_ERROR');

        $this->assertSame(5, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        $this->sendOrder()->assertConflict()->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');
        $this->assertSame(5, $method->refresh()->consecutive_failed_times);
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(5);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 5);
    }

    public function test_manually_reenabling_an_automatically_disabled_channel_starts_a_new_failure_sequence(): void
    {
        $method = $this->routedMethod();
        $method->update(['max_failed_times' => 2]);
        Event::fake([PaymentLinkCreationFailed::class]);
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->twice()
            ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $this->merchant->id
                && str_contains($message, $method->method_code))
            ->andReturn(true);
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)]);

        $this->sendOrder()->assertStatus(502);
        $this->sendOrder()->assertStatus(502);
        $this->assertFalse($method->refresh()->is_active);
        $this->assertSame(2, $method->consecutive_failed_times);

        $method->update(['is_active' => true]);

        $this->assertTrue($method->is_active);
        $this->assertSame(0, $method->consecutive_failed_times);
        $this->sendOrder()->assertStatus(502);
        $this->assertSame(1, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);

        $this->sendOrder()->assertStatus(502);

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(4);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 4);
    }

    public function test_shared_channels_use_their_override_and_accumulate_failures_across_merchants(): void
    {
        $method = $this->routedMethod();
        SystemConfig::set('payment.max_failed_times', '9');
        $other = Merchant::create(['name' => 'Other Failure Merchant']);
        $application = Application::createWithCredentials([
            'merchant_id' => $other->id, 'name' => 'Other Failure Application', 'website' => 'https://shop.example.com',
        ]);
        $otherGroup = PaymentGroup::create([
            'merchant_id' => $other->id, 'group_key' => 'failure-test', 'group_name' => 'Other Failure Group', 'is_active' => true,
        ]);
        $method->update(['merchant_id' => null, 'max_failed_times' => 2]);
        $method->assignedMerchants()->sync([$this->merchant->id, $other->id]);
        $otherGroup->paymentMethods()->attach($method->id, ['priority' => 100]);
        Event::fake([PaymentLinkCreationFailed::class]);
        $telegram = $this->mock(TelegramNotificationService::class);
        foreach ([$this->merchant->id, $other->id] as $recipientId) {
            $telegram->shouldReceive('send')->once()
                ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $recipientId
                    && str_contains($message, $method->method_code) && str_contains($message, '2'))
                ->andReturn(true);
        }
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)]);

        $this->sendOrder()->assertStatus(502);
        $this->assertSame(1, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);

        $this->sendOrder(application: $application)->assertStatus(502);

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        $this->assertDatabaseCount('orders', 2);
        Http::assertSentCount(2);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 2);
    }

    public function test_a_successful_creation_resets_failures_but_reusing_an_existing_link_does_not(): void
    {
        $method = $this->routedMethod();
        Event::fake([PaymentLinkCreationFailed::class]);
        $calls = 0;
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => function () use (&$calls) {
            $calls++;

            return $calls === 2
                ? Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/recovered']])
                : Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400);
        }]);
        $this->sendOrder()->assertStatus(502);
        $this->assertSame(1, $method->refresh()->consecutive_failed_times);

        $this->sendOrder()->assertOk()->assertJsonPath('data.pay_url', 'https://pay.example.com/recovered');

        $this->assertSame(0, $method->refresh()->consecutive_failed_times);
        foreach (['NEXT-1', 'NEXT-2', 'NEXT-3'] as $merchantOrderNo) {
            $this->sendOrder(['merchant_order_no' => $merchantOrderNo])->assertStatus(502);
        }
        $this->assertSame(3, $method->refresh()->consecutive_failed_times);

        $this->sendOrder()->assertOk()->assertJsonPath('data.pay_url', 'https://pay.example.com/recovered');

        $this->assertSame(3, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Http::assertSentCount(5);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 4);
    }

    public function test_exhausted_internal_connection_retries_count_as_one_creation_failure(): void
    {
        $method = $this->routedMethod();
        config(['payment_gateway.retry_times' => 3, 'payment_gateway.retry_sleep_ms' => 0]);
        Event::fake([PaymentLinkCreationFailed::class]);
        $attempts = 0;
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('Simulated connection failure');
        }]);

        $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');

        $this->assertSame(3, $attempts);
        $this->assertSame(1, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 1);
    }

    #[DataProvider('preGatewayFailures')]
    public function test_channel_configuration_and_product_preparation_errors_count_as_creation_failures(string $reason): void
    {
        $method = $this->routedMethod();
        if ($reason === 'missing_credentials') {
            $method->update(['domain_client_sk' => '']);
        } else {
            $method->update(['domain' => 'https://remote.example.com', 'product_match_mode' => PaymentMethod::MODE_CREATE]);
            $this->mock(OrderItemService::class)->shouldReceive('createItems')->once()
                ->andThrow(new OrderItemPreparationException('Remote product preparation failed'));
        }
        Event::fake([PaymentLinkCreationFailed::class]);
        Http::preventStrayRequests();

        $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');

        $this->assertSame(1, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Http::assertNothingSent();
        Event::assertNotDispatched(PaymentLinkCreationFailed::class);
    }

    /** @return array<string, array{string}> */
    public static function preGatewayFailures(): array
    {
        return [
            'missing channel credentials' => ['missing_credentials'],
            'remote product preparation failure' => ['product_preparation'],
        ];
    }

    #[DataProvider('preCreationRejections')]
    public function test_validation_risk_and_disabled_channel_rejections_do_not_change_failure_counts(string $reason, int $status): void
    {
        $method = $this->routedMethod();
        $method->forceFill(['consecutive_failed_times' => 2])->save();
        $overrides = [];
        if ($reason === 'invalid_amount') {
            $overrides['amount'] = '99.00';
        } elseif ($reason === 'risk_control') {
            $method->update(['max_amount_per_transaction' => '99.00']);
        } else {
            $method->update(['is_active' => false]);
        }
        Event::fake([PaymentLinkCreationFailed::class]);
        Http::preventStrayRequests();

        $this->sendOrder($overrides)->assertStatus($status);

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
        Event::assertNotDispatched(PaymentLinkCreationFailed::class);
    }

    /** @return array<string, array{string, int}> */
    public static function preCreationRejections(): array
    {
        return [
            'invalid order amount' => ['invalid_amount', 422],
            'risk control rejection' => ['risk_control', 409],
            'disabled channel rejection' => ['disabled', 409],
        ];
    }

    public function test_failure_updates_use_current_persisted_counts_instead_of_stale_models(): void
    {
        $method = $this->routedMethod();
        $first = $this->contextOrder($method, 'FIRST');
        $second = $this->contextOrder($method, 'SECOND');
        $staleMethod = $method->fresh();
        Http::preventStrayRequests();
        $service = app(PaymentMethodFailureService::class);

        $service->recordFailure($first, $method, $first->gatewayOrderReference());
        $service->recordFailure($second, $staleMethod, $second->gatewayOrderReference());

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Http::assertNothingSent();
    }

    public function test_in_flight_failures_only_notify_once_when_the_channel_has_already_been_disabled(): void
    {
        $method = $this->routedMethod();
        $method->update(['max_failed_times' => 1]);
        $first = $this->contextOrder($method, 'FIRST');
        $second = $this->contextOrder($method, 'SECOND');
        $staleMethod = $method->fresh();
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->once()
            ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $this->merchant->id
                && str_contains($message, $method->method_code))
            ->andReturn(true);
        Http::preventStrayRequests();
        $service = app(PaymentMethodFailureService::class);

        $service->recordFailure($first, $method, $first->gatewayOrderReference());
        $service->recordFailure($second, $staleMethod, $second->gatewayOrderReference());

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        Http::assertNothingSent();
    }

    public function test_a_threshold_notification_exception_does_not_replace_the_creation_failure_response(): void
    {
        $method = $this->routedMethod();
        $method->update(['max_failed_times' => 1]);
        Event::fake([PaymentLinkCreationFailed::class]);
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->once()
            ->andThrow(new RuntimeException('Notification delivery failed'));
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => Http::response(['code' => 10011, 'message' => 'Original gateway failure'], 400)]);

        $this->sendOrder()->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');

        $this->assertSame(1, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 1);
    }

    public function test_in_flight_failures_cannot_overflow_the_persisted_counter_or_repeat_the_disable_notification(): void
    {
        $method = $this->routedMethod();
        $method->forceFill(['max_failed_times' => 4294967295, 'consecutive_failed_times' => 4294967294])->save();
        $first = $this->contextOrder($method, 'FIRST');
        $second = $this->contextOrder($method, 'SECOND');
        $staleMethod = $method->fresh();
        $this->mock(TelegramNotificationService::class)->shouldReceive('send')->once()
            ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $this->merchant->id
                && str_contains($message, $method->method_code))
            ->andReturn(true);
        Http::preventStrayRequests();
        $service = app(PaymentMethodFailureService::class);

        $service->recordFailure($first, $method, $first->gatewayOrderReference());

        $this->assertSame(4294967295, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);

        $service->recordFailure($second, $staleMethod, $second->gatewayOrderReference());

        $this->assertSame(4294967295, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        Http::assertNothingSent();
    }

    public function test_a_channel_assignment_revoked_during_creation_cannot_change_the_shared_failure_count(): void
    {
        $method = $this->routedMethod();
        $method->update(['merchant_id' => null]);
        $method->assignedMerchants()->attach($this->merchant->id);
        $method->forceFill(['consecutive_failed_times' => 2])->save();
        Event::fake([PaymentLinkCreationFailed::class]);
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => function () use ($method) {
            $method->assignedMerchants()->detach($this->merchant->id);

            return Http::response(['code' => 10011, 'message' => 'Late gateway failure'], 400);
        }]);

        $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 1);
    }

    public function test_a_shared_channel_disable_notification_continues_after_one_merchants_notification_throws(): void
    {
        $method = $this->routedMethod();
        $other = Merchant::create(['name' => 'Other Notification Merchant']);
        $method->update(['merchant_id' => null, 'max_failed_times' => 1]);
        $method->assignedMerchants()->sync([$this->merchant->id, $other->id]);
        $order = $this->contextOrder($method, 'NOTIFY');
        $telegram = $this->mock(TelegramNotificationService::class);
        $telegram->shouldReceive('send')->once()
            ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $this->merchant->id
                && str_contains($message, $method->method_code))
            ->andThrow(new RuntimeException('The first merchant notification failed'));
        $telegram->shouldReceive('send')->once()
            ->withArgs(fn (int $merchantId, string $message): bool => $merchantId === $other->id
                && str_contains($message, $method->method_code))
            ->andReturn(true);
        Http::preventStrayRequests();

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        $this->assertSame(1, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        Http::assertNothingSent();
    }

    #[DataProvider('staleCreationResults')]
    public function test_results_for_an_obsolete_reference_or_channel_do_not_change_failure_counts(bool $success, string $changedField): void
    {
        $method = $this->routedMethod();
        $method->forceFill(['consecutive_failed_times' => 2])->save();
        $order = $this->contextOrder($method, 'STALE');
        $reference = $order->gatewayOrderReference();
        $replacement = $this->makePaymentMethod('replacement');
        $order->fresh()->update([
            'gateway_order_no' => $changedField === 'reference' ? 'GWNEWREFERENCE' : null,
            'payment_method_id' => $changedField === 'channel' ? $replacement->id : $method->id,
            'payment_method' => $changedField === 'channel' ? $replacement->method_code : $method->method_code,
            'pay_url' => $success ? 'https://pay.example.com/current' : null,
        ]);
        Http::preventStrayRequests();
        $service = app(PaymentMethodFailureService::class);

        if ($success) {
            $service->recordSuccess($order, $method, $reference);
        } else {
            $service->recordFailure($order, $method, $reference);
        }

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertSame(0, $replacement->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Http::assertNothingSent();
    }

    /** @return array<string, array{bool, string}> */
    public static function staleCreationResults(): array
    {
        return [
            'obsolete reference failure' => [false, 'reference'],
            'obsolete channel failure' => [false, 'channel'],
            'obsolete reference success' => [true, 'reference'],
            'obsolete channel success' => [true, 'channel'],
        ];
    }

    public function test_a_successful_in_flight_creation_resets_the_count_without_reenabling_the_channel(): void
    {
        $method = $this->routedMethod();
        $method->forceFill(['consecutive_failed_times' => 2])->save();
        Event::fake([PaymentLinkCreationFailed::class]);
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => function () use ($method) {
            $method->update(['is_active' => false]);

            return Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/in-flight']]);
        }]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.pay_url', 'https://pay.example.com/in-flight');

        $this->assertSame(0, $method->refresh()->consecutive_failed_times);
        $this->assertFalse($method->is_active);
        Http::assertSentCount(1);
        Event::assertNotDispatched(PaymentLinkCreationFailed::class);
    }

    #[DataProvider('creationOutcomes')]
    public function test_recording_a_creation_result_does_not_assign_an_unowned_shared_channel_to_the_logged_in_user(bool $success): void
    {
        $method = $this->routedMethod();
        $method->forceFill(['merchant_id' => null, 'owner_id' => null, 'consecutive_failed_times' => 2])->save();
        $method->assignedMerchants()->attach($this->merchant->id);
        $order = $this->contextOrder($method, 'OWNER');
        if ($success) {
            $order->update(['pay_url' => 'https://pay.example.com/owner-test']);
        }
        $this->actingAs(User::factory()->create([
            'merchant_id' => $this->merchant->id, 'status' => true, 'is_super_admin' => false,
        ]));
        Http::preventStrayRequests();
        $service = app(PaymentMethodFailureService::class);

        if ($success) {
            $service->recordSuccess($order, $method, $order->gatewayOrderReference());
        } else {
            $service->recordFailure($order, $method, $order->gatewayOrderReference());
        }

        $this->assertNull($method->refresh()->owner_id);
        $this->assertNull($method->merchant_id);
        $this->assertSame($success ? 0 : 3, $method->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Http::assertNothingSent();
    }

    /** @return array<string, array{bool}> */
    public static function creationOutcomes(): array
    {
        return ['successful creation' => [true], 'failed creation' => [false]];
    }

    #[DataProvider('concurrentOrderProgress')]
    public function test_a_gateway_failure_does_not_change_counts_if_a_payment_or_link_was_recorded_concurrently(bool $paid): void
    {
        $method = $this->routedMethod();
        $method->forceFill(['consecutive_failed_times' => 2])->save();
        Event::fake([PaymentLinkCreationFailed::class]);
        Http::preventStrayRequests();
        Http::fake([self::PAY_URL => function () use ($paid) {
            Order::query()->update($paid
                ? ['status' => 'paid', 'paid_at' => now(), 'transaction_id' => 'txn-concurrent']
                : ['pay_url' => 'https://pay.example.com/concurrent']);

            return Http::response(['code' => 10011, 'message' => 'Late gateway failure'], 400);
        }]);

        $this->sendOrder()->assertOk();

        $this->assertSame(2, $method->refresh()->consecutive_failed_times);
        $this->assertTrue($method->is_active);
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(PaymentLinkCreationFailed::class, 1);
    }

    /** @return array<string, array{bool}> */
    public static function concurrentOrderProgress(): array
    {
        return ['payment received' => [true], 'payment link saved' => [false]];
    }

    private function routedMethod(): PaymentMethod
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('creation-failure');
        $method->update(['payment_config_id' => 11]);
        $group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'failure-test',
            'group_name' => 'Failure Test Group',
            'is_active' => true,
        ]);
        $group->paymentMethods()->attach($method->id, ['priority' => 100]);

        return $method;
    }

    private function contextOrder(PaymentMethod $method, string $suffix): Order
    {
        return $this->makeOrder('FAILURE-'.$suffix, 'merchant-'.$suffix, [
            'payment_method_id' => $method->id,
            'payment_method' => $method->method_code,
        ]);
    }

    private function sendOrder(array $overrides = [], ?Application $application = null): TestResponse
    {
        $application ??= $this->application;
        $body = array_replace([
            'merchant_order_no' => 'FAILURE-001',
            'platform' => 'wordpress',
            'currency' => 'USD',
            'group_key' => 'failure-test',
            'subtotal' => '100.00',
            'shipping_fee' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'amount' => '100.00',
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
            'shipping_address' => ['line1' => '123 Main St', 'city' => 'City', 'country' => 'US'],
            'items' => [[
                'product_id' => 'SOURCE-001',
                'product_url' => 'https://shop.example.com/product/1',
                'product_name' => 'Original Product',
                'unit_price' => '100.00',
                'quantity' => 1,
            ]],
            'notify_url' => 'https://shop.example.com/notify',
            'return_url' => 'https://shop.example.com/return',
            'cancel_url' => 'https://shop.example.com/cancel',
        ], $overrides);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body['sign'] = SignatureCanonicalizer::sign($application->app_id, $timestamp, $nonce, $body, $application->api_key);

        return $this->withHeaders([
            'App-ID' => $application->app_id,
            'Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
        ])->postJson('/api/order/create', $body);
    }
}
