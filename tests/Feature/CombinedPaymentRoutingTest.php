<?php

namespace Tests\Feature;

use App\Exceptions\CombinedPaymentException;
use App\Models\CombinedOrder;
use App\Models\ExchangeRate;
use App\Models\Merchant;
use App\Services\CombinedOrderService;
use App\Services\CombinedPaymentRouter;
use App\Services\OrderPaymentStatusService;
use App\Services\PaymentCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        $b = $this->combinedMethod('second', '700.00');
        $c = $this->combinedMethod('third', '600.00');
        $this->combinedMethod('fourth', '500.00');
        $this->combinedMethod('daily-short', '1200.00', ['max_amount_per_day' => '1199.99']);
        $this->combinedMethod('monthly-short', '1300.00', ['max_amount_per_month' => '1299.99']);
        $this->combinedMethod('inactive', '2000.00', ['is_active' => false]);
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
}
