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
use App\Services\OrderPaymentStatusService;
use App\Services\PaymentService;
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

        config(['payment_gateway.retry_times' => 1]);
        $this->app->instance(Randomizer::class, new Randomizer(new Mt19937(0)));
    }

    public function test_retry_reuses_the_original_channel_reference_and_successful_link_even_if_another_channel_is_available(): void
    {
        $group = $this->createGroup();
        $selected = $this->attachMethod($group, 'selected', 11);
        $this->attachMethod($group, 'other', 22);
        $selected->update(['invoice_prefix' => 'ORIGINAL']);
        Queue::fake([SendPaymentLinkJob::class]);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return count($requests) === 1
                    ? Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                    : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/success', 'wp_order_id' => 202]]);
            },
        ]);

        $this->sendOrder(['send_mail' => 'Y'])->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');
        $failedOrder = Order::sole();
        $this->assertSame($selected->id, $failedOrder->payment_method_id);
        Queue::assertNotPushed(SendPaymentLinkJob::class);

        $this->sendOrder(['send_mail' => 'N'])->assertOk()
            ->assertJsonPath('data.order_no', $failedOrder->order_no)
            ->assertJsonPath('data.payment_method', $selected->method_code)
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/success');
        $this->assertDatabaseCount('orders', 1);
        $order = Order::sole();
        $this->assertSame($failedOrder->id, $order->id);
        $this->assertSame($failedOrder->payment_link_token, $order->payment_link_token);
        $this->assertSame($selected->id, $order->payment_method_id);
        $this->assertSame($order->order_no, $requests[0]['s_order_id']);
        $this->assertSame($requests[0]['s_order_id'], $requests[1]['s_order_id']);
        $this->assertNull($order->gateway_order_no);
        $this->assertSame('ORIGINAL_'.strtoupper($order->order_no), $order->invoice_number);
        $this->assertSame([11, 11], array_column($requests, 'gateway_config_id'));
        $this->assertTrue($order->send_mail);
        Queue::assertPushed(SendPaymentLinkJob::class, 1);

        $this->sendOrder(['send_mail' => 'Y'])->assertOk()
            ->assertJsonPath('data.order_no', $order->order_no)
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/success');
        Http::assertSentCount(2);
        Queue::assertPushed(SendPaymentLinkJob::class, 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_retry_preserves_financial_snapshots_and_created_products_even_if_current_fees_change_and_another_channel_is_available(): void
    {
        $group = $this->createGroup();
        $this->merchant->update(['settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $first = $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'other', 22);
        $first->update(['domain' => 'https://first.example.com', 'product_match_mode' => PaymentMethod::MODE_CREATE, 'fee_percent' => 1, 'fee_fixed' => 1]);
        $settings = MerchantPaymentSetting::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $first->id,
            'fee_percent' => '2.5000',
            'fee_fixed' => '0.30',
            'refund_fee' => '4.00',
            'chargeback_fee' => '12.00',
            'reserve_percent' => '7.0000',
            'reserve_days' => 45,
            'settlement_mode' => 'immediate',
            'settlement_days' => 2,
        ]);
        $itemService = $this->mock(OrderItemService::class);
        $itemService->shouldReceive('createItems')->once()
            ->withArgs(fn (PaymentMethod $selected, Order $order, ?Collection $reusable): bool => $selected->is($first) && $reusable?->isEmpty())
            ->andReturn($this->matchedItems('remote-first', true));
        $itemService->shouldReceive('createItems')->once()
            ->withArgs(fn (PaymentMethod $selected, Order $order, ?Collection $reusable): bool => $selected->is($first) && $reusable?->pluck('product_id')->all() === ['remote-first'])
            ->andReturn($this->matchedItems('remote-first', true));
        Http::preventStrayRequests();
        Http::fake([
            'https://first.example.com/wp-json/payment-plugin/v1/pay*' => Http::sequence()
                ->push(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                ->push(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/first', 'wp_order_id' => 202]]),
        ]);

        $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');
        $settings->update(['fee_fixed' => '101.00', 'reserve_days' => 90, 'settlement_days' => 30]);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'first');

        $order = Order::sole();
        $this->assertSame('2.50', $order->fee_percent_amount);
        $this->assertSame('0.30', $order->fee_fixed_amount);
        $this->assertSame('97.20', $order->settlement_amount);
        $this->assertSame('7.0000', $order->reserve_percent);
        $this->assertSame(45, $order->reserve_days);
        $this->assertSame('4.00', $order->refund_fee_snapshot);
        $this->assertSame('12.00', $order->chargeback_fee_snapshot);
        $this->assertSame('immediate', $order->settlement_mode);
        $this->assertSame(2, $order->settlement_days);
        $this->assertSame('100.00', $order->original_amount);
        $this->assertSame('100.00', $order->converted_amount);
        $this->assertSame('1.000000', $order->exchange_rate);
        $this->assertSame('0.00', $order->auto_discount);
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertNull($order->gateway_order_no);
        $this->assertSame(['remote-first'], $order->matchedItems()->pluck('product_id')->all());
        $this->assertSame(['SOURCE-001'], $order->items()->pluck('product_id')->all());
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 11 && $request['items'][0]['product_id'] === 'remote-first');
        Http::assertSentCount(2);
    }

    public function test_gateway_failure_returns_502_without_trying_another_channel(): void
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

        $response->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');
        $this->assertCount(1, $requests);
        $this->assertDatabaseCount('orders', 1);
        $order = Order::sole();
        $this->assertNull($order->pay_url);
        $this->assertNull($order->gateway_order_no);
        $this->assertSame($order->order_no, $requests[0]['s_order_id']);
        $this->assertSame($requests[0]['gateway_config_id'], (int) $order->paymentMethodConfig()->payment_config_id);
        Queue::assertNotPushed(SendPaymentLinkJob::class);
        Http::assertSentCount(1);
    }

    public function test_each_retry_request_attempts_the_original_channel_once_until_it_recovers(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $second = $this->attachMethod($group, 'second', 22);
        $third = $this->attachMethod($group, 'third', 33);
        $second->update(['is_active' => false]);
        $third->update(['is_active' => false]);
        Queue::fake([SendPaymentLinkJob::class]);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return count($requests) === 3
                    ? Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/recovered']])
                    : Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400);
            },
        ]);

        $this->sendOrder(['send_mail' => 'Y'])->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');
        $original = Order::sole();
        Http::assertSentCount(1);
        $second->update(['is_active' => true]);
        $third->update(['is_active' => true]);

        $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');

        Http::assertSentCount(2);
        $this->assertSame($first->id, Order::sole()->payment_method_id);
        $this->assertSame('pending', Order::sole()->status);
        Queue::assertNotPushed(SendPaymentLinkJob::class);

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'first');

        Http::assertSentCount(3);
        $this->assertSame([11, 11, 11], array_column($requests, 'gateway_config_id'));
        $this->assertSame([$original->order_no], array_values(array_unique(array_column($requests, 's_order_id'))));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($original->id, Order::sole()->id);
        $this->assertSame($original->payment_link_token, Order::sole()->payment_link_token);
        Queue::assertPushed(SendPaymentLinkJob::class, 1);
    }

    #[DataProvider('legacyPaymentMethodIds')]
    public function test_retry_preserves_a_legacy_gateway_reference_without_creating_a_second_local_order(bool $missingMethodId): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        Queue::fake([SendPaymentLinkJob::class]);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return count($requests) === 1
                    ? Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                    : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/recovered', 'wp_order_id' => 303]]);
            },
        ]);
        $this->sendOrder(['send_mail' => 'Y'])->assertStatus(502);
        $failedOrder = Order::sole();
        $failedOrder->update([
            'gateway_order_no' => 'GWLEGACYATTEMPT',
            'payment_method_id' => $missingMethodId ? null : $first->id,
        ]);
        $first->update(['fee_fixed' => '101.00']);
        $failedReference = $failedOrder->gateway_order_no;

        $this->sendOrder(['send_mail' => 'N'])->assertOk()
            ->assertJsonPath('data.order_no', $failedOrder->order_no)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/recovered');

        $order = Order::sole();
        $this->assertSame($failedOrder->id, $order->id);
        $this->assertSame($failedOrder->payment_link_token, $order->payment_link_token);
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertSame($failedReference, $requests[1]['s_order_id']);
        $this->assertSame($failedReference, $order->gateway_order_no);
        $this->assertSame([11, 11], array_column($requests, 'gateway_config_id'));
        $this->assertSame('100.00', $order->settlement_amount);
        $this->assertTrue($order->send_mail);
        Queue::assertPushed(SendPaymentLinkJob::class, 1);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{bool}> */
    public static function legacyPaymentMethodIds(): array
    {
        return [
            'saved payment method id' => [false],
            'legacy order without payment method id' => [true],
        ];
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
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');

        $order = Order::sole();
        $this->assertSame($designated->id, $order->payment_method_id);
        $this->assertSame($designated->method_code, $order->designated_payment_method_key);
        $this->assertNull($order->gateway_order_no);
        Http::assertSentCount(1);
    }

    #[DataProvider('unavailableGroupChannels')]
    public function test_retry_returns_409_without_using_another_channel_if_the_original_channel_is_unavailable(string $reason): void
    {
        $group = $this->createGroup();
        $method = $this->attachMethod($group, 'shared', 11);
        $method->update(['merchant_id' => null]);
        $this->merchant->assignedPaymentMethods()->attach($method->id);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400),
        ]);
        $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');
        $original = Order::sole();
        $this->attachMethod($group, 'other', 22);
        match ($reason) {
            'disabled' => $method->update(['is_active' => false]),
            'soft_deleted' => $method->delete(),
            'unassigned' => $this->merchant->assignedPaymentMethods()->detach($method->id),
            'removed_from_group' => $group->paymentMethods()->detach($method->id),
            'risk_blocked' => $method->update(['max_amount_per_transaction' => '99.00']),
            'group_disabled' => $group->update(['is_active' => false]),
            'group_deleted' => $group->delete(),
        };

        $this->sendOrder()->assertConflict()->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($original->id, Order::sole()->id);
        $this->assertSame($original->payment_method_id, Order::sole()->payment_method_id);
        $this->assertNull(Order::sole()->pay_url);
        $this->assertNull(Order::sole()->gateway_order_no);
    }

    /** @return array<string, array{string}> */
    public static function unavailableGroupChannels(): array
    {
        return [
            'disabled original channel' => ['disabled'],
            'soft deleted original channel' => ['soft_deleted'],
            'shared channel assignment revoked' => ['unassigned'],
            'original channel removed from group' => ['removed_from_group'],
            'original channel blocked by risk control' => ['risk_blocked'],
            'disabled original group' => ['group_disabled'],
            'soft deleted original group' => ['group_deleted'],
        ];
    }

    #[DataProvider('unavailableDesignatedChannels')]
    public function test_designated_retry_returns_422_if_the_saved_channel_is_unavailable_even_if_a_different_key_is_submitted(string $reason): void
    {
        $group = $this->createGroup();
        $designated = $this->attachMethod($group, 'designated', 11);
        $other = $this->attachMethod($group, 'other', 22);
        $designated->update(['merchant_id' => null]);
        $this->merchant->assignedPaymentMethods()->attach($designated->id);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400),
        ]);
        $this->sendOrder(['payment_method_key' => $designated->method_code])->assertStatus(502);
        match ($reason) {
            'disabled' => $designated->update(['is_active' => false]),
            'soft_deleted' => $designated->delete(),
            'unassigned' => $this->merchant->assignedPaymentMethods()->detach($designated->id),
        };

        $this->sendOrder(['payment_method_key' => $other->method_code])->assertUnprocessable()
            ->assertJsonPath('error_code', 'PAYMENT_METHOD_NOT_AVAILABLE');

        $order = Order::sole();
        $this->assertSame($designated->id, $order->payment_method_id);
        $this->assertSame($designated->method_code, $order->designated_payment_method_key);
        $this->assertNull($order->pay_url);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string}> */
    public static function unavailableDesignatedChannels(): array
    {
        return [
            'disabled designated channel' => ['disabled'],
            'soft deleted designated channel' => ['soft_deleted'],
            'designated channel assignment revoked' => ['unassigned'],
        ];
    }

    #[DataProvider('legacyPaymentMethodIds')]
    public function test_designated_retry_uses_the_saved_channel_and_urls_even_if_the_request_changes_them(bool $missingMethodId): void
    {
        $group = $this->createGroup();
        $designated = $this->attachMethod($group, 'designated', 11);
        $other = $this->attachMethod($group, 'other', 22);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return count($requests) === 1
                    ? Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                    : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/designated']]);
            },
        ]);
        $this->sendOrder(['payment_method_key' => $designated->method_code])->assertStatus(502);
        Order::sole()->update(['payment_method_id' => $missingMethodId ? null : $designated->id]);

        $this->sendOrder([
            'payment_method_key' => $other->method_code,
            'group_key' => 'different',
            'return_url' => 'https://other.example.com/return',
            'cancel_url' => 'https://other.example.com/cancel',
            'notify_url' => 'https://other.example.com/notify',
        ])->assertOk()->assertJsonPath('data.payment_method', 'designated');

        $order = Order::sole();
        $this->assertSame($designated->id, $order->payment_method_id);
        $this->assertSame($designated->method_code, $order->designated_payment_method_key);
        $this->assertSame($group->id, $order->payment_group_id);
        $this->assertSame([11, 11], array_column($requests, 'gateway_config_id'));
        $this->assertSame($requests[0]['s_order_id'], $requests[1]['s_order_id']);
        $this->assertSame('https://shop.example.com/return', $requests[1]['return_url']);
        $this->assertSame('https://shop.example.com/cancel', $requests[1]['cancel_url']);
        Http::assertSentCount(2);
    }

    public function test_a_renamed_designated_channel_can_be_retried_using_its_saved_id(): void
    {
        $group = $this->createGroup();
        $designated = $this->attachMethod($group, 'designated', 11);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return count($requests) === 1
                    ? Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                    : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/renamed']]);
            },
        ]);
        $this->sendOrder(['payment_method_key' => 'designated'])->assertStatus(502);
        $designated->update(['method_code' => 'renamed']);

        $this->sendOrder(['payment_method_key' => 'designated'])->assertOk()
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/renamed');

        $order = Order::sole();
        $this->assertSame($designated->id, $order->payment_method_id);
        $this->assertSame('designated', $order->designated_payment_method_key);
        $this->assertSame([11, 11], array_column($requests, 'gateway_config_id'));
        $this->assertSame($requests[0]['s_order_id'], $requests[1]['s_order_id']);
        Http::assertSentCount(2);
    }

    public function test_a_deleted_designated_channel_cannot_be_replaced_by_a_new_channel_with_the_same_code(): void
    {
        $group = $this->createGroup();
        $designated = $this->attachMethod($group, 'designated', 11);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400),
        ]);
        $this->sendOrder(['payment_method_key' => 'designated'])->assertStatus(502);
        $designated->delete();
        $replacement = $this->attachMethod($group, 'designated', 22);

        $this->sendOrder(['payment_method_key' => 'designated'])->assertUnprocessable()
            ->assertJsonPath('error_code', 'PAYMENT_METHOD_NOT_AVAILABLE');

        $this->assertNotSame($replacement->id, $designated->id);
        $this->assertSame($designated->id, Order::sole()->payment_method_id);
        $this->assertNull(Order::sole()->pay_url);
        Http::assertSentCount(1);
    }

    public function test_group_retry_uses_the_saved_group_even_if_the_request_supplies_another_group_and_designated_key(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'second', 22);
        $otherGroup = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'other',
            'group_name' => 'Other',
            'is_active' => true,
        ]);
        $other = $this->attachMethod($otherGroup, 'other', 33);
        Http::preventStrayRequests();
        $requests = [];
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$requests) {
                $requests[] = $request->data();

                return count($requests) === 1
                    ? Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                    : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/first']]);
            },
        ]);
        $this->sendOrder()->assertStatus(502);

        $this->sendOrder(['group_key' => $otherGroup->group_key, 'payment_method_key' => $other->method_code])
            ->assertOk()->assertJsonPath('data.payment_method', 'first');

        $order = Order::sole();
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertSame($group->id, $order->payment_group_id);
        $this->assertNull($order->designated_payment_method_key);
        $this->assertSame([11, 11], array_column($requests, 'gateway_config_id'));
        Http::assertSentCount(2);
    }

    public function test_a_payment_recorded_during_a_gateway_failure_is_returned_as_success(): void
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

    public function test_a_payment_link_recorded_during_a_gateway_failure_is_returned_as_success(): void
    {
        $group = $this->createGroup();
        $selected = $this->attachMethod($group, 'selected', 11);
        $this->attachMethod($group, 'other', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function () {
                Order::query()->update([
                    'pay_url' => 'https://pay.example.com/concurrent',
                    'wp_order_id' => 404,
                ]);

                return Http::response(['code' => 10011, 'message' => 'Late gateway failure'], 400);
            },
        ]);

        $this->sendOrder()->assertOk()
            ->assertJsonPath('data.payment_method', 'selected')
            ->assertJsonPath('data.pay_url', 'https://pay.example.com/concurrent');

        $order = Order::sole();
        $this->assertSame($selected->id, $order->payment_method_id);
        $this->assertSame('https://pay.example.com/concurrent', $order->pay_url);
        $this->assertSame(404, $order->wp_order_id);
        $this->assertNull($order->gateway_order_no);
        Http::assertSentCount(1);
    }

    #[DataProvider('concurrentOrderProgress')]
    public function test_retry_does_not_send_a_payment_request_if_the_order_progresses_during_channel_validation(bool $paid): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $second = $this->attachMethod($group, 'second', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 10011, 'message' => 'Channel unavailable'], 400),
        ]);
        $routing = $this->mock(PaymentService::class);
        $routing->shouldReceive('resolvePaymentMethod')->once()
            ->withArgs(fn (PaymentGroup $selected, float $amount, array $excluded): bool => $selected->is($group) && $excluded === [])
            ->andReturn($first);
        $routing->shouldReceive('resolvePaymentMethod')->once()
            ->withArgs(fn (PaymentGroup $selected, float $amount, array $excluded): bool => $selected->is($group) && $excluded === [$second->id])
            ->andReturnUsing(function () use ($paid, $first): PaymentMethod {
                Order::query()->update($paid
                    ? ['status' => 'paid', 'paid_at' => now(), 'transaction_id' => 'txn-during-routing']
                    : ['pay_url' => 'https://pay.example.com/during-routing', 'wp_order_id' => 505]);

                return $first;
            });
        $this->sendOrder()->assertStatus(502);

        $response = $this->sendOrder()->assertOk();

        $order = Order::sole();
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertNull($order->gateway_order_no);
        $response->assertJsonPath('data.status', $paid ? 'paid' : 'pending');
        $response->assertJsonPath('data.pay_url', $paid ? null : 'https://pay.example.com/during-routing');
        Http::assertSentCount(1);
    }

    /** @return array<string, array{bool}> */
    public static function concurrentOrderProgress(): array
    {
        return [
            'payment received during routing' => [true],
            'payment link saved during routing' => [false],
        ];
    }

    public function test_a_late_callback_for_an_obsolete_gateway_reference_cannot_mark_the_current_order_as_paid(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::sequence()
                ->push(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                ->push(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/first']]),
        ]);
        $this->sendOrder()->assertStatus(502);
        $previousReference = Order::sole()->gatewayOrderReference();
        Order::sole()->update(['gateway_order_no' => 'GWLEGACYATTEMPT']);
        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'first');
        $currentReference = Order::sole()->gatewayOrderReference();

        app(OrderPaymentStatusService::class)->handle([
            's_order_id' => $previousReference,
            'status' => 'paid',
            'transaction_id' => 'txn-previous-attempt',
        ]);

        $order = Order::sole();
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertSame($currentReference, $order->gatewayOrderReference());
        $this->assertNotSame($previousReference, $currentReference);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->transaction_id);
        Http::assertSentCount(2);
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

        $this->sendOrder()->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');

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

    public function test_transport_failure_returns_502_without_trying_another_channel(): void
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

        $this->sendOrder()->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');

        $this->assertSame([11], $attemptedConfigs);
        $this->assertDatabaseCount('orders', 1);
        $this->assertNull(Order::sole()->pay_url);
        $this->assertSame('first', Order::sole()->payment_method);
    }

    /** @param array<string, mixed> $response */
    #[DataProvider('unusableGatewayResponses')]
    public function test_an_unusable_gateway_response_returns_502_without_trying_another_channel(array $response): void
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

        $this->sendOrder()->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('first', Order::sole()->payment_method);
        $this->assertNull(Order::sole()->pay_url);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unusableGatewayResponses(): array
    {
        return [
            'missing payment link' => [['code' => 0, 'data' => ['wp_order_id' => 101]]],
            'invalid data type' => [['code' => 0, 'data' => 'invalid data']],
        ];
    }

    public function test_retry_preserves_automatic_discount_and_exchange_rate_snapshots(): void
    {
        $group = $this->createGroup();
        $this->application->update(['is_auto_discount_enabled' => true]);
        ExchangeRate::updateBatchRates(['EUR' => 2]);
        $this->attachMethod($group, 'first', 11);
        $this->attachMethod($group, 'second', 22);
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/pay*' => Http::sequence()
                ->push(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                ->push(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/preserved']]),
        ]);

        $data = [
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
        ];
        $this->sendOrder($data)->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');
        ExchangeRate::updateBatchRates(['EUR' => 3]);
        $this->application->update(['is_auto_discount_enabled' => false]);

        $this->sendOrder($data)->assertOk()->assertJsonPath('data.payment_method', 'first');

        $order = Order::sole();
        $this->assertSame('0.02', $order->original_amount);
        $this->assertSame('0.01', $order->auto_discount);
        $this->assertSame('0.01', $order->amount);
        $this->assertSame('0.01', $order->discount);
        $this->assertSame('2.000000', $order->exchange_rate);
        $this->assertSame('2.000000', $order->original_exchange_rate);
        $this->assertSame('0.02', $order->converted_amount);
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 11 && $request['amount'] === 0.01 && $request['discount_fee'] === 0.01);
        Http::assertSentCount(2);
    }

    public function test_retry_replaces_matching_overflow_instead_of_accumulating_discounts(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 11);
        $first->update(['domain' => 'https://first.example.com']);
        $itemService = $this->mock(OrderItemService::class);
        foreach ([['110.00', '10.00'], ['120.00', '20.00']] as [$subtotal, $overflow]) {
            $matched = $this->matchedItems('remote-first', false);
            $matched['subtotal'] = $subtotal;
            $matched['overflow'] = $overflow;
            $matched['items'][0]['unit_price'] = $subtotal;
            $matched['items'][0]['total_price'] = $subtotal;
            $matched['items'][0]['converted_unit_price'] = $subtotal;
            $itemService->shouldReceive('matchItems')->once()
                ->withArgs(fn (PaymentMethod $selected, string $target, string $rate): bool => $selected->is($first) && $target === '100.00' && $rate === '1.000000')
                ->andReturn($matched);
        }
        Http::preventStrayRequests();
        Http::fake([
            'https://first.example.com/wp-json/payment-plugin/v1/pay*' => Http::sequence()
                ->push(['code' => 10011, 'message' => 'Channel unavailable'], 400)
                ->push(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/first']]),
        ]);

        $this->sendOrder()->assertStatus(502)->assertJsonPath('msg', '建单失败，请重试');

        $this->sendOrder()->assertOk()->assertJsonPath('data.payment_method', 'first');

        $order = Order::sole();
        $this->assertSame('120.00', $order->subtotal);
        $this->assertSame('20.00', $order->discount);
        $this->assertSame('100.00', $order->amount);
        $this->assertSame('100.00', $order->items()->sole()->total_price);
        Http::assertSent(fn (Request $request): bool => $request['gateway_config_id'] === 11 && $request['discount_fee'] === 20.0 && $request['subtotal'] === 120.0);
        Http::assertSentCount(2);
    }

    public function test_gateway_failure_does_not_route_to_a_newly_enabled_channel(): void
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

        $this->sendOrder()->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');

        Http::assertNotSent(fn (Request $request): bool => $request['gateway_config_id'] === 22);
        Http::assertSentCount(1);
        $this->assertSame('first', Order::sole()->payment_method);
        $this->assertNull(Order::sole()->pay_url);
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
    public function test_a_channel_product_preparation_failure_returns_502_without_trying_another_channel(string $mode, string $preparationMethod): void
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
        $itemService->shouldReceive($preparationMethod)->never()
            ->withArgs(fn (PaymentMethod $method, Order $order, ?Collection $reusable): bool => $method->is($second) && ($reusable === null || $reusable->isEmpty()));
        Http::preventStrayRequests();
        Http::fake([
            'https://second.example.com/wp-json/payment-plugin/v1/pay*' => Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/prepared']]),
        ]);

        $this->sendOrder()->assertStatus(502)
            ->assertJsonPath('error_code', 'GATEWAY_ERROR')
            ->assertJsonPath('msg', '建单失败，请重试');

        $order = Order::sole();
        $this->assertSame($first->id, $order->payment_method_id);
        $this->assertNull($order->gateway_order_no);
        $this->assertNull($order->pay_url);
        $this->assertSame([], $order->matchedItems()->pluck('product_id')->all());
        Http::assertNothingSent();
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
