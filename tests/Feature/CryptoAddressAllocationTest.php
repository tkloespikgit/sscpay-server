<?php

namespace Tests\Feature;

use App\Models\CryptoAddressAllocation;
use App\Models\CryptoPublicAccount;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\SystemConfig;
use App\Services\CryptoAddressAllocationService;
use App\Services\CryptoPaymentRequestService;
use Database\Seeders\CryptoPaymentTemplatesSeeder;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class CryptoAddressAllocationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_same_source_across_channels_has_one_counter_and_allocations_are_idempotent(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $secondMethod = $method->replicate(['method_code_uniq']);
        $secondMethod->method_code = 'eth-second';
        $secondMethod->save();
        $secondOrder = $this->makeOrder('second-order', 'second-order', ['payment_type' => 'crypto', 'payment_method_id' => $secondMethod->id]);
        $this->fakeDerivation();
        $service = app(CryptoAddressAllocationService::class);

        $first = $service->allocate($this->merchant->id, $order->id, $method->id, 'first-allocation-key');
        $repeat = $service->allocate($this->merchant->id, $order->id, $method->id, 'first-allocation-key');
        $second = $service->allocate($this->merchant->id, $secondOrder->id, $secondMethod->id, 'second-allocation-key');

        $this->assertSame($first->id, $repeat->id);
        $this->assertSame(2, $first->derivation_index);
        $this->assertSame(3, $second->derivation_index);
        $this->assertSame('0x'.str_repeat('c', 40), $first->address);
        $this->assertSame('0x'.str_repeat('d', 40), $second->address);
        $this->assertDatabaseCount('crypto_public_accounts', 1);
        $this->assertDatabaseCount('crypto_address_allocations', 2);
        $this->assertSame(4, CryptoPublicAccount::query()->sole()->next_index);
        Http::assertSentCount(6);
    }

    public function test_timeout_preserves_reserved_index_and_retry_uses_it_again(): void
    {
        [$order, $method] = $this->arrangeOrder();
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v3/ethereum/address/*' => Http::sequence()->push([], 401)
            ->push(['address' => '0x'.str_repeat('a', 40)])
            ->push(['address' => '0x'.str_repeat('b', 40)])
            ->push(['address' => '0x'.str_repeat('c', 40)])]);
        $service = app(CryptoAddressAllocationService::class);
        try {
            $service->allocate($this->merchant->id, $order->id, $method->id, 'retry-allocation-key');
            $this->fail('Failed derivation was accepted.');
        } catch (DomainException) {
            $this->assertSame('reserved', CryptoAddressAllocation::query()->sole()->status);
            $this->assertSame(3, CryptoPublicAccount::query()->sole()->next_index);
        }
        $allocation = $service->allocate($this->merchant->id, $order->id, $method->id, 'retry-allocation-key');

        $this->assertSame(2, $allocation->derivation_index);
        $this->assertSame(3, CryptoPublicAccount::query()->sole()->next_index);
        $this->assertDatabaseCount('crypto_address_allocations', 1);
    }

    public function test_local_recovery_mismatch_never_produces_a_payable_or_bound_address(): void
    {
        [$order, $method] = $this->arrangeOrder();
        Http::preventStrayRequests();
        Http::fake(['https://api.tatum.io/v3/ethereum/address/*' => Http::response(['address' => '0x'.str_repeat('f', 40)])]);

        try {
            app(CryptoAddressAllocationService::class)->allocate($this->merchant->id, $order->id, $method->id, 'mismatch-allocation-key');
            $this->fail('Incompatible recovery was accepted.');
        } catch (DomainException) {
            $this->assertSame('reserved', CryptoAddressAllocation::query()->sole()->status);
            $this->assertNull(CryptoAddressAllocation::query()->sole()->address);
            $this->assertDatabaseCount('crypto_addresses', 0);
            $this->assertDatabaseCount('crypto_payment_requests', 0);
            Http::assertSentCount(1);
        }
    }

    public function test_allocation_prepares_a_quoted_request_but_does_not_mark_monitor_ready(): void
    {
        $this->freezeTime();
        [$order, $method] = $this->arrangeOrder();
        $this->fakeDerivation();
        SystemConfig::set('crypto.quote_markup_percent', '2');
        SystemConfig::set('crypto.quote_ttl_seconds', '900');
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        Http::fake(['https://api.tatum.io/v4/data/rate/symbol*' => Http::response([
            'value' => '2500', 'symbol' => 'ETH', 'basePair' => 'USD', 'timestamp' => now()->getTimestampMs(),
        ])]);

        $request = app(CryptoPaymentRequestService::class)->prepareAllocated($this->merchant->id, $order->id, $method->id, 'prepare-allocation-key', 100);
        $repeat = app(CryptoPaymentRequestService::class)->prepareAllocated($this->merchant->id, $order->id, $method->id, 'prepare-allocation-key', 101);
        $differentKey = app(CryptoPaymentRequestService::class)->prepareAllocated($this->merchant->id, $order->id, $method->id, 'another-allocation-key', 101);

        $this->assertSame($request->id, $repeat->id);
        $this->assertSame($request->id, $differentKey->id);
        $this->assertSame('preparing', $request->status);
        $this->assertSame('40800000000000000', $request->due_units);
        $this->assertSame('0x'.str_repeat('c', 40), $request->cryptoAddress->address);
        $this->assertSame(100, $request->cryptoAddress->scan_from_block);
        $this->assertSame(CryptoAddressAllocation::query()->sole()->id, $request->cryptoAddress->crypto_address_allocation_id);
        $this->assertDatabaseCount('crypto_payment_requests', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        Http::assertSentCount(4);
    }

    public function test_cross_merchant_allocation_is_not_found_before_any_remote_call(): void
    {
        [$order, $method] = $this->arrangeOrder();
        Http::preventStrayRequests();
        $this->expectException(ModelNotFoundException::class);
        try {
            app(CryptoAddressAllocationService::class)->allocate($this->merchant->id + 1, $order->id, $method->id, 'foreign-allocation-key');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_counter_cannot_be_rewound(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $this->fakeDerivation();
        app(CryptoAddressAllocationService::class)->allocate($this->merchant->id, $order->id, $method->id, 'counter-allocation-key');
        $this->expectException(\LogicException::class);

        CryptoPublicAccount::query()->sole()->update(['next_index' => 2]);
    }

    /** @return array{0: Order, 1: PaymentMethod} */
    private function arrangeOrder(): array
    {
        $this->createMerchantAndApplication();
        $this->seed(CryptoPaymentTemplatesSeeder::class);
        config(['services.tatum.testnet_key' => 'test-key', 'services.tatum.api_key' => 'price-key']);
        $method = PaymentMethod::create([
            'merchant_id' => $this->merchant->id, 'method_code' => 'eth', 'method_name' => 'ETH', 'payment_type' => 'crypto',
            'is_active' => true, 'config_map_id' => PaymentMethodConfigMap::query()->where('name', 'Crypto / Ethereum')->sole()->id,
            'config' => CryptoConfigurationTest::publicConfig(), 'fee_percent' => '1', 'fee_fixed' => '0',
        ]);
        $order = $this->makeOrder('allocation-order', 'allocation-order', ['payment_type' => 'crypto', 'payment_method_id' => $method->id]);

        return [$order, $method];
    }

    private function fakeDerivation(): void
    {
        Http::preventStrayRequests();
        foreach ([0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'] as $index => $letter) {
            Http::fake(['https://api.tatum.io/v3/ethereum/address/*/'.$index.'*' => Http::response(['address' => '0x'.str_repeat($letter, 40)])]);
        }
    }
}
