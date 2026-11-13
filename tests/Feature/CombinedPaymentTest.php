<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentLinkJob;
use App\Mail\PaymentLinkMail;
use App\Models\Application;
use App\Models\Carrier;
use App\Models\CombinedOrder;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderNotificationAttempt;
use App\Models\PaymentMethod;
use App\Services\AdConversionService;
use App\Services\BalanceService;
use App\Services\CombinedOrderService;
use App\Services\OrderNotificationService;
use App\Services\OrderPaymentStatusService;
use App\Support\SignatureCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\TestCase;

class CombinedPaymentTest extends TestCase
{
    use CreatesCombinedPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCombinedPayments();
    }

    public function test_create_is_idempotent_and_does_not_precreate_children_or_remote_orders(): void
    {
        $this->combinedMethod();
        $response = $this->createCombined()->assertOk()->assertJsonPath('data.order_type', 'combine')
            ->assertJsonPath('data.amount', '4000.00')->assertJsonPath('data.remaining_amount', '4000.00')
            ->assertJsonPath('data.children', [])->assertJsonPath('data.payment_method', null);
        $parent = CombinedOrder::sole();
        $this->createCombined()->assertOk()->assertJsonPath('data.order_no', $parent->order_no);
        $this->createCombined(['amount' => '4001.00', 'subtotal' => '4001.00'])->assertStatus(409);
        $this->assertDatabaseCount('combined_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($parent->current_quote);
        $this->assertStringContainsString('/combine-payment/', $response->json('data.pay_url'));
        $this->assertSame(2, $this->application->fresh()->webhook_version);
        Http::assertNothingSent();
    }

    public function test_disabled_application_keeps_the_existing_single_order_path(): void
    {
        $this->combinedMethod();
        $this->application->update(['is_combine_payment_enabled' => false]);
        $this->createCombined()->assertStatus(409)->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');
        $this->assertDatabaseCount('combined_orders', 0);
        $this->fakeCombinedGateway();
        $this->createCombined(['amount' => '100.00', 'subtotal' => '100.00'])->assertOk()->assertJsonPath('data.order_type', 'single');
    }

    public function test_cashier_get_and_quote_are_safe_and_do_not_create_remote_payments(): void
    {
        $this->withoutVite();
        $this->combinedMethod();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $this->get($parent->pay_url)->assertOk()->assertDontSee($this->application->api_key);
        $quote = $this->postJson($parent->pay_url.'/quote')->assertOk()->json('data.quote');
        $this->assertGreaterThanOrEqual(560, (float) $quote['amount']);
        $this->assertLessThanOrEqual(720, (float) $quote['amount']);
        $this->postJson($parent->pay_url.'/quote')->assertOk()->assertJsonPath('data.quote.id', $quote['id']);
        $this->getJson($parent->pay_url.'/status')->assertOk()->assertJsonPath('data.children', []);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
        $this->get(str_replace('localhost', 'foreign.example.com', $parent->pay_url))->assertNotFound();
    }

    public function test_sequential_payments_notify_each_step_and_only_finish_original_order_when_fully_paid(): void
    {
        $firstMethod = $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $quote = $payments->quote($parent);
        $first = $payments->pay($parent, $quote['id']);
        $again = $payments->pay($parent, $quote['id']);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('COMBINE-001-S001', $first->merchant_order_no);
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
        $this->paid($first);
        $parent->refresh();
        $this->assertSame('partially_paid', $parent->status);
        $this->assertSame($first->amount, $parent->paid_amount);
        $this->assertNull($parent->paid_at);
        $initial = OrderNotificationAttempt::sole();
        $this->assertSame('pending', $initial->request_payload['status']);
        $this->assertSame('partially_paid', $initial->request_payload['order_status']);
        $this->assertSame($parent->order_no, $initial->request_payload['order_no']);
        $this->assertSame($first->order_no, $initial->request_payload['paid_order_no']);
        $this->assertCount(1, $initial->request_payload['children']);
        $this->paid($first);
        $this->assertSame(1, $parent->fresh()->version);
        $this->assertDatabaseCount('order_notification_attempts', 1);

        $firstMethod->update(['is_active' => false]);
        $secondMethod = $this->combinedMethod('alternative');
        $this->application->update(['is_combine_payment_enabled' => false]);
        $index = 1;
        while ($parent->refresh()->remaining_amount !== '0.00') {
            $this->assertLessThan(10, $index);
            $quote = $payments->quote($parent);
            $child = $payments->pay($parent, $quote['id']);
            $this->assertSame($secondMethod->id, $child->payment_method_id);
            $this->assertSame(sprintf('COMBINE-001-S%03d', ++$index), $child->merchant_order_no);
            $this->paid($child);
            $firstMethod->update(['is_active' => true]);
        }
        $this->assertSame('paid', $parent->status);
        $this->assertSame('4000.00', $parent->paid_amount);
        $this->assertSame('4000.00', $parent->paid_converted_amount);
        $this->assertSame('0.00', $parent->remaining_converted_amount);
        $this->assertNotNull($parent->paid_at);
        $this->assertSame($index, $parent->version);
        $this->assertDatabaseCount('order_notification_attempts', $index);
        $last = OrderNotificationAttempt::latest('id')->first();
        $this->assertSame('paid', $last->request_payload['status']);
        $this->assertCount($index, $last->request_payload['children']);
        $this->assertSame('pending', $initial->fresh()->request_payload['status']);
        $this->signedCombinedRequest('/api/order/query', ['merchant_order_no' => $parent->merchant_order_no])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_amount', '4000.00');
        $this->signedCombinedRequest('/api/order/query', ['merchant_order_no' => $first->merchant_order_no])
            ->assertOk()->assertJsonPath('data.parent_order_no', $parent->order_no);
    }

    public function test_webhook_signs_all_nested_fields_and_retry_preserves_event_snapshot(): void
    {
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $child = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->paid($child);
        $attempt = OrderNotificationAttempt::sole();
        $snapshot = $attempt->request_payload;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://shop.example.com/notify' => Http::response('retry', 503)]);
        app(OrderNotificationService::class)->attempt($attempt);
        $attempt->refresh();
        $this->assertSame('failed', $attempt->status);
        $headers = $attempt->request_headers;
        $this->assertSame(
            SignatureCanonicalizer::sign($headers['App-ID'], $headers['Timestamp'], $headers['X-Nonce'], $attempt->request_payload, $this->application->api_key),
            $attempt->request_payload['sign'],
        );
        $tampered = $attempt->request_payload;
        $tampered['children'][0]['amount'] = '0.01';
        $this->assertNotSame($attempt->request_payload['sign'], SignatureCanonicalizer::sign(
            $headers['App-ID'], $headers['Timestamp'], $headers['X-Nonce'], $tampered, $this->application->api_key,
        ));
        $this->travelTo($attempt->next_retry_at->addSecond());
        $next = $attempt->createNextAttempt();
        $this->assertSame($snapshot['event_id'], $next->event_id);
        $this->assertSame($snapshot['version'], $next->request_payload['version']);
        $this->assertSame($snapshot['paid_amount'], $next->request_payload['paid_amount']);
    }

    public function test_amount_mismatch_stops_combination_without_recording_a_successful_payment(): void
    {
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $child = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->paid($child, ['amount' => bcadd($child->amount, '1.00', 2)]);
        $this->assertNull($child->fresh()->paid_at);
        $this->assertSame('review', $parent->fresh()->status);
        $this->assertSame('0.00', $parent->fresh()->paid_amount);
        $this->assertDatabaseCount('order_notification_attempts', 0);
        $this->assertDatabaseHas('order_gateway_status_events', ['order_id' => $child->id, 'outcome' => 'amount_mismatch']);
        $this->postJson($parent->pay_url.'/quote')->assertStatus(409);
    }

    public function test_shipping_requires_a_real_child_and_full_parent_payment(): void
    {
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined(['amount' => '900.00', 'subtotal' => '900.00'])->assertOk();
        $parent = CombinedOrder::sole();
        Carrier::create(['carrier_name' => 'UPS', 'carrier_code' => 'ups', 'status' => Carrier::STATUS_ENABLED]);
        $shipping = ['logistics_company' => 'ups', 'tracking_number' => '1Z-COMBINE', 'shipped_at' => now()->toIso8601String()];
        $this->signedCombinedRequest('/api/order/ship', $shipping + ['merchant_order_no' => $parent->merchant_order_no])
            ->assertStatus(422)->assertJsonPath('error_code', 'CHILD_ORDER_REQUIRED');
        $payments = app(CombinedOrderService::class);
        $first = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->paid($first);
        $this->signedCombinedRequest('/api/order/ship', $shipping + ['merchant_order_no' => $first->merchant_order_no])
            ->assertStatus(409)->assertJsonPath('error_code', 'COMBINE_ORDER_NOT_PAID');
        $final = $payments->pay($parent->refresh(), $payments->quote($parent)['id']);
        $this->paid($final);
        $this->signedCombinedRequest('/api/order/ship', $shipping + ['merchant_order_no' => $first->merchant_order_no])
            ->assertOk()->assertJsonPath('data.order_type', 'combine')->assertJsonPath('data.parent_order_no', $parent->order_no);
        $this->assertDatabaseHas('order_shippings', ['order_id' => $first->id, 'tracking_number' => '1Z-COMBINE']);
        $this->signedCombinedRequest('/api/order/query', ['order_no' => $parent->order_no])
            ->assertOk()->assertJsonPath('data.shipping_status', 'partially_shipped')
            ->assertJsonPath('data.children.0.logistics.tracking_number', '1Z-COMBINE');
    }

    public function test_query_cannot_cross_merchants_or_ignore_a_conflicting_second_identifier(): void
    {
        $this->combinedMethod();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $other = Application::createWithCredentials([
            'merchant_id' => Merchant::create(['name' => 'Other'])->id, 'name' => 'Other app', 'website' => 'https://other.example.com',
        ]);
        $this->signedCombinedRequest('/api/order/query', ['order_no' => $parent->order_no], $other)->assertNotFound();
        $this->signedCombinedRequest('/api/order/query', [
            'order_no' => $parent->order_no, 'merchant_order_no' => 'OTHER',
        ])->assertNotFound();
    }

    public function test_a_stale_quote_cannot_start_a_payment_after_channel_is_disabled(): void
    {
        $method = $this->combinedMethod();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $quote = app(CombinedOrderService::class)->quote($parent);
        $method->update(['is_active' => false]);
        $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])
            ->assertStatus(409)->assertJsonPath('error_code', 'QUOTE_EXPIRED');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_remote_result_keeps_the_same_child_channel_and_reference_on_retry(): void
    {
        $method = $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $quote = app(CombinedOrderService::class)->quote($parent);
        $sent = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://shop.example.com/wp-json/payment-plugin/v1/pay*' => function (Request $request) use (&$sent) {
            $sent[] = $request->data();

            return count($sent) === 1
                ? Http::response(['code' => 10011, 'message' => 'Temporarily unavailable'], 500)
                : Http::response(['code' => 0, 'data' => ['pay_url' => 'https://pay.example.com/retried']]);
        }]);
        $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])->assertStatus(502);
        $child = Order::sole();
        $this->combinedMethod('other');
        $this->postJson($parent->pay_url.'/pay', [])->assertOk()->assertJsonPath('pay_url', 'https://pay.example.com/retried');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($method->id, $child->fresh()->payment_method_id);
        $this->assertSame($sent[0]['s_order_id'], $sent[1]['s_order_id']);
    }

    #[DataProvider('excludedPendingProductModes')]
    public function test_pending_children_cannot_continue_with_an_excluded_product_mode(string $mode, ?string $payUrl): void
    {
        $method = $this->combinedMethod('original', '800.00', ['product_match_mode' => $mode]);
        $parent = $this->combinedParent();
        $child = $this->makeOrder('PENDING-CHILD', $parent->merchant_order_no.'-S001', [
            'combined_order_id' => $parent->id, 'combined_sequence' => 1,
            'payment_group_id' => $this->group->id, 'payment_method_id' => $method->id,
            'payment_method' => $method->method_code, 'status' => 'pending',
            'risk_reservation_status' => 'held', 'pay_url' => $payUrl,
        ]);
        $parent->update(['current_order_id' => $child->id, 'next_sequence' => 2]);
        $this->combinedMethod('other');

        $this->postJson($parent->pay_url.'/pay', [])->assertStatus(409)
            ->assertJsonPath('error_code', 'COMBINE_PAYMENT_UNAVAILABLE');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_matched_items', 0);
        $this->assertSame($child->id, $parent->refresh()->current_order_id);
        $this->assertSame('0.00', $parent->paid_amount);
        $this->assertSame('held', $child->refresh()->risk_reservation_status);
        $this->assertSame($method->id, $child->payment_method_id);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string, string|null}> */
    public static function excludedPendingProductModes(): array
    {
        return [
            'create without remote link' => [PaymentMethod::MODE_CREATE, null],
            'copy without remote link' => [PaymentMethod::MODE_COPY, null],
            'create with remote link' => [PaymentMethod::MODE_CREATE, 'https://pay.example.com/existing'],
            'copy with remote link' => [PaymentMethod::MODE_COPY, 'https://pay.example.com/existing'],
        ];
    }

    public function test_parent_reserves_its_child_number_namespace(): void
    {
        $this->combinedMethod();
        $this->createCombined()->assertOk();
        $this->createCombined([
            'merchant_order_no' => 'COMBINE-001-S001', 'amount' => '100.00', 'subtotal' => '100.00',
        ])->assertStatus(409)->assertJsonPath('error_code', 'CHILD_NUMBER_CONFLICT');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_expiry_preserves_unresolved_payment_and_late_confirmation_without_reopening_checkout(): void
    {
        $this->freezeTime();
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $child = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->travelTo($parent->expires_at->addSecond());
        $this->artisan('combined-orders:expire')->assertSuccessful();
        $this->assertSame('expired', $parent->fresh()->status);
        $this->assertSame('held', $child->fresh()->risk_reservation_status);
        $this->postJson($parent->pay_url.'/pay', [])->assertStatus(409);
        $this->paid($child);
        $this->assertSame('expired', $parent->fresh()->status);
        $this->assertSame($child->amount, $parent->fresh()->paid_amount);
        $this->assertSame('consumed', $child->fresh()->risk_reservation_status);
        $this->assertSame('expired', OrderNotificationAttempt::sole()->request_payload['status']);
    }

    public function test_payment_email_points_to_parent_and_does_not_create_a_fake_order(): void
    {
        Mail::fake();
        $this->application->update([
            'sender_email' => 'billing@example.com', 'mail_driver' => 'postmark',
            'mail_credentials' => ['token' => 'test-token'], 'is_order_email_enabled' => true,
        ]);
        $this->combinedMethod();
        $this->createCombined(['send_mail' => 'Y'])->assertOk();
        $parent = CombinedOrder::sole();
        Queue::assertPushed(SendPaymentLinkJob::class,
            fn ($job): bool => $job->orderId === $parent->id && $job->orderType === 'combine');
        (new SendPaymentLinkJob($parent->id, 'combine'))->handle();
        Mail::assertSent(PaymentLinkMail::class, function ($mail) use ($parent): bool {
            $this->assertSame($parent->pay_url, $mail->order->pay_url);
            $this->assertSame('4000.00', $mail->order->amount);

            return true;
        });
        $this->assertNotNull($parent->fresh()->payment_link_sent_at);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_partial_funds_remain_unsettled_and_ad_conversion_fires_only_for_full_original_amount(): void
    {
        $this->merchant->update(['settlement_mode' => 'immediate', 'settlement_days' => 0]);
        $this->combinedMethod('card', '800.00', ['settlement_mode' => 'immediate', 'settlement_days' => 0]);
        $this->fakeCombinedGateway();
        $this->mock(AdConversionService::class)->shouldReceive('dispatchInitial')->once()
            ->withArgs(fn (Order $order): bool => str_starts_with($order->order_no, 'CMB') && $order->amount === '900.00');
        $this->createCombined(['amount' => '900.00', 'subtotal' => '900.00'])->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $first = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->paid($first);
        $settlement = $first->settlement()->firstOrFail();
        $this->assertFalse(app(BalanceService::class)->settleOrder($settlement));
        $last = $payments->pay($parent->refresh(), $payments->quote($parent)['id']);
        $this->paid($last);
        $this->assertTrue(app(BalanceService::class)->settleOrder($settlement));
        $this->assertFalse(app(BalanceService::class)->settleOrder($settlement));
    }

    public function test_a_maximum_length_merchant_reference_can_generate_and_query_a_child(): void
    {
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $number = str_repeat('A', 64);
        $this->createCombined(['merchant_order_no' => $number])->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $child = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->assertSame($number.'-S001', $child->merchant_order_no);
        $this->signedCombinedRequest('/api/order/query', ['merchant_order_no' => $child->merchant_order_no])
            ->assertOk()->assertJsonPath('data.parent_merchant_order_no', $number);
    }

    public function test_manual_refund_pauses_the_parent_without_decreasing_gross_payment_progress(): void
    {
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $child = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->paid($child);
        app(BalanceService::class)->refund($child->fresh(), '1.00', null, 'Test refund');
        $this->assertSame('review', $parent->fresh()->status);
        $this->assertSame($child->amount, $parent->fresh()->paid_amount);
        $this->assertDatabaseCount('order_notification_attempts', 1);
        $this->postJson($parent->pay_url.'/quote')->assertStatus(409);
    }

    public function test_unfinished_combined_settlements_do_not_starve_other_due_orders(): void
    {
        $this->freezeTime();
        $method = $this->combinedMethod('card', '800.00', ['settlement_mode' => 'immediate', 'settlement_days' => 1]);
        $this->fakeCombinedGateway();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $payments = app(CombinedOrderService::class);
        $child = $payments->pay($parent, $payments->quote($parent)['id']);
        $this->paid($child);
        $single = $this->makeOrder('ORD-SINGLE', 'ORD-SINGLE', [
            'payment_method_id' => $method->id, 'status' => 'pending',
            'settlement_mode' => 'immediate', 'settlement_days' => 1, 'settlement_amount' => '100.00',
        ]);
        $this->paid($single);
        $this->travel(2)->days();
        $this->artisan('orders:settle-due', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('pending', $child->settlement()->first()->status);
        $this->assertSame('settled', $single->settlement()->first()->status);
    }

    private function paid(Order $order, array $overrides = []): void
    {
        app(OrderPaymentStatusService::class)->handle(array_replace([
            's_order_id' => $order->gatewayOrderReference(), 'status' => 'paid',
            'amount' => $order->amount, 'currency' => $order->currency,
            'paid_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ], $overrides));
    }
}
