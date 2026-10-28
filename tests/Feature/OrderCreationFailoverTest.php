<?php

namespace Tests\Feature;

use App\Exceptions\OrderItemPreparationException;
use App\Jobs\SendPaymentLinkJob;
use App\Models\ExchangeRate;
use App\Models\MerchantPaymentSetting;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Services\OrderItemService;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderCreationFailoverTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment_gateway.retry_times' => 1, 'payment_gateway.failover_max_attempts' => 3]);
        $this->app->instance(Randomizer::class, new Randomizer(new Mt19937(0)));
    }

    public function test_api_switches_same_site_channels_with_a_new_gateway_reference_and_reuses_the_successful_link(): void
    {
        $group = $this->createGroup();
        $failed = $this->attachMethod($group, 'failed', 11);
        $successful = $this->attachMethod($group, 'successful', 22);
        $successful->update(['invoice_prefix' => 'SECOND']);
        Queue::fake([SendPaymentLinkJob::class]);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return $request['gateway_config_id'] === 11
                    ? Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                    : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/success', 'wp_order_id' => 202]]);
            },
        ]);

        $response = $this->sendOrder(['send_mail' => 'Y']);

        $response->assertOk()
            ->assertJsonPath('data.payment_method', $successful->method_code)
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/success');
        $this->assertDatabaseCount('orders', 1);
        $order = Order::sole();
        $this->assertSame($successful->id, $order->payment_method_id);
        $this->assertSame($order->order_no, $requests[0]['s_order_id']);
        $this->assertNotSame($requests[0]['s_order_id'], $requests[1]['s_order_id']);
        $this->assertSame($order->gateway_order_no, $requests[1]['s_order_id']);
        $this->assertSame('SECOND_'.strtoupper($order->order_no), $order->invoice_number);
        $this->assertSame([11, 22], array_column($requests, 'gateway_config_id'));
        $this->assertSame($failed->id, PaymentMethod::where('payment_config_id', $requests[0]['gateway_config_id'])->sole()->id);
        Queue::assertPushed(SendPaymentLinkJob::class, 1);

        $this->sendOrder(['send_mail' => 'Y'])->assertOk()
            ->assertJsonPath('data.order_no', $order->order_no)
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/success');
        Http::assertSentCount(2);
        Queue::assertPushed(SendPaymentLinkJob::class, 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_failover_recalculates_merchant_financial_snapshots_and_does_not_reuse_other_site_products(): void
    {
        $group = $this->createGroup();
        $this->merchant->update(['settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $first = $this->attachMethod($group, 'first', 11);
        $second = $this->attachMethod($group, 'second', 22);
        $first->update(['domain' => 'https://first.example.com', 'product_match_mode' => PaymentMethod::MODE_CREATE, 'fee_percent' => 1, 'fee_fixed' => 1]);
        $second->update(['domain' => 'https://second.example.com', 'product_match_mode' => PaymentMethod::MODE_CREATE, 'fee_percent' => 50, 'reserve_days' => 90]);
        MerchantPaymentSetting::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $second->id,
            'fee_percent' => '2.5000',
            'fee_fixed' => '0.30',
            'refund_fee' => '4.00',
            'chargeback_fee' => '12.00',
            'reserve_percent' => '7.0000',
            'reserve_days' => 45,
        ]);
        $itemService = $this->mock(OrderItemService::class);
        foreach ([$first, $second] as $method) {
            $itemService->shouldReceive('createItems')->once()
                ->withArgs(function (PaymentMethod $selected, Order $order, ?Collection $reusable) use ($method): bool {
                    return $selected->is($method) && ($reusable === null || $reusable->isEmpty());
                })
                ->andReturn($this->matchedItems('remote-'.$method->id, true));
        }
        Http::preventStrayRequests();
        Http::fake([
            'https://first.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400),
            'https://second.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/second', 'wp_order_id' => 202]]),
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'second');

        $order = Order::sole();
        $this->assertSame('2.50', $order->fee_percent_amount);
        $this->assertSame('0.30', $order->fee_fixed_amount);
        $this->assertSame('97.20', $order->settlement_amount);
        $this->assertSame('7.0000', $order->reserve_percent);
        $this->assertSame(45, $order->reserve_days);
        $this->assertSame('4.00', $order->refund_fee_snapshot);
        $this->assertSame('12.00', $order->chargeback_fee_snapshot);
        $this->assertSame('shipped', $order->settlement_mode);
        $this->assertSame(7, $order->settlement_days);
        $this->assertSame('100.00', $order->original_amount);
        $this->assertSame('100.00', $order->converted_amount);
        $this->assertSame('1.000000', $order->exchange_rate);
        $this->assertSame('0.00', $order->auto_discount);
        $this->assertSame(['remote-'.$second->id], $order->matchedItems()->pluck('product_id')->all());
        $this->assertSame(['SOURCE-001'], $order->items()->pluck('product_id')->all());
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 22 && $request['items'][0]['product_id'] === 'remote-'.$second->id);
        Http::assertSentCount(2);
    }

    public function test_failover_stops_after_three_distinct_gateway_attempts_and_keeps_one_local_order(): void
    {
        $group = $this->createGroup();
        foreach ([11, 22, 33, 44] as $configId) {
            $this->attachMethod($group, 'method-'.$configId, $configId);
        }
        Queue::fake([SendPaymentLinkJob::class]);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return Http::response(['code' => 10011, 'message' => 'Failure '.$request['gateway_config_id']], 400);
            },
        ]);

        $response = $this->sendOrder(['send_mail' => 'Y']);

        $response->assertStatus(502)->assertJsonPath('error_code', 'GATEWAY_ERROR');
        $this->assertCount(3, $requests);
        $this->assertCount(3, array_unique(array_column($requests, 'gateway_config_id')));
        $this->assertCount(3, array_unique(array_column($requests, 's_order_id')));
        $response->assertJsonPath('msg', 'Failure '.$requests[2]['gateway_config_id']);
        $this->assertDatabaseCount('orders', 1);
        $order = Order::sole();
        $this->assertNull($order->pay_url);
        $this->assertSame($requests[2]['s_order_id'], $order->gateway_order_no);
        $this->assertSame($requests[2]['gateway_config_id'], (int) $order->paymentMethodConfig()->payment_config_id);
        Queue::assertNotPushed(SendPaymentLinkJob::class);
        Http::assertSentCount(3);
    }

    public function test_retry_of_an_exhausted_order_can_switch_immediately_without_creating_a_second_local_order(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'second', 22);
        Queue::fake([SendPaymentLinkJob::class]);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return count($requests) < 4
                    ? Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                    : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/recovered', 'wp_order_id' => 303]]);
            },
        ]);
        $this->sendOrder(['send_mail' => 'Y'])->assertStatus(502);
        $failedOrder = Order::sole();
        $failedOrder->update(['status' => 'failed', 'failed_at' => now()]);
        $failedReference = $failedOrder->gateway_order_no;

        $this->sendOrder(['send_mail' => 'N'])->assertOk()
            ->assertJsonPath('data.order_no', $failedOrder->order_no)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/recovered');

        $order = Order::sole();
        $this->assertSame($failedOrder->id, $order->id);
        $this->assertSame($failedOrder->payment_link_token, $order->payment_link_token);
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertSame($failedReference, $requests[2]['s_order_id']);
        $this->assertNotSame($failedReference, $requests[3]['s_order_id']);
        $this->assertSame($requests[3]['s_order_id'], $order->gateway_order_no);
        $this->assertTrue($order->send_mail);
        Queue::assertPushed(SendPaymentLinkJob::class, 1);
        Http::assertSentCount(4);
    }

    public function test_designated_channel_failure_does_not_use_another_group_channel(): void
    {
        $group = $this->createGroup();
        $designated = $this->attachMethod($group, 'designated', 11);
        $this->attachMethod($group, 'other', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 10011, 'message' => 'Designated channel unavailable'], 400),
        ]);

        $this->sendOrder(['payment_method_key' => $designated->method_code])->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR');

        $order = Order::sole();
        $this->assertSame($designated->id, $order->payment_method_id);
        $this->assertSame($designated->method_code, $order->designated_payment_method_key);
        $this->assertNull($order->gateway_order_no);
        Http::assertSentCount(1);
    }

    public function test_a_payment_recorded_during_a_gateway_failure_prevents_channel_switching(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'second', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function () {
                Order::query()->update(['status' => 'paid', 'paid_at' => now(), 'transaction_id' => 'txn-already-paid']);

                return Http::response(['code' => 10011, 'message' => 'Late gateway failure'], 400);
            },
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.status', 'paid');

        $order = Order::sole();
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertSame('txn-already-paid', $order->transaction_id);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->gateway_order_no);
        Http::assertSentCount(1);
    }

    #[DataProvider('staleGatewayResponses')]
    public function test_a_stale_gateway_response_does_not_overwrite_or_retry_a_newer_attempt(bool $successfulResponse): void
    {
        $group = $this->createGroup();
        $this->attachMethod($group, 'first', 11);
        $newerMethod = $this->attachMethod($group, 'newer', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function () use ($newerMethod, $successfulResponse) {
                Order::query()->update([
                    'gateway_order_no' => 'GWCONCURRENTATTEMPT',
                    'payment_method_id' => $newerMethod->id,
                    'payment_method' => $newerMethod->method_code,
                    'status' => 'pending',
                    'pay_url' => null,
                    'wp_order_id' => null,
                ]);

                return $successfulResponse
                    ? Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/obsolete', 'wp_order_id' => 999]])
                    : Http::response(['code' => 10011, 'message' => 'Obsolete failure'], 400);
            },
        ]);

        $this->sendOrder()->assertStatus(502)->assertJsonPath('error_code', 'GATEWAY_ERROR');

        $order = Order::sole();
        $this->assertSame('GWCONCURRENTATTEMPT', $order->gateway_order_no);
        $this->assertSame($newerMethod->id, $order->payment_method_id);
        $this->assertNull($order->pay_url);
        $this->assertNull($order->wp_order_id);
        $this->assertSame('pending', $order->status);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{bool}> */
    public static function staleGatewayResponses(): array
    {
        return ['stale success' => [true], 'stale failure' => [false]];
    }

    public function test_transport_failure_can_switch_channels_in_the_same_request(): void
    {
        $group = $this->createGroup();
        $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'second', 22);
        Http::preventStrayRequests();
        $attemptedConfigs = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$attemptedConfigs) {
                $attemptedConfigs[] = $request['gateway_config_id'];
                if ($request['gateway_config_id'] === 11) {
                    throw new ConnectionException('Simulated gateway connection failure');
                }

                return Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/recovered']]);
            },
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'second');

        $this->assertSame([11, 22], $attemptedConfigs);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('https://pay.example.com/recovered', Order::sole()->pay_url);
    }

    /** @param array<string, mixed> $response */
    #[DataProvider('unusableGatewayResponses')]
    public function test_an_unusable_gateway_response_switches_to_another_channel(array $response): void
    {
        $group = $this->createGroup();
        $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'second', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => fn (Request $request) => $request['gateway_config_id'] === 11
                ? Http::response($response)
                : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/recovered']]),
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'second')
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/recovered');

        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unusableGatewayResponses(): array
    {
        return [
            'missing payment link' => [['code' => 0, 'data' => ['wp_order_id' => 101]]],
            'invalid data type' => [['code' => 0, 'data' => 'invalid data']],
        ];
    }

    public function test_failover_preserves_automatic_discount_and_exchange_rate_snapshots(): void
    {
        $group = $this->createGroup();
        $this->application->update(['is_auto_discount_enabled' => true]);
        ExchangeRate::updateBatchRates(['EUR' => 2]);
        $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'second', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) {
                if ($request['gateway_config_id'] === 11) {
                    ExchangeRate::updateBatchRates(['EUR' => 3]);
                    $this->application->update(['is_auto_discount_enabled' => false]);

                    return Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400);
                }

                return Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/preserved']]);
            },
        ]);

        $this->sendOrder([
            'currency' => 'EUR',
            'subtotal' => '0.02',
            'amount' => '0.02',
            'items' => [[
                'product_id' => 'SOURCE-001',
                'product_url' => 'https://shop.example.com/product/1',
                'product_name' => 'Original Product',
                'unit_price' => '0.02',
                'quantity' => 1,
            ]],
        ])->assertOk()->assertJsonPath('data.payment_method', 'second');

        $order = Order::sole();
        $this->assertSame('0.02', $order->original_amount);
        $this->assertSame('0.01', $order->auto_discount);
        $this->assertSame('0.01', $order->amount);
        $this->assertSame('0.01', $order->discount);
        $this->assertSame('2.000000', $order->exchange_rate);
        $this->assertSame('2.000000', $order->original_exchange_rate);
        $this->assertSame('0.02', $order->converted_amount);
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 22 && $request['amount'] === 0.01 && $request['discount_fee'] === 0.01);
        Http::assertSentCount(2);
    }

    public function test_switching_channels_replaces_matching_overflow_instead_of_accumulating_discounts(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $second = $this->attachMethod($group, 'second', 22);
        $first->update(['domain' => 'https://first.example.com']);
        $second->update(['domain' => 'https://second.example.com']);
        $itemService = $this->mock(OrderItemService::class);
        foreach ([[$first, '110.00', '10.00'], [$second, '120.00', '20.00']] as [$method, $subtotal, $overflow]) {
            $matched = $this->matchedItems('remote-'.$method->id, false);
            $matched['subtotal'] = $subtotal;
            $matched['overflow'] = $overflow;
            $matched['items'][0]['unit_price'] = $subtotal;
            $matched['items'][0]['total_price'] = $subtotal;
            $matched['items'][0]['converted_unit_price'] = $subtotal;
            $itemService->shouldReceive('matchItems')->once()
                ->withArgs(fn (PaymentMethod $selected, string $target, string $rate): bool => $selected->is($method) && $target === '100.00' && $rate === '1.000000')
                ->andReturn($matched);
        }
        Http::preventStrayRequests();
        Http::fake([
            'https://first.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400),
            'https://second.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/second']]),
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'second');

        $order = Order::sole();
        $this->assertSame('120.00', $order->subtotal);
        $this->assertSame('20.00', $order->discount);
        $this->assertSame('100.00', $order->amount);
        $this->assertSame('100.00', $order->items()->sole()->total_price);
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 22 && $request['discount_fee'] === 20.0 && $request['subtotal'] === 120.0);
        Http::assertSentCount(2);
    }

    public function test_failover_skips_candidates_whose_fees_exceed_the_order_amount(): void
    {
        $group = $this->createGroup();
        $this->attachMethod($group, 'first', 11);
        $expensive = $this->attachMethod($group, 'expensive', 22);
        $expensive->update(['is_active' => false, 'fee_fixed' => '101.00']);
        $successful = $this->attachMethod($group, 'successful', 33);
        $successful->update(['is_active' => false]);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use ($expensive, $successful) {
                if ($request['gateway_config_id'] === 11) {
                    $expensive->update(['is_active' => true]);
                    $successful->update(['is_active' => true]);

                    return Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400);
                }

                return Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/affordable']]);
            },
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'successful');

        Http::assertNotSent(fn (Request $request): bool => $request['gateway_config_id'] === 22);
        Http::assertSentCount(2);
        $this->assertSame('100.00', Order::sole()->settlement_amount);
    }

    public function test_initial_selection_skips_an_unaffordable_channel_before_creating_the_order(): void
    {
        $group = $this->createGroup();
        $expensive = $this->attachMethod($group, 'expensive', 11);
        $expensive->update(['fee_fixed' => '101.00']);
        $affordable = $this->attachMethod($group, 'affordable', 22);
        $affordable->update(['fee_percent' => '2.0000']);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/affordable']]),
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'affordable');

        $order = Order::sole();
        $this->assertSame($affordable->id, $order->payment_method_id);
        $this->assertSame('98.00', $order->settlement_amount);
        $this->assertNull($order->gateway_order_no);
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 22);
        Http::assertNotSent(fn (Request $request): bool => $request['gateway_config_id'] === 11);
        Http::assertSentCount(1);
    }

    #[DataProvider('remoteProductPreparationModes')]
    public function test_a_channel_product_preparation_failure_switches_to_a_working_channel(string $mode, string $preparationMethod): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $second = $this->attachMethod($group, 'second', 22);
        $first->update(['domain' => 'https://first.example.com', 'product_match_mode' => $mode]);
        $second->update(['domain' => 'https://second.example.com', 'product_match_mode' => $mode]);
        $itemService = $this->mock(OrderItemService::class);
        $itemService->shouldReceive($preparationMethod)->once()
            ->withArgs(fn (PaymentMethod $method): bool => $method->is($first))
            ->andThrow(new OrderItemPreparationException('The first site could not prepare a product'));
        $itemService->shouldReceive($preparationMethod)->once()
            ->withArgs(fn (PaymentMethod $method, Order $order, ?Collection $reusable): bool => $method->is($second) && ($reusable === null || $reusable->isEmpty()))
            ->andReturn($this->matchedItems('remote-'.$second->id, true));
        Http::preventStrayRequests();
        Http::fake([
            'https://second.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/prepared']]),
        ]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'second')
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/prepared');

        $order = Order::sole();
        $this->assertSame($second->id, $order->payment_method_id);
        $this->assertNotNull($order->gateway_order_no);
        $this->assertSame(['remote-'.$second->id], $order->matchedItems()->pluck('product_id')->all());
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 22);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string, string}> */
    public static function remoteProductPreparationModes(): array
    {
        return [
            'create product' => [PaymentMethod::MODE_CREATE, 'createItems'],
            'copy product' => [PaymentMethod::MODE_COPY, 'copyItems'],
        ];
    }

    private function createGroup(): PaymentGroup
    {
        $this->createMerchantAndApplication();

        return PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'default',
            'group_name' => 'Default',
            'is_active' => true,
        ]);
    }

    private function attachMethod(PaymentGroup $group, string $code, int $configId): PaymentMethod
    {
        $method = $this->makePaymentMethod($code);
        $method->update(['payment_config_id' => $configId]);
        $group->paymentMethods()->attach($method->id, ['priority' => 100]);

        return $method;
    }

    private function sendOrder(array $overrides = []): TestResponse
    {
        $body = array_replace([
            'merchant_order_no' => 'FAILOVER-001',
            'platform' => 'wordpress',
            'currency' => 'USD',
            'group_key' => 'default',
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
        $signed = $body + [
            'sign' => SignatureCanonicalizer::sign($this->application->app_id, $timestamp, $nonce, $body, $this->application->api_key),
        ];

        return $this->withHeaders([
            'App-ID' => $this->application->app_id,
            'Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
        ])->postJson('/api/order/create', $signed);
    }

    /**
     * @return array{items: list<array<string, mixed>>, subtotal: string, overflow: string}
     */
    private function matchedItems(string $productId, bool $autoCreated): array
    {
        return [
            'items' => [[
                'product_sku' => $productId,
                'product_id' => $productId,
                'product_url' => 'https://products.example.com/'.$productId,
                'product_name' => 'Remote Product',
                'product_description' => '',
                'unit_price' => '100.00',
                'quantity' => 1,
                'total_price' => '100.00',
                'converted_unit_price' => '100.00',
                'auto_created' => $autoCreated,
            ]],
            'subtotal' => '100.00',
            'overflow' => '0.00',
        ];
    }
}
