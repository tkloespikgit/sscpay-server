<?php

namespace Tests\Feature;

use App\Exceptions\BalanceOperationException;
use App\Models\CryptoAddress;
use App\Models\CryptoAddressMonitor;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\CryptoBalanceService;
use App\Services\CryptoPaymentRequestService;
use App\Services\OrderAccountingService;
use App\Services\PaymentService;
use App\Support\CryptoAmount;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class CryptoPaymentFoundationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_preparation_is_idempotent_and_never_publishes_an_unmonitored_address(): void
    {
        [$order, $method] = $this->arrangeOrder();
        config(['services.tatum.evm_min_confirmations.ethereum-sepolia' => '12']);
        $request = $this->prepare($order, $method);
        config(['services.tatum.evm_min_confirmations.ethereum-sepolia' => '100']);
        $repeated = $this->prepare($order, $method);

        $this->assertSame($request->id, $repeated->id);
        $this->assertSame('preparing', $request->status);
        $this->assertSame('40800000000000000', $request->due_units);
        $this->assertSame('40392000000000000', $request->net_units);
        $this->assertSame('0.500000', $request->quote_snapshot['fiat_usd_rate']);
        $this->assertSame('12', $repeated->quote_snapshot['verification_policy']['minimum_confirmations']);
        $this->assertSame(['network' => 'ethereum-sepolia', 'asset' => 'ETH'], $request->quote_snapshot['payment_config_snapshot']);
        $this->assertDatabaseCount('crypto_addresses', 1);
        $this->assertDatabaseCount('crypto_payment_requests', 1);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_confirmed_payment_credits_net_once_and_preserves_fiat_balances(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        $fiatBefore = $this->merchant->refresh()->only(['balance', 'frozen_balance', 'pending_settlement_balance', 'pending_withdrawal_balance']);

        $ledger = app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);
        $repeated = app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);

        $this->assertSame($ledger->id, $repeated->id);
        $this->assertSame('40392000000000000', $ledger->amount_units);
        $this->assertSame('408000000000000', $ledger->fee_units);
        $this->assertSame('40392000000000000', CryptoAssetBalance::query()->sole()->balance_units);
        $this->assertSame('credited', $request->refresh()->status);
        $this->assertSame($fiatBefore, $this->merchant->refresh()->only(array_keys($fiatBefore)));
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        $this->assertDatabaseCount('order_settlements', 0);
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
    }

    #[DataProvider('unacceptableEvents')]
    public function test_does_not_credit_unverified_late_or_mismatched_transfers(array $changes): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request, $changes);

        try {
            app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);
            $this->fail('Unsafe transfer was credited.');
        } catch (DomainException) {
            $this->assertDatabaseCount('crypto_asset_balances', 0);
            $this->assertDatabaseCount('crypto_ledger_entries', 0);
            $this->assertNull($request->refresh()->credited_at);
        }
    }

    public static function unacceptableEvents(): array
    {
        return [
            'unverified' => [['verified_at' => null]],
            'unconfirmed' => [['confirmed_at' => null]],
            'orphaned' => [['orphaned_at' => '2026-11-11 12:00:00']],
            'late' => [['block_time' => '2026-11-12 12:00:00']],
            'before quote' => [['block_time' => '2026-11-11 11:59:59']],
            'before scan' => [['block_height' => 99]],
            'wrong asset' => [['asset' => 'USDT']],
            'wrong network' => [['network' => 'ethereum-mainnet']],
            'underpaid' => [['amount_units' => '40799999999999999']],
            'overpaid' => [['amount_units' => '40800000000000001']],
        ];
    }

    public function test_two_confirmed_parts_are_accumulated_without_rounding_or_duplicate_fees(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request, ['amount_units' => '20000000000000000']);
        $this->confirm($request, ['amount_units' => '20800000000000000']);

        $ledger = app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);

        $this->assertSame('40800000000000000', $ledger->gross_units);
        $this->assertSame('40392000000000000', $ledger->amount_units);
        $this->assertDatabaseCount('crypto_ledger_entries', 1);
    }

    public function test_an_extra_unconfirmed_event_blocks_credit_even_when_confirmed_sum_matches_due(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        $this->confirm($request, ['amount_units' => '1', 'confirmed_at' => null]);

        try {
            app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);
            $this->fail('Credit ignored an outstanding transfer.');
        } catch (DomainException) {
            $this->assertDatabaseCount('crypto_ledger_entries', 0);
        }
    }

    #[DataProvider('blockingReceiptStatuses')]
    public function test_outstanding_receipt_blocks_credit_of_an_otherwise_confirmed_payment(string $status): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        $monitor = CryptoAddressMonitor::factory()->create([
            'merchant_id' => $this->merchant->id, 'crypto_payment_request_id' => $request->id, 'crypto_address_id' => $request->crypto_address_id,
        ]);
        CryptoWebhookReceipt::factory()->create([
            'merchant_id' => $this->merchant->id, 'crypto_address_monitor_id' => $monitor->id,
            'crypto_payment_request_id' => $request->id, 'status' => $status,
        ]);

        try {
            app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);
            $this->fail('Credit ignored an outstanding receipt.');
        } catch (DomainException $exception) {
            $this->assertSame('Outstanding chain verification or review prevents automatic credit.', $exception->getMessage());
            $this->assertDatabaseCount('crypto_ledger_entries', 0);
        }
    }

    public static function blockingReceiptStatuses(): array
    {
        return ['pending' => ['pending'], 'confirming' => ['confirming'], 'review' => ['review']];
    }

    public function test_payment_mined_within_quote_can_be_credited_after_confirmation_past_expiry(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        $this->travel(16)->minutes();

        $ledger = app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);

        $this->assertSame('40392000000000000', $ledger->amount_units);
        $this->assertSame('credited', $request->refresh()->status);
    }

    public function test_expired_address_cannot_be_reassigned_to_a_new_order_even_with_different_case(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $first = $this->prepare($order, $method);
        $first->update(['status' => 'expired']);
        $this->travel(16)->minutes();
        $other = $this->makeOrder('second', 'second', ['payment_type' => 'crypto', 'payment_method_id' => $method->id, 'currency' => 'AUD', 'amount' => '200', 'original_exchange_rate' => '0.5']);

        try {
            $this->prepare($other, $method, '0x'.str_repeat('A', 40), 1);
            $this->fail('Previously bound address was reused.');
        } catch (QueryException) {
            $this->assertDatabaseCount('crypto_addresses', 1);
            $this->assertDatabaseCount('crypto_payment_requests', 1);
            $this->assertSame($order->id, CryptoAddress::query()->sole()->order_id);
        }
    }

    public function test_cross_merchant_credit_is_not_found_even_without_a_login(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        $this->expectException(ModelNotFoundException::class);

        app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id + 1, $request->id);
    }

    public function test_duplicate_chain_event_cannot_be_recorded_twice_with_a_different_supplied_key(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request, ['transaction_hash' => str_repeat('a', 64)]);

        try {
            $this->confirm($request, ['transaction_hash' => '0x'.str_repeat('A', 64), 'event_key' => hash('sha256', 'different')]);
            $this->fail('Duplicate economic event was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('crypto_chain_events', 1);
        }
    }

    public function test_two_outputs_in_one_transaction_are_distinct_events(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request, ['transaction_hash' => str_repeat('b', 64), 'event_position' => 'log:0', 'amount_units' => '20000000000000000']);
        $this->confirm($request, ['transaction_hash' => str_repeat('b', 64), 'event_position' => 'log:1', 'amount_units' => '20800000000000000']);

        $ledger = app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);

        $this->assertSame('40800000000000000', $ledger->gross_units);
        $this->assertDatabaseCount('crypto_chain_events', 2);
    }

    public function test_balance_overflow_rolls_back_the_entire_credit(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        $balance = CryptoAssetBalance::factory()->create([
            'merchant_id' => $this->merchant->id, 'balance_units' => CryptoAmount::MAX_UNITS,
        ]);

        try {
            app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);
            $this->fail('Overflowing balance was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(CryptoAmount::MAX_UNITS, $balance->refresh()->balance_units);
            $this->assertNull($request->refresh()->credited_at);
            $this->assertDatabaseCount('crypto_ledger_entries', 0);
        }
    }

    public function test_second_paid_request_cannot_credit_the_same_order_again(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);
        $address = CryptoAddress::factory()->create([
            'merchant_id' => $this->merchant->id, 'order_id' => $order->id, 'payment_method_id' => $method->id,
            'address' => '0x'.str_repeat('b', 40),
        ]);
        $second = CryptoPaymentRequest::factory()->create([
            ...$request->only(['merchant_id', 'order_id', 'payment_method_id', 'network', 'asset', 'decimals', 'due_units', 'fee_units', 'net_units', 'quote_snapshot', 'expires_at']),
            'crypto_address_id' => $address->id, 'status' => 'confirming',
        ]);

        try {
            app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $second->id);
            $this->fail('Order was credited by a second request.');
        } catch (DomainException) {
            $this->assertDatabaseCount('crypto_ledger_entries', 1);
            $this->assertSame('40392000000000000', CryptoAssetBalance::query()->sole()->balance_units);
        }
    }

    #[DataProvider('auditMutations')]
    public function test_rejects_reassignment_and_rewriting_of_audit_records(string $target): void
    {
        [$order, $method] = $this->arrangeOrder();
        $request = $this->prepare($order, $method);
        $this->confirm($request);
        $ledger = app(CryptoBalanceService::class)->creditConfirmedRequest($this->merchant->id, $request->id);
        $this->expectException(\LogicException::class);

        match ($target) {
            'address' => $request->cryptoAddress->update(['order_id' => $order->id + 1]),
            'quote' => $request->update(['due_units' => '1']),
            'ledger' => $ledger->update(['amount_units' => '1']),
            'event' => CryptoChainEvent::query()->sole()->update(['amount_units' => '1']),
            'order type' => $order->update(['payment_type' => 'fiat']),
        };
    }

    public static function auditMutations(): array
    {
        return ['address' => ['address'], 'quote' => ['quote'], 'ledger' => ['ledger'], 'event' => ['event'], 'order type' => ['order type']];
    }

    public function test_missing_markup_does_not_silently_quote_at_zero_markup(): void
    {
        [$order, $method] = $this->arrangeOrder();
        SystemConfig::query()->where('config_key', 'crypto.quote_markup_percent')->delete();
        Cache::forget('system_config:crypto.quote_markup_percent');
        try {
            $this->prepare($order, $method);
            $this->fail('Unconfigured quote was accepted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('crypto_payment_requests', 0);
            $this->assertDatabaseCount('crypto_addresses', 0);
        }
    }

    public function test_cross_merchant_preparation_is_not_found(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $this->expectException(ModelNotFoundException::class);

        app(CryptoPaymentRequestService::class)->prepare($this->merchant->id + 1, $order->id, $method->id, '0x'.str_repeat('b', 40), hash('sha256', 'other'), 0, 1, '2500', CarbonImmutable::now());
    }

    public function test_stale_market_price_creates_neither_request_nor_address(): void
    {
        [$order, $method] = $this->arrangeOrder();
        try {
            app(CryptoPaymentRequestService::class)->prepare($this->merchant->id, $order->id, $method->id, '0x'.str_repeat('b', 40), hash('sha256', 'source'), 0, 1, '2500', CarbonImmutable::now()->subSeconds(61));
            $this->fail('Stale price was accepted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('crypto_addresses', 0);
            $this->assertDatabaseCount('crypto_payment_requests', 0);
        }
    }

    public function test_existing_channels_and_orders_remain_fiat_by_default(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('fiat-default');
        $order = $this->makeOrder('fiat-default', 'fiat-default');

        $this->assertSame('fiat', $method->refresh()->payment_type);
        $this->assertSame('fiat', $method->configMap->payment_type);
        $this->assertSame('fiat', $order->refresh()->payment_type);
    }

    public function test_fiat_routing_excludes_crypto_channels_until_chain_checkout_is_implemented(): void
    {
        [, $crypto] = $this->arrangeOrder();
        $fiat = $this->makePaymentMethod('fiat-route');
        $group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id, 'group_key' => 'mixed', 'group_name' => 'Mixed', 'is_active' => true,
        ]);
        $group->paymentMethods()->attach([$fiat->id, $crypto->id]);
        Http::preventStrayRequests();

        $service = app(PaymentService::class);
        $selected = $service->resolvePaymentMethod($group, 100);
        $snapshot = $service->routingLoadSnapshot($group);

        $this->assertSame($fiat->id, $selected->id);
        $this->assertNull($snapshot[$crypto->id]['rank']);
        $this->assertFalse($snapshot[$crypto->id]['in_top3']);
        Http::assertNothingSent();
    }

    public function test_derivation_index_cannot_be_reused_with_a_different_address(): void
    {
        [$order, $method] = $this->arrangeOrder();
        $this->prepare($order, $method);
        $other = $this->makeOrder('other-index', 'other-index', ['payment_type' => 'crypto', 'payment_method_id' => $method->id]);

        try {
            $this->prepare($other, $method, '0x'.str_repeat('c', 40));
            $this->fail('Same derivation index was assigned twice.');
        } catch (QueryException) {
            $this->assertDatabaseCount('crypto_addresses', 1);
            $this->assertDatabaseCount('crypto_payment_requests', 1);
        }
    }

    public function test_crypto_order_never_creates_a_fiat_accounting_task(): void
    {
        [$order] = $this->arrangeOrder();

        app(OrderAccountingService::class)->recordForStatus($order, 'paid', true);
        app(OrderAccountingService::class)->recordForStatus($order, 'refunded');

        $this->assertDatabaseCount('order_accounting_tasks', 0);
    }

    #[DataProvider('fiatOperations')]
    public function test_fiat_operations_reject_crypto_orders(string $operation): void
    {
        [$order] = $this->arrangeOrder();
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $service = app(BalanceService::class);

        try {
            match ($operation) {
                'credit' => $service->creditForPaidOrder($order),
                'refund' => $service->refund($order, '1', null),
                'chargeback' => $service->chargeback($order, null),
                'shipment' => $service->startSettlementForShippedOrder($order),
                'dispute' => $service->freezeForDisputeEvent($order, User::factory()->create(), []),
            };
            $this->fail('Crypto order entered fiat accounting.');
        } catch (BalanceOperationException) {
            $this->assertDatabaseCount('merchant_balance_transactions', 0);
            $this->assertDatabaseCount('merchant_fund_freezes', 0);
        }
    }

    public static function fiatOperations(): array
    {
        return ['credit' => ['credit'], 'refund' => ['refund'], 'chargeback' => ['chargeback'], 'shipment' => ['shipment'], 'dispute' => ['dispute']];
    }

    /** @return array{0: Order, 1: PaymentMethod} */
    private function arrangeOrder(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
        $this->createMerchantAndApplication();
        SystemConfig::set('crypto.quote_markup_percent', '2');
        SystemConfig::set('crypto.quote_ttl_seconds', '900');
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        $map = PaymentMethodConfigMap::create(['name' => 'Ethereum test', 'payment_type' => 'crypto', 'is_active' => true, 'fields' => []]);
        $method = PaymentMethod::create([
            'merchant_id' => $this->merchant->id, 'method_code' => 'eth', 'method_name' => 'ETH', 'is_active' => true,
            'payment_type' => 'crypto', 'config_map_id' => $map->id, 'fee_percent' => '1', 'fee_fixed' => '0',
            'config' => ['network' => 'ethereum-sepolia', 'asset' => 'ETH'],
        ]);
        $order = $this->makeOrder('crypto-order', 'crypto-order', [
            'payment_type' => 'crypto', 'payment_method_id' => $method->id,
            'amount' => '200', 'currency' => 'AUD', 'original_exchange_rate' => '0.5',
        ]);

        return [$order, $method];
    }

    private function prepare(Order $order, PaymentMethod $method, ?string $address = null, int $index = 0): CryptoPaymentRequest
    {
        return app(CryptoPaymentRequestService::class)->prepare(
            $this->merchant->id, $order->id, $method->id, $address ?? '0x'.str_repeat('a', 40),
            hash('sha256', 'public-source'), $index, 100, '2500', CarbonImmutable::now(),
        );
    }

    private function confirm(CryptoPaymentRequest $request, array $changes = []): void
    {
        $request->update(['status' => 'confirming']);
        CryptoChainEvent::factory()->create(array_replace([
            'merchant_id' => $request->merchant_id, 'crypto_payment_request_id' => $request->id,
            'network' => $request->network, 'asset' => $request->asset, 'amount_units' => $request->due_units,
            'block_height' => 100,
            'verified_at' => now(), 'confirmed_at' => now(),
        ], $changes));
    }
}
