<?php

namespace Tests\Feature;

use App\Models\CombinedOrder;
use App\Models\CryptoCheckout;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderNotificationAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\SystemConfig;
use App\Services\OrderPaymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\TestCase;

class CryptoCombinedPaymentCompatibilityTest extends TestCase
{
    use CreatesCombinedPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCombinedPayments();
        config(['app.url' => 'http://localhost', 'services.tatum.checkout_enabled' => true,
            'services.tatum.subscriptions_enabled' => true, 'services.tatum.history_enabled' => true,
            'services.tatum.verification_enabled' => true, 'services.tatum.settlement_enabled' => true]);
    }

    public function test_combined_enabled_application_can_create_and_query_a_single_crypto_order_at_the_raw_rate(): void
    {
        $this->combinedMethod(limit: '60.00');
        $crypto = $this->cryptoMethod(['max_amount_per_transaction' => '66.00']);
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'AUD', 'rate' => '0.650000', 'fetched_at' => now()]);
        SystemConfig::set('exchange.surcharge_type', 'percent');
        SystemConfig::set('exchange.surcharge_percent', '10');

        $response = $this->createCombined(['amount' => '100.00', 'subtotal' => '100.00', 'currency' => 'AUD'])
            ->assertOk()->assertJsonPath('data.order_type', 'single')->assertJsonPath('data.payment_type', 'crypto')
            ->assertJsonPath('data.converted_amount', '65.00')->assertJsonPath('data.exchange_rate', '0.650000')
            ->assertJsonPath('data.crypto_payment.state', 'checking');
        $order = Order::sole();
        $this->assertSame($crypto->id, $order->payment_method_id);
        $this->assertSame($order->pay_url, $response->json('data.payment_url'));
        $this->assertSame('0.00', $order->settlement_amount);
        $this->assertSame('0.00', $order->surcharge_fee);
        $this->assertNull($order->combined_order_id);
        $this->assertSame('1.0000', CryptoCheckout::sole()->fee_snapshot['fee_percent']);
        $this->signedCombinedRequest('/api/order/query', ['order_no' => $order->order_no])->assertOk()
            ->assertJsonPath('data.order_type', 'single')->assertJsonPath('data.payment_type', 'crypto')
            ->assertJsonPath('data.crypto_payment.state', 'checking')->assertJsonPath('data.parent_order_no', null);
        $this->withoutVite();
        $this->get($order->pay_url)->assertOk()->assertSee(__('crypto.checkout.title'));
        $this->assertDatabaseCount('combined_orders', 0);
        $this->assertDatabaseCount('crypto_payment_requests', 0);
        Http::assertNothingSent();
    }

    public function test_unpaid_crypto_orders_reserve_the_raw_usd_amount_without_using_combination_to_bypass_capacity(): void
    {
        $this->cryptoMethod(['max_amount_per_transaction' => '80.00', 'max_amount_per_day' => '100.00']);
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'AUD', 'rate' => '0.650000', 'fetched_at' => now()]);
        SystemConfig::set('exchange.surcharge_type', 'percent');
        SystemConfig::set('exchange.surcharge_percent', '-5');

        $this->createCombined(['amount' => '100.00', 'subtotal' => '100.00', 'currency' => 'AUD'])->assertOk();
        $this->createCombined(['merchant_order_no' => 'CRYPTO-SECOND', 'amount' => '60.00', 'subtotal' => '60.00', 'currency' => 'AUD'])
            ->assertStatus(409)->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');

        $this->assertSame('65.00', Order::sole()->converted_amount);
        $this->assertSame('held', Order::sole()->risk_reservation_status);
        $this->assertDatabaseCount('combined_orders', 0);
        $this->assertDatabaseCount('crypto_checkouts', 1);
        Http::assertNothingSent();
    }

    public function test_crypto_only_group_cannot_create_a_fiat_combination_to_bypass_its_single_payment_limit(): void
    {
        $this->cryptoMethod(['max_amount_per_transaction' => '800.00']);

        $this->createCombined()->assertStatus(409)->assertJsonPath('error_code', 'NO_AVAILABLE_PAYMENT_METHOD');

        $this->assertDatabaseCount('combined_orders', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_unlimited_crypto_channel_does_not_prevent_a_fiat_combination(): void
    {
        $this->combinedMethod();
        $this->cryptoMethod();
        config(['services.tatum.checkout_enabled' => false]);

        $this->createCombined()->assertOk()->assertJsonPath('data.order_type', 'combine');

        $this->assertDatabaseCount('combined_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_combination_finishes_using_fiat_children_after_crypto_channel_capacity_is_increased(): void
    {
        $fiat = $this->combinedMethod();
        $crypto = $this->cryptoMethod(['max_amount_per_transaction' => '1000.00']);
        $this->createCombined()->assertOk()->assertJsonPath('data.order_type', 'combine');
        $parent = CombinedOrder::sole();
        $crypto->update(['max_amount_per_transaction' => '0.00']);
        $this->fakeCombinedGateway();
        $count = 0;

        while ($parent->refresh()->remaining_amount !== '0.00') {
            $this->assertLessThan(10, ++$count);
            $quote = $this->postJson($parent->pay_url.'/quote')->assertOk()
                ->assertJsonPath('data.quote.payment_method', $fiat->method_code)->json('data.quote');
            $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])->assertOk();
            $child = Order::query()->where('combined_order_id', $parent->id)->where('combined_sequence', $count)->sole();
            $this->assertSame('fiat', $child->payment_type);
            app(OrderPaymentStatusService::class)->handle([
                's_order_id' => $child->gatewayOrderReference(), 'status' => 'paid',
                'amount' => $child->amount, 'currency' => $child->currency,
                'paid_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]);
        }

        $this->signedCombinedRequest('/api/order/query', ['order_no' => $parent->order_no])->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_amount', '4000.00')
            ->assertJsonPath('data.remaining_amount', '0.00')->assertJsonCount($count, 'data.children');
        $this->assertSame($count, OrderNotificationAttempt::count());
        $this->assertDatabaseCount('crypto_checkouts', 0);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        Http::assertSentCount($count);
    }

    #[DataProvider('remainingAmounts')]
    public function test_fresh_combination_quotes_exclude_crypto_for_both_split_and_full_balance_payments(string $remaining): void
    {
        $fiat = $this->combinedMethod();
        $this->cryptoMethod();
        $parent = $this->combinedParent([
            'paid_amount' => bcsub('4000.00', $remaining, 2), 'paid_converted_amount' => bcsub('4000.00', $remaining, 2),
            'remaining_amount' => $remaining, 'remaining_converted_amount' => $remaining,
        ]);

        $this->postJson($parent->pay_url.'/quote')->assertOk()
            ->assertJsonPath('data.quote.payment_method', $fiat->method_code);

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_status_polling_does_not_exhaust_payment_limit_and_payment_retries_remain_bounded(): void
    {
        $this->combinedMethod();
        $this->createCombined()->assertOk();
        $parent = CombinedOrder::sole();
        $quote = $this->postJson($parent->pay_url.'/quote')->assertOk()->json('data.quote');
        for ($index = 0; $index < 11; $index++) {
            $this->getJson($parent->pay_url.'/status')->assertOk();
        }
        $this->fakeCombinedGateway();

        for ($index = 0; $index < 10; $index++) {
            $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])->assertOk();
        }
        $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])->assertTooManyRequests();
        $this->getJson($parent->pay_url.'/status')->assertOk();
        $this->travel(61)->seconds();
        $this->postJson($parent->pay_url.'/pay', ['quote_id' => $quote['id']])->assertOk();

        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
    }

    public static function remainingAmounts(): array
    {
        return ['split payment' => ['4000.00'], 'full balance' => ['500.00']];
    }

    private function cryptoMethod(array $overrides = []): PaymentMethod
    {
        $map = PaymentMethodConfigMap::create(['name' => 'Crypto', 'payment_type' => 'crypto', 'fields' => [], 'is_active' => true]);
        $method = PaymentMethod::create(array_replace([
            'merchant_id' => $this->merchant->id, 'method_code' => 'eth', 'method_name' => 'ETH',
            'payment_type' => 'crypto', 'is_active' => true, 'config_map_id' => $map->id,
            'config' => CryptoConfigurationTest::publicConfig(), 'fee_percent' => '1', 'fee_fixed' => '0',
        ], $overrides));
        $this->group->paymentMethods()->attach($method->id, ['priority' => 100]);

        return $method;
    }
}
