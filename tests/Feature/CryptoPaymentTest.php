<?php

namespace Tests\Feature;

use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class CryptoPaymentTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const RECEIVE_ADDRESS = 'TTestReceiveAddressCryptoPoC00001';

    private const TOKEN_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private ?PaymentMethodConfigMap $cryptoConfigMap = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tatum.api_key' => '',
            'tatum.webhook_url' => '',
        ]);
        $this->createMerchantAndApplication();

        $this->cryptoConfigMap = PaymentMethodConfigMap::query()->where('name', 'TRON USDT')->first();
        $this->assertNotNull($this->cryptoConfigMap);

        PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'default',
            'group_name' => 'Default',
            'is_active' => true,
        ]);
    }

    public function test_crypto_checkout_skips_pga_and_binds_fixed_address(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt');

        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('100.50', 'CRYPTO-100', 'tron_usdt'),
            $this->merchant,
            $this->application,
            'api',
        );

        $order->refresh();
        $this->assertSame(self::RECEIVE_ADDRESS, $order->crypto_receive_address);
        $this->assertSame('tron', $order->crypto_chain);
        $this->assertSame('USDT', $order->crypto_symbol);
        $this->assertSame(3, $order->cryptoAmountScale());
        $this->assertSame(0, bccomp((string) $order->converted_amount, '100.500', 3));
        $this->assertSame(0, bccomp((string) $order->surcharge_fee, '0', 2));
        $this->assertNotNull($order->crypto_expires_at);
        $this->assertStringContainsString('/payment/', (string) $order->pay_url);
        Http::assertNothingSent();

        $this->get('/payment/'.$order->payment_link_token)
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Pending', false)
            ->assertSee('Order number', false)
            ->assertSee('Network', false)
            ->assertSee('Currency', false)
            ->assertSee('Amount due', false)
            ->assertSee('Receive address', false)
            ->assertSee('Copy', false)
            ->assertSee('Time left', false)
            ->assertSee('100.500', false)
            ->assertDontSee('100.50000000', false)
            ->assertDontSee('待支付', false)
            ->assertSee('Open in TronLink', false)
            ->assertDontSee('在钱包中打开', false);
    }

    public function test_payment_page_formats_converted_amount_to_thousandths(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt_scale');

        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('878.78', 'CRYPTO-SCALE', 'tron_usdt_scale'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->assertSame(3, $order->fresh()->cryptoAmountScale());
        $this->assertSame(0, bccomp((string) $order->fresh()->converted_amount, '878.780', 3));
        $this->get('/payment/'.$order->payment_link_token)
            ->assertOk()
            ->assertSee('878.780', false);
    }

    public function test_webhook_marks_paid_on_exact_expected_amount_only(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt2');

        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('50.00', 'CRYPTO-50', 'tron_usdt2'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->postJson('/api/webhooks/tatum/address', [
            'address' => $order->crypto_receive_address,
            'amount' => '60.00',
            'txId' => 'txid-overpay-ignored',
            'chain' => 'tron',
            'asset' => 'USDT',
        ])->assertOk();

        $this->assertSame('pending', $order->fresh()->status);

        $this->postJson('/api/webhooks/tatum/address', [
            'address' => $order->crypto_receive_address,
            'amount' => (string) $order->fresh()->converted_amount,
            'txId' => 'txid-exact-1',
            'chain' => 'tron',
            'asset' => 'USDT',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(0, bccomp((string) $order->crypto_amount_received, (string) $order->converted_amount, 3));
        $this->assertSame('txid-exact-1', $order->transaction_id);
        $this->assertContains('txid-exact-1', $order->crypto_txids);
    }

    public function test_webhook_accepts_tatum_incoming_fungible_payload_shape(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt_real_shape');

        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('1.00', 'CRYPTO-TATUM-SHAPE', 'tron_usdt_real_shape'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->postJson('/api/webhooks/tatum/address', [
            'currency' => 'TRON',
            'address' => $order->crypto_receive_address,
            'blockNumber' => 138413,
            'counterAddress' => 'TCounterAddressExample000000000001',
            'txId' => '27cabcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456',
            'chain' => 'tron-mainnet',
            'subscriptionType' => 'INCOMING_FUNGIBLE_TX',
            'amount' => '1',
            'contractAddress' => self::TOKEN_CONTRACT,
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(0, bccomp((string) $order->crypto_amount_received, '1.000', 3));
        $this->assertSame('27cabcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456', $order->transaction_id);
    }

    public function test_partial_payment_does_not_match_under_amount_exclusion(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt3');

        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('40.00', 'CRYPTO-40', 'tron_usdt3'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->postJson('/api/webhooks/tatum/address', [
            'address' => $order->crypto_receive_address,
            'amount' => '10.00',
            'txId' => 'txid-partial-1',
            'chain' => 'tron',
            'asset' => 'USDT',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame(0, bccomp((string) $order->crypto_amount_received, '0.000', 3));
    }

    public function test_same_base_amount_gets_downward_unique_expected(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt_salt');

        $first = app(OrderCreationService::class)->createOrder(
            $this->payload('100.00', 'CRYPTO-SALT-1', 'tron_usdt_salt'),
            $this->merchant,
            $this->application,
            'api',
        );
        $second = app(OrderCreationService::class)->createOrder(
            $this->payload('100.00', 'CRYPTO-SALT-2', 'tron_usdt_salt'),
            $this->merchant,
            $this->application,
            'api',
        );

        $first->refresh();
        $second->refresh();

        $this->assertSame(0, bccomp((string) $first->converted_amount, '100.000', 3));
        $this->assertSame(0, bccomp((string) $second->converted_amount, '99.999', 3));
        $this->assertNotSame(
            (string) $first->converted_amount,
            (string) $second->converted_amount,
        );

        $this->postJson('/api/webhooks/tatum/address', [
            'address' => $second->crypto_receive_address,
            'amount' => '99.999',
            'txId' => 'txid-salt-second',
            'chain' => 'tron',
            'asset' => 'USDT',
        ])->assertOk();

        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame('pending', $first->fresh()->status);
    }

    public function test_expire_command_marks_due_orders_expired(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt4');

        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('20.00', 'CRYPTO-20', 'tron_usdt4'),
            $this->merchant,
            $this->application,
            'api',
        );
        $order->forceFill(['crypto_expires_at' => now()->subMinute()])->save();

        $this->artisan('crypto:expire-payments')->assertSuccessful();

        $this->assertSame('expired', $order->fresh()->status);
    }

    public function test_subscribe_reuses_existing_tatum_subscription_id(): void
    {
        $webhookUrl = 'https://example.com/api/webhooks/tatum/address';
        Http::fake([
            '*/v4/subscription' => Http::response([
                'statusCode' => 403,
                'errorCode' => 'subscription.exists.on.address-and-currency',
                'message' => 'Subscription for type INCOMING_FUNGIBLE_TX on the address '.self::RECEIVE_ADDRESS.', currency TRON and url '.$webhookUrl.' already exists (6ae853466f66995d4d610265).',
            ], 403),
        ]);
        // 全局留空，凭证来自渠道 config（模板字段）。
        config([
            'tatum.api_key' => '',
            'tatum.base_url' => 'https://api.tatum.io',
            'tatum.webhook_url' => '',
        ]);
        $method = $this->cryptoMethod('tron_usdt_exists', [
            'tatum_api_key' => 'test-key-from-method-config',
            'tatum_base_url' => 'https://api.tatum.io',
            'tatum_webhook_url' => $webhookUrl,
        ]);

        app(OrderCreationService::class)->createOrder(
            $this->payload('10.00', 'CRYPTO-EXISTS', 'tron_usdt_exists'),
            $this->merchant,
            $this->application,
            'api',
        );

        $this->assertSame('6ae853466f66995d4d610265', $method->fresh()->cryptoTatumSubscriptionId());
        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'test-key-from-method-config'));
    }

    public function test_rejects_non_usdt_symbol(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_btc_blocked', ['symbol' => 'BTC']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('当前仅支持 USDT');

        app(OrderCreationService::class)->createOrder(
            $this->payload('10.00', 'CRYPTO-NON-USDT', 'tron_btc_blocked'),
            $this->merchant,
            $this->application,
            'api',
        );
    }

    public function test_rejects_usdt_without_token_contract(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt_no_contract', ['token_contract' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('token_contract');

        app(OrderCreationService::class)->createOrder(
            $this->payload('10.00', 'CRYPTO-NO-CONTRACT', 'tron_usdt_no_contract'),
            $this->merchant,
            $this->application,
            'api',
        );
    }

    /**
     * @param  array<string, string>  $configOverrides
     */
    private function cryptoMethod(string $code, array $configOverrides = []): PaymentMethod
    {
        $method = $this->makePaymentMethod($code);
        $method->update([
            'config_map_id' => $this->cryptoConfigMap?->id,
            'config' => array_merge([
                'chain' => 'tron',
                'symbol' => 'USDT',
                'token_contract' => self::TOKEN_CONTRACT,
                'receive_address' => self::RECEIVE_ADDRESS,
            ], $configOverrides),
        ]);

        return $method->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $amount, string $merchantOrderNo, string $methodCode): array
    {
        return [
            'merchant_order_no' => $merchantOrderNo,
            'currency' => 'USD',
            'group_key' => 'default',
            'payment_method_key' => $methodCode,
            'subtotal' => $amount,
            'discount' => '0.00',
            'shipping_fee' => '0.00',
            'tax' => '0.00',
            'amount' => $amount,
            'customer_first_name' => 'John',
            'customer_last_name' => 'Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => null,
            'shipping_address_line1' => '123 Main St',
            'shipping_city' => 'City',
            'shipping_country' => 'US',
            'shipping_zip' => null,
            'notify_url' => 'https://shop.example.com/notify',
            'return_url' => 'https://shop.example.com/return',
            'cancel_url' => 'https://shop.example.com/cancel',
            'items' => [[
                'product_id' => '1',
                'product_url' => 'https://shop.example.com/product/1',
                'product_name' => 'Product',
                'unit_price' => $amount,
                'quantity' => 1,
            ]],
        ];
    }
}
