<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Merchant;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\OrderRefund;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderSurchargeRecalculationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class RecalculateOrderSurchargeTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private PaymentMethod $paymentMethod;

    private string $reportDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();
        $this->paymentMethod = $this->makePaymentMethod('surcharge-test');
        $this->configureSurcharge('percent', '-2', '0');
        $this->reportDirectory = sys_get_temp_dir().'/order-surcharge-tests-'.Str::uuid();
        File::makeDirectory($this->reportDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->reportDirectory)) {
            File::deleteDirectory($this->reportDirectory);
        }

        parent::tearDown();
    }

    public function test_default_preview_reports_the_adjustment_without_changing_orders_or_money(): void
    {
        $order = $this->creditedOrder('PREVIEW');
        $before = $order->refresh()->getRawOriginal();
        $balance = $this->merchant->refresh()->getRawOriginal();
        $ledger = MerchantBalanceTransaction::query()->get()->toArray();

        $report = $this->runCommand();

        $this->assertSame('preview', $report['mode']);
        $this->assertSame($this->merchant->id, $report['merchant_id']);
        $this->assertSame(1, $report['summary']['changed']);
        $this->assertSame('110.00', $report['summary']['no_surcharge_amount']);
        $this->assertSame('107.80', $report['summary']['new_converted_amount']);
        $this->assertSame('-2.20', $report['summary']['surcharge_difference']);
        $this->assertSame('106.40', $report['summary']['old_settlement_amount']);
        $this->assertSame('104.20', $report['summary']['new_settlement_amount']);
        $this->assertSame('-2.20', $report['summary']['settlement_difference']);
        $this->assertSame($before, $order->refresh()->getRawOriginal());
        $this->assertSame($balance, $this->merchant->refresh()->getRawOriginal());
        $this->assertSame($ledger, MerchantBalanceTransaction::query()->get()->toArray());
    }

    public function test_apply_uses_the_historical_market_rate_and_preserves_fees_items_and_ledgers(): void
    {
        $order = $this->creditedOrder('APPLY');
        ExchangeRate::create([
            'base_currency' => 'USD', 'target_currency' => 'EUR',
            'rate' => '99.000000', 'retrieved_at' => now(),
        ]);
        $this->paymentMethod->update(['fee_percent' => '99.0000', 'fee_fixed' => '99.00']);
        $item = $order->items()->create([
            'product_name' => 'Original item', 'unit_price' => '50.00',
            'quantity' => 2, 'total_price' => '100.00', 'converted_unit_price' => '55.00',
        ]);
        $matched = $order->matchedItems()->create([
            'product_name' => 'Gateway item', 'unit_price' => '50.00',
            'quantity' => 2, 'total_price' => '100.00', 'converted_unit_price' => '55.00',
        ]);
        $itemBefore = $item->refresh()->getRawOriginal();
        $matchedBefore = $matched->refresh()->getRawOriginal();
        $balanceBefore = $this->merchant->refresh()->getRawOriginal();
        $ledgerBefore = MerchantBalanceTransaction::query()->get()->toArray();

        $report = $this->runCommand(['--apply' => true]);

        $order->refresh();
        $this->assertSame('applied', $report['mode']);
        $this->assertSame('1.100000', $order->original_exchange_rate);
        $this->assertSame('1.078000', $order->exchange_rate);
        $this->assertSame('-2.0000', $order->surcharge_percent);
        $this->assertSame('percent', $order->surcharge_type);
        $this->assertSame('-0.022000', $order->surcharge_amount);
        $this->assertSame('-2.20', $order->surcharge_fee);
        $this->assertSame('107.80', $order->converted_amount);
        $this->assertSame('129.36', $order->subtotal_converted);
        $this->assertSame('5.39', $order->shipping_fee_converted);
        $this->assertSame('32.34', $order->discount_converted);
        $this->assertSame('5.39', $order->tax_converted);
        $this->assertSame('3.30', $order->fee_percent_amount);
        $this->assertSame('0.30', $order->fee_fixed_amount);
        $this->assertSame('104.20', $order->settlement_amount);
        $this->assertSame('100.00', $order->amount);
        $this->assertSame('120.00', $order->original_amount);
        $this->assertSame($itemBefore, $item->refresh()->getRawOriginal());
        $this->assertSame($matchedBefore, $matched->refresh()->getRawOriginal());
        $this->assertSame($balanceBefore, $this->merchant->refresh()->getRawOriginal());
        $this->assertSame($ledgerBefore, MerchantBalanceTransaction::query()->get()->toArray());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_positive_percentage_uses_the_existing_upward_rate_formula(): void
    {
        $this->configureSurcharge('percent', '2', '0');
        $order = $this->creditedOrder('POSITIVE');

        $report = $this->runCommand(['--apply' => true]);

        $this->assertSame('1.122000', $order->refresh()->exchange_rate);
        $this->assertSame('112.20', $order->converted_amount);
        $this->assertSame('108.60', $order->settlement_amount);
        $this->assertSame('2.20', $report['summary']['surcharge_difference']);
        $this->assertSame('2.20', $report['summary']['settlement_difference']);
    }

    public function test_fixed_negative_surcharge_is_applied_to_the_historical_rate(): void
    {
        $this->configureSurcharge('fixed', '50', '-0.01');
        $order = $this->creditedOrder('FIXED');

        $report = $this->runCommand(['--apply' => true]);

        $this->assertSame('1.090000', $order->refresh()->exchange_rate);
        $this->assertSame('fixed', $order->surcharge_type);
        $this->assertSame('-0.010000', $order->surcharge_amount);
        $this->assertSame('-1.00', $order->surcharge_fee);
        $this->assertSame('109.00', $order->converted_amount);
        $this->assertSame('105.40', $order->settlement_amount);
        $this->assertSame('-1.00', $report['summary']['settlement_difference']);
    }

    public function test_latest_configuration_is_read_from_the_database_even_if_cache_is_stale(): void
    {
        Cache::forever('system_config:exchange.surcharge_type', 'fixed');
        Cache::forever('system_config:exchange.surcharge_percent', '99');
        Cache::forever('system_config:exchange.surcharge_fixed', '20');
        $order = $this->creditedOrder('LATEST');

        $report = $this->runCommand(['--apply' => true]);

        $this->assertSame('1.078000', $order->refresh()->exchange_rate);
        $this->assertIsArray($report['settings']);
        $this->assertSame('-2.20', $report['summary']['settlement_difference']);
    }

    public function test_orders_are_isolated_by_merchant_and_soft_deleted_and_usd_orders_are_untouched(): void
    {
        $selected = $this->creditedOrder('SELECTED');
        $usd = $this->creditedOrder('USD', ['currency' => 'USD']);
        $deleted = $this->creditedOrder('DELETED');
        $deleted->delete();
        $otherMerchant = $this->otherMerchant('Another Merchant');
        $other = $this->creditedOrder('OTHER', ['merchant_id' => $otherMerchant->id]);
        $excludedBefore = [$usd->refresh()->getRawOriginal(), $deleted->refresh()->getRawOriginal(), $other->refresh()->getRawOriginal()];

        $report = $this->runCommand(['--apply' => true]);

        $this->assertSame('107.80', $selected->refresh()->converted_amount);
        $this->assertSame($excludedBefore, [$usd->refresh()->getRawOriginal(), $deleted->refresh()->getRawOriginal(), $other->refresh()->getRawOriginal()]);
        $this->assertSame(1, $report['summary']['changed']);
        $reportedIds = array_column($report['orders'], 'order_id');
        $this->assertNotContains($other->id, $reportedIds);
        $this->assertNotContains($deleted->id, $reportedIds);
    }

    public function test_created_at_date_filter_includes_both_boundaries_in_system_timezone(): void
    {
        config(['app.timezone' => 'Asia/Shanghai']);
        $early = $this->creditedOrder('EARLY', ['created_at' => '2026-09-30 23:59:59']);
        $first = $this->creditedOrder('FIRST', ['created_at' => '2026-10-01 00:00:00', 'paid_at' => '2026-10-08 10:00:00']);
        $last = $this->creditedOrder('LAST', ['created_at' => '2026-10-02 23:59:59', 'paid_at' => null]);
        $late = $this->creditedOrder('LATE', ['created_at' => '2026-10-03 00:00:00']);

        $report = $this->runCommand(['--apply' => true, '--from' => '2026-10-01', '--to' => '2026-10-02']);

        $this->assertSame(2, $report['summary']['changed']);
        $this->assertSame('107.80', $first->refresh()->converted_amount);
        $this->assertSame('107.80', $last->refresh()->converted_amount);
        $this->assertSame('110.00', $early->refresh()->converted_amount);
        $this->assertSame('110.00', $late->refresh()->converted_amount);
        $this->assertEqualsCanonicalizing(['2026-10-08', '2026-10-02'], $report['stats_dates']);
    }

    public function test_existing_surcharge_requires_opt_in_and_reports_both_different_deltas(): void
    {
        $order = $this->creditedOrder('EXISTING', [
            'exchange_rate' => '1.111000', 'surcharge_percent' => '1.0000',
            'surcharge_amount' => '0.011000', 'surcharge_fee' => '1.10',
            'converted_amount' => '111.10', 'settlement_amount' => '107.50',
        ]);

        $default = $this->runCommand(['--apply' => true]);

        $this->assertSame(0, $default['summary']['changed']);
        $this->assertSame('111.10', $order->refresh()->converted_amount);

        $included = $this->runCommand(['--apply' => true, '--include-existing' => true]);

        $this->assertSame(1, $included['summary']['changed']);
        $this->assertSame('-2.20', $included['summary']['surcharge_difference']);
        $this->assertSame('-3.30', $included['summary']['settlement_difference']);
        $this->assertSame('104.20', $order->refresh()->settlement_amount);
    }

    public function test_repeat_apply_has_no_new_difference_and_does_not_rewrite_the_audit_trail(): void
    {
        $order = $this->creditedOrder('REPEAT');
        $this->runCommand(['--apply' => true]);
        $before = $order->refresh()->getRawOriginal();

        $repeat = $this->runCommand(['--apply' => true, '--include-existing' => true]);

        $this->assertSame(0, $repeat['summary']['changed']);
        $this->assertSame(1, $repeat['summary']['unchanged']);
        $this->assertSame('0.00', $repeat['summary']['settlement_difference']);
        $this->assertSame('0.00', $repeat['summary']['surcharge_difference']);
        $this->assertSame('unchanged', $repeat['orders'][0]['result']);
        $this->assertSame($before, $order->refresh()->getRawOriginal());
        $this->assertSame(1, MerchantBalanceTransaction::query()->count());
    }

    public function test_unpaid_reversed_disputed_and_inconsistent_orders_are_skipped_with_reasons(): void
    {
        $pending = $this->creditedOrder('PENDING', ['status' => 'pending']);
        $uncredited = $this->baseOrder('UNCREDITED');
        $mismatch = $this->creditedOrder('MISMATCH');
        $mismatch->update(['settlement_amount' => '105.00']);
        $refunded = $this->creditedOrder('REFUND-RECORD');
        OrderRefund::create([
            'merchant_id' => $this->merchant->id, 'order_id' => $refunded->id,
            'currency' => 'EUR', 'amount' => '1.00', 'exchange_rate' => '1.100000',
            'amount_usd' => '1.10', 'fee' => '0.00',
        ]);
        $reversal = $this->creditedOrder('REVERSAL');
        MerchantBalanceTransaction::create([
            'merchant_id' => $this->merchant->id, 'order_id' => $reversal->id,
            'type' => MerchantBalanceTransaction::TYPE_CHARGEBACK,
            'amount' => '-1.00', 'balance_before' => '100.00', 'balance_after' => '99.00',
        ]);
        $disputed = $this->creditedOrder('CLOSED-DISPUTE');
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        OrderDisputeEvent::create([
            'merchant_id' => $this->merchant->id, 'order_id' => $disputed->id,
            'order_no' => $disputed->order_no, 'event_no' => 'CLOSED-CASE',
            'status' => OrderDisputeEvent::STATUS_CLOSED, 'reason' => 'Historical dispute',
            'final_action' => 'refund', 'deadline_value' => 24, 'deadline_unit' => 'hours',
            'deadline_hours' => 24, 'frozen_amount' => '110.00', 'opened_by' => $operator->id,
            'opened_at' => now()->subDays(2), 'due_at' => now()->subDay(), 'closed_at' => now(),
        ]);
        $orders = collect([$pending, $uncredited, $mismatch, $refunded, $reversal, $disputed]);
        $before = $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->refresh()->getRawOriginal()])->all();
        $refunds = OrderRefund::query()->get()->toArray();
        $disputes = OrderDisputeEvent::query()->get()->toArray();

        $report = $this->runCommand(['--apply' => true]);

        $this->assertSame(0, $report['summary']['changed']);
        $this->assertSame(6, $report['summary']['skipped']);
        foreach ($report['orders'] as $entry) {
            $this->assertSame('skipped', $entry['result']);
            $this->assertNotEmpty($entry['reason']);
        }
        $this->assertSame($before, $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->refresh()->getRawOriginal()])->all());
        $this->assertSame($refunds, OrderRefund::query()->get()->toArray());
        $this->assertSame($disputes, OrderDisputeEvent::query()->get()->toArray());
    }

    public function test_a_paid_ledger_belonging_to_another_merchant_does_not_authorize_recalculation(): void
    {
        $other = $this->otherMerchant('Ledger Merchant');
        $order = $this->baseOrder('WRONG-LEDGER');
        MerchantBalanceTransaction::create([
            'merchant_id' => $other->id, 'order_id' => $order->id,
            'type' => MerchantBalanceTransaction::TYPE_ORDER_PAID,
            'amount' => '106.40', 'balance_before' => '0.00', 'balance_after' => '106.40',
            'idempotency_key' => 'order_paid:'.$order->id,
        ]);

        $report = $this->runCommand(['--apply' => true]);

        $this->assertSame(0, $report['summary']['changed']);
        $this->assertSame(1, $report['summary']['skipped']);
        $this->assertSame('110.00', $order->refresh()->converted_amount);
    }

    public function test_refunds_and_reversals_with_inconsistent_merchant_ids_are_not_hidden(): void
    {
        $other = $this->otherMerchant('Inconsistent Records Merchant');
        $refunded = $this->creditedOrder('WRONG-REFUND');
        OrderRefund::create([
            'merchant_id' => $other->id, 'order_id' => $refunded->id,
            'currency' => 'EUR', 'amount' => '1.00', 'exchange_rate' => '1.100000',
            'amount_usd' => '1.10', 'fee' => '0.00',
        ]);
        $reversed = $this->creditedOrder('WRONG-REVERSAL');
        MerchantBalanceTransaction::create([
            'merchant_id' => $other->id, 'order_id' => $reversed->id,
            'type' => MerchantBalanceTransaction::TYPE_REFUND,
            'amount' => '-1.10', 'balance_before' => '10.00', 'balance_after' => '8.90',
        ]);
        $before = [$refunded->refresh()->getRawOriginal(), $reversed->refresh()->getRawOriginal()];
        $balance = $this->merchant->refresh()->getRawOriginal();
        $ledgers = MerchantBalanceTransaction::query()->get()->toArray();
        $refunds = OrderRefund::query()->get()->toArray();

        $report = $this->runCommand(['--apply' => true]);

        $this->assertSame(0, $report['summary']['changed']);
        $this->assertSame(2, $report['summary']['skipped']);
        foreach ($report['orders'] as $entry) {
            $this->assertSame('skipped', $entry['result']);
            $this->assertNotEmpty($entry['reason']);
        }
        $this->assertSame($before, [$refunded->refresh()->getRawOriginal(), $reversed->refresh()->getRawOriginal()]);
        $this->assertSame($balance, $this->merchant->refresh()->getRawOriginal());
        $this->assertSame($ledgers, MerchantBalanceTransaction::query()->get()->toArray());
        $this->assertSame($refunds, OrderRefund::query()->get()->toArray());
    }

    public function test_report_retains_order_snapshots_and_can_select_the_merchant_by_full_name(): void
    {
        $order = $this->creditedOrder('AUDIT', ['status' => 'shipped']);

        $report = $this->runCommand(['merchant' => $this->merchant->name, '--apply' => true]);

        $this->assertArrayHasKey('settings', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertSame($order->id, $report['orders'][0]['order_id']);
        $this->assertSame($order->order_no, $report['orders'][0]['order_no']);
        $this->assertSame('shipped', $report['orders'][0]['status']);
        $this->assertSame('changed', $report['orders'][0]['result']);
        $this->assertSame('110.00', $report['orders'][0]['before']['converted_amount']);
        $this->assertSame('107.80', $report['orders'][0]['after']['converted_amount']);
        $this->assertSame('106.40', $report['orders'][0]['before']['settlement_amount']);
        $this->assertSame('104.20', $report['orders'][0]['after']['settlement_amount']);
        $this->assertSame('-2.20', $report['orders'][0]['surcharge_difference']);
        $this->assertSame('-2.20', $report['orders'][0]['settlement_difference']);
    }

    public function test_invalid_configuration_and_unknown_merchant_fail_without_modifying_orders(): void
    {
        $order = $this->creditedOrder('INVALID');
        $before = $order->refresh()->getRawOriginal();
        $this->configureSurcharge('percent', 'not-a-number', '0');

        $this->artisan('orders:recalculate-surcharge', [
            'merchant' => (string) $this->merchant->id, '--apply' => true,
            '--report' => $this->reportDirectory.'/invalid.json',
        ])->assertFailed();

        $this->configureSurcharge('percent', '-2', '0');
        $this->artisan('orders:recalculate-surcharge', [
            'merchant' => 'nonexistent-merchant', '--apply' => true,
            '--report' => $this->reportDirectory.'/unknown.json',
        ])->assertFailed();

        $this->assertSame($before, $order->refresh()->getRawOriginal());
    }

    public function test_invalid_or_reversed_dates_fail_without_writing_orders(): void
    {
        $order = $this->creditedOrder('BAD-DATES');
        $before = $order->refresh()->getRawOriginal();

        foreach ([['2026-02-30', '2026-03-01'], ['2026-10-03', '2026-10-01']] as [$from, $to]) {
            $this->artisan('orders:recalculate-surcharge', [
                'merchant' => (string) $this->merchant->id, '--apply' => true,
                '--from' => $from, '--to' => $to,
                '--report' => $this->reportDirectory.'/bad-dates-'.Str::uuid().'.json',
            ])->assertFailed();
        }

        $this->assertSame($before, $order->refresh()->getRawOriginal());
    }

    public function test_an_unavailable_report_path_prevents_any_order_or_balance_changes(): void
    {
        $orders = collect([$this->creditedOrder('ROLLBACK-1'), $this->creditedOrder('ROLLBACK-2')]);
        $before = $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->refresh()->getRawOriginal()])->all();
        $balance = $this->merchant->refresh()->getRawOriginal();
        $ledger = MerchantBalanceTransaction::query()->get()->toArray();
        $blockedPath = $this->reportDirectory.'/directory.json';
        File::makeDirectory($blockedPath);

        $this->artisan('orders:recalculate-surcharge', [
            'merchant' => (string) $this->merchant->id, '--apply' => true,
            '--report' => $blockedPath,
        ])->assertFailed();

        $this->assertSame($before, $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->refresh()->getRawOriginal()])->all());
        $this->assertSame($balance, $this->merchant->refresh()->getRawOriginal());
        $this->assertSame($ledger, MerchantBalanceTransaction::query()->get()->toArray());
    }

    public function test_report_write_failure_after_order_updates_rolls_back_the_entire_batch(): void
    {
        $orders = collect([$this->creditedOrder('WRITE-1'), $this->creditedOrder('WRITE-2')]);
        $before = $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->refresh()->getRawOriginal()])->all();
        $balance = $this->merchant->refresh()->getRawOriginal();
        $ledgers = MerchantBalanceTransaction::query()->get()->toArray();
        $updatedOrders = 0;
        DB::listen(function (QueryExecuted $query) use (&$updatedOrders): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update ') && str_contains($query->sql, 'orders')) {
                $updatedOrders++;
            }
        });
        $service = new class extends OrderSurchargeRecalculationService
        {
            protected function writeReport($file, array $report): void
            {
                throw new RuntimeException('模拟审计报告写入失败。');
            }
        };

        try {
            $service->run($this->merchant->id, true, null, null, false, $this->reportDirectory.'/write-failure.json');
            $this->fail('报告写入失败必须抛出异常。');
        } catch (RuntimeException $e) {
            $this->assertSame('模拟审计报告写入失败。', $e->getMessage());
        }

        $this->assertSame(2, $updatedOrders, '必须先执行两笔订单 UPDATE，再验证报告失败使整批回滚。');
        $this->assertSame($before, $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->refresh()->getRawOriginal()])->all());
        $this->assertSame($balance, $this->merchant->refresh()->getRawOriginal());
        $this->assertSame($ledgers, MerchantBalanceTransaction::query()->get()->toArray());
    }

    private function configureSurcharge(string $type, string $percent, string $fixed): void
    {
        SystemConfig::set('exchange.surcharge_type', $type);
        SystemConfig::set('exchange.surcharge_percent', $percent);
        SystemConfig::set('exchange.surcharge_fixed', $fixed);
    }

    private function baseOrder(string $identifier, array $overrides = []): Order
    {
        $order = $this->makeOrder('SSC-'.$identifier, 'M-'.$identifier, array_merge([
            'status' => 'paid', 'currency' => 'EUR', 'converted_currency' => 'USD',
            'payment_method' => $this->paymentMethod->method_code,
            'payment_method_id' => $this->paymentMethod->id,
            'subtotal' => '120.00', 'shipping_fee' => '5.00', 'discount' => '30.00', 'tax' => '5.00',
            'original_amount' => '120.00', 'auto_discount' => '20.00', 'amount' => '100.00',
            'converted_amount' => '110.00', 'subtotal_converted' => '132.00',
            'shipping_fee_converted' => '5.50', 'discount_converted' => '33.00', 'tax_converted' => '5.50',
            'exchange_rate' => '1.100000', 'original_exchange_rate' => '1.100000',
            'surcharge_type' => 'percent', 'fee_percent_amount' => '3.30',
            'fee_fixed_amount' => '0.30', 'settlement_amount' => '106.40', 'paid_at' => now(),
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $order->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $order;
    }

    private function otherMerchant(string $name): Merchant
    {
        return Merchant::withoutEvents(fn () => Merchant::create([
            'name' => $name, 'contact_person' => 'Tester', 'contact_phone' => '123456',
            'contact_email' => 'other@example.com',
        ]));
    }

    private function creditedOrder(string $identifier, array $overrides = []): Order
    {
        $order = $this->baseOrder($identifier, $overrides);
        app(BalanceService::class)->creditForPaidOrder($order);

        return $order;
    }

    private function runCommand(array $options = []): array
    {
        $path = $this->reportDirectory.'/'.Str::uuid().'.json';
        $this->artisan('orders:recalculate-surcharge', array_merge([
            'merchant' => (string) $this->merchant->id,
            '--report' => $path,
        ], $options))->assertSuccessful();

        $this->assertFileExists($path);

        return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
