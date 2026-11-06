<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\SystemConfig;
use App\Services\Crypto\CryptoQuote;
use App\Services\Crypto\CryptoQuoteService;
use App\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class CryptoQuoteTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const RECEIVE_ADDRESS = 'TTestReceiveAddressCryptoQuote0001';

    private const TOKEN_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private ?PaymentMethodConfigMap $cryptoConfigMap = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tatum.api_key' => 'test-tatum-key',
            'tatum.webhook_url' => '',
            'tatum.quote_cache_ttl_hours' => 24,
        ]);
        Cache::flush();
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

    public function test_usd_basis_uses_order_amount_not_converted_amount(): void
    {
        $order = new Order([
            'amount' => '99.95',
            'converted_amount' => '120.00',
            'currency' => 'USD',
            'exchange_rate' => '1',
        ]);

        $usd = app(CryptoQuoteService::class)->usdBasisFromOrderAmount($order);
        $this->assertSame(0, bccomp($usd, '99.95', 2));
    }

    public function test_non_usd_basis_multiplies_amount_by_exchange_rate(): void
    {
        $order = new Order([
            'amount' => '10.00',
            'converted_amount' => '999.00',
            'currency' => 'EUR',
            'exchange_rate' => '1.100000',
        ]);

        $usd = app(CryptoQuoteService::class)->usdBasisFromOrderAmount($order);
        $this->assertSame(0, bccomp($usd, '11.00000000', 8));
    }

    public function test_stablecoin_uses_peg_without_tatum_http(): void
    {
        Http::fake();
        $method = $this->cryptoMethod('tron_usdt_quote_peg', ['symbol' => 'USDT']);

        $quote = app(CryptoQuoteService::class)->quoteForMethod($method);

        $this->assertSame('1', $quote->rate);
        $this->assertSame(CryptoQuote::SOURCE_PEG, $quote->source);
        Http::assertNothingSent();
    }

    public function test_usdt_checkout_uses_converted_amount_without_tatum_http(): void
    {
        Http::fake();
        $this->cryptoMethod('tron_usdt_quote_order');

        $order = app(OrderCreationService::class)->createOrder(
            $this->payload('100.00', 'CRYPTO-QUOTE-PEG', 'tron_usdt_quote_order'),
            $this->merchant,
            $this->application,
            'api',
        );

        $order->refresh();
        $this->assertSame(0, bccomp((string) $order->converted_amount, '100.000', 3));
        $this->assertSame(self::RECEIVE_ADDRESS, $order->crypto_receive_address);
        Http::assertNothingSent();
    }

    public function test_volatile_token_fetches_tatum_and_converts_usd(): void
    {
        Http::fake([
            'api.tatum.io/v4/data/rate/symbol*' => Http::response([
                'value' => '2.00000000',
                'basePair' => 'USD',
                'timestamp' => 1_700_000_000_000,
                'source' => 'CoinGecko',
                'symbol' => 'TRX',
            ], 200),
        ]);

        SystemConfig::set(
            CryptoQuoteService::STABLECOIN_CONFIG_KEY,
            json_encode(['USDT', 'USDC']),
            'crypto',
            'stablecoins'
        );
        Cache::forget('system_config:'.CryptoQuoteService::STABLECOIN_CONFIG_KEY);

        $method = $this->cryptoMethod('tron_trx_quote', [
            'symbol' => 'TRX',
            'token_contract' => '',
        ]);

        $quote = app(CryptoQuoteService::class)->quoteForMethod($method);
        $this->assertSame(CryptoQuote::SOURCE_TATUM, $quote->source);
        $this->assertSame(0, bccomp($quote->rate, '2', 8));

        $base = app(CryptoQuoteService::class)->tokenBaseFromUsd('100.00', $quote->rate, 3);
        $this->assertSame(0, bccomp($base, '50.000', 3));
    }

    public function test_volatile_token_falls_back_to_last_cache_when_tatum_fails(): void
    {
        $method = $this->cryptoMethod('tron_trx_cache', [
            'symbol' => 'TRX',
            'token_contract' => '',
        ]);

        Http::fake([
            '*' => Http::response([
                'value' => '4.00000000',
                'basePair' => 'USD',
                'timestamp' => 1_700_000_000_000,
                'symbol' => 'TRX',
            ], 200),
        ]);

        $service = app(CryptoQuoteService::class);
        $first = $service->quoteForMethod($method);
        $this->assertSame(CryptoQuote::SOURCE_TATUM, $first->source);

        $cacheKey = sha1('TRX|tron|');
        Cache::forget('crypto:quote:fresh:'.$cacheKey);
        $this->assertNull(Cache::get('crypto:quote:fresh:'.$cacheKey));
        $this->assertNotNull(Cache::get('crypto:quote:last:'.$cacheKey));

        Http::swap(new Factory);
        Http::fake(function (Request $request) {
            return Http::response(['message' => 'down', 'url' => $request->url()], 500);
        });

        try {
            $quote = $service->quoteForMethod($method);
        } catch (\Throwable $e) {
            $this->fail('should use last cache, got: '.$e->getMessage());
        }

        $this->assertSame(CryptoQuote::SOURCE_CACHE, $quote->source);
        $this->assertSame(0, bccomp($quote->rate, '4', 8));
        $this->assertNotEmpty(Http::recorded());
    }

    public function test_volatile_token_fails_when_tatum_and_cache_missing(): void
    {
        Http::fake([
            'api.tatum.io/*' => Http::response(['message' => 'down'], 500),
        ]);
        Cache::forget('system_config:'.CryptoQuoteService::STABLECOIN_CONFIG_KEY);

        $method = $this->cryptoMethod('tron_trx_fail', [
            'symbol' => 'TRX',
            'token_contract' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('无法获取 TRX/USD 报价');
        app(CryptoQuoteService::class)->quoteForMethod($method);
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
                'tatum_api_key' => 'test-tatum-key',
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
