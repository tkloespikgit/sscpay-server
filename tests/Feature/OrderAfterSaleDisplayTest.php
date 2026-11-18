<?php

namespace Tests\Feature;

use App\Filament\Resources\ChargebackOrders\ChargebackOrderResource;
use App\Filament\Resources\ChargebackOrders\Pages\ManageChargebackOrders;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\RefundOrders\Pages\ManageRefundOrders;
use App\Filament\Resources\RefundOrders\RefundOrderResource;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\OrderGatewayStatusEvent;
use App\Models\OrderRefund;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderAfterSaleService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderAfterSaleDisplayTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private PaymentMethod $paymentMethod;

    private User $reader;

    public function test_lists_separate_partial_and_full_refunds_from_direct_and_converted_chargebacks(): void
    {
        $this->prepareReader();
        $partial = $this->paidOrder('partial');
        $full = $this->paidOrder('full');
        $partialConversion = $this->paidOrder('partial-conversion');
        $fullConversion = $this->paidOrder('full-conversion');
        $direct = $this->paidOrder('direct');
        $pendingRefund = $this->paidOrder('pending-refund', ['status' => 'refunded']);
        $pendingChargeback = $this->paidOrder('pending-chargeback', ['status' => 'chargeback']);
        $paid = $this->paidOrder('unaffected');
        $disputing = $this->paidOrder('disputing', ['status' => 'disputing']);
        $this->refund($partial, '30.00', '2026-11-10 10:00:00');
        $this->refund($full, '100.00', '2026-11-10 11:00:00');
        $this->refund($partialConversion, '30.00', '2026-11-10 12:00:00');
        $this->refund($fullConversion, '100.00', '2026-11-10 13:00:00');
        $this->chargeback($partialConversion, '2026-11-18 10:00:00');
        $this->chargeback($fullConversion, '2026-11-18 11:00:00');
        $this->chargeback($direct, '2026-11-18 12:00:00');

        Livewire::test(ManageRefundOrders::class)->assertOk()
            ->assertCanSeeTableRecords([$partial, $full, $pendingRefund])
            ->assertCanNotSeeTableRecords([$partialConversion, $fullConversion, $direct, $pendingChargeback, $paid, $disputing]);
        Livewire::test(ManageChargebackOrders::class)->assertOk()
            ->assertCanSeeTableRecords([$partialConversion, $fullConversion, $direct, $pendingChargeback])
            ->assertCanNotSeeTableRecords([$partial, $full, $pendingRefund, $paid, $disputing]);
    }

    public function test_financial_facts_remain_visible_when_legacy_order_statuses_disagree(): void
    {
        $this->prepareReader();
        $refunded = $this->paidOrder('legacy-refund');
        $chargedBack = $this->paidOrder('legacy-chargeback');
        $gateway = $this->paidOrder('gateway-chargeback', ['gateway_status' => 'chargeback']);
        $this->refund($refunded, '30.00', '2026-11-10 10:00:00');
        $this->chargeback($chargedBack, '2026-11-11 10:00:00');
        $refunded->update(['status' => 'completed', 'gateway_status' => 'paid', 'refunded_amount' => '0.00']);
        $chargedBack->update(['status' => 'refunded', 'gateway_status' => 'refunded']);

        Livewire::test(ManageRefundOrders::class)->assertCanSeeTableRecords([$refunded])->assertCanNotSeeTableRecords([$chargedBack, $gateway]);
        Livewire::test(ManageChargebackOrders::class)->assertCanSeeTableRecords([$chargedBack, $gateway])->assertCanNotSeeTableRecords([$refunded]);
    }

    #[TestWith(['zh_CN'])]
    #[TestWith(['en'])]
    public function test_details_preserve_each_refund_and_show_actual_fees_to_an_order_reader(string $locale): void
    {
        $this->prepareReader();
        app()->setLocale($locale);
        $this->merchant->update(['timezone' => 'Pacific/Honolulu']);
        $order = $this->paidOrder('history');
        $this->refund($order, '30.00', '2026-11-09 10:15:00');
        $this->refund($order, '70.00', '2026-11-10 11:25:00');
        $this->chargeback($order, '2026-11-18 12:35:00');
        $order->update(['chargeback_fee_snapshot' => '99.00']);
        $this->paymentMethod->update(['chargeback_fee' => '120.00']);

        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertOk()
            ->assertSchemaComponentStateSet('after_sale_refunded_at', Carbon::parse('2026-11-10 11:25:00'))
            ->assertSchemaComponentStateSet('after_sale_chargeback_at', Carbon::parse('2026-11-10 11:25:00'))
            ->assertSchemaComponentStateSet('after_sale_chargeback_fee', '$15.00')
            ->assertSchemaComponentStateSet('after_sale_refunded_total', '100.00')
            ->assertSchemaComponentStateSet('after_sale_refund_fee_total', '4.00')
            ->assertSee('2026-11-09 10:15:00')->assertSee('2026-11-10 11:25:00')
            ->assertSee(__('admin.order.after_sale.refund_conversion'))
            ->assertSee(__('admin.order.after_sale.accounted'))
            ->assertActionHidden('refund')->assertActionHidden('chargeback');
        Livewire::test(ManageChargebackOrders::class)->assertTableColumnStateSet('after_sale_chargeback_fee', '$15.00', $order);
        $this->assertFalse($this->reader->can(Permissions::FINANCE_VIEW));
        $this->assertFalse($this->reader->can(Permissions::ORDERS_REFUND));
        $this->assertFalse($this->reader->can(Permissions::ORDERS_CHARGEBACK));
    }

    public function test_refund_sorting_and_inclusive_filters_use_the_latest_refund_time(): void
    {
        $this->prepareReader();
        $older = $this->paidOrder('older');
        $newer = $this->paidOrder('newer');
        $this->refund($newer, '20.00', '2026-11-05 10:00:00');
        $this->refund($older, '100.00', '2026-11-09 10:00:00');
        $this->refund($newer, '30.00', '2026-11-10 10:00:00');

        Livewire::test(ManageRefundOrders::class)->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->filterTable('after_sale_from', ['after_sale_from' => '2026-11-10 10:00:00'])
            ->filterTable('after_sale_to', ['after_sale_to' => '2026-11-10 10:00:00'])
            ->assertCanSeeTableRecords([$newer])->assertCanNotSeeTableRecords([$older])
            ->filterTable('after_sale_from', ['after_sale_from' => null])
            ->filterTable('after_sale_to', ['after_sale_to' => null])
            ->sortTable('after_sale_refunded_at', 'asc')->assertCanSeeTableRecords([$older, $newer], inOrder: true);
    }

    #[TestWith(['30.00'])]
    #[TestWith(['100.00'])]
    public function test_conversion_sorting_and_filters_use_the_refund_date_and_preserve_real_ledger_dates(string $amount): void
    {
        $this->prepareReader();
        $converted = $this->paidOrder('converted-time');
        $direct = $this->paidOrder('direct-time');
        $this->refund($converted, $amount, '2026-11-10 10:00:00');
        $this->chargeback($direct, '2026-11-11 10:00:00');
        $this->chargeback($converted, '2026-11-18 10:00:00');

        Livewire::test(ManageChargebackOrders::class)->assertCanSeeTableRecords([$direct, $converted], inOrder: true)
            ->filterTable('after_sale_from', ['after_sale_from' => '2026-11-10 10:00:00'])
            ->filterTable('after_sale_to', ['after_sale_to' => '2026-11-10 10:00:00'])
            ->assertCanSeeTableRecords([$converted])->assertCanNotSeeTableRecords([$direct])
            ->filterTable('after_sale_to', ['after_sale_to' => '2026-11-18 23:59:59'])
            ->filterTable('after_sale_from', ['after_sale_from' => '2026-11-18 00:00:00'])->assertCountTableRecords(0);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'order_id' => $converted->id, 'type' => 'chargeback', 'created_at' => '2026-11-18 10:00:00',
        ]);
        $this->assertSame('2026-11-11 10:00:00', $this->summary($direct)->after_sale_chargeback_at->format('Y-m-d H:i:s'));
    }

    public function test_gateway_fallback_ignores_rejected_duplicate_foreign_and_obsolete_events(): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('gateway-time', ['status' => 'refunded', 'gateway_order_no' => 'current-ref']);
        $this->gatewayEvent($order, 'refunded', '2026-11-10 10:00:00');
        $this->gatewayEvent($order, 'refunded', '2026-11-18 10:00:00', ['outcome' => 'duplicate']);
        $this->gatewayEvent($order, 'refunded', '2026-11-19 10:00:00', ['outcome' => 'conflict']);
        $this->gatewayEvent($order, 'refunded', '2026-11-20 10:00:00', ['gateway_reference' => 'obsolete-ref']);
        $this->gatewayEvent($order, 'refunded', '2026-11-21 10:00:00', ['merchant_id' => Merchant::create(['name' => 'Other event merchant'])->id]);

        $this->assertSame('2026-11-10 10:00:00', $this->summary($order)->after_sale_refunded_at->format('Y-m-d H:i:s'));
        Livewire::test(ManageRefundOrders::class)->filterTable('after_sale_to', ['after_sale_to' => '2026-11-10 10:00:00'])->assertCanSeeTableRecords([$order]);
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertSchemaComponentStateSet('after_sale_refunded_at', Carbon::parse('2026-11-10 10:00:00'));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_legacy_conversions_use_the_refund_gateway_time_and_leave_missing_refund_times_unknown(bool $hasRefundTime): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('legacy-conversion', ['status' => 'chargeback', 'refunded_amount' => '100.00']);
        $this->gatewayEvent($order, 'chargeback', '2026-11-18 10:00:00');

        if ($hasRefundTime) {
            $this->gatewayEvent($order, 'refunded', '2026-11-10 10:00:00');
        }

        $expected = $hasRefundTime ? Carbon::parse('2026-11-10 10:00:00') : null;
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertSchemaComponentStateSet('after_sale_refunded_at', $expected)
            ->assertSchemaComponentStateSet('after_sale_chargeback_at', $expected);
    }

    public function test_foreign_merchant_refund_and_ledger_rows_do_not_change_an_orders_classification(): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('foreign-children');
        $other = Merchant::create(['name' => 'Foreign child merchant']);
        OrderRefund::create([
            'merchant_id' => $other->id, 'order_id' => $order->id,
            'currency' => 'USD', 'amount' => '100.00', 'exchange_rate' => '1.000000', 'amount_usd' => '100.00', 'fee' => '50.00',
        ]);
        MerchantBalanceTransaction::create([
            'merchant_id' => $other->id, 'order_id' => $order->id, 'type' => 'chargeback',
            'amount' => '-100.00', 'balance_before' => '100.00', 'balance_after' => '0.00',
        ]);
        $admin = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        Livewire::test(ManageRefundOrders::class)->assertCanNotSeeTableRecords([$order]);
        Livewire::test(ManageChargebackOrders::class)->assertCanNotSeeTableRecords([$order]);
        $summary = $this->summary($order);
        $this->assertNull($summary->after_sale_refunded_total);
        $this->assertNull($summary->after_sale_chargeback_id);
    }

    public function test_successful_records_take_precedence_over_later_gateway_timestamps(): void
    {
        $this->prepareReader();
        $refund = $this->paidOrder('real-refund');
        $chargeback = $this->paidOrder('real-chargeback');
        $this->refund($refund, '100.00', '2026-11-10 10:00:00');
        $this->chargeback($chargeback, '2026-11-11 10:00:00');
        $this->gatewayEvent($refund, 'refunded', '2026-11-18 10:00:00');
        $this->gatewayEvent($chargeback, 'chargeback', '2026-11-18 10:00:00');

        $this->assertSame('2026-11-10 10:00:00', $this->summary($refund)->after_sale_refunded_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-11 10:00:00', $this->summary($chargeback)->after_sale_chargeback_at->format('Y-m-d H:i:s'));
    }

    public function test_missing_chargeback_time_is_not_inferred_from_order_creation_or_updates(): void
    {
        $this->prepareReader();
        $gateway = $this->paidOrder('pending-gateway', ['status' => 'chargeback']);
        $missing = $this->paidOrder('missing-time', ['status' => 'chargeback']);
        $this->gatewayEvent($gateway, 'chargeback', '2026-11-11 10:00:00');

        $this->assertSame('2026-11-11 10:00:00', $this->summary($gateway)->after_sale_chargeback_at->format('Y-m-d H:i:s'));
        $this->assertNull($this->summary($missing)->after_sale_chargeback_at);
        Livewire::test(ViewOrder::class, ['record' => $missing->id])
            ->assertSchemaComponentStateSet('after_sale_chargeback_at', null)->assertSee(__('admin.order.after_sale.not_recorded'));
    }

    public function test_gateway_receipt_time_is_used_when_the_accepted_event_has_no_remote_timestamp(): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('receipt-time', ['status' => 'refunded']);
        $this->travelToDate('2026-11-12 13:14:15');
        $this->gatewayEvent($order, 'refunded', null);

        $this->assertSame('2026-11-12 13:14:15', $this->summary($order)->after_sale_refunded_at->format('Y-m-d H:i:s'));
    }

    #[TestWith([null, '15.00'])]
    #[TestWith(['0.00', '0.00'])]
    #[TestWith(['12.50', '12.50'])]
    public function test_pending_fees_use_the_snapshot_before_the_original_channel_and_preserve_zero(?string $snapshot, string $expected): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('pending-fee', ['status' => 'chargeback', 'chargeback_fee_snapshot' => $snapshot]);
        $this->paymentMethod->delete();

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertSchemaComponentStateSet('after_sale_chargeback_fee', '$'.$expected)->assertSee(__('admin.order.after_sale.pending_accounting'));
        Livewire::test(ManageChargebackOrders::class)->assertTableColumnStateSet('after_sale_chargeback_fee', '$'.$expected, $order);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    public function test_legacy_orders_resolve_pending_fees_by_their_original_channel_code(): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('legacy-code', ['status' => 'chargeback', 'payment_method_id' => null, 'chargeback_fee_snapshot' => null]);
        $this->paymentMethod->delete();

        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertSchemaComponentStateSet('after_sale_chargeback_fee', '$15.00');
    }

    public function test_zero_fee_conversions_are_accounted_without_a_fee_transaction(): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('zero-fee', ['chargeback_fee_snapshot' => '0.00']);
        $this->refund($order, '100.00', '2026-11-10 10:00:00');
        $this->chargeback($order, '2026-11-18 10:00:00');
        $order->update(['chargeback_fee_snapshot' => '99.00']);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertSchemaComponentStateSet('after_sale_chargeback_fee', '$0.00')
            ->assertSee(__('admin.order.after_sale.accounted'))->assertDontSee(__('admin.order.after_sale.pending_accounting'));
        $this->assertDatabaseHas('merchant_balance_transactions', ['order_id' => $order->id, 'type' => 'chargeback', 'amount' => '0.00']);
        $this->assertDatabaseMissing('merchant_balance_transactions', ['order_id' => $order->id, 'type' => 'chargeback_fee']);
    }

    #[DataProvider('resources')]
    public function test_order_view_permission_opens_read_only_lists_linking_to_existing_details(string $resource, string $page, string $status): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('reader', ['status' => $status]);

        $this->assertTrue($resource::canAccess());
        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canEdit($order));
        $this->assertFalse($resource::canDelete($order));
        Livewire::test($page)->assertOk()->assertCanSeeTableRecords([$order])
            ->assertActionDoesNotExist('create')->assertActionDoesNotExist(TestAction::make('delete')->table($order))
            ->assertActionExists(TestAction::make('view')->table($order), fn (Action $action): bool => $action->getUrl() === OrderResource::getUrl('view', ['record' => $order]));
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertOk();
        $this->assertContains($resource, Filament::getPanel('admin')->getResources());
        $this->assertContains($resource, Filament::getPanel('merchant')->getResources());
        $this->assertNotContains($resource, Filament::getPanel('observer')->getResources());
    }

    #[DataProvider('resources')]
    public function test_guests_unprivileged_and_disabled_users_cannot_access_the_lists(string $resource, string $page, string $status): void
    {
        $this->prepareReader();
        auth()->logout();
        $this->assertFalse($resource::canAccess());
        Livewire::test($page)->assertForbidden();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo([Permissions::FINANCE_VIEW, Permissions::ORDERS_REFUND, Permissions::ORDERS_CHARGEBACK]);
        $this->actingAs($user);
        $this->assertFalse($resource::canAccess());
        Livewire::test($page)->assertForbidden();
        $user->givePermissionTo(Permissions::ORDERS_VIEW);
        $user->update(['status' => false]);
        $this->actingAs($user->fresh());

        $this->assertFalse($resource::canAccess());
        Livewire::test($page)->assertForbidden();
    }

    #[DataProvider('resources')]
    public function test_tenant_filters_cannot_expose_other_merchants_orders_or_details(string $resource, string $page, string $status): void
    {
        $this->prepareReader();
        $own = $this->paidOrder('own', ['status' => $status]);
        $other = $this->otherMerchantOrder($status);

        Livewire::test($page)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
            ->filterTable('application_id', $other->application_id)->assertCountTableRecords(0)
            ->filterTable('application_id', null)
            ->set('tableFilters.merchant_id.value', $other->merchant_id)->call('applyTableFilters')
            ->assertCanNotSeeTableRecords([$other]);
        Livewire::test(ViewOrder::class, ['record' => $other->id])->assertNotFound();
    }

    #[DataProvider('resources')]
    public function test_platform_managers_are_scoped_while_super_admins_can_view_all_merchants(string $resource, string $page, string $status): void
    {
        $this->prepareReader();
        $manager = User::factory()->create(['merchant_id' => null, 'status' => true]);
        $manager->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->merchant->update(['owner_id' => $manager->id]);
        $own = $this->paidOrder('managed', ['status' => $status]);
        $other = $this->otherMerchantOrder($status);
        Filament::setCurrentPanel('admin');

        Livewire::actingAs($manager)->test($page)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
            ->filterTable('merchant_id', $other->merchant_id)->assertCountTableRecords(0);
        $admin = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        Livewire::actingAs($admin)->test($page)->assertCanSeeTableRecords([$own, $other]);
    }

    public function test_conversion_refreshes_the_open_detail_without_a_page_reload(): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('live-conversion');
        $this->refund($order, '100.00', '2026-11-10 10:00:00');
        $this->reader->givePermissionTo(Permissions::ORDERS_CHARGEBACK);
        $authenticator = app(AppAuthentication::class);
        $this->reader->saveAppAuthenticationSecret($authenticator->generateSecret());
        $this->travelToDate('2026-11-18 10:00:00');

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('chargeback', ['reason' => 'Confirmed interception', 'mfa_code' => $authenticator->getCurrentCode($this->reader, $this->reader->getAppAuthenticationSecret())])
            ->assertHasNoFormErrors()
            ->assertSchemaComponentStateSet('after_sale_chargeback_at', Carbon::parse('2026-11-10 10:00:00'))
            ->assertSchemaComponentStateSet('after_sale_chargeback_fee', '$15.00')
            ->assertSee(__('admin.order.after_sale.refund_conversion'));
        $this->assertSame('chargeback', $order->refresh()->status);
    }

    public function test_reads_escape_refund_reasons_and_have_no_financial_or_remote_side_effects(): void
    {
        $this->prepareReader();
        $order = $this->paidOrder('read-only');
        $refund = $this->refund($order, '100.00', '2026-11-10 10:00:00');
        $refund->update(['reason' => '<script>alert("refund")</script>']);
        $this->chargeback($order, '2026-11-18 10:00:00');
        $balance = $this->merchant->fresh()->balance;
        $transactions = MerchantBalanceTransaction::query()->count();

        Livewire::test(ManageRefundOrders::class)->assertOk();
        Livewire::test(ManageChargebackOrders::class)->assertOk();
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertOk()
            ->assertSee('<script>alert("refund")</script>')->assertDontSee('<script>alert("refund")</script>', escape: false);
        $this->assertSame($balance, $this->merchant->fresh()->balance);
        $this->assertDatabaseCount('merchant_balance_transactions', $transactions);
        $this->assertDatabaseCount('order_refunds', 1);
        Http::assertNothingSent();
    }

    /** @return array<string, array{class-string, class-string, string}> */
    public static function resources(): array
    {
        return [
            'refunds' => [RefundOrderResource::class, ManageRefundOrders::class, 'refunded'],
            'chargebacks' => [ChargebackOrderResource::class, ManageChargebackOrders::class, 'chargeback'],
        ];
    }

    private function prepareReader(): void
    {
        app()->setLocale('en');
        Filament::setCurrentPanel('merchant');
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '10000.00']);
        $this->paymentMethod = $this->makePaymentMethod('after-sale');
        $this->paymentMethod->update(['refund_fee' => '2.00', 'chargeback_fee' => '15.00']);
        $this->reader = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $this->reader->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->actingAs($this->reader);
        $this->travelToDate('2026-11-01 09:00:00');
    }

    private function paidOrder(string $suffix, array $overrides = []): Order
    {
        return $this->makeOrder('AFTER-'.$suffix, 'M-'.$suffix, array_merge([
            'status' => 'paid', 'paid_at' => now(),
            'payment_method_id' => $this->paymentMethod->id, 'payment_method' => $this->paymentMethod->method_code,
            'refund_fee_snapshot' => '2.00', 'chargeback_fee_snapshot' => '15.00',
        ], $overrides));
    }

    private function otherMerchantOrder(string $status): Order
    {
        $merchant = Merchant::create(['name' => 'Other merchant']);
        $application = Application::createWithCredentials(['merchant_id' => $merchant->id, 'name' => 'Other app', 'website' => 'https://other.example.com']);

        return $this->paidOrder('other', ['status' => $status, 'merchant_id' => $merchant->id, 'application_id' => $application->id]);
    }

    private function refund(Order $order, string $amount, string $at): OrderRefund
    {
        $this->travelToDate($at);

        return app(BalanceService::class)->refund($order->fresh(), $amount, $this->reader, 'Refund confirmed');
    }

    private function chargeback(Order $order, string $at): void
    {
        $this->travelToDate($at);
        app(BalanceService::class)->chargeback($order->fresh(), $this->reader, 'Chargeback confirmed');
        $order->refresh();
    }

    private function gatewayEvent(Order $order, string $status, ?string $at, array $overrides = []): void
    {
        OrderGatewayStatusEvent::create(array_merge([
            'merchant_id' => $order->merchant_id, 'order_id' => $order->id, 'gateway_reference' => $order->gatewayOrderReference(),
            'source' => 'webhook', 'status' => $status, 'gateway_updated_at' => $at, 'outcome' => 'accepted', 'payload' => [],
        ], $overrides));
    }

    private function summary(Order $order): Order
    {
        return app(OrderAfterSaleService::class)->withSummary(Order::query())->findOrFail($order->id);
    }

    private function travelToDate(string $date): void
    {
        $this->travelTo(Carbon::parse($date, config('app.timezone')));
    }
}
