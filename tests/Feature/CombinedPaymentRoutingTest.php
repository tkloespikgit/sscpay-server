<?php

namespace Tests\Feature;

use App\Exceptions\CombinedPaymentException;
use App\Models\CombinedOrder;
use App\Models\ExchangeRate;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\CombinedOrderService;
use App\Services\CombinedPaymentRouter;
use App\Services\OrderPaymentStatusService;
use App\Services\PaymentCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\TestCase;

class CombinedPaymentRoutingTest extends TestCase
{
    use CreatesCombinedPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCombinedPayments();
    }

    public function test_only_three_highest_eligible_limits_are_random_candidates(): void
    {
        $a = $this->combinedMethod('first', '800.00');
        $b = $this->combinedMethod('second', '700.00', ['product_match_mode' => PaymentMethod::MODE_VIRTUAL]);
        $c = $this->combinedMethod('third', '600.00');
        $this->combinedMethod('fourth', '500.00');
        $this->combinedMethod('daily-short', '1200.00', ['max_amount_per_day' => '1199.99']);
        $this->combinedMethod('monthly-short', '1300.00', ['max_amount_per_month' => '1299.99']);
        $this->combinedMethod('inactive', '2000.00', ['is_active' => false]);
        $this->combinedMethod('create', '2100.00', ['product_match_mode' => PaymentMethod::MODE_CREATE]);
        $this->combinedMethod('copy', '2200.00', ['product_match_mode' => PaymentMethod::MODE_COPY]);
        $parent = $this->combinedParent();
        $router = app(CombinedPaymentRouter::class);
        $seen = [];
        $limits = [$a->id => '800.00', $b->id => '700.00', $c->id => '600.00'];
        for ($i = 0; $i < 60; $i++) {
            $quote = $router->quote($parent);
            $this->assertArrayHasKey($quote['payment_method_id'], $limits);
            $limit = $limits[$quote['payment_method_id']];
            $this->assertGreaterThanOrEqual(0, bccomp($quote['converted_amount'], bcmul($limit, '0.70', 2), 2));
            $this->assertLessThanOrEqual(0, bccomp($quote['converted_amount'], bcmul($limit, '0.90', 2), 2));
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $quote['amount']);
            $seen[$quote['payment_method_id']] = true;
        }
        $this->assertCount(3, $seen);
        Http::assertNothingSent();
    }

    public function test_remaining_balance_prefers_one_payment_and_can_use_unlimited_channel(): void
    {
        $this->combinedMethod('small', '800.00');
        $full = $this->combinedMethod('full', '1000.00');
        $parent = $this->combinedParent([
            'paid_amount' => '3100.00', 'paid_converted_amount' => '3100.00',
            'remaining_amount' => '900.00', 'remaining_converted_amount' => '900.00',
        ]);
        $quote = app(CombinedPaymentRouter::class)->quote($parent);
        $this->assertSame($full->id, $quote['payment_method_id']);
        $this->assertSame('900.00', $quote['amount']);
        $full->update(['max_amount_per_transaction' => '0.00']);
        $quote = app(CombinedPaymentRouter::class)->quote($parent);
        $this->assertSame('900.00', $quote['converted_amount']);
    }

    public function test_next_payment_keeps_the_previous_channel_even_outside_the_highest_three_limits(): void
    {
        $previous = $this->combinedMethod('previous', '800.00');
        $parent = $this->parentPaidOn($previous);
        $this->combinedMethod('larger-one', '1000.00');
        $this->combinedMethod('larger-two', '1200.00');
        $this->combinedMethod('larger-three', '1400.00');

        $quote = $this->postJson($parent->pay_url.'/quote')->assertOk()
            ->assertJsonPath('data.quote.payment_method', $previous->method_code)->json('data.quote');

        $this->assertGreaterThanOrEqual(0, bccomp($quote['amount'], '560.00', 2));
        $this->assertLessThanOrEqual(0, bccomp($quote['amount'], '720.00', 2));
        Http::assertNothingSent();
    }

    public function test_previous_channel_is_kept_even_when_another_channel_can_pay_the_whole_balance(): void
    {
        $previous = $this->combinedMethod('previous', '800.00');
        $parent = $this->parentPaidOn($previous);
        $this->combinedMethod('full-balance', '5000.00');

        $quote = app(CombinedPaymentRouter::class)->quote($parent);

        $this->assertSame($previous->id, $quote['payment_method_id']);
        $this->assertLessThanOrEqual(0, bccomp($quote['amount'], '720.00', 2));
        Http::assertNothingSent();
    }

    public function test_final_balance_uses_previous_channel_even_when_daily_capacity_is_below_its_full_cap(): void
    {
        $previous = $this->combinedMethod('previous', '800.00', ['max_amount_per_day' => '1000.00']);
        $parent = $this->parentPaidOn($previous, [
            'original_amount' => '1000.00', 'amount' => '1000.00', 'converted_amount' => '1000.00',
            'remaining_amount' => '360.00', 'remaining_converted_amount' => '360.00',
        ]);
        $this->combinedMethod('alternative-one', '1000.00');
        $this->combinedMethod('alternative-two', '1200.00');
        for ($i = 0; $i < 5; $i++) {
            $quote = app(CombinedPaymentRouter::class)->quote($parent);
            $this->assertSame($previous->id, $quote['payment_method_id']);
            $this->assertSame('360.00', $quote['amount']);
        }
        Http::assertNothingSent();
    }

    public function test_expired_first_quote_keeps_its_channel_when_it_is_still_available(): void
    {
        $this->freezeTime();
        $previous = $this->combinedMethod('previous', '800.00');
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $first = $this->postJson($parent->pay_url.'/quote')->assertOk()->json('data.quote');
        $this->combinedMethod('larger-one', '1000.00');
        $this->combinedMethod('larger-two', '1200.00');
        $this->combinedMethod('larger-three', '1400.00');
        $this->travel(6)->minutes();

        $next = $this->postJson($parent->pay_url.'/quote')->assertOk()
            ->assertJsonPath('data.quote.payment_method', $previous->method_code)->json('data.quote');

        $this->assertNotSame($first['id'], $next['id']);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('unavailablePreviousChannelSettings')]
    public function test_previous_channel_is_replaced_only_when_it_cannot_take_a_payment(array $settings): void
    {
        $previous = $this->combinedMethod('previous', '800.00');
        $parent = $this->parentPaidOn($previous);
        $previous->update($settings);
        $alternative = $this->combinedMethod('alternative', '700.00');

        $quote = app(CombinedPaymentRouter::class)->quote($parent);

        $this->assertSame($alternative->id, $quote['payment_method_id']);
        $this->assertSame('640.00', $parent->refresh()->paid_amount);
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    public function test_expired_fallback_quote_keeps_the_new_channel_when_the_old_channel_recovers(): void
    {
        $this->freezeTime();
        $previous = $this->combinedMethod('previous', '800.00');
        $parent = $this->parentPaidOn($previous);
        $previous->update(['is_active' => false]);
        $alternative = $this->combinedMethod('alternative', '700.00');
        $first = $this->postJson($parent->pay_url.'/quote')->assertOk()
            ->assertJsonPath('data.quote.payment_method', $alternative->method_code)->json('data.quote');
        $previous->update(['is_active' => true]);
        $this->travel(6)->minutes();

        $next = $this->postJson($parent->pay_url.'/quote')->assertOk()
            ->assertJsonPath('data.quote.payment_method', $alternative->method_code)->json('data.quote');

        $this->assertNotSame($first['id'], $next['id']);
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unavailablePreviousChannelSettings(): array
    {
        return [
            'disabled' => [['is_active' => false]],
            'daily capacity below full cap' => [['max_amount_per_day' => '1439.99']],
            'monthly capacity below full cap' => [['max_amount_per_month' => '1439.99']],
            'daily count exhausted' => [['max_count_per_day' => 1]],
            'fees exceed allowed random amount' => [['fee_fixed' => '721.00']],
            'create mode' => [['product_match_mode' => PaymentMethod::MODE_CREATE]],
            'copy mode' => [['product_match_mode' => PaymentMethod::MODE_COPY]],
        ];
    }

    public function test_previous_shared_channel_must_still_be_authorized_for_the_merchant(): void
    {
        $previous = $this->combinedMethod('shared', '800.00');
        $previous->update(['merchant_id' => null]);
        $previous->assignedMerchants()->attach($this->merchant->id);
        $parent = $this->parentPaidOn($previous);
        $previous->assignedMerchants()->detach($this->merchant->id);
        $alternative = $this->combinedMethod('alternative', '700.00');

        $quote = app(CombinedPaymentRouter::class)->quote($parent);

        $this->assertSame($alternative->id, $quote['payment_method_id']);
        Http::assertNothingSent();
    }

    public function test_full_balance_routing_excludes_create_and_copy_from_the_normal_channel_selector(): void
    {
        $allowed = $this->combinedMethod('allowed', '1000.00');
        $this->combinedMethod('create', '0.00', ['product_match_mode' => PaymentMethod::MODE_CREATE]);
        $this->combinedMethod('copy', '0.00', ['product_match_mode' => PaymentMethod::MODE_COPY]);
        $parent = $this->combinedParent([
            'paid_amount' => '3100.00', 'paid_converted_amount' => '3100.00',
            'remaining_amount' => '900.00', 'remaining_converted_amount' => '900.00',
        ]);
        for ($i = 0; $i < 10; $i++) {
            $quote = app(CombinedPaymentRouter::class)->quote($parent);
            $this->assertSame($allowed->id, $quote['payment_method_id']);
            $this->assertSame('900.00', $quote['amount']);
        }
        Http::assertNothingSent();
    }

    public function test_a_balance_is_split_again_when_only_create_or_copy_can_cover_it_in_full(): void
    {
        $allowed = $this->combinedMethod('allowed', '800.00');
        $this->combinedMethod('create', '0.00', ['product_match_mode' => PaymentMethod::MODE_CREATE]);
        $this->combinedMethod('copy', '0.00', ['product_match_mode' => PaymentMethod::MODE_COPY]);
        $parent = $this->combinedParent([
            'paid_amount' => '3100.00', 'paid_converted_amount' => '3100.00',
            'remaining_amount' => '900.00', 'remaining_converted_amount' => '900.00',
        ]);

        $quote = app(CombinedPaymentRouter::class)->quote($parent);

        $this->assertSame($allowed->id, $quote['payment_method_id']);
        $this->assertGreaterThanOrEqual(0, bccomp($quote['amount'], '560.00', 2));
        $this->assertLessThanOrEqual(0, bccomp($quote['amount'], '720.00', 2));
        Http::assertNothingSent();
    }

    #[DataProvider('excludedProductModes')]
    public function test_an_over_limit_order_cannot_create_a_parent_with_only_an_excluded_product_mode(string $mode): void
    {
        $this->combinedMethod('excluded', '800.00', ['product_match_mode' => $mode]);

        $this->createCombined()->assertStatus(409)->assertJsonPath('error_code', 'COMBINE_PAYMENT_UNAVAILABLE');

        $this->assertDatabaseCount('combined_orders', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('excludedProductModes')]
    public function test_create_and_copy_remain_available_for_single_payments(string $mode): void
    {
        $this->combinedMethod('small', '800.00');
        $method = $this->combinedMethod('single', '5000.00', ['product_match_mode' => $mode]);
        $this->fakeCombinedGateway();

        $this->createCombined()->assertOk()->assertJsonPath('data.order_type', 'single')
            ->assertJsonPath('data.payment_method', $method->method_code);

        $this->assertDatabaseCount('combined_orders', 0);
        $this->assertNull(Order::sole()->combined_order_id);
        Http::assertSentCount(1);
    }

    #[DataProvider('excludedProductModes')]
    public function test_a_quote_cannot_be_paid_or_reused_after_its_channel_switches_to_an_excluded_mode(string $mode): void
    {
        $method = $this->combinedMethod();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $quote = app(CombinedOrderService::class)->quote($parent);
        $method->update(['product_match_mode' => $mode]);
        $allowed = $this->combinedMethod('allowed');

        $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])
            ->assertStatus(409)->assertJsonPath('error_code', 'QUOTE_EXPIRED');
        $response = $this->postJson($parent->pay_url.'/quote')->assertOk()
            ->assertJsonPath('data.quote.payment_method', $allowed->method_code);

        $this->assertNotSame($quote['id'], $response->json('data.quote.id'));
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function excludedProductModes(): array
    {
        return ['create' => [PaymentMethod::MODE_CREATE], 'copy' => [PaymentMethod::MODE_COPY]];
    }

    public function test_shared_channel_pending_reservations_reduce_daily_monthly_and_count_capacity(): void
    {
        $method = $this->combinedMethod('shared', '800.00', [
            'max_amount_per_day' => '1600.00', 'max_amount_per_month' => '2400.00', 'max_count_per_day' => 2,
        ]);
        $otherMerchant = Merchant::create(['name' => 'Other']);
        $this->makeOrder('OTHER-PAID', 'OTHER-PAID', [
            'merchant_id' => $otherMerchant->id, 'payment_method_id' => $method->id,
            'status' => 'paid', 'paid_at' => now(), 'converted_amount' => '500.00',
        ]);
        $hold = $this->makeOrder('OTHER-HOLD', 'OTHER-HOLD', [
            'merchant_id' => $otherMerchant->id, 'payment_method_id' => $method->id,
            'status' => 'pending', 'risk_reservation_status' => 'held', 'converted_amount' => '400.00',
        ]);
        $capacity = app(PaymentCapacityService::class);
        $this->assertSame(['day' => '700.00', 'month' => '1500.00', 'count' => 0], $capacity->remaining($method));
        $this->assertTrue($capacity->permits($method, '400.00', $hold->id));
        $this->expectException(CombinedPaymentException::class);
        app(CombinedPaymentRouter::class)->quote($this->combinedParent());
    }

    public function test_having_enough_for_the_random_amount_but_not_the_full_cap_is_ineligible(): void
    {
        $this->combinedMethod('limited', '800.00', ['max_amount_per_day' => '799.99']);
        $this->createCombined()->assertStatus(409)->assertJsonPath('error_code', 'COMBINE_PAYMENT_UNAVAILABLE');
        $this->assertDatabaseCount('combined_orders', 0);
    }

    public function test_combination_is_not_used_to_work_around_daily_limits_or_unlimited_transaction_channels(): void
    {
        $method = $this->combinedMethod('daily', '5000.00', ['max_amount_per_day' => '3000.00']);
        $this->createCombined()->assertStatus(409)->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');
        $method->update(['max_amount_per_transaction' => '0.00']);
        $this->createCombined()->assertStatus(409)->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');
        $this->assertDatabaseCount('combined_orders', 0);
    }

    public function test_channels_whose_fees_exceed_random_payment_amounts_are_excluded(): void
    {
        $this->combinedMethod('expensive', '800.00', ['fee_fixed' => '721.00']);
        $affordable = $this->combinedMethod('affordable', '700.00');
        $quote = app(CombinedPaymentRouter::class)->quote($this->combinedParent());
        $this->assertSame($affordable->id, $quote['payment_method_id']);
    }

    public function test_quote_must_be_revalidated_against_capacity_before_creating_any_child(): void
    {
        $method = $this->combinedMethod('limited', '800.00', ['max_amount_per_day' => '1000.00']);
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $quote = app(CombinedOrderService::class)->quote($parent);
        $this->makeOrder('HELD', 'HELD', [
            'payment_method_id' => $method->id, 'status' => 'pending',
            'risk_reservation_status' => 'held', 'converted_amount' => '300.00',
        ]);
        $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])
            ->assertStatus(409)->assertJsonPath('error_code', 'QUOTE_EXPIRED');
        $this->assertSame(0, $parent->children()->count());
        Http::assertNothingSent();
    }

    public function test_exchange_snapshot_and_cumulative_rounding_preserve_both_currency_totals(): void
    {
        ExchangeRate::updateBatchRates(['EUR' => '1.234567']);
        $this->combinedMethod();
        $this->fakeCombinedGateway();
        $this->createCombined(['currency' => 'EUR', 'amount' => '4000.01', 'subtotal' => '4000.01'])->assertOk();
        $parent = CombinedOrder::sole();
        $rate = $parent->exchange_rate;
        ExchangeRate::updateBatchRates(['EUR' => '2.000000']);
        $payments = app(CombinedOrderService::class);
        $sum = '0.00';
        $usd = '0.00';
        $sequence = 0;
        while ($parent->refresh()->remaining_amount !== '0.00') {
            $this->assertLessThan(12, $sequence++);
            $quote = $payments->quote($parent);
            $child = $payments->pay($parent, $quote['id']);
            $this->assertSame($rate, $child->exchange_rate);
            $this->assertLessThanOrEqual(0, bccomp($child->converted_amount, '800.00', 2));
            $sum = bcadd($sum, $child->amount, 2);
            $usd = bcadd($usd, $child->converted_amount, 2);
            app(OrderPaymentStatusService::class)->handle([
                's_order_id' => $child->gatewayOrderReference(), 'status' => 'paid',
                'amount' => $child->amount, 'currency' => 'EUR',
            ]);
        }
        $this->assertSame('4000.01', $sum);
        $this->assertSame($parent->converted_amount, $usd);
        $this->assertSame($usd, $parent->paid_converted_amount);
        $this->assertSame('paid', $parent->status);
    }

    private function parentPaidOn(PaymentMethod $method, array $overrides = []): CombinedOrder
    {
        $parent = $this->combinedParent(array_merge([
            'status' => 'partially_paid', 'paid_amount' => '640.00', 'paid_converted_amount' => '640.00',
            'remaining_amount' => '3360.00', 'remaining_converted_amount' => '3360.00',
            'next_sequence' => 2, 'version' => 1, 'first_paid_at' => now(),
        ], $overrides));
        $this->makeOrder('PREVIOUS-CHILD', $parent->merchant_order_no.'-S001', [
            'combined_order_id' => $parent->id, 'combined_sequence' => 1,
            'payment_group_id' => $this->group->id, 'payment_method_id' => $method->id,
            'payment_method' => $method->method_code, 'status' => 'paid', 'paid_at' => now(),
            'amount' => '640.00', 'subtotal' => '640.00', 'converted_amount' => '640.00',
            'subtotal_converted' => '640.00', 'risk_reservation_status' => 'consumed',
        ]);

        return $parent;
    }
}
