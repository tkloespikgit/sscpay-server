<?php

namespace Tests\Feature\Filament;

use App\Events\OrderStatusChanged;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Application;
use App\Models\Carrier;
use App\Models\GatewayRefund;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\GatewayRefundService;
use App\Services\OrderPaymentStatusService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderLifecycleActionsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Filament::setCurrentPanel('admin');
    }

    #[TestWith(['pending_settlement'])]
    #[TestWith(['completed'])]
    public function test_paid_orders_allow_financial_actions_and_cannot_be_manually_marked_complete(string $status): void
    {
        $order = $this->makeOrder('ACTIONS-'.$status, 'M-'.$status, ['status' => $status, 'paid_at' => now()]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('refund')
            ->assertActionVisible('chargeback')
            ->assertActionVisible('openDisputeEvent')
            ->assertActionHidden('manualStatusChange');
    }

    public function test_pending_order_can_only_be_manually_marked_failed(): void
    {
        $order = $this->makeOrder('MANUAL-FAIL', 'M-MANUAL-FAIL');
        Event::fake([OrderStatusChanged::class]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('manualStatusChange', ['target_status' => 'completed'])
            ->assertHasFormErrors(['target_status']);
        $this->assertSame('pending', $order->refresh()->status);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('manualStatusChange', ['target_status' => 'failed', 'reason' => 'Confirmed unpaid'])
            ->assertHasNoFormErrors();

        $this->assertSame('failed', $order->refresh()->status);
        $this->assertNotNull($order->failed_at);
        $this->assertStringContainsString('Confirmed unpaid', $order->remark);
    }

    public function test_refund_permission_is_rechecked_after_the_modal_is_opened(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_REFUND]);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $order = $this->makeOrder('REVOKED-REFUND', 'M-REVOKED-REFUND', ['status' => 'completed']);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('refund')
            ->fillForm(['amount' => '10.00', 'mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())]);
        $user->revokePermissionTo(Permissions::ORDERS_REFUND);

        $page->call('callMountedAction')->assertSuccessful();

        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertSame('completed', $order->refresh()->status);
    }

    public function test_disabled_user_cannot_execute_an_already_open_refund(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_REFUND]);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $order = $this->makeOrder('DISABLED-REFUND', 'M-DISABLED-REFUND', ['status' => 'completed']);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('refund')
            ->fillForm(['amount' => '10.00', 'mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())]);
        $user->update(['status' => false]);

        $page->call('callMountedAction')->assertSuccessful();

        $this->assertDatabaseCount('order_refunds', 0);
    }

    #[TestWith(['zh_CN'])]
    #[TestWith(['en'])]
    public function test_a_merchant_can_convert_a_fully_refunded_order_with_the_snapshotted_fee_and_totp(string $locale): void
    {
        app()->setLocale($locale);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_CHARGEBACK]);
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->refundedOrder($user);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('chargeback')
            ->assertActionHasLabel('chargeback', __('admin.finance.chargeback.refund_conversion_action'))
            ->assertActionExists('chargeback', fn (Action $action): bool => $action->getModalDescription()
                === __('admin.finance.chargeback.refund_conversion_desc', ['fee' => '12.00']))
            ->mountAction('chargeback')
            ->assertActionMounted('chargeback')
            ->fillForm([
                'reason' => '拒付拦截补扣手续费',
                'mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret()),
            ])
            ->call('callMountedAction')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.finance.chargeback.success'))
            ->assertActionHidden('chargeback');

        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('886.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'merchant_id' => $this->merchant->id, 'order_id' => $order->id,
            'type' => 'chargeback_fee', 'amount' => '-12.00',
            'operator_id' => $user->id, 'reason' => '拒付拦截补扣手续费',
        ]);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_refund_to_chargeback_conversion_requires_a_bound_authenticator(): void
    {
        $user = auth()->user();
        $order = $this->refundedOrder($user);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('chargeback', ['reason' => '拒付拦截', 'mfa_code' => '123456'])
            ->assertNotified(__('admin.finance.security.not_bound'));

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('898.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseMissing('merchant_balance_transactions', ['type' => 'chargeback']);
    }

    public function test_chargeback_permission_is_rechecked_after_opening_a_refund_conversion(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_CHARGEBACK]);
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->refundedOrder($user);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('chargeback')
            ->fillForm(['mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())]);
        $user->revokePermissionTo(Permissions::ORDERS_CHARGEBACK);

        $page->call('callMountedAction')->assertSuccessful();

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('898.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseMissing('merchant_balance_transactions', ['type' => 'chargeback_fee']);
    }

    #[TestWith(['refunded', '0.00'])]
    #[TestWith(['refunded', '50.00'])]
    #[TestWith(['partially_refunded', '150.00'])]
    public function test_incomplete_refunds_do_not_offer_chargeback_conversion(string $status, string $refundedAmount): void
    {
        $order = $this->makeOrder('INCOMPLETE-REFUND', 'M-INCOMPLETE-REFUND', [
            'status' => $status, 'refunded_amount' => $refundedAmount,
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('chargeback');
    }

    #[TestWith(['zh_CN'])]
    #[TestWith(['en'])]
    public function test_partial_refund_conversion_shows_remaining_principal_and_requires_a_reason(string $locale): void
    {
        app()->setLocale($locale);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_CHARGEBACK]);
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->refundedOrder($user, '30.00');
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('chargeback')
            ->assertActionHasLabel('chargeback', __('admin.finance.chargeback.partial_refund_conversion_action'))
            ->assertActionExists('chargeback', fn (Action $action): bool => $action->getModalDescription()
                === __('admin.finance.chargeback.partial_refund_conversion_desc', [
                    'refunded' => '30.00', 'amount' => '70.00', 'fee' => '12.00', 'total' => '82.00',
                ]))
            ->mountAction('chargeback')
            ->fillForm(['mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())])
            ->call('callMountedAction')
            ->assertHasFormErrors(['reason' => 'required']);
        $this->assertSame('968.00', $this->merchant->refresh()->balance);

        $page->fillForm(['reason' => '核对后确认剩余本金拒付'])
            ->call('callMountedAction')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.finance.chargeback.success'))
            ->assertActionHidden('chargeback');

        $this->assertSame('886.00', $this->merchant->refresh()->balance);
        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('30.00', $order->refunded_amount);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'order_id' => $order->id, 'type' => 'chargeback', 'amount' => '-70.00',
        ]);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_actual_settlement_filter_is_independent_of_order_status_and_reversal_accounting(): void
    {
        $settled = $this->makeOrder('SETTLED-PARTIAL', 'M-SETTLED-PARTIAL', ['status' => 'partially_refunded']);
        $pending = $this->makeOrder('MANUAL-COMPLETED', 'M-MANUAL-COMPLETED', ['status' => 'completed']);
        $legacy = $this->makeOrder('LEGACY-COMPLETED', 'M-LEGACY-COMPLETED', ['status' => 'completed']);
        $this->settlement($settled, 'settled');
        $this->settlement($pending, 'pending');

        Livewire::test(ListOrders::class)
            ->filterTable('settlement_status', 'settled')
            ->assertCanSeeTableRecords([$settled])
            ->assertCanNotSeeTableRecords([$pending, $legacy])
            ->assertTableColumnFormattedStateSet('settlement.status', __('settlement.statuses.settled'), $settled)
            ->filterTable('settlement_status', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$settled, $legacy])
            ->filterTable('settlement_status', 'none')
            ->assertCanSeeTableRecords([$legacy])
            ->assertCanNotSeeTableRecords([$settled, $pending]);
    }

    public function test_query_action_cannot_run_after_order_view_permission_is_revoked(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->makeOrder('REVOKED-QUERY', 'M-REVOKED-QUERY', ['status' => 'completed']);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);
        $this->mock(OrderPaymentStatusService::class)->shouldNotReceive('queryAndApply');
        $user->revokePermissionTo(Permissions::ORDERS_VIEW);

        $page->call('mountAction', 'queryOrderStatus');

        $this->assertSame('completed', $order->refresh()->status);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    #[TestWith(['pending_settlement', 'pending'])]
    #[TestWith(['completed', 'settled'])]
    public function test_payment_based_order_can_record_shipping_without_losing_settlement_progress(string $status, string $settlementStatus): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $order = $this->makeOrder('SETTLEMENT-SHIP', 'M-SETTLEMENT-SHIP', [
            'status' => $status, 'paid_at' => now(), 'settlement_mode' => 'immediate', 'settlement_days' => 7,
        ]);
        $this->settlement($order, $settlementStatus);
        Carrier::create(['carrier_code' => 'ups', 'carrier_name' => 'UPS', 'status' => Carrier::STATUS_ENABLED]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction(TestAction::make('recordShipment')->schemaComponent(), [
                'logistics_company' => 'ups',
                'tracking_number' => '1Z-COMPLETED',
                'shipped_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('order_shippings', ['order_id' => $order->id, 'tracking_number' => '1Z-COMPLETED']);
        $this->assertSame($status, $order->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_order_readers_see_unbooked_gateway_evidence_when_no_local_refund_exists(): void
    {
        $reader = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $reader->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->actingAs($reader);
        Filament::setCurrentPanel('merchant');
        $method = $this->makePaymentMethod('receipt');
        $order = $this->makeOrder('NO-CREDIT', 'M-NO-CREDIT', [
            'status' => 'paid', 'paid_at' => now(), 'transaction_id' => 'txn_1',
            'payment_method' => $method->method_code, 'payment_method_id' => $method->id,
        ]);
        $receipt = $this->receiveRefund($order, '12.34', 're_missing_credit');
        $this->assertSame('missing_income_ledger', $receipt->last_error);
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('re_missing_credit')->assertSee('12.34 USD')
            ->assertSee(__('admin.finance.refund.receipt_reason.missing_income_ledger'))
            ->assertActionHidden('resolveGatewayRefund');
        $this->assertDatabaseCount('order_refunds', 0);
    }

    public function test_refund_conflicts_only_show_the_report_for_the_viewers_order(): void
    {
        $method = $this->makePaymentMethod('conflict-source');
        $bookedOrder = $this->makeOrder('BOOKED-ORDER', 'M-BOOKED', [
            'status' => 'paid', 'paid_at' => now(), 'transaction_id' => 'txn_1', 'settlement_amount' => '95.00',
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        app(BalanceService::class)->creditForPaidOrder($bookedOrder);
        $receipt = $this->receiveRefund($bookedOrder, '10.00', 're_shared');
        $this->assertSame('booked', $receipt->status);
        $otherMerchant = Merchant::create(['name' => 'Other merchant']);
        $app = Application::createWithCredentials(['merchant_id' => $otherMerchant->id, 'name' => 'Other app', 'website' => 'https://other.example.com']);
        $otherOrder = $this->makeOrder('OTHER-ORDER', 'M-OTHER', ['merchant_id' => $otherMerchant->id, 'application_id' => $app->id]);
        $reports = [
            [$bookedOrder, 'txn_booked_claim', '23.45', 'USD'],
            [$otherOrder, 'txn_other_claim', '67.89', 'EUR'],
        ];
        $this->travelTo(now()->setTime(13, 14, 15));
        foreach ($reports as [$order, $paymentReference, $amount, $currency]) {
            app(GatewayRefundService::class)->receive([
                'event' => 'payment_refund', 'status' => 'succeeded', 's_order_id' => $order->gatewayOrderReference(),
                'payment_method' => 'stripe', 'livemode' => true, 'refund_id' => 're_shared',
                'payment_reference' => $paymentReference, 'amount' => $amount, 'currency' => $currency,
                'refunded_at' => '2026-11-25T10:00:00Z',
            ]);
        }
        Filament::setCurrentPanel('merchant');
        foreach ($reports as $index => [$order, $paymentReference, $amount, $currency]) {
            $reader = User::factory()->create(['merchant_id' => $order->merchant_id]);
            $reader->givePermissionTo(Permissions::ORDERS_VIEW);
            $this->actingAs($reader);
            Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
                ->assertSee('re_shared')->assertSee($paymentReference)->assertSee($amount.' '.$currency)
                ->assertSee(now()->format('Y-m-d H:i:s'))->assertDontSee($reports[1 - $index][1]);
        }
        $this->assertDatabaseCount('order_refunds', 1);
        $this->assertSame('85.00', $this->merchant->fresh()->balance);
        $this->assertSame('0.00', $otherMerchant->fresh()->balance);
    }

    public function test_an_unlinked_gateway_receipt_can_only_be_reconciled_with_totp(): void
    {
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $operator->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_REFUND]);
        $this->actingAs($operator);
        Filament::setCurrentPanel('merchant');
        $order = $this->refundedOrder($operator, '10.00');
        $receipt = GatewayRefund::create([
            'merchant_id' => $order->merchant_id, 'order_id' => $order->id, 'payment_method_id' => $order->payment_method_id,
            'identity_hash' => hash('sha256', 'receipt'), 'order_refund_hash' => hash('sha256', 'refund'),
            'gateway_reference' => $order->order_no, 'payment_method' => 'stripe', 'livemode' => true,
            'refund_id' => 're_unlinked', 'payment_reference' => 'transaction', 'amount' => '10.00',
            'currency' => 'USD', 'refunded_at' => now(), 'status' => 'review', 'last_error' => 'unlinked_refunds',
        ]);
        $data = ['receipt_id' => $receipt->id, 'resolution' => $order->refunds()->sole()->id];
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertSee('re_unlinked')
            ->assertSee(__('admin.finance.refund.receipt_reason.unlinked_refunds'))
            ->callAction('resolveGatewayRefund', $data + ['mfa_code' => '123456']);
        $this->assertSame('review', $receipt->refresh()->status);
        $auth = app(AppAuthentication::class);
        $operator->saveAppAuthenticationSecret($auth->generateSecret());
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('resolveGatewayRefund', $data + ['mfa_code' => $auth->getCurrentCode($operator, $operator->getAppAuthenticationSecret())]);
        $this->assertSame('booked', $receipt->refresh()->status);
        $this->assertSame($operator->id, $receipt->reviewed_by);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    private function refundedOrder(User $operator, string $refundAmount = '100.00'): Order
    {
        $this->merchant->update(['balance' => '900.00']);
        $method = $this->makePaymentMethod('refund-conversion');
        $method->update(['chargeback_fee' => '50.00']);
        $order = $this->makeOrder('REFUND-CONVERSION', 'M-REFUND-CONVERSION', [
            'status' => 'paid', 'paid_at' => now(), 'payment_method_id' => $method->id,
            'payment_method' => $method->method_code, 'refund_fee_snapshot' => '2.00',
            'chargeback_fee_snapshot' => '12.00', 'settlement_amount' => '100.00',
        ]);
        app(BalanceService::class)->creditForPaidOrder($order);
        app(BalanceService::class)->refund($order, $refundAmount, $operator);

        return $order->refresh();
    }

    private function settlement(Order $order, string $status): OrderSettlement
    {
        return OrderSettlement::create([
            'merchant_id' => $order->merchant_id, 'order_id' => $order->id,
            'amount' => '95.00', 'remaining_amount' => $status === 'settled' ? '0.00' : '95.00',
            'mode' => 'immediate', 'cycle_days' => 7, 'reserve_percent' => '0', 'reserve_days' => 0,
            'status' => $status, 'started_at' => now()->subDays(7), 'due_at' => $status === 'settled' ? now() : now()->addDays(7),
            'settled_at' => $status === 'settled' ? now() : null,
        ]);
    }
}
